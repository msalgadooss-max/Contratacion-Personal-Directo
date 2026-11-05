<?php
/**
 * v10.29 (pedido explícito del usuario) - El postulante entrega por su
 * cuenta los documentos que dejó "pendientes" (casilla "No lo tengo ahora"
 * de la Etapa 2, o marcados como pendientes por el JAO) desde el módulo de
 * seguimiento -- pensado sobre todo para quienes postulan a distancia.
 *
 * Se identifica con RUT + correo (el mismo doble factor de seguimiento.php,
 * con el mismo límite de 10 intentos fallidos / 15 min). Solo se aceptan
 * archivos de documentos que figuran como pendientes de ESA postulación:
 * no sirve para reemplazar documentos ya entregados ni para subir otros.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rut.php';

exigirMetodo('POST');

$documentoCrudo = trim((string)($_POST['rut'] ?? ''));
$correo = filter_var(trim((string)($_POST['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
if ($documentoCrudo === '' || !$correo) {
    responderError('Tu documento y tu correo son obligatorios.', 422);
}
$documentoRut = normalizarRut($documentoCrudo);

$pdo = obtenerConexion();
if (intentosBloqueado($pdo, 'documento', $documentoRut)) {
    responderBloqueoIntentos();
}

$stmt = $pdo->prepare(
    'SELECT id, estado FROM postulaciones
      WHERE (rut = :doc_crudo OR rut = :doc_rut) AND LOWER(correo) = LOWER(:correo)
      ORDER BY creado_at DESC, id DESC
      LIMIT 1'
);
$stmt->execute(['doc_crudo' => $documentoCrudo, 'doc_rut' => $documentoRut, 'correo' => $correo]);
$postulacion = $stmt->fetch();
if (!$postulacion) {
    registrarIntentoFallido($pdo, 'documento', $documentoRut);
    responderError('No se encontró una postulación con esos datos.', 404);
}
if ($postulacion['estado'] === 'Rechazado') {
    responderError('Tu postulación ya no continúa en el proceso.', 409);
}
$postulacionId = (int)$postulacion['id'];

$stmtPend = $pdo->prepare(
    "SELECT tipo FROM documentos_pendientes
      WHERE postulacion_id = :id AND condicion = 'pendiente' AND resuelto_at IS NULL"
);
$stmtPend->execute(['id' => $postulacionId]);
$tiposPendientes = $stmtPend->fetchAll(PDO::FETCH_COLUMN);
if (!$tiposPendientes) {
    responderError('No tienes documentos pendientes de entrega.', 409);
}

$etiquetas = etiquetasDocumentos();
$entregados = [];
$pdo->beginTransaction();
try {
    foreach ($tiposPendientes as $tipo) {
        if (($_FILES[$tipo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // puede entregar de a uno
        }
        $etiqueta = $etiquetas[$tipo] ?? $tipo;
        $ruta = guardarArchivoSubido($_FILES[$tipo], 'documentos', 'tu ' . $etiqueta);

        $stmtDoc = $pdo->prepare(
            'INSERT INTO postulacion_documentos (postulacion_id, tipo, ruta_archivo)
             VALUES (:pid, :tipo, :ruta)
             ON DUPLICATE KEY UPDATE ruta_archivo = VALUES(ruta_archivo), rechazado_at = NULL, motivo_rechazo = NULL, resubido_at = NULL'
        );
        $stmtDoc->execute(['pid' => $postulacionId, 'tipo' => $tipo, 'ruta' => $ruta]);

        $stmtResuelto = $pdo->prepare(
            'UPDATE documentos_pendientes SET resuelto_at = NOW()
              WHERE postulacion_id = :pid AND tipo = :tipo'
        );
        $stmtResuelto->execute(['pid' => $postulacionId, 'tipo' => $tipo]);

        registrarLog($pdo, $postulacionId, null, 'El postulante entregó el documento pendiente: ' . $etiqueta . '.');
        $entregados[] = $tipo;
    }
    if (!$entregados) {
        throw new RuntimeException('No se recibió ningún archivo.');
    }
    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    responderError($e->getMessage(), 422);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('pendientes_enviar error: ' . $e->getMessage());
    responderError('No fue posible guardar tus documentos. Intenta nuevamente.', 500);
}

// Espejo en la carpeta del trabajador (no bloqueante).
try {
    generarCarpetaDocumentosPersonales($pdo, $postulacionId);
} catch (Throwable $e) {
    error_log('pendientes_enviar carpeta error: ' . $e->getMessage());
}

$quedan = array_values(array_diff($tiposPendientes, $entregados));
responderOk([
    'mensaje' => count($entregados) === 1 ? 'Documento recibido. ¡Gracias!' : 'Documentos recibidos. ¡Gracias!',
    'quedan' => count($quedan),
]);
