<?php
/**
 * v10.14 (pedido explícito del usuario) - Segundo exportador Buk:
 * "Template Trabajos.xls" (hoja "trabajos"), complementario al Template
 * Empleado que ya arma exportar_excel.php. Se enlazan por RUT ("Número
 * de Documento*") -- por eso reutiliza el mismo listado/selección de
 * contratados (admin_general/contratados_listar.php) y el mismo
 * parámetro "ids" que el primer exportador.
 *
 * Columnas obligatorias que Buk exige pero esta app todavía no capta en
 * ningún formulario (Sueldo Base, Horario Semanal, Tipo de Contrato,
 * RUT del Supervisor, Plan de Beneficios) quedan en blanco a propósito
 * -- pedido explícito del usuario: "dejarlos en blanco, y una vez tenga
 * el dato, vemos cómo lo incorporamos en la app". Se completan a mano
 * en Buk, igual que ya se hace con las columnas grises del Template
 * Empleado (ver exportar_excel.php).
 *
 * Los códigos de Comuna, Sub-área y Empresa son fijos para esta obra
 * (ver OBRA_COMUNA_BUK/OBRA_SUBAREA_BUK/OBRA_EMPRESA_BUK en
 * config.php) -- confirmados por el usuario contra las hojas de
 * referencia reales del template ("Comunas", "Sub-áreas", "Empresas").
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$vendorAutoload = __DIR__ . '/../../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    responderError('Falta instalar dependencias (composer install) para generar el Excel.', 500);
}
require_once $vendorAutoload;

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Administrativo']);
exigirMetodo('GET');

$pdo = obtenerConexion();

$idsSolicitados = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), fn ($v) => $v > 0);

$sqlBase = "SELECT p.rut, c.codigo_buk AS cargo_codigo, c.nombre_cargo,
                   j.codigo_ficha, j.ingreso_compania
              FROM postulaciones p
              JOIN cargos c ON c.id = p.cargo_id
              LEFT JOIN datos_jao j ON j.postulacion_id = p.id
             WHERE p.estado IN (\"Contratado\", \"Proceso_completo\")";

if ($idsSolicitados) {
    $in = implode(',', array_fill(0, count($idsSolicitados), '?'));
    $stmt = $pdo->prepare("$sqlBase AND p.id IN ($in) ORDER BY p.actualizado_at ASC");
    $stmt->execute(array_values($idsSolicitados));
} else {
    $stmt = $pdo->query("$sqlBase ORDER BY p.actualizado_at ASC");
}
$filas = $stmt->fetchAll();

if (!$filas) {
    responderError('No hay contrataciones para exportar con esos criterios.', 404);
}

// v10.14: si a alguien seleccionado le falta el código Buk de su cargo
// (cargo nuevo agregado por Jefe de Terreno vía "➕ Otro", todavía sin
// mapear), se avisa en vez de exportar una fila con esa columna vacía
// sin que nadie se dé cuenta.
$sinCodigo = array_values(array_unique(array_map(
    fn ($f) => $f['nombre_cargo'],
    array_filter($filas, fn ($f) => $f['cargo_codigo'] === null || $f['cargo_codigo'] === '')
)));

$columnas = [
    'Número de Documento*', 'Código de Ficha', 'Sueldo Base*', 'Moneda*', 'Fecha de Inicio*',
    'Horario Semanal*', 'Código Cargo*', 'Código Sub-área*', 'Número de Documento Supervisor*',
    'Código de Ficha Supervisor', 'Tipo de Contrato*', 'Obra', 'Comuna/Localidad',
    'Término de Contrato', 'Empresa*', 'Recibe Gratificaciones*', 'Jornada Laboral',
    'Días de la Jornada', 'Tipo de Jornada', 'Con Liquidaciones', 'Recinto',
    'Recintos Secundarios', 'Registra asistencia', 'Recinto para marcar asistencia', 'Aguinaldos',
    'Valor Diario Amipass', 'Días Amipass', 'Control de Vacaciones', 'Jornada', 'Lugar de Trabajo',
    'Plan Construye Tranquilo', 'Plan de Beneficios*', 'Seguro Complementario Tritec',
    'Tramo Prima Seguro Colectivo', 'Tramo Seguro Comple. Carga Especial',
    'Tramo Seguro Complementario',
];

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $spreadsheet->getActiveSheet();
$hoja->setTitle('trabajos');

foreach ($columnas as $i => $encabezado) {
    $hoja->setCellValue([$i + 1, 1], $encabezado);
}
$hoja->getStyle('1:1')->getFont()->setBold(true);

foreach ($filas as $filaIdx => $f) {
    $numeroFila = $filaIdx + 2;
    $fechaInicio = '';
    if (!empty($f['ingreso_compania'])) {
        $ts = strtotime($f['ingreso_compania']);
        $fechaInicio = $ts ? date('d-m-Y', $ts) : '';
    }
    // Mismo orden que $columnas -- '' es una columna que se deja en
    // blanco a propósito (ver docblock de arriba).
    $valores = [
        $f['rut'],                  // Número de Documento*
        $f['codigo_ficha'] ?? '',   // Código de Ficha
        '',                         // Sueldo Base*
        'CLP',                      // Moneda*
        $fechaInicio,               // Fecha de Inicio*
        '',                         // Horario Semanal*
        $f['cargo_codigo'] ?? '',   // Código Cargo*
        OBRA_SUBAREA_BUK,           // Código Sub-área*
        '',                         // Número de Documento Supervisor*
        '',                         // Código de Ficha Supervisor
        '',                         // Tipo de Contrato*
        '',                         // Obra
        OBRA_COMUNA_BUK,            // Comuna/Localidad
        '',                         // Término de Contrato
        OBRA_EMPRESA_BUK,           // Empresa*
        '',                         // Recibe Gratificaciones*
    ];
    foreach ($valores as $i => $v) {
        $hoja->setCellValue([$i + 1, $numeroFila], $v);
    }
    // Columnas 17 a 36: quedan en blanco (ninguna es obligatoria).
}

foreach (range(1, count($columnas)) as $i) {
    $hoja->getColumnDimensionByColumn($i)->setAutoSize(true);
}

$nombreArchivo = 'carga_masiva_buk_trabajos_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
if ($sinCodigo) {
    // Header custom (no estándar) para que el frontend pueda avisar sin
    // tener que parsear el archivo -- no afecta la descarga.
    header('X-Cargos-Sin-Codigo-Buk: ' . implode(', ', $sinCodigo));
}

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit;
