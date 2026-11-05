<?php
/**
 * v6.6 - Autoservicio: si el correo con el enlace de Etapa 2 no llega
 * (spam, buzón lleno, correo mal escrito, etc.), el propio postulante
 * puede pedir aquí un enlace nuevo desde la misma pantalla de
 * seguimiento -- identificándose con RUT + código de seguimiento, el
 * mismo par de dos factores que ya protege todo ese módulo.
 *
 * Reutiliza otorgarAccesoEtapa2() (genera un token nuevo, invalida el
 * anterior si había uno vencido, e intenta reenviar el correo), pero
 * además devuelve el enlace directo en la respuesta -- así el
 * postulante puede seguir aunque el correo, de nuevo, no le llegue.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rut.php';

exigirMetodo('POST');

$body = leerJsonBody();
$documentoCrudo = trim((string)($body['rut'] ?? ''));
$codigo = strtoupper(limpiarTexto($body['codigo_seguimiento'] ?? '', 10));

if ($documentoCrudo === '' || $codigo === '') {
    responderError('Tu documento y el código de seguimiento son obligatorios.', 422);
}

$documentoRut = normalizarRut($documentoCrudo);

$pdo = obtenerConexion();

// v10.27: mismo límite que seguimiento.php (10 intentos fallidos / 15 min).
if (intentosBloqueado($pdo, 'documento', $documentoRut)) {
    responderBloqueoIntentos();
}

$stmt = $pdo->prepare(
    'SELECT id, estado,
            (SELECT COUNT(*) FROM datos_contratacion d WHERE d.postulacion_id = postulaciones.id) > 0 AS etapa2_completada
       FROM postulaciones
      WHERE (rut = :doc_crudo OR rut = :doc_rut) AND codigo_seguimiento = :codigo
      LIMIT 1'
);
$stmt->execute(['doc_crudo' => $documentoCrudo, 'doc_rut' => $documentoRut, 'codigo' => $codigo]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    registrarIntentoFallido($pdo, 'documento', $documentoRut);
    responderError('No se encontró una postulación con esos datos.', 404);
}
// v10.7: ya no depende de admin_autorizado_at (dato interno) -- basta
// con que el Capataz ya lo haya seleccionado (ver terreno/aprobar.php).
if ($postulacion['estado'] !== 'Pre_aprobado_terreno') {
    responderError('Todavía no está disponible el siguiente paso de tu postulación.', 409);
}
if ((bool)$postulacion['etapa2_completada']) {
    responderError('Ya completaste tus datos; no necesitas un enlace nuevo.', 409);
}

try {
    // usuarioId null: esta acción la origina el propio postulante, no
    // un usuario interno (igual que otras acciones de autoservicio).
    otorgarAccesoEtapa2($pdo, (int)$postulacion['id'], null);
} catch (Throwable $e) {
    error_log('reenviar_etapa2 error: ' . $e->getMessage());
    responderError('No fue posible generar tu enlace. Intenta nuevamente.', 500);
}

$stmtToken = $pdo->prepare('SELECT token_privado FROM postulaciones WHERE id = :id');
$stmtToken->execute(['id' => $postulacion['id']]);
$token = $stmtToken->fetchColumn();

responderOk([
    'mensaje' => 'Generamos un nuevo enlace y te lo reenviamos por correo. Si de nuevo no te llega, usa el botón de abajo para continuar directamente.',
    'url_etapa2' => BASE_URL . '/frontend/public/completar.html?token=' . $token,
]);
