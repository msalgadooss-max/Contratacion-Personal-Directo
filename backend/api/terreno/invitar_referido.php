<?php
/**
 * v10.28 - Capataz o Jefe de Terreno envían por correo su enlace personal
 * de postulación a un referido (puede estar lejos de la obra). El enlace
 * lleva ?ref=<id de quien invita>, así la postulación queda asociada a él
 * (ver public/postular.php y public/listas.php).
 *
 * Límite: 10 correos cada 15 minutos por persona, para que esto no sirva
 * de envío masivo de correo desde el dominio de la empresa.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Capataz', 'Jefe_Terreno']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$correo = filter_var(trim((string)($body['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
$nombre = limpiarTexto($body['nombre'] ?? '', 100);
if (!$correo) {
    responderError('Escribe un correo válido.', 422);
}

$pdo = obtenerConexion();
$claveLimite = (string)$usuario['id'];
if (intentosBloqueado($pdo, 'invitacion', $claveLimite)) {
    responderError('Enviaste muchas invitaciones seguidas. Espera ' . INTENTOS_VENTANA_MINUTOS . ' minutos.', 429);
}

require_once __DIR__ . '/../../mailer/Mailer.php';
$urlPostulacion = BASE_URL . '/frontend/public/index.html?ref=' . (int)$usuario['id'];
$html = (function () use ($nombre, $urlPostulacion) {
    return require __DIR__ . '/../../mailer/templates/link_postulacion.php';
})();

$ok = Mailer::enviar($correo, $nombre !== '' ? $nombre : 'Postulante', 'Postula a ICAFAL - Enlace de postulación', $html);
if (!$ok) {
    responderError('No fue posible enviar el correo. Intenta de nuevo en unos minutos.', 500);
}
registrarIntentoFallido($pdo, 'invitacion', $claveLimite); // cuenta cada envío para el límite

responderOk(['mensaje' => 'Invitación enviada a ' . $correo . '.']);
