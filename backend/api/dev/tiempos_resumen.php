<?php
/**
 * v10.28 - Panel de Desarrollador: cuánto se demoró cada rol en hacer su
 * parte de cada etapa del proceso.
 *
 * No hay tabla nueva: todo sale de marcas de hora que la plataforma ya
 * guarda (creación de la postulación, el log de cambios de estado, la
 * verificación de identidad, la firma del contrato, la recepción en
 * terreno y la fecha en que el postulante completó la Etapa 2).
 *
 * v10.37 - Etapas alineadas con el DUEÑO REAL de cada paso (de un hito al siguiente).
 * Antes faltaban Prevención, Portería y Bodega; la "firma" del JAO incluía la noche entre
 * el Día 0 y el Día 1, y el "retiro" del capataz incluía la IRL y el EPP de otros roles.
 *
 *    0. Aprobación de cupos        Admin. Contrato  solicitud de cupos -> aprobada
 *    1. Selección en terreno       Capataz          postula -> seleccionado
 *    2. Ingreso a la obra          Postulante/Port. seleccionado -> Portería confirma ingreso
 *    3. Etapa 2 del postulante     Postulante       seleccionado -> datos completados
 *    4. Verificación de identidad  JAO              datos completados -> verificada
 *    5. Inducción (Día 0)          Prevención       verificada -> check de inducción
 *    6. Presentación Día 1         Postulante/Port. inducción -> Portería autoriza el paso
 *    7. Firma de contrato          JAO              Portería autoriza -> firmado
 *    8. IRL (Día 1)                Prevención       firmado -> IRL registrada
 *    9. Entrega de EPP             Bodega           IRL -> kit entregado (Contratado)
 *   10. Retiro en terreno          Capataz / J.T.   Contratado -> recibido en terreno
 *
 * Filtro opcional "origen": qr (llegó por el QR de la obra) o referido (llegó por el
 * enlace de un capataz o Jefe de Terreno). Sus tiempos son muy distintos y mezclarlos
 * distorsiona las medianas.
 *
 * Los tiempos son de reloj corrido (incluyen noches y fines de semana):
 * por eso se muestra la MEDIANA como cifra principal -- el promedio se
 * distorsiona con una sola persona que quedó esperando el fin de semana.
 *
 * Parámetros opcionales: dias (7, 30, 90; 0 o ausente = todo el historial) y origen (qr | referido).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('GET');

$dias = (int)($_GET['dias'] ?? 0);
$pdo = obtenerConexion();

$origen = (string)($_GET['origen'] ?? '');
$where = "p.estado <> 'Rechazado'";
if ($dias > 0) {
    $where .= ' AND p.creado_at >= DATE_SUB(NOW(), INTERVAL ' . $dias . ' DAY)';
}
if ($origen === 'qr') {
    $where .= ' AND p.referido_por_id IS NULL';
} elseif ($origen === 'referido') {
    $where .= ' AND p.referido_por_id IS NOT NULL';
}

// Primer o último hito (hora o usuario) de una acción registrada en la bitácora.
$hito = function (string $condicion, string $campo, string $orden = 'DESC'): string {
    return "(SELECT t.$campo FROM trazabilidad_logs t
              WHERE t.postulacion_id = p.id AND $condicion
              ORDER BY t.fecha_hora $orden, t.id $orden LIMIT 1)";
};
$cambioA = fn (string $estadoNuevo) => "t.accion LIKE " . $pdo->quote("Cambio de estado: % -> $estadoNuevo");
$condSeleccion = $cambioA('Pre_aprobado_terreno');
$condInduccion = $cambioA('Induccion_ok');
$condPorteria1 = 't.accion = ' . $pdo->quote(ACCION_INGRESO_CONTRATACION);
// v10.46: la IRL ahora tiene dos confirmaciones: presentes en la sala (incluye la marca antigua) e IRL realizada.
$condIrlSala   = "(t.accion LIKE " . $pdo->quote('Prevención registró la IRL%') . " OR t.accion = " . $pdo->quote(ACCION_IRL_PRESENTES) . ")";
$condIrl       = "t.accion = " . $pdo->quote(ACCION_IRL_COMPLETADA);
$condEpp       = $cambioA('Contratado');

$stmt = $pdo->query(
    "SELECT p.id, p.estado, p.creado_at, p.referido_por_id,
            p.ingreso_faena_at,
            p.identidad_verificada_at, p.identidad_verificada_por,
            p.contrato_firmado_at, p.contrato_firmado_por,
            p.recibido_terreno_at, p.recibido_terreno_por,
            d.creado_at AS etapa2_at,
            {$hito($condSeleccion, 'fecha_hora')} AS seleccion_at,
            {$hito($condSeleccion, 'usuario_id')} AS seleccion_por,
            {$hito($condInduccion, 'fecha_hora')} AS induccion_at,
            {$hito($condInduccion, 'usuario_id')} AS induccion_por,
            {$hito($condPorteria1, 'fecha_hora')} AS porteria1_at,
            {$hito($condPorteria1, 'usuario_id')} AS porteria1_por,
            {$hito($condIrlSala, 'fecha_hora')} AS irl_sala_at,
            {$hito($condIrlSala, 'usuario_id')} AS irl_sala_por,
            {$hito($condIrl, 'fecha_hora')} AS irl_at,
            {$hito($condIrl, 'usuario_id')} AS irl_por,
            {$hito($condEpp, 'fecha_hora')} AS epp_at,
            {$hito($condEpp, 'usuario_id')} AS epp_por
       FROM postulaciones p
       LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
      WHERE $where"
);
$filas = $stmt->fetchAll();
$ahora = strtotime((string)$pdo->query('SELECT NOW()')->fetchColumn());

$nombres = [];
foreach ($pdo->query('SELECT id, nombre FROM usuarios') as $u) {
    $nombres[(int)$u['id']] = $u['nombre'];
}

// Definición de etapas: desde/hasta son columnas de la fila; actor = columna
// con el id del usuario que cerró la etapa (null si no hay un usuario único).
// 'esperando' decide quién está hoy "en espera" de que se cierre la etapa.
$etapas = [
    ['clave' => 'seleccion',   'nombre' => 'Selección en terreno',         'rol' => 'Capataz',
     'desde' => 'creado_at',                'hasta' => 'seleccion_at',            'actor' => 'seleccion_por',
     'esperando' => fn ($f) => $f['estado'] === 'Pendiente'],
    ['clave' => 'ingreso',     'nombre' => 'Ingreso a la obra (Día 0)',    'rol' => 'Postulante / Portería',
     'desde' => 'seleccion_at',             'hasta' => 'ingreso_faena_at',        'actor' => null,
     'esperando' => fn ($f) => $f['estado'] === 'Pre_aprobado_terreno' && $f['ingreso_faena_at'] === null],
    ['clave' => 'etapa2',      'nombre' => 'Etapa 2 (datos y documentos)', 'rol' => 'Postulante',
     'desde' => 'seleccion_at',             'hasta' => 'etapa2_at',               'actor' => null,
     'esperando' => fn ($f) => $f['estado'] === 'Pre_aprobado_terreno'],
    ['clave' => 'verificacion', 'nombre' => 'Verificación de identidad',   'rol' => 'JAO',
     'desde' => 'etapa2_at',                'hasta' => 'identidad_verificada_at', 'actor' => 'identidad_verificada_por',
     'esperando' => fn ($f) => $f['etapa2_at'] !== null && $f['identidad_verificada_at'] === null],
    ['clave' => 'induccion',   'nombre' => 'Inducción (Día 0)',            'rol' => 'Prevención',
     'desde' => 'identidad_verificada_at',  'hasta' => 'induccion_at',            'actor' => 'induccion_por',
     'esperando' => fn ($f) => $f['identidad_verificada_at'] !== null && $f['induccion_at'] === null],
    ['clave' => 'porteria1',   'nombre' => 'Presentación en Portería (Día 1)', 'rol' => 'Postulante / Portería',
     'desde' => 'induccion_at',             'hasta' => 'porteria1_at',            'actor' => 'porteria1_por',
     'esperando' => fn ($f) => $f['induccion_at'] !== null && $f['porteria1_at'] === null],
    ['clave' => 'firma',       'nombre' => 'Firma de contrato',            'rol' => 'JAO',
     'desde' => 'porteria1_at',             'hasta' => 'contrato_firmado_at',     'actor' => 'contrato_firmado_por',
     'esperando' => fn ($f) => $f['porteria1_at'] !== null && $f['contrato_firmado_at'] === null],
    ['clave' => 'irl_sala',    'nombre' => 'Presentes en la sala (Día 1)',  'rol' => 'Prevención',
     'desde' => 'contrato_firmado_at',      'hasta' => 'irl_sala_at',             'actor' => 'irl_sala_por',
     'esperando' => fn ($f) => $f['contrato_firmado_at'] !== null && $f['irl_sala_at'] === null],
    ['clave' => 'irl',         'nombre' => 'IRL realizada (Día 1)',        'rol' => 'Prevención',
     'desde' => 'irl_sala_at',              'hasta' => 'irl_at',                  'actor' => 'irl_por',
     'esperando' => fn ($f) => $f['irl_sala_at'] !== null && $f['irl_at'] === null],
    ['clave' => 'epp',         'nombre' => 'Entrega de EPP',               'rol' => 'Bodega',
     'desde' => 'irl_at',                   'hasta' => 'epp_at',                  'actor' => 'epp_por',
     'esperando' => fn ($f) => $f['irl_at'] !== null && $f['epp_at'] === null],
    ['clave' => 'retiro',      'nombre' => 'Retiro en terreno',            'rol' => 'Capataz / Jefe de Terreno',
     'desde' => 'epp_at',                   'hasta' => 'recibido_terreno_at',     'actor' => 'recibido_terreno_por',
     'esperando' => fn ($f) => $f['epp_at'] !== null && $f['recibido_terreno_at'] === null],
];

function estadisticas(array $segundos): array
{
    sort($segundos);
    $n = count($segundos);
    if ($n === 0) {
        return ['n' => 0, 'mediana' => null, 'promedio' => null, 'minimo' => null, 'maximo' => null];
    }
    $mitad = intdiv($n, 2);
    $mediana = $n % 2 ? $segundos[$mitad] : (int)round(($segundos[$mitad - 1] + $segundos[$mitad]) / 2);
    return [
        'n' => $n,
        'mediana' => $mediana,
        'promedio' => (int)round(array_sum($segundos) / $n),
        'minimo' => $segundos[0],
        'maximo' => $segundos[$n - 1],
    ];
}

$resultado = [];

// Etapa 0: aprobación de cupos (Jefe de Terreno pide -> Administrador aprueba). Va por solicitud,
// no por persona, así que sale de su propia tabla.
$sqlCupos = "SELECT creado_at, resuelta_at, resuelta_por, estado FROM solicitudes_cupo";
if ($dias > 0) {
    $sqlCupos .= ' WHERE creado_at >= DATE_SUB(NOW(), INTERVAL ' . $dias . ' DAY)';
}
$durCupos = []; $porUsuarioCupos = []; $esperaCupos = [];
foreach ($pdo->query($sqlCupos)->fetchAll() as $c) {
    if ($c['resuelta_at'] !== null && $c['estado'] === 'Aprobada') {
        $seg = strtotime($c['resuelta_at']) - strtotime($c['creado_at']);
        if ($seg >= 0) {
            $durCupos[] = $seg;
            if (!empty($c['resuelta_por'])) {
                $porUsuarioCupos[(int)$c['resuelta_por']][] = $seg;
            }
        }
    } elseif ($c['estado'] === 'Pendiente') {
        $esperaCupos[] = max($ahora - strtotime($c['creado_at']), 0);
    }
}
$usuariosCupos = [];
foreach ($porUsuarioCupos as $uid => $lista) {
    $usuariosCupos[] = ['usuario' => $nombres[$uid] ?? ('Usuario #' . $uid)] + estadisticas($lista);
}
$resultado[] = [
    'clave' => 'cupos', 'nombre' => 'Aprobación de cupos', 'rol' => 'Admin. de Contrato',
    'completadas' => estadisticas($durCupos),
    'en_espera' => [
        'n' => count($esperaCupos),
        'mas_antigua' => $esperaCupos ? max($esperaCupos) : null,
        'promedio' => $esperaCupos ? (int)round(array_sum($esperaCupos) / count($esperaCupos)) : null,
    ],
    'por_usuario' => $usuariosCupos,
];

foreach ($etapas as $e) {
    $duraciones = [];
    $porUsuario = [];
    $enEspera = [];
    foreach ($filas as $f) {
        $desde = $f[$e['desde']] ?? null;
        $hasta = $f[$e['hasta']] ?? null;
        if ($desde !== null && $hasta !== null) {
            $seg = strtotime($hasta) - strtotime($desde);
            if ($seg < 0) {
                continue; // dato inconsistente (p. ej. selección deshecha): se ignora
            }
            $duraciones[] = $seg;
            if ($e['actor'] !== null && !empty($f[$e['actor']])) {
                $porUsuario[(int)$f[$e['actor']]][] = $seg;
            }
        } elseif ($desde !== null && $hasta === null && $e['esperando']($f)) {
            $enEspera[] = max($ahora - strtotime($desde), 0);
        }
    }

    $usuarios = [];
    foreach ($porUsuario as $uid => $lista) {
        $usuarios[] = ['usuario' => $nombres[$uid] ?? ('Usuario #' . $uid)] + estadisticas($lista);
    }
    usort($usuarios, fn ($a, $b) => $b['n'] <=> $a['n']);

    $resultado[] = [
        'clave' => $e['clave'],
        'nombre' => $e['nombre'],
        'rol' => $e['rol'],
        'completadas' => estadisticas($duraciones),
        'en_espera' => [
            'n' => count($enEspera),
            'mas_antigua' => $enEspera ? max($enEspera) : null,
            'promedio' => $enEspera ? (int)round(array_sum($enEspera) / count($enEspera)) : null,
        ],
        'por_usuario' => $usuarios,
    ];
}

// Proceso completo: de la selección en terreno hasta el retiro.
$totales = [];
foreach ($filas as $f) {
    if ($f['seleccion_at'] !== null && $f['recibido_terreno_at'] !== null) {
        $seg = strtotime($f['recibido_terreno_at']) - strtotime($f['seleccion_at']);
        if ($seg >= 0) {
            $totales[] = $seg;
        }
    }
}

responderOk([
    'dias' => $dias,
    'origen' => $origen,
    'postulaciones_consideradas' => count($filas),
    'etapas' => $resultado,
    'proceso_completo' => estadisticas($totales),
]);
