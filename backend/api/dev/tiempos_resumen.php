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
 * Etapas (de un hito al siguiente) y quién es el dueño de cada una:
 *   1. Selección en terreno      Capataz         creación  -> seleccionado
 *   2. Autorización de cupo      Admin. Contrato seleccionado -> autorizado (si se usó)
 *   3. Etapa 2 del postulante    Postulante      seleccionado -> datos completados
 *   4. Verificación de identidad JAO             datos completados -> verificada
 *   5. Firma de contrato         JAO             verificada -> firmado
 *   6. Retiro en terreno         Capataz / J.T.  firmado -> recibido en terreno
 *
 * Los tiempos son de reloj corrido (incluyen noches y fines de semana):
 * por eso se muestra la MEDIANA como cifra principal -- el promedio se
 * distorsiona con una sola persona que quedó esperando el fin de semana.
 *
 * Parámetro opcional: dias (7, 30, 90; 0 o ausente = todo el historial).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('GET');

$dias = (int)($_GET['dias'] ?? 0);
$pdo = obtenerConexion();

$where = "p.estado <> 'Rechazado'";
$params = [];
if ($dias > 0) {
    $where .= ' AND p.creado_at >= DATE_SUB(NOW(), INTERVAL ' . $dias . ' DAY)';
}

$stmt = $pdo->query(
    "SELECT p.id, p.estado, p.creado_at,
            p.admin_autorizado_at, p.admin_autorizado_por,
            p.identidad_verificada_at, p.identidad_verificada_por,
            p.contrato_firmado_at, p.contrato_firmado_por,
            p.recibido_terreno_at, p.recibido_terreno_por,
            d.creado_at AS etapa2_at,
            (SELECT t.fecha_hora FROM trazabilidad_logs t
              WHERE t.postulacion_id = p.id AND t.accion LIKE 'Cambio de estado: % -> Pre_aprobado_terreno'
              ORDER BY t.fecha_hora DESC, t.id DESC LIMIT 1) AS seleccion_at,
            (SELECT t.usuario_id FROM trazabilidad_logs t
              WHERE t.postulacion_id = p.id AND t.accion LIKE 'Cambio de estado: % -> Pre_aprobado_terreno'
              ORDER BY t.fecha_hora DESC, t.id DESC LIMIT 1) AS seleccion_por
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
// con el id del usuario que cerró la etapa (null si la hizo el postulante).
$etapas = [
    ['clave' => 'seleccion',    'nombre' => 'Selección en terreno',       'rol' => 'Capataz',
     'desde' => 'creado_at',               'hasta' => 'seleccion_at',            'actor' => 'seleccion_por',
     'esperando' => fn ($f) => $f['estado'] === 'Pendiente'],
    ['clave' => 'autorizacion', 'nombre' => 'Autorización del cupo',      'rol' => 'Admin. de Contrato',
     'desde' => 'seleccion_at',            'hasta' => 'admin_autorizado_at',     'actor' => 'admin_autorizado_por',
     'esperando' => fn ($f) => false],
    ['clave' => 'etapa2',       'nombre' => 'Etapa 2 (datos y documentos)', 'rol' => 'Postulante',
     'desde' => 'seleccion_at',            'hasta' => 'etapa2_at',               'actor' => null,
     'esperando' => fn ($f) => $f['estado'] === 'Pre_aprobado_terreno'],
    ['clave' => 'verificacion', 'nombre' => 'Verificación de identidad',  'rol' => 'JAO',
     'desde' => 'etapa2_at',               'hasta' => 'identidad_verificada_at', 'actor' => 'identidad_verificada_por',
     'esperando' => fn ($f) => $f['etapa2_at'] !== null && $f['identidad_verificada_at'] === null],
    ['clave' => 'firma',        'nombre' => 'Firma de contrato',          'rol' => 'JAO',
     'desde' => 'identidad_verificada_at', 'hasta' => 'contrato_firmado_at',     'actor' => 'contrato_firmado_por',
     'esperando' => fn ($f) => $f['identidad_verificada_at'] !== null && $f['contrato_firmado_at'] === null],
    ['clave' => 'retiro',       'nombre' => 'Retiro en terreno',          'rol' => 'Capataz / Jefe de Terreno',
     'desde' => 'contrato_firmado_at',     'hasta' => 'recibido_terreno_at',     'actor' => 'recibido_terreno_por',
     'esperando' => fn ($f) => $f['contrato_firmado_at'] !== null && $f['recibido_terreno_at'] === null],
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
    'postulaciones_consideradas' => count($filas),
    'etapas' => $resultado,
    'proceso_completo' => estadisticas($totales),
]);
