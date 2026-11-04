<?php
/**
 * v3 - Visor de documentos para Admin_Contrato y Jefe_Administrativo:
 * el CV (Etapa 1) y los 5 documentos legales (Etapa 2). La carpeta
 * backend/uploads/ esta bloqueada por .htaccess; este es el unico
 * camino para llegar a esos archivos, y exige sesion de un rol
 * autorizado antes de leer nada del disco.
 *
 * tipo=cv                      -> postulaciones.cv_ruta_archivo
 * tipo=<uno de los 5 oficiales> -> postulacion_documentos
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Admin_Contrato', 'Jefe_Administrativo']);
exigirMetodo('GET');

$postulacionId = (int)($_GET['postulacion_id'] ?? 0);
$tipo = (string)($_GET['tipo'] ?? '');
$tiposValidos = ['cv', 'cedula_identidad', 'cedula_identidad_reverso', 'certificado_afp', 'cotizaciones_afp', 'certificado_salud', 'ultimo_finiquito', 'certificado_residencia'];

if ($postulacionId <= 0 || !in_array($tipo, $tiposValidos, true)) {
    responderError('Parámetros inválidos.', 422);
}

$pdo = obtenerConexion();

if ($tipo === 'cv') {
    $stmt = $pdo->prepare('SELECT cv_ruta_archivo AS ruta FROM postulaciones WHERE id = :id');
} else {
    $stmt = $pdo->prepare('SELECT ruta_archivo AS ruta FROM postulacion_documentos WHERE postulacion_id = :id AND tipo = :tipo');
}
$params = ['id' => $postulacionId];
if ($tipo !== 'cv') {
    $params['tipo'] = $tipo;
}
$stmt->execute($params);
$ruta = $stmt->fetchColumn();

if (!$ruta) {
    responderError('Documento no encontrado.', 404);
}

$rutaCompleta = realpath(__DIR__ . '/../../uploads/' . $ruta);
$carpetaUploads = realpath(__DIR__ . '/../../uploads');

if (!$rutaCompleta || !$carpetaUploads || !str_starts_with($rutaCompleta, $carpetaUploads) || !is_file($rutaCompleta)) {
    responderError('Documento no encontrado.', 404);
}

$extension = strtolower(pathinfo($rutaCompleta, PATHINFO_EXTENSION));
$tiposMime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
$mime = $tiposMime[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $tipo . '-postulacion-' . $postulacionId . '.' . $extension . '"');
header('Content-Length: ' . filesize($rutaCompleta));
readfile($rutaCompleta);
exit;
