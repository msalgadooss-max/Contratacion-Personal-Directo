<?php
/**
 * v10.14 (pedido explícito del usuario) - Segundo exportador Buk:
 * "Template Trabajos.xls" (hoja "trabajos"), complementario al Template
 * Empleado que ya arma exportar_excel.php. Se enlazan por RUT ("Número
 * de Documento*") -- por eso reutiliza el mismo listado/selección de
 * contratados (admin_general/contratados_listar.php) y el mismo
 * parámetro "ids" que el primer exportador.
 *
 * v2: revisado contra un envío REAL de Luis López a Buk (Ariel Torres
 * Roa / Elin Sánchez Molina, 10-09) -- reveló una columna obligatoria
 * que no existía en la plantilla en blanco que se había analizado antes
 * ("ctrlit_recinto*") y varios valores que resultaron ser constantes
 * fijas para esta obra, no datos por persona.
 *
 * Confirmado explícitamente por el usuario después de ver ese envío:
 * Horario Semanal (42) y el Supervisor (RUT + código de ficha) son
 * fijos para todo el personal MOD de esta obra -- ver
 * OBRA_HORARIO_SEMANAL_MOD_BUK / OBRA_SUPERVISOR_RUT_BUK /
 * OBRA_SUPERVISOR_FICHA_BUK en config.php. El Sueldo Base sigue en
 * blanco a propósito ("el sueldo dejémoslo en blanco mientras tanto"),
 * igual que Término de Contrato (varía por persona, sin dato real
 * capturado todavía en la app).
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

// v2: 37 columnas -- la plantilla en blanco que se revisó primero tenía
// 36, pero un envío real trae "ctrlit_recinto*" entre "Control de
// Vacaciones" y "Jornada", así que la plantilla real vigente tiene una
// columna más de la que se había mapeado.
$columnas = [
    'Número de Documento*', 'Código de Ficha', 'Sueldo Base*', 'Moneda*', 'Fecha de Inicio*',
    'Horario Semanal*', 'Código Cargo*', 'Código Sub-área*', 'Número de Documento Supervisor*',
    'Código de Ficha Supervisor', 'Tipo de Contrato*', 'Obra', 'Comuna/Localidad',
    'Término de Contrato', 'Empresa*', 'Recibe Gratificaciones*', 'Jornada Laboral',
    'Días de la Jornada', 'Tipo de Jornada', 'Con Liquidaciones', 'Recinto',
    'Recintos Secundarios', 'Registra asistencia', 'Recinto para marcar asistencia', 'Aguinaldos',
    'Valor Diario Amipass', 'Días Amipass', 'Control de Vacaciones', 'ctrlit_recinto*', 'Jornada',
    'Lugar de Trabajo', 'Plan Construye Tranquilo', 'Plan de Beneficios*',
    'Seguro Complementario Tritec', 'Tramo Prima Seguro Colectivo',
    'Tramo Seguro Comple. Carga Especial', 'Tramo Seguro Complementario',
];

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $spreadsheet->getActiveSheet();
$hoja->setTitle('trabajos');

foreach ($columnas as $i => $encabezado) {
    $hoja->setCellValue([$i + 1, 1], $encabezado);
}
$hoja->getStyle('1:1')->getFont()->setBold(true);

// v10.14: horario de jornada estándar para MOD en esta obra, tal cual
// lo escribió Luis López en su envío real -- mismo texto para ambos
// casos revisados.
const JORNADA_TEXTO_MOD = 'L42. Lun - Mar y Vie 08:00 a 17:00/Mie a Jue 08:00 a 18:00/ CO 13:00 a 14:00';

foreach ($filas as $filaIdx => $f) {
    $numeroFila = $filaIdx + 2;
    $fechaInicio = '';
    if (!empty($f['ingreso_compania'])) {
        $ts = strtotime($f['ingreso_compania']);
        $fechaInicio = $ts ? date('d-m-Y', $ts) : '';
    }
    // Mismo orden que $columnas. '' es una columna que se deja en blanco
    // a propósito -- o porque el envío real de Luis también la dejaba
    // en blanco (ej. "Comuna/Localidad"), o porque todavía no hay un
    // dato real capturado en la app para eso (ej. Sueldo Base).
    $valores = [
        $f['rut'],                  // Número de Documento*
        $f['codigo_ficha'] ?? '',   // Código de Ficha
        '',                         // Sueldo Base* -- pedido explícito del usuario: sigue en blanco
        'CLP',                      // Moneda*
        $fechaInicio,               // Fecha de Inicio*
        OBRA_HORARIO_SEMANAL_MOD_BUK, // Horario Semanal* -- confirmado por el usuario como default MOD
        $f['cargo_codigo'] ?? '',   // Código Cargo*
        OBRA_SUBAREA_BUK,           // Código Sub-área*
        OBRA_SUPERVISOR_RUT_BUK,    // Número de Documento Supervisor* -- confirmado por el usuario
        OBRA_SUPERVISOR_FICHA_BUK,  // Código de Ficha Supervisor -- confirmado por el usuario
        'Plazo fijo',               // Tipo de Contrato* -- estándar para MOD, confirmado en envío real
        OBRA_CODIGO_CORTO_BUK,      // Obra
        '',                         // Comuna/Localidad -- Luis también la deja en blanco
        '',                         // Término de Contrato -- varía por persona, sin dato real capturado aún
        OBRA_EMPRESA_BUK,           // Empresa*
        '1',                        // Recibe Gratificaciones* -- confirmado en envío real
        'mensual',                  // Jornada Laboral
        '["l","m","w","j","v"]',    // Días de la Jornada
        'Ordinaria ART 22',         // Tipo de Jornada
        '',                         // Con Liquidaciones
        '',                         // Recinto
        '',                         // Recintos Secundarios
        'Sí',                       // Registra asistencia
        OBRA_CODIGO_CORTO_BUK,      // Recinto para marcar asistencia
        '',                         // Aguinaldos
        '',                         // Valor Diario Amipass
        '',                         // Días Amipass
        '',                         // Control de Vacaciones
        OBRA_SUBAREA_NOMBRE_BUK,    // ctrlit_recinto*
        JORNADA_TEXTO_MOD,          // Jornada
        '',                         // Lugar de Trabajo
        '',                         // Plan Construye Tranquilo
        'Beneficios Generales Personal Obras', // Plan de Beneficios*
        '',                         // Seguro Complementario Tritec
        '',                         // Tramo Prima Seguro Colectivo
        '',                         // Tramo Seguro Comple. Carga Especial
        '',                         // Tramo Seguro Complementario
    ];
    foreach ($valores as $i => $v) {
        $hoja->setCellValue([$i + 1, $numeroFila], $v);
    }
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
