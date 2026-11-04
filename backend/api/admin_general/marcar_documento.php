<?php
/**
 * v10.26 - El JAO deja un documento como "pendiente de entrega" u
 * "opcional" SIN frenar el proceso.
 *
 * Diferencia clave con rechazar_documento.php: rechazar un documento
 * BLOQUEA "Firmar Contrato" hasta que el postulante lo corrija; marcarlo
 * pendiente u opcional NO bloquea nada -- la persona sigue su camino
 * (verificación, firma, Prevención, Bodega) y el pendiente queda a la
 * vista del JAO hasta que lo marque como entregado.
 *
 * acciones:
 *   pendiente -> se va a entregar después
 *   opcional  -> para esta persona no se exige
 *   entregado -> el pendiente ya llegó (queda registrado, deja de avisar)
 *   quitar    -> borra la marca (vuelve a verse como documento faltante)
 *
 * La cédula (frente y reverso) no se puede postergar: es la base de la
 * verificación de identidad.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Administrativo']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
$tipo = (string)($body['tipo_documento'] ?? '');
$accion = (string)($body['accion'] ?? '');
$nota = limpiarTexto($body['nota'] ?? '', 255);

$etiquetas = etiquetasDocumentos();
if ($postulacionId <= 0 || !isset($etiquetas[$tipo]) || !in_array($accion, ['pendiente', 'opcional', 'entregado', 'quitar'], true)) {
    responderError('Solicitud inválida.', 422);
}
if (in_array($tipo, ['cedula_identidad', 'cedula_identidad_reverso'], true)) {
    responderError('La cédula no se puede dejar pendiente: se necesita para verificar la identidad.', 422);
}

$pdo = obtenerConexion();
$pdo->beginTransaction();

try {
    $stmtP = $pdo->prepare('SELECT id FROM postulaciones WHERE id = :id FOR UPDATE');
    $stmtP->execute(['id' => $postulacionId]);
    if (!$stmtP->fetch()) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }

    $etiqueta = $etiquetas[$tipo];

    if ($accion === 'quitar') {
        $stmt = $pdo->prepare('DELETE FROM documentos_pendientes WHERE postulacion_id = :pid AND tipo = :tipo');
        $stmt->execute(['pid' => $postulacionId, 'tipo' => $tipo]);
        registrarLog($pdo, $postulacionId, $usuario['id'], 'Quitó la marca del documento "' . $etiqueta . '".');
        $mensaje = 'Marca quitada.';
    } elseif ($accion === 'entregado') {
        $stmt = $pdo->prepare(
            'UPDATE documentos_pendientes SET resuelto_at = NOW(), marcado_por = :uid
              WHERE postulacion_id = :pid AND tipo = :tipo'
        );
        $stmt->execute(['uid' => $usuario['id'], 'pid' => $postulacionId, 'tipo' => $tipo]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Ese documento no estaba marcado como pendiente.|404');
        }
        registrarLog($pdo, $postulacionId, $usuario['id'], 'Marcó como entregado el documento "' . $etiqueta . '".');
        $mensaje = 'Marcado como entregado.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO documentos_pendientes (postulacion_id, tipo, condicion, nota, marcado_por)
             VALUES (:pid, :tipo, :condicion, :nota, :uid)
             ON DUPLICATE KEY UPDATE condicion = VALUES(condicion), nota = VALUES(nota),
                                     marcado_por = VALUES(marcado_por), marcado_at = NOW(), resuelto_at = NULL'
        );
        $stmt->execute([
            'pid' => $postulacionId, 'tipo' => $tipo, 'condicion' => $accion,
            'nota' => $nota !== '' ? $nota : null, 'uid' => $usuario['id'],
        ]);
        registrarLog(
            $pdo,
            $postulacionId,
            $usuario['id'],
            ($accion === 'pendiente'
                ? 'Dejó pendiente de entrega el documento "'
                : 'Marcó como opcional el documento "') . $etiqueta . '"' . ($nota !== '' ? ': ' . $nota : '.')
        );
        $mensaje = $accion === 'pendiente'
            ? 'Documento pendiente de entrega. No frena el proceso.'
            : 'Documento marcado como opcional. No frena el proceso.';
    }

    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('marcar_documento error: ' . $e->getMessage());
    responderError('No fue posible actualizar el documento.', 500);
}

responderOk(['mensaje' => $mensaje]);
