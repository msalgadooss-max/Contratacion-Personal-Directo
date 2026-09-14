<?php
/**
 * v4 - Verificación MANUAL (con un clic, sin OCR) de que el RUT/documento
 * declarado por el postulante coincide con el que aparece en la foto o
 * PDF de cédula que subió en la Etapa 2. La persona del JAO revisa
 * ambos datos con sus propios ojos (ver documentos/ver.php para abrir
 * la cédula) y confirma con este botón -- queda registrado quién y
 * cuándo lo confirmó, y es requisito (junto a datos_jao) para poder
 * "Firmar Contrato" el día 2 (ver firmar_contrato.php).
 *
 * v10.14 (pedido explícito del usuario): al verificar, se le avisa al
 * postulante que avanzó -- pero SOLO si Prevención sigue pausada
 * (notificarPresentarseManana() aquí mismo, ver más abajo). Con
 * Prevención activa, todavía le queda la inducción ODI por hacer ese
 * mismo día, así que ese aviso ahora sale recién cuando Prevención
 * marca la inducción como realizada (ver prevencion/marcar_induccion.php).
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
if ($postulacionId <= 0) {
    responderError('postulacion_id inválido.', 422);
}

$pdo = obtenerConexion();

$stmtCheck = $pdo->prepare(
    'SELECT p.id, p.ingreso_faena_at,
            (SELECT COUNT(*) FROM postulacion_documentos pd
              WHERE pd.postulacion_id = p.id AND pd.tipo = "cedula_identidad") AS tiene_cedula
       FROM postulaciones p
      WHERE p.id = :id'
);
$stmtCheck->execute(['id' => $postulacionId]);
$postulacion = $stmtCheck->fetch();

if (!$postulacion) {
    responderError('Postulación no encontrada.', 404);
}
// v7: candado nuevo -- no se puede verificar documentos hasta que
// Portería confirmó que la persona se presentó en faena (ver
// porteria/marcar_ingreso.php).
if ($postulacion['ingreso_faena_at'] === null) {
    responderError('Esta persona todavía no registra ingreso a faena en Portería.', 409);
}
if ((int)$postulacion['tiene_cedula'] === 0) {
    responderError('Esta postulación aún no tiene la foto/PDF de cédula subida.', 409);
}

$stmt = $pdo->prepare(
    'UPDATE postulaciones
        SET identidad_verificada_at = NOW(), identidad_verificada_por = :uid
      WHERE id = :id'
);
$stmt->execute(['uid' => $usuario['id'], 'id' => $postulacionId]);

registrarLog($pdo, $postulacionId, $usuario['id'], 'Verificó manualmente que el RUT declarado coincide con la cédula subida.');

if (!MODULO_PREVENCION_ACTIVO) {
    try {
        notificarPresentarseManana($pdo, $postulacionId);
    } catch (\Throwable $e) {
        error_log('notificarPresentarseManana error: ' . $e->getMessage());
    }
}

responderOk(['mensaje' => 'Identidad verificada correctamente.']);
