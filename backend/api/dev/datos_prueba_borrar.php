<?php
/**
 * v10.33 - Paso 2 de "Borrar datos de prueba": borra las postulaciones indicadas
 * (y todo lo que cuelga de ellas: datos de nómina, documentos y sus archivos,
 * carpeta espejo, bitácora, cursos, evaluaciones) y devuelve a su cargo el cupo
 * que cada una tenía reservado.
 *
 * Salvaguardas (esto borra datos de verdad y no se puede deshacer):
 *  - Hay que escribir la palabra BORRAR.
 *  - Solo borra postulaciones cuyo RUT esté en la lista de RUT que se envía
 *    junto con los ids: no basta con conocer un id.
 *  - Máximo 100 RUT por vez. Solo actúa sobre la obra de la sesión actual.
 *  - Queda registro en el log del servidor de quién borró qué.
 * No toca: usuarios, cargos, solicitudes de cupo (los cupos pedidos para probar
 * se ajustan aparte), ni respuestas de la encuesta (son anónimas).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$dev = requireRol(['Desarrollador']);
exigirMetodo('POST');
exigirCsrfValido();

function listaRutNormalizada(string $texto): array
{
    $partes = preg_split('/[\s,;]+/', strtoupper($texto), -1, PREG_SPLIT_NO_EMPTY);
    $ruts = [];
    foreach ($partes as $p) {
        $p = str_replace('.', '', $p);
        if (preg_match('/^\d{1,9}-[\dK]$/', $p)) {
            $ruts[$p] = true;
        }
    }
    return array_keys($ruts);
}

/** Borra una carpeta y su contenido, solo si queda DENTRO de la carpeta base. */
function borrarCarpetaSegura(string $carpeta, string $base): bool
{
    $real = realpath($carpeta);
    $realBase = realpath($base);
    if ($real === false || $realBase === false || strpos($real, $realBase . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    return @rmdir($real);
}

$body = leerJsonBody();
if (trim((string)($body['confirmacion'] ?? '')) !== 'BORRAR') {
    responderError('Para borrar hay que escribir la palabra BORRAR.', 422);
}
$ruts = listaRutNormalizada((string)($body['ruts'] ?? ''));
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['ids'] ?? [])), fn($i) => $i > 0)));
if (!$ruts || !$ids) {
    responderError('Falta la lista de RUT o de postulaciones a borrar.', 422);
}
if (count($ruts) > 100 || count($ids) > 200) {
    responderError('Son demasiados registros a la vez.', 422);
}

$pdo = obtenerConexion();
$marcasIds = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT p.id, p.rut, p.nombre, p.apellido, p.segundo_apellido, p.codigo_seguimiento, p.cv_ruta_archivo,
            p.cargo_id, (c.nombre_cargo <> 'Por asignar') AS ocupa_cupo
       FROM postulaciones p JOIN cargos c ON c.id = p.cargo_id
      WHERE p.id IN ($marcasIds)"
);
$stmt->execute($ids);
$filas = $stmt->fetchAll();

$permitidos = array_flip($ruts);
foreach ($filas as $f) {
    if (!isset($permitidos[strtoupper(str_replace('.', '', $f['rut']))])) {
        responderError('Una de las postulaciones no coincide con los RUT indicados. No se borró nada.', 422);
    }
}
if (count($filas) !== count($ids)) {
    responderError('Alguna postulación ya no existe. Vuelve a buscar. No se borró nada.', 409);
}

// Archivos y carpetas a limpiar DESPUÉS de borrar en la base (si la base falla, no se pierde nada).
$archivos = [];
$carpetas = [];
$base = carpetaBasePostulantes();
foreach ($filas as $f) {
    if (!empty($f['cv_ruta_archivo'])) {
        $archivos[] = $f['cv_ruta_archivo'];
    }
    $stmtD = $pdo->prepare('SELECT ruta_archivo FROM postulacion_documentos WHERE postulacion_id = :id');
    $stmtD->execute(['id' => $f['id']]);
    foreach ($stmtD->fetchAll() as $d) {
        $archivos[] = $d['ruta_archivo'];
    }
    $stmtF = $pdo->prepare('SELECT codigo_ficha FROM datos_jao WHERE postulacion_id = :id');
    $stmtF->execute(['id' => $f['id']]);
    $ficha = $stmtF->fetchColumn();
    foreach (array_filter([$f['codigo_seguimiento'], $ficha]) as $prefijo) {
        $carpetas[$base . '/' . nombreCarpetaPostulante($f, $prefijo)] = true;
    }
}

$cuposDevueltos = 0;
$pdo->beginTransaction();
try {
    $in = "($marcasIds)";
    foreach (['datos_contratacion', 'datos_jao', 'documentos_pendientes', 'postulacion_documentos', 'postulacion_cursos', 'evaluaciones_trabajador', 'trazabilidad_logs'] as $tabla) {
        $pdo->prepare("DELETE FROM $tabla WHERE postulacion_id IN $in")->execute($ids);
    }
    foreach ($filas as $f) {
        if ((int)$f['ocupa_cupo'] === 1) {
            $r = $pdo->prepare('UPDATE cargos SET cupos_activos = LEAST(cupos_totales, cupos_activos + 1) WHERE id = :id');
            $r->execute(['id' => $f['cargo_id']]);
            $cuposDevueltos++;
        }
    }
    $pdo->prepare("DELETE FROM postulaciones WHERE id IN $in")->execute($ids);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('datos_prueba_borrar error: ' . $e->getMessage());
    responderError('No fue posible borrar. No se cambió nada.', 500);
}

$archivosBorrados = 0;
foreach (array_unique($archivos) as $ruta) {
    $completa = realpath(__DIR__ . '/../../uploads/' . $ruta);
    $carpetaUploads = realpath(__DIR__ . '/../../uploads');
    if ($completa && $carpetaUploads && strpos($completa, $carpetaUploads . DIRECTORY_SEPARATOR) === 0 && @unlink($completa)) {
        $archivosBorrados++;
    }
}
$carpetasBorradas = 0;
foreach (array_keys($carpetas) as $c) {
    if (is_dir($c) && borrarCarpetaSegura($c, $base)) {
        $carpetasBorradas++;
    }
}

error_log(sprintf(
    'datos_prueba_borrar: dev #%d borró %d postulaciones en %s (RUT: %s); cupos devueltos %d',
    $dev['id'], count($ids), OBRA_CODIGO, implode(',', array_column($filas, 'rut')), $cuposDevueltos
));

responderOk([
    'mensaje' => sprintf(
        'Listo (%s): %d postulaciones borradas, %d cupos devueltos, %d archivos y %d carpetas eliminados.',
        OBRA_NOMBRE, count($ids), $cuposDevueltos, $archivosBorrados, $carpetasBorradas
    ),
]);
