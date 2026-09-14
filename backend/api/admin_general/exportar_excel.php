<?php
/**
 * v3 - "Exportar Carga Masiva": genera un .xlsx con las 78 columnas
 * EXACTAS de "Template Empleados.xls" en el mismo orden, para subir
 * directo a Buk sin tener que reordenar ni renombrar nada a mano.
 *
 * Las columnas verdes/celestes/amarillas se llenan con los datos de
 * postulaciones/datos_contratacion/datos_jao; las grises y sin color
 * quedan en blanco a peticion explicita del usuario (se completan
 * directo en Buk).
 *
 * v4: el JAO ahora ELIGE con checkboxes a quiénes exportar (ver
 * admin_general/contratados_listar.php + parámetro "ids" abajo). Si no
 * se envía "ids", se mantiene el comportamiento anterior por
 * compatibilidad: exportar todo lo 'Contratado' aún no exportado
 * (exportado_at IS NULL). Marca exportado_at al terminar en ambos casos.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/columnas_buk.php';

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

if ($idsSolicitados) {
    $in = implode(',', array_fill(0, count($idsSolicitados), '?'));
    $stmt = $pdo->prepare(
        "SELECT p.*, d.*, j.*, p.id AS postulacion_id
           FROM postulaciones p
           JOIN datos_contratacion d ON d.postulacion_id = p.id
           LEFT JOIN datos_jao j ON j.postulacion_id = p.id
          WHERE p.estado IN (\"Contratado\", \"Proceso_completo\") AND p.id IN ($in)
          ORDER BY p.actualizado_at ASC"
    );
    $stmt->execute(array_values($idsSolicitados));
} else {
    $stmt = $pdo->query(
        'SELECT p.*, d.*, j.*, p.id AS postulacion_id
           FROM postulaciones p
           JOIN datos_contratacion d ON d.postulacion_id = p.id
           LEFT JOIN datos_jao j ON j.postulacion_id = p.id
          WHERE p.estado IN ("Contratado", "Proceso_completo") AND p.exportado_at IS NULL
          ORDER BY p.actualizado_at ASC'
    );
}
$filas = $stmt->fetchAll();

if (!$filas) {
    responderError('No hay contrataciones para exportar con esos criterios.', 404);
}

$columnas = columnasBuk();

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $spreadsheet->getActiveSheet();
$hoja->setTitle('Empleados');

foreach ($columnas as $i => [$encabezado, $tabla, $col, $tipo]) {
    $hoja->setCellValue([$i + 1, 1], $encabezado);
}
$hoja->getStyle('1:1')->getFont()->setBold(true);

$idsExportados = [];
foreach ($filas as $filaIdx => $fila) {
    $numeroFila = $filaIdx + 2; // fila 1 = encabezados
    foreach ($columnas as $i => [$encabezado, $tabla, $col, $tipo]) {
        $valor = '';
        if ($tabla !== null) {
            $valor = (string)($fila[$col] ?? '');
            if ($tipo === 'fecha' && $valor !== '') {
                $ts = strtotime($valor);
                $valor = $ts ? date('d-m-Y', $ts) : $valor;
            }
        }
        // v10.14 (pedido explícito del usuario): confirmado contra un
        // envío real de Luis López a Buk (Ariel Torres / Elin Sánchez) --
        // estas columnas quedaban en blanco pero Buk sí las necesita.
        // Plan Isapre UF/Pesos ya no se fuerza a "0" -- el JAO las llena
        // a mano solo cuando corresponde (Isapre real, no Fonasa) y si
        // no, quedan en blanco (columnasBuk() ya las mapea a datos_jao).
        if ($encabezado === 'Teléfono Oficina') {
            $valor = (string)($fila['telefono'] ?? '');
        } elseif ($encabezado === 'Email') {
            $valor = (string)($fila['correo'] ?? '');
        } elseif ($encabezado === 'Bono Target') {
            $valor = 'BT_00'; // mismo valor fijo en ambos casos reales revisados.
        }
        $hoja->setCellValue([$i + 1, $numeroFila], $valor);
    }
    $idsExportados[] = (int)$fila['postulacion_id'];
}

foreach (range(1, count($columnas)) as $i) {
    $hoja->getColumnDimensionByColumn($i)->setAutoSize(true);
}

$nombreArchivo = 'carga_masiva_buk_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');

// Marcar como exportadas y dejar trazabilidad, DESPUES de generar el
// archivo, para no perder datos si algo falla a mitad de la escritura.
$in = implode(',', array_fill(0, count($idsExportados), '?'));
$stmtMarcar = $pdo->prepare("UPDATE postulaciones SET exportado_at = NOW() WHERE id IN ($in)");
$stmtMarcar->execute($idsExportados);

foreach ($idsExportados as $id) {
    registrarLog($pdo, $id, $usuario['id'], 'Incluido en exportación de carga masiva a Buk (Excel).');
}

exit;
