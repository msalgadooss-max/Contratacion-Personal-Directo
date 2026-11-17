<?php
/**
 * v3.5 - Exporta a Excel el histórico que ve Jefe de Terreno
 * ("Postulantes" o "Personal Contratado"), con los mismos filtros de
 * fecha y "aprobado por" (Capataz, ver v10.14 en historico.php) que la
 * vista en pantalla.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$vendorAutoload = __DIR__ . '/../../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    responderError('Falta instalar dependencias (composer install) para generar el Excel.', 500);
}
require_once $vendorAutoload;

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('GET');

$vista = $_GET['vista'] ?? 'en_proceso';
if (!in_array($vista, ['en_proceso', 'contratados'], true)) {
    responderError('Vista inválida.', 422);
}

$desde = limpiarTexto($_GET['desde'] ?? '', 10);
$hasta = limpiarTexto($_GET['hasta'] ?? '', 10);
$aprobadoPor = (int)($_GET['aprobado_por'] ?? 0);

$pdo = obtenerConexion();

$estadosEnProceso = ['Pre_aprobado_terreno', 'Datos_completados', 'Aprobado_admin', 'Induccion_ok', 'EPP_listo'];
$condicionEstado = $vista === 'contratados'
    ? 'p.estado IN ("Contratado", "Proceso_completo")'
    : 'p.estado IN (' . implode(',', array_fill(0, count($estadosEnProceso), '?')) . ')';
$params = $vista === 'contratados' ? [] : $estadosEnProceso;

$sql = "SELECT p.tipo_documento, p.rut, p.nombre_completo, p.comuna, p.estado,
               c.nombre_cargo, ap.fecha_hora AS fecha_aprobacion, u.nombre AS aprobado_por_nombre
          FROM postulaciones p
          JOIN cargos c ON c.id = p.cargo_id
          JOIN (
                SELECT postulacion_id, MIN(fecha_hora) AS fecha_hora,
                       SUBSTRING_INDEX(GROUP_CONCAT(usuario_id ORDER BY fecha_hora SEPARATOR ','), ',', 1) AS usuario_id
                  FROM trazabilidad_logs
                 WHERE accion IN (
                        'Cambio de estado: Pendiente -> Pre_aprobado_terreno',
                        'Cambio de estado: En_banco -> Pre_aprobado_terreno'
                       )
                 GROUP BY postulacion_id
               ) ap ON ap.postulacion_id = p.id
          LEFT JOIN usuarios u ON u.id = ap.usuario_id
         WHERE $condicionEstado";

if ($desde !== '') { $sql .= ' AND ap.fecha_hora >= ?'; $params[] = $desde . ' 00:00:00'; }
if ($hasta !== '') { $sql .= ' AND ap.fecha_hora <= ?'; $params[] = $hasta . ' 23:59:59'; }
if ($aprobadoPor > 0) { $sql .= ' AND u.id = ?'; $params[] = $aprobadoPor; }
// v10.40 (pedido explicito del usuario): cada Profesional de Terreno ve solo a SU gente
// (las personas cuyo capataz es de su equipo).
$sql .= condicionGentePT($pdo, (int)$usuario['id']);
$sql .= ' ORDER BY ap.fecha_hora DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$filas = $stmt->fetchAll();

if (!$filas) {
    responderError('No hay datos para exportar en ese rango.', 404);
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $spreadsheet->getActiveSheet();
$hoja->setTitle($vista === 'contratados' ? 'Personal Contratado' : 'Personal en Proceso');

$encabezados = ['Tipo Doc.', 'RUT', 'Nombre', 'Cargo', 'Comuna', 'Estado actual', 'Aprobado por', 'Fecha aprobación'];
foreach ($encabezados as $i => $h) {
    $hoja->setCellValue([$i + 1, 1], $h);
}
$hoja->getStyle('1:1')->getFont()->setBold(true);

foreach ($filas as $idx => $f) {
    $fila = $idx + 2;
    $valores = [
        $f['tipo_documento'], $f['rut'], $f['nombre_completo'], $f['nombre_cargo'], $f['comuna'],
        $f['estado'], $f['aprobado_por_nombre'] ?? '', $f['fecha_aprobacion'] ?? '',
    ];
    foreach ($valores as $i => $v) {
        $hoja->setCellValue([$i + 1, $fila], $v);
    }
}
foreach (range(1, count($encabezados)) as $i) {
    $hoja->getColumnDimensionByColumn($i)->setAutoSize(true);
}

$nombreArchivo = ($vista === 'contratados' ? 'personal_contratado_' : 'personal_en_proceso_') . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit;
