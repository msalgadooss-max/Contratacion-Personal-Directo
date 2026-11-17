<?php
/**
 * v10.39 - Excel de las personas liberadas (check de Prevención) del día, o del rango
 * ?desde= / ?hasta= (hoy por defecto). Acepta los mismos filtros que el panel
 * (?cargo= ?capataz= ?estado= ?q=) y además deja filtros de columna activos en la fila
 * de encabezados, para filtrar y ordenar libremente dentro de Excel.
 * Hoja 1 "Liberados": una fila por persona (nombre, RUT, cargo, capataz, frente, PT,
 * fechas, tallas, estado). Hoja 2 "Resumen": cuántos kits de cada talla y por capataz.
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

[$desde, $hasta] = rangoFechasDeUrl();
$filas = filtrarLiberadosDeUrl(liberadosPorDia(obtenerConexion(), $desde, $hasta));
if (!$filas) {
    responderError('No hay personas liberadas en ese período con esos filtros.', 404);
}

$fmt = static fn ($f) => $f ? (new DateTime($f))->format('d-m-Y') : '';
$texto = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
$libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $libro->getActiveSheet();
$hoja->setTitle('Liberados');
$titulo = 'Personal liberado - ' . OBRA_NOMBRE . ' - ' . ($desde === $hasta ? $fmt($desde) : $fmt($desde) . ' al ' . $fmt($hasta));
$hoja->setCellValue('A1', $titulo);
$hoja->mergeCells('A1:L1');
$hoja->getStyle('A1')->getFont()->setBold(true)->setSize(12);

$enc = ['N°', 'Nombre', 'RUT', 'Cargo', 'Capataz', 'Frente', 'Profesional de Terreno', 'Día liberación', 'Fecha de ingreso', 'N° calzado', 'Talla overol', 'Estado'];
foreach ($enc as $i => $h) {
    $hoja->setCellValue([$i + 1, 3], $h);
}
$hoja->getStyle('A3:L3')->getFont()->setBold(true);
$hoja->getStyle('A3:L3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

$r = 4;
foreach ($filas as $i => $f) {
    $vals = [$i + 1, $f['nombre_completo'], $f['rut'], $f['nombre_cargo'], $f['capataz'] ?? '', $f['frente'] ?? '',
             $f['profesional_terreno'] ?? '', $fmt($f['dia_liberacion']), $fmt($f['fecha_ingreso']),
             $f['talla_calzado'] ?? '', $f['talla_overol'] ?? '', $f['estado_texto']];
    foreach ($vals as $c => $v) {
        $hoja->setCellValueExplicit([$c + 1, $r], (string)$v, $texto);
    }
    $r++;
}
$ultima = $r - 1;
$hoja->setAutoFilter("A3:L{$ultima}");
$hoja->freezePane('A4');
$hoja->setCellValue('A' . ($r + 1), 'Total de personas');
$hoja->setCellValue('B' . ($r + 1), '=SUBTOTAL(103,B4:B' . $ultima . ')');
$hoja->getStyle('A' . ($r + 1) . ':B' . ($r + 1))->getFont()->setBold(true);
foreach (range('A', 'L') as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}

// Resumen: kits por talla y personas por capataz.
$res = $libro->createSheet();
$res->setTitle('Resumen');
$cuenta = static function (array $filas, string $k): array {
    $o = [];
    foreach ($filas as $f) {
        $v = trim((string)($f[$k] ?? ''));
        $v = $v === '' ? '(sin dato)' : $v;
        $o[$v] = ($o[$v] ?? 0) + 1;
    }
    ksort($o);
    return $o;
};
$bloques = [['Calzado', 'talla_calzado', 'A'], ['Overol', 'talla_overol', 'D'], ['Capataz', 'capataz', 'G']];
foreach ($bloques as [$nombre, $k, $col]) {
    $c2 = chr(ord($col) + 1);
    $res->setCellValue($col . '1', $nombre);
    $res->setCellValue($c2 . '1', 'Cantidad');
    $res->getStyle("{$col}1:{$c2}1")->getFont()->setBold(true);
    $n = 2;
    foreach ($cuenta($filas, $k) as $v => $cant) {
        $res->setCellValueExplicit($col . $n, (string)$v, $texto);
        $res->setCellValue($c2 . $n, $cant);
        $n++;
    }
}
foreach (range('A', 'H') as $col) {
    $res->getColumnDimension($col)->setAutoSize(true);
}
$libro->setActiveSheetIndex(0);

$archivo = 'liberados_' . ($desde === $hasta ? $desde : $desde . '_a_' . $hasta) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
exit;
