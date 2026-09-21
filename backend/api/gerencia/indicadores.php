<?php
/**
 * v10.16 (pedido explicito de Ricardo): indicadores de gestion para
 * controlar el avance del proyecto -- ingresos por dia (throughput) y
 * tiempo promedio de contratacion (desde que Capataz selecciona hasta
 * 'Contratado'), agregados en el rango de fechas elegido.
 *
 * Mismos roles que detalle_tiempos.php (version agregada de esa misma
 * idea, por eso comparte roles con ella en vez de con estadisticas.php).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Jefe_Administrativo', 'Admin_Contrato', 'Gerencia']);
exigirMetodo('GET');

$dias = (int)($_GET['dias'] ?? 30);

$pdo = obtenerConexion();

// --- Ingresos por dia (throughput) -----------------------------------
$sqlIngresos = 'SELECT DATE(creado_at) AS fecha, COUNT(*) AS total
                  FROM postulaciones';
if ($dias > 0) {
    $sqlIngresos .= ' WHERE creado_at >= DATE_SUB(NOW(), INTERVAL :dias DAY)';
}
$sqlIngresos .= ' GROUP BY DATE(creado_at) ORDER BY fecha ASC';

$stmtIngresos = $pdo->prepare($sqlIngresos);
if ($dias > 0) {
    $stmtIngresos->bindValue('dias', $dias, PDO::PARAM_INT);
}
$stmtIngresos->execute();
$ingresosPorDia = $stmtIngresos->fetchAll();

// --- Tiempo promedio de contratacion -----------------------------------
// Desde que llega a 'Pre_aprobado_terreno' (Capataz selecciona) hasta
// que llega a 'Contratado'. Misma fuente que detalle_tiempos.php
// (trazabilidad_logs, que el trigger llena solo en cada cambio de
// estado), pero agregada sobre todas las postulaciones que terminaron
// dentro del rango en vez de una sola.
$sqlTiempos = "SELECT p.id,
                      MIN(CASE WHEN t.accion LIKE '%-> Pre_aprobado_terreno' THEN t.fecha_hora END) AS t_inicio,
                      MIN(CASE WHEN t.accion LIKE '%-> Contratado' THEN t.fecha_hora END) AS t_contratado,
                      MIN(CASE WHEN t.accion LIKE '%-> Proceso_completo' THEN t.fecha_hora END) AS t_completo
                 FROM postulaciones p
                 JOIN trazabilidad_logs t ON t.postulacion_id = p.id
                WHERE p.estado IN ('Contratado', 'Proceso_completo')";
if ($dias > 0) {
    $sqlTiempos .= ' AND p.actualizado_at >= DATE_SUB(NOW(), INTERVAL :dias DAY)';
}
$sqlTiempos .= ' GROUP BY p.id';

$stmtTiempos = $pdo->prepare($sqlTiempos);
if ($dias > 0) {
    $stmtTiempos->bindValue('dias', $dias, PDO::PARAM_INT);
}
$stmtTiempos->execute();

$duraciones = [];
$fechasContratacion = [];
foreach ($stmtTiempos->fetchAll() as $fila) {
    $inicio = $fila['t_inicio'];
    $fin = $fila['t_contratado'] ?? $fila['t_completo'];
    if ($fin !== null) {
        $fechasContratacion[] = $fin;
    }
    if ($inicio === null || $fin === null) {
        continue;
    }
    $segundos = strtotime($fin) - strtotime($inicio);
    if ($segundos >= 0) {
        $duraciones[] = $segundos;
    }
}

// --- Contrataciones por semana (pedido explicito de Ricardo) -----------
// Agrupa cada contratacion por la fecha del lunes de su semana (ISO,
// no depende de "this week" de PHP que varia si hoy ya es lunes).
$porSemana = [];
foreach ($fechasContratacion as $fechaHora) {
    $dt = new DateTime($fechaHora);
    $diaIso = (int)$dt->format('N'); // 1 = lunes ... 7 = domingo
    $lunes = (clone $dt)->modify('-' . ($diaIso - 1) . ' days')->format('Y-m-d');
    $porSemana[$lunes] = ($porSemana[$lunes] ?? 0) + 1;
}
ksort($porSemana);
$contratacionesPorSemana = [];
foreach ($porSemana as $semanaInicio => $total) {
    $contratacionesPorSemana[] = ['semana_inicio' => $semanaInicio, 'total' => $total];
}
$promedioSemanal = count($porSemana) > 0
    ? round(array_sum($porSemana) / count($porSemana), 1)
    : null;

/** "2d 3h 15min" -- igual que detalle_tiempos.php, sin segundos (es un promedio, no un instante). */
function formatearDuracionAproximada(int $segundos): string
{
    $dias = intdiv($segundos, 86400);
    $segundos %= 86400;
    $horas = intdiv($segundos, 3600);
    $segundos %= 3600;
    $minutos = intdiv($segundos, 60);

    $partes = [];
    if ($dias > 0) $partes[] = "{$dias}d";
    if ($horas > 0) $partes[] = "{$horas}h";
    if ($minutos > 0 || !$partes) $partes[] = "{$minutos}min";
    return implode(' ', $partes);
}

$muestras = count($duraciones);
$promedioSegundos = $muestras > 0 ? (int)round(array_sum($duraciones) / $muestras) : null;

responderOk([
    'ingresos_por_dia' => $ingresosPorDia,
    'tiempo_contratacion' => [
        'muestras' => $muestras,
        'promedio_segundos' => $promedioSegundos,
        'promedio_texto' => $promedioSegundos !== null ? formatearDuracionAproximada($promedioSegundos) : null,
        'minimo_texto' => $muestras > 0 ? formatearDuracionAproximada(min($duraciones)) : null,
        'maximo_texto' => $muestras > 0 ? formatearDuracionAproximada(max($duraciones)) : null,
    ],
    'contrataciones_por_semana' => $contratacionesPorSemana,
    'contrataciones_promedio_semanal' => $promedioSemanal,
]);
