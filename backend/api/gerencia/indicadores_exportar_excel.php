<?php
/**
 * v10.16 (pedido explicito de Ricardo): version descargable de los
 * indicadores de gestion del panel de Gerencia (indicadores.php) -- los
 * mismos numeros que se ven en pantalla, mas contrataciones por semana
 * con su promedio, para poder compartirlos o pegarlos en otro reporte.
 *
 * Mismas consultas que indicadores.php + el conteo de
 * admin_general/estadisticas.php, repetidas aqui en vez de compartidas
 * porque cada endpoint de exportacion en esta app ya es autocontenido
 * (ver gerencia/exportar_excel.php, dev/encuesta_exportar_excel.php).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$vendorAutoload = __DIR__ . '/../../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    responderError('Falta instalar dependencias (composer install) para generar el Excel.', 500);
}
require_once $vendorAutoload;

iniciarSesionSegura();
requireRol(['Jefe_Administrativo', 'Admin_Contrato', 'Gerencia']);
exigirMetodo('GET');

$dias = (int)($_GET['dias'] ?? 30);
$rangoTexto = $dias > 0 ? "Últimos {$dias} días" : 'Todo el historial';

$pdo = obtenerConexion();

// --- Ingresos por dia --------------------------------------------------
$sqlIngresos = 'SELECT DATE(creado_at) AS fecha, COUNT(*) AS total FROM postulaciones';
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

// --- Contratados vs. rechazados (misma logica que admin_general/estadisticas.php) --
$sqlConteo = "SELECT estado, COUNT(*) AS total
                FROM postulaciones
               WHERE estado IN ('Contratado', 'Proceso_completo', 'Rechazado')";
if ($dias > 0) {
    $sqlConteo .= ' AND actualizado_at >= DATE_SUB(NOW(), INTERVAL :dias DAY)';
}
$sqlConteo .= ' GROUP BY estado';
$stmtConteo = $pdo->prepare($sqlConteo);
if ($dias > 0) {
    $stmtConteo->bindValue('dias', $dias, PDO::PARAM_INT);
}
$stmtConteo->execute();
$conteo = ['Contratado' => 0, 'Rechazado' => 0];
foreach ($stmtConteo->fetchAll() as $fila) {
    if ($fila['estado'] === 'Proceso_completo') {
        $conteo['Contratado'] += (int)$fila['total'];
    } else {
        $conteo[$fila['estado']] = (int)$fila['total'];
    }
}

// --- Tiempo de contratacion + contrataciones por semana (misma fuente que indicadores.php) --
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
$porSemana = [];
foreach ($stmtTiempos->fetchAll() as $fila) {
    $inicio = $fila['t_inicio'];
    $fin = $fila['t_contratado'] ?? $fila['t_completo'];
    if ($fin !== null) {
        $dt = new DateTime($fin);
        $diaIso = (int)$dt->format('N');
        $lunes = (clone $dt)->modify('-' . ($diaIso - 1) . ' days')->format('Y-m-d');
        $porSemana[$lunes] = ($porSemana[$lunes] ?? 0) + 1;
    }
    if ($inicio === null || $fin === null) {
        continue;
    }
    $segundos = strtotime($fin) - strtotime($inicio);
    if ($segundos >= 0) {
        $duraciones[] = $segundos;
    }
}
ksort($porSemana);

if (!$ingresosPorDia && !$porSemana) {
    responderError('No hay datos en ese rango de fechas.', 404);
}

/** "2d 3h 15min" -- igual que indicadores.php. */
function formatearDuracionAproximadaExport(int $segundos): string
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

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

// --- Hoja "Ingresos por dia" -------------------------------------------
$hojaIngresos = $spreadsheet->getActiveSheet();
$hojaIngresos->setTitle('Ingresos por dia');
$hojaIngresos->setCellValue('A1', 'Fecha');
$hojaIngresos->setCellValue('B1', 'Ingresos');
$hojaIngresos->getStyle('A1:B1')->getFont()->setBold(true);
foreach ($ingresosPorDia as $idx => $f) {
    $fila = $idx + 2;
    $hojaIngresos->setCellValue("A{$fila}", $f['fecha']);
    $hojaIngresos->setCellValue("B{$fila}", (int)$f['total']);
}
$hojaIngresos->getColumnDimension('A')->setAutoSize(true);
$hojaIngresos->getColumnDimension('B')->setAutoSize(true);
$ultimaFilaIngresos = count($ingresosPorDia) + 1;

// --- Hoja "Contrataciones por semana" -----------------------------------
$hojaSemana = $spreadsheet->createSheet();
$hojaSemana->setTitle('Contrataciones por semana');
$hojaSemana->setCellValue('A1', 'Semana (lunes)');
$hojaSemana->setCellValue('B1', 'Contrataciones');
$hojaSemana->getStyle('A1:B1')->getFont()->setBold(true);
$filaSemana = 2;
foreach ($porSemana as $semanaInicio => $total) {
    $hojaSemana->setCellValue("A{$filaSemana}", $semanaInicio);
    $hojaSemana->setCellValue("B{$filaSemana}", $total);
    $filaSemana++;
}
$ultimaFilaSemana = $filaSemana - 1;
if ($ultimaFilaSemana >= 2) {
    $hojaSemana->setCellValue("A{$filaSemana}", 'Promedio semanal');
    $hojaSemana->setCellValue("B{$filaSemana}", "=AVERAGE(B2:B{$ultimaFilaSemana})");
    $hojaSemana->getStyle("A{$filaSemana}:B{$filaSemana}")->getFont()->setBold(true);
}
$hojaSemana->getColumnDimension('A')->setAutoSize(true);
$hojaSemana->getColumnDimension('B')->setAutoSize(true);

// --- Hoja "Resumen" ------------------------------------------------------
$hojaResumen = $spreadsheet->createSheet();
$hojaResumen->setTitle('Resumen');
$hojaResumen->setCellValue('A1', 'Indicador');
$hojaResumen->setCellValue('B1', 'Valor');
$hojaResumen->getStyle('A1:B1')->getFont()->setBold(true);

$muestras = count($duraciones);
$promedioSegundos = $muestras > 0 ? (int)round(array_sum($duraciones) / $muestras) : null;

$filasResumen = [
    ['Rango analizado', $rangoTexto],
    ['Total ingresos (postulaciones recibidas)', $ultimaFilaIngresos > 1 ? "=SUM('Ingresos por dia'!B2:B{$ultimaFilaIngresos})" : 0],
    ['Contratados', $conteo['Contratado']],
    ['Rechazados', $conteo['Rechazado']],
    ['Tasa de conversión (contratados / finalizados)',
        ($conteo['Contratado'] + $conteo['Rechazado']) > 0
            ? round($conteo['Contratado'] / ($conteo['Contratado'] + $conteo['Rechazado']) * 100, 1) . '%'
            : 'Sin datos'],
    ['Contrataciones promedio por semana',
        $ultimaFilaSemana >= 2 ? "='Contrataciones por semana'!B{$filaSemana}" : 'Sin datos'],
    ['Tiempo promedio de contratación (selección → contratado)',
        $promedioSegundos !== null ? formatearDuracionAproximadaExport($promedioSegundos) : 'Sin datos'],
    ['Tiempo mínimo', $muestras > 0 ? formatearDuracionAproximadaExport(min($duraciones)) : 'Sin datos'],
    ['Tiempo máximo', $muestras > 0 ? formatearDuracionAproximadaExport(max($duraciones)) : 'Sin datos'],
];
foreach ($filasResumen as $idx => [$etiqueta, $valor]) {
    $fila = $idx + 2;
    $hojaResumen->setCellValue("A{$fila}", $etiqueta);
    $hojaResumen->setCellValue("B{$fila}", $valor);
}
$hojaResumen->getColumnDimension('A')->setAutoSize(true);
$hojaResumen->getColumnDimension('B')->setAutoSize(true);

$spreadsheet->setActiveSheetIndex(0);

$nombreArchivo = 'indicadores_gestion_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit;
