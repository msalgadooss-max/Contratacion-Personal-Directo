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
            d.creado_at AS etapa2_completada_at
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
       LEFT JOIN usuarios uAdmin ON uAdmin.id = p.admin_autorizado_por
       LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
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

$hitos = [];
$hitos[] = [
    'etiqueta' => 'Postulación recibida',
    'fecha_hora' => $postulacion['creado_at'],
    'quien' => null,
];

foreach ($logs as $log) {
    if (!preg_match('/Cambio de estado: .+ -> (\w+)/', $log['accion'], $m)) {
        continue;
    }
    $estadoNuevo = $m[1];
    $hitos[] = [
        'etiqueta' => $etiquetasEstado[$estadoNuevo] ?? $estadoNuevo,
        'fecha_hora' => $log['fecha_hora'],
        'quien' => $log['usuario_nombre'],
    ];

    // v6.7: justo al llegar a 'Pre_aprobado_terreno' se insertan los dos
    // hitos sinteticos del flujo SECUENCIAL actual -- cronologicamente
    // ambos caen entre esta llegada y la siguiente ("-> Aprobado_admin"),
    // por eso se insertan aqui mismo, en el orden real: primero autoriza
    // el Administrador, recien despues el postulante completa Etapa 2.
    if ($estadoNuevo === 'Pre_aprobado_terreno') {
        if ($postulacion['admin_autorizado_at'] !== null) {
            $hitos[] = [
                'etiqueta' => 'Autorización del Administrador de Contrato',
                'fecha_hora' => $postulacion['admin_autorizado_at'],
                'quien' => $postulacion['admin_autorizado_por_nombre'],
            ];
        }
        if ($postulacion['etapa2_completada_at'] !== null) {
            $hitos[] = [
                'etiqueta' => 'Datos y documentos completados por el postulante',
                'fecha_hora' => $postulacion['etapa2_completada_at'],
                'quien' => null,
            ];
        }
    }
}

// Duracion desde el hito anterior, para cada uno.
$anterior = null;
foreach ($hitos as &$h) {
    $segundos = $anterior !== null ? (strtotime($h['fecha_hora']) - strtotime($anterior)) : null;
    $h['duracion_desde_anterior'] = $segundos !== null ? formatearDuracionFina(max($segundos, 0)) : null;
    $anterior = $h['fecha_hora'];
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

responderOk([
    'nombre_completo' => $postulacion['nombre_completo'],
    'rut' => $postulacion['rut'],
    'cargo' => $postulacion['nombre_cargo'],
    'estado' => $postulacion['estado'],
    'hitos' => $hitos,
    'duracion_total_desde_terreno' => $duracionTotal,
    'proceso_en_curso' => $enCurso,
]);
