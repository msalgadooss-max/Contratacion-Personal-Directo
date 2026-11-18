<?php
/**
 * v6.7 - Detalle de tiempos por trabajador (JAO): a qué hora exacta
 * (con segundos) empezó y terminó cada etapa de su proceso, desde que
 * Jefe de Terreno pre-aprobó hasta quedar Contratado (o hasta ahora, si
 * todavía sigue en curso).
 *
 * No se guarda un timestamp por etapa en una tabla aparte: se arma
 * combinando dos fuentes que ya existen -- trazabilidad_logs (el
 * trigger trg_postulaciones_log_estado deja registrado CADA cambio de
 * `estado`, con NOW() al segundo, sin que ningún endpoint tenga que
 * acordarse de hacerlo a mano) y las dos banderas propias del tramo
 * "Pre_aprobado_terreno" (admin_autorizado_at y datos_contratacion.creado_at),
 * que no son estados reales pero sí hitos que al JAO le importan.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
// v10.14 (pedido explícito del usuario, item 14): Admin de Contrato y
// Subgerente también pueden ver el detalle de tiempos por trabajador
// (antes solo el JAO).
requireRol(['Jefe_Administrativo', 'Admin_Contrato', 'Gerencia']);
exigirMetodo('GET');

$postulacionId = (int)($_GET['postulacion_id'] ?? 0);
if ($postulacionId <= 0) {
    responderError('postulacion_id inválido.', 422);
}

$pdo = obtenerConexion();

$stmt = $pdo->prepare(
    'SELECT p.nombre_completo, p.rut, p.estado, p.creado_at, p.admin_autorizado_at,
            uAdmin.nombre AS admin_autorizado_por_nombre,
            c.nombre_cargo,
            d.creado_at AS etapa2_completada_at,
            p.ingreso_faena_at,
            p.identidad_verificada_at, uIdent.nombre AS identidad_verificada_por_nombre,
            p.contrato_firmado_at, uFirma.nombre AS contrato_firmado_por_nombre,
            j.creado_at AS datos_jao_at
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
       LEFT JOIN usuarios uAdmin ON uAdmin.id = p.admin_autorizado_por
       LEFT JOIN usuarios uIdent ON uIdent.id = p.identidad_verificada_por
       LEFT JOIN usuarios uFirma ON uFirma.id = p.contrato_firmado_por
       LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
       LEFT JOIN datos_jao j ON j.postulacion_id = p.id
      WHERE p.id = :id'
);
$stmt->execute(['id' => $postulacionId]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    responderError('Postulación no encontrada.', 404);
}

$stmtLogs = $pdo->prepare(
    "SELECT t.accion, t.fecha_hora, u.nombre AS usuario_nombre
       FROM trazabilidad_logs t
       LEFT JOIN usuarios u ON u.id = t.usuario_id
      WHERE t.postulacion_id = :id AND t.accion LIKE 'Cambio de estado:%'
      ORDER BY t.fecha_hora ASC, t.id ASC"
);
$stmtLogs->execute(['id' => $postulacionId]);
$logs = $stmtLogs->fetchAll();

$etiquetasEstado = [
    // v10.24: quien selecciona hoy es el Capataz (el Jefe de Terreno es de
    // solo lectura); el nombre real de la persona sale en "por ...".
    'Pre_aprobado_terreno' => 'Seleccionado en terreno (Capataz)',
    'Aprobado_admin'       => 'En revisión Jefe Administrativo',
    'Induccion_ok'         => 'Inducción de seguridad realizada',
    'EPP_listo'            => 'Kit de EPP listo',
    'Contratado'           => 'Contratado',
    'Proceso_completo'     => 'Recibido en terreno -- proceso completo',
    'Rechazado'            => 'Rechazado (no continúa en el proceso)',
];

/** "2d 3h 15min 42s" -- se omiten las unidades en cero, nunca se muestran decimales. */
function formatearDuracionFina(int $segundos): string
{
    $dias = intdiv($segundos, 86400);
    $segundos %= 86400;
    $horas = intdiv($segundos, 3600);
    $segundos %= 3600;
    $minutos = intdiv($segundos, 60);
    $segundos %= 60;

    $partes = [];
    if ($dias > 0) $partes[] = "{$dias}d";
    if ($horas > 0) $partes[] = "{$horas}h";
    if ($minutos > 0) $partes[] = "{$minutos}min";
    if ($segundos > 0 || !$partes) $partes[] = "{$segundos}s";
    return implode(' ', $partes);
}

// v10.45 (pedido del usuario, prueba real del 17-11): el detalle solo mostraba los CAMBIOS DE ESTADO,
// asi que no aparecian el ingreso a la obra, la verificacion de identidad del JAO, los datos de
// nomina, la autorizacion de Porteria del Dia 1, la firma ni la IRL: parecia detenido en Prevencion
// aunque la persona esperaba la firma del JAO. Todo esto YA se guardaba (columnas de postulaciones,
// datos_jao y trazabilidad_logs); aqui solo se lee. No cambia ningun paso del proceso.
$stmtExtra = $pdo->prepare(
    "SELECT t.accion, t.fecha_hora, u.nombre AS usuario_nombre
       FROM trazabilidad_logs t
       LEFT JOIN usuarios u ON u.id = t.usuario_id
      WHERE t.postulacion_id = :id
        AND (t.accion = :paso OR t.accion IN (:irl_a, :irl_b) OR t.accion = :irl_c OR t.accion LIKE '%ingreso a faena%')
      ORDER BY t.fecha_hora ASC, t.id ASC"
);
$stmtExtra->execute(['id' => $postulacionId, 'paso' => ACCION_INGRESO_CONTRATACION, 'irl_a' => ACCION_IRL_REALIZADA, 'irl_b' => ACCION_IRL_PRESENTES, 'irl_c' => ACCION_IRL_COMPLETADA]);
$logsExtra = ['paso' => null, 'sala' => null, 'irl' => null, 'faena' => null];
foreach ($stmtExtra->fetchAll() as $e) {
    if ($e['accion'] === ACCION_INGRESO_CONTRATACION && $logsExtra['paso'] === null) {
        $logsExtra['paso'] = $e;
    } elseif (in_array($e['accion'], [ACCION_IRL_REALIZADA, ACCION_IRL_PRESENTES], true) && $logsExtra['sala'] === null) {
        $logsExtra['sala'] = $e;
    } elseif ($e['accion'] === ACCION_IRL_COMPLETADA && $logsExtra['irl'] === null) {
        $logsExtra['irl'] = $e;
    } elseif (stripos($e['accion'], 'ingreso a faena') !== false && $logsExtra['faena'] === null) {
        $logsExtra['faena'] = $e;
    }
}

// Orden de desempate cuando dos hitos caen en el mismo segundo (el orden real del proceso).
$ORDEN = [
    'recibida' => 0, 'Pre_aprobado_terreno' => 1, 'admin' => 2, 'faena' => 3, 'etapa2' => 4, 'Aprobado_admin' => 5,
    'identidad' => 6, 'Induccion_ok' => 7, 'nomina' => 8, 'paso' => 9, 'firma' => 10, 'sala' => 10.5, 'irl' => 11,
    'EPP_listo' => 12, 'Contratado' => 13, 'Proceso_completo' => 14, 'Rechazado' => 15,
];
// v10.45 (pedido del usuario): de QUIEN son los minutos que aparecen en cada paso. El tiempo desde el hito
// anterior se atribuye al rol que realizó ESTE paso (mismo criterio que "Tiempos por rol y etapa" del Desarrollador).
$ROL_DEL_TIEMPO = [
    'Pre_aprobado_terreno' => 'Capataz', 'admin' => 'Administrador de Contrato', 'faena' => 'Postulante / Portería',
    'etapa2' => 'Postulante', 'Aprobado_admin' => 'Postulante', 'identidad' => 'JAO', 'Induccion_ok' => 'Prevención',
    'nomina' => 'JAO', 'paso' => 'Postulante / Portería (Día 1)', 'firma' => 'JAO', 'sala' => 'Prevención', 'irl' => 'Prevención',
    'EPP_listo' => 'Bodega', 'Contratado' => 'Bodega', 'Proceso_completo' => 'Capataz / Jefe de Terreno',
];
$hitos = [['clave' => 'recibida', 'etiqueta' => 'Postulación recibida', 'fecha_hora' => $postulacion['creado_at'], 'quien' => null]];

foreach ($logs as $log) {
    if (!preg_match('/Cambio de estado: .+ -> (\w+)/', $log['accion'], $m)) {
        continue;
    }
    $estadoNuevo = $m[1];
    // "En revision del JAO" cae en el MISMO segundo que "Datos completados" (es el mismo momento): se omite.
    if ($estadoNuevo === 'Aprobado_admin' && $postulacion['etapa2_completada_at'] !== null
        && abs(strtotime($log['fecha_hora']) - strtotime($postulacion['etapa2_completada_at'])) <= 2) {
        continue;
    }
    $hitos[] = [
        'clave' => $estadoNuevo,
        'etiqueta' => $etiquetasEstado[$estadoNuevo] ?? $estadoNuevo,
        'fecha_hora' => $log['fecha_hora'],
        'quien' => $log['usuario_nombre'],
    ];
}
if ($postulacion['admin_autorizado_at'] !== null) {
    $hitos[] = ['clave' => 'admin', 'etiqueta' => 'Autorización del Administrador de Contrato',
                'fecha_hora' => $postulacion['admin_autorizado_at'], 'quien' => $postulacion['admin_autorizado_por_nombre']];
}
$ingresoFaena = $postulacion['ingreso_faena_at'] ?? ($logsExtra['faena']['fecha_hora'] ?? null);
if ($ingresoFaena !== null) {
    // v10.48: si lo registró el JAO (a mano o al verificar la identidad) no se dice "Portería".
    $textoFaena = (string)($logsExtra['faena']['accion'] ?? '');
    $faenaPorJao = stripos($textoFaena, 'JAO confirmó') !== false || stripos($textoFaena, 'al verificar la identidad') !== false;
    $hitos[] = ['clave' => 'faena',
                'etiqueta' => $faenaPorJao ? 'Ingreso a la obra confirmado a mano por el JAO' : 'Ingreso a la obra confirmado (Portería)',
                'fecha_hora' => $ingresoFaena, 'quien' => $logsExtra['faena']['usuario_nombre'] ?? null];
}
if ($postulacion['etapa2_completada_at'] !== null) {
    $hitos[] = ['clave' => 'etapa2', 'etiqueta' => 'Datos y documentos completados por el postulante',
                'fecha_hora' => $postulacion['etapa2_completada_at'], 'quien' => null];
}
if ($postulacion['identidad_verificada_at'] !== null) {
    $hitos[] = ['clave' => 'identidad', 'etiqueta' => 'Identidad verificada (JAO)',
                'fecha_hora' => $postulacion['identidad_verificada_at'], 'quien' => $postulacion['identidad_verificada_por_nombre']];
}
if ($postulacion['datos_jao_at'] !== null) {
    $hitos[] = ['clave' => 'nomina', 'etiqueta' => 'Datos de nómina completados (JAO)',
                'fecha_hora' => $postulacion['datos_jao_at'], 'quien' => null];
}
if ($logsExtra['paso'] !== null) {
    $hitos[] = ['clave' => 'paso', 'etiqueta' => 'Paso a contratación autorizado (Portería, Día 1)',
                'fecha_hora' => $logsExtra['paso']['fecha_hora'], 'quien' => $logsExtra['paso']['usuario_nombre']];
}
if ($postulacion['contrato_firmado_at'] !== null) {
    $hitos[] = ['clave' => 'firma', 'etiqueta' => 'Contrato firmado (JAO)',
                'fecha_hora' => $postulacion['contrato_firmado_at'], 'quien' => $postulacion['contrato_firmado_por_nombre']];
}
if ($logsExtra['sala'] !== null) {
    $hitos[] = ['clave' => 'sala', 'etiqueta' => 'Presentes en la sala (Prevención, Día 1)',
                'fecha_hora' => $logsExtra['sala']['fecha_hora'], 'quien' => $logsExtra['sala']['usuario_nombre']];
}
if ($logsExtra['irl'] !== null) {
    $hitos[] = ['clave' => 'irl', 'etiqueta' => 'IRL realizada (Prevención, Día 1)',
                'fecha_hora' => $logsExtra['irl']['fecha_hora'], 'quien' => $logsExtra['irl']['usuario_nombre']];
}

usort($hitos, static function ($x, $y) use ($ORDEN) {
    $c = strtotime($x['fecha_hora']) <=> strtotime($y['fecha_hora']);
    return $c !== 0 ? $c : (($ORDEN[$x['clave']] ?? 99) <=> ($ORDEN[$y['clave']] ?? 99));
});

// Duracion desde el hito anterior, para cada uno.
$anterior = null;
$etiquetaAnterior = null;
foreach ($hitos as &$h) {
    $segundos = $anterior !== null ? (strtotime($h['fecha_hora']) - strtotime($anterior)) : null;
    $h['duracion_desde_anterior'] = $segundos !== null ? formatearDuracionFina(max($segundos, 0)) : null;
    $h['rol_tiempo'] = $segundos !== null ? ($ROL_DEL_TIEMPO[$h['clave']] ?? null) : null;
    $h['desde_etiqueta'] = $etiquetaAnterior;
    $anterior = $h['fecha_hora'];
    $etiquetaAnterior = $h['etiqueta'];
    unset($h['clave']);
}
unset($h);

// v6.7: el total que le importa al JAO -- desde que Terreno pre-aprobo
// hasta Contratado (o hasta ahora mismo, si el proceso sigue en curso).
$fechaTerreno = null;
$fechaContratado = null;
foreach ($hitos as $h) {
    if ($h['etiqueta'] === 'Seleccionado en terreno (Capataz)' && $fechaTerreno === null) {
        $fechaTerreno = $h['fecha_hora'];
    }
    if ($h['etiqueta'] === 'Contratado') {
        $fechaContratado = $h['fecha_hora'];
    }
}
$enCurso = $fechaContratado === null && $postulacion['estado'] !== 'Rechazado';
// v6.7: "ahora" se pide a MySQL, no a PHP -- si el contenedor y la BD
// quedaran en zonas horarias distintas (paso justo en el hosting), usar
// date() de PHP como "ahora" desalinea el calculo contra los timestamps
// que puso el trigger (todos con NOW() de MySQL), y podia mostrar una
// duracion "en curso" con horas de mas o de menos.
$finParaTotal = $fechaContratado ?? ($enCurso ? $pdo->query('SELECT NOW()')->fetchColumn() : null);
$duracionTotal = ($fechaTerreno !== null && $finParaTotal !== null)
    ? formatearDuracionFina(max(strtotime($finParaTotal) - strtotime($fechaTerreno), 0))
    : null;

// v10.45: en qué paso está AHORA y a quién le toca (solo lectura: se deduce de lo que ya está registrado).
$pasoActual = null;
$ultimoHito = $hitos ? end($hitos)['fecha_hora'] : null;
$estadoActual = $postulacion['estado'];
$texto = null;
$responsable = null;
if ($estadoActual === 'Pendiente') {
    [$texto, $responsable] = ['Esperando que un Capataz la seleccione', 'Capataz'];
} elseif ($estadoActual === 'Pre_aprobado_terreno') {
    if ($postulacion['etapa2_completada_at'] === null) {
        [$texto, $responsable] = ['Esperando que el postulante complete su Etapa 2 (datos y documentos)', 'Postulante'];
    } else {
        [$texto, $responsable] = ['Esperando la revisión del JAO', 'JAO'];
    }
} elseif ($estadoActual === 'Aprobado_admin') {
    if ($postulacion['identidad_verificada_at'] === null) {
        [$texto, $responsable] = ['Esperando la verificación de identidad del JAO', 'JAO'];
    } else {
        [$texto, $responsable] = ['Esperando el check de inducción de Prevención (Día 0)', 'Prevención'];
    }
} elseif ($estadoActual === 'Induccion_ok') {
    $faltan = [];
    if ($logsExtra['paso'] === null) {
        $faltan[] = 'la autorización de Portería del Día 1 (el JAO puede confirmarla a mano)';
    }
    if ($postulacion['datos_jao_at'] === null) {
        $faltan[] = 'los datos de nómina del JAO';
    }
    if ($faltan) {
        [$texto, $responsable] = ['Esperando ' . implode(' y ', $faltan) . ' para poder firmar el contrato', $logsExtra['paso'] === null ? 'Portería / JAO' : 'JAO'];
    } elseif ($postulacion['contrato_firmado_at'] === null) {
        [$texto, $responsable] = ['Esperando la firma del contrato', 'JAO'];
    } elseif ($logsExtra['sala'] === null) {
        [$texto, $responsable] = ['Esperando que Prevención confirme que están presentes en la sala (Día 1)', 'Prevención'];
    } elseif ($logsExtra['irl'] === null) {
        [$texto, $responsable] = ['Presentes en la sala: esperando que Prevención confirme que la IRL se realizó', 'Prevención'];
    } else {
        [$texto, $responsable] = ['Esperando la entrega del kit de EPP', 'Bodega'];
    }
} elseif ($estadoActual === 'Contratado') {
    [$texto, $responsable] = ['Esperando el retiro en terreno', 'Capataz / Jefe de Terreno'];
}
if ($texto !== null && $ultimoHito !== null) {
    $ahoraBd = $pdo->query('SELECT NOW()')->fetchColumn();
    $pasoActual = [
        'texto' => $texto,
        'responsable' => $responsable,
        'desde' => $ultimoHito,
        'hace' => formatearDuracionFina(max(strtotime($ahoraBd) - strtotime($ultimoHito), 0)),
    ];
}

responderOk([
    'nombre_completo' => $postulacion['nombre_completo'],
    'rut' => $postulacion['rut'],
    'cargo' => $postulacion['nombre_cargo'],
    'estado' => $postulacion['estado'],
    'hitos' => $hitos,
    'duracion_total_desde_terreno' => $duracionTotal,
    'proceso_en_curso' => $enCurso,
    'paso_actual' => $pasoActual,
]);
