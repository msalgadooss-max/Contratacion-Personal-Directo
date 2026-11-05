<?php
/**
 * v10.29 (pedido explícito del usuario) - El Jefe de Bodega descarga en
 * Excel las tallas de calzado y overol del personal que llega, para armar
 * los kits de EPP sin depender del correo.
 *
 * Por defecto trae a quienes todavía NO están incluidos en un correo de
 * tallas a Bodega (la misma lista que ve en su panel). Con ?todos=1 trae a
 * todos los que ya completaron la Etapa 2 y siguen en proceso (antes de
 * ser contratados). Descargar NO marca nada como enviado: eso solo lo hace
 * el botón "Enviar tallas por correo".
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$vendorAutoload = __DIR__ . '/../../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    responderError('Falta instalar dependencias (composer install) para generar el Excel.', 500);
}
require_once $vendorAutoload;

iniciarSesionSegura();
requireRol(['Jefe_Bodega']);
exigirModuloActivo(MODULO_BODEGA_ACTIVO, 'Bodega');
exigirMetodo('GET');

$pdo = obtenerConexion();

if (($_GET['todos'] ?? '') === '1') {
    $stmt = $pdo->query(
        "SELECT p.id, p.nombre_completo, p.rut, c.nombre_cargo, d.talla_calzado, d.talla_overol,
                (SELECT MAX(t.fecha_hora) FROM trazabilidad_logs t
                  WHERE t.postulacion_id = p.id
                    AND t.accion LIKE 'Cambio de estado: % -> Aprobado_admin') AS completado_at
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
           JOIN datos_contratacion d ON d.postulacion_id = p.id
          WHERE p.estado IN ('Aprobado_admin', 'Induccion_ok', 'EPP_listo')
          ORDER BY completado_at ASC, p.id ASC"
    );
    $personas = $stmt->fetchAll();
    $titulo = 'Tallas del personal en proceso';
} else {
    $personas = tallasSinEnviar($pdo);
    $titulo = 'Tallas pendientes de envío';
}

if (!$personas) {
    responderError('No hay personal con tallas para descargar por ahora.', 404);
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $spreadsheet->getActiveSheet();
$hoja->setTitle('Tallas');

$hoja->setCellValue('A1', $titulo . ' - ' . OBRA_NOMBRE . ' - ' . date('d-m-Y H:i'));
$hoja->mergeCells('A1:G1');
$hoja->getStyle('A1')->getFont()->setBold(true)->setSize(12);

$encabezados = ['N°', 'Nombre', 'RUT', 'Cargo', 'Talla calzado', 'Talla overol', 'Completó Etapa 2'];
foreach ($encabezados as $i => $titulo) {
    $hoja->setCellValue([$i + 1, 3], $titulo);
}
$hoja->getStyle('A3:G3')->getFont()->setBold(true);
$hoja->getStyle('A3:G3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
    ->getStartColor()->setRGB('E5E7EB');

$fila = 4;
foreach (array_values($personas) as $i => $p) {
    $valores = [
        $i + 1,
        $p['nombre_completo'],
        $p['rut'],
        $p['nombre_cargo'],
        $p['talla_calzado'],
        $p['talla_overol'],
        !empty($p['completado_at']) ? (new DateTime($p['completado_at']))->format('d-m-Y H:i') : '',
    ];
    foreach ($valores as $c => $v) {
        $hoja->setCellValueExplicit(
            [$c + 1, $fila],
            (string)$v,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
    }
    $fila++;
}

$ultima = $fila - 1;
$hoja->setCellValue('A' . ($fila + 1), 'Total de personas');
$hoja->setCellValue('B' . ($fila + 1), '=COUNTA(B4:B' . $ultima . ')');
$hoja->getStyle('A' . ($fila + 1) . ':B' . ($fila + 1))->getFont()->setBold(true);

// Resumen por talla: cuántos kits de cada medida hay que preparar.
$resumen = $spreadsheet->createSheet();
$resumen->setTitle('Resumen por talla');
$resumen->setCellValue('A1', 'Calzado');
$resumen->setCellValue('B1', 'Cantidad');
$resumen->setCellValue('D1', 'Overol');
$resumen->setCellValue('E1', 'Cantidad');
$resumen->getStyle('A1:E1')->getFont()->setBold(true);
$porCalzado = [];
$porOverol = [];
foreach ($personas as $p) {
    $porCalzado[$p['talla_calzado']] = ($porCalzado[$p['talla_calzado']] ?? 0) + 1;
    $porOverol[$p['talla_overol']] = ($porOverol[$p['talla_overol']] ?? 0) + 1;
}
ksort($porCalzado);
ksort($porOverol);
$r = 2;
foreach ($porCalzado as $talla => $n) {
    $resumen->setCellValueExplicit("A{$r}", (string)$talla, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $resumen->setCellValue("B{$r}", "=COUNTIF(Tallas!E4:E{$ultima},A{$r})");
    $r++;
}
$r = 2;
foreach ($porOverol as $talla => $n) {
    $resumen->setCellValueExplicit("D{$r}", (string)$talla, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $resumen->setCellValue("E{$r}", "=COUNTIF(Tallas!F4:F{$ultima},D{$r})");
    $r++;
}

foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
    $resumen->getColumnDimension($col)->setAutoSize(true);
}
$spreadsheet->setActiveSheetIndex(0);

$nombreArchivo = 'tallas_bodega_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');

$escritor = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$escritor->save('php://output');
exit;
