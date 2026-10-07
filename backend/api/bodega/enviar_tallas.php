<?php
/**
 * v10.22 (pedido explícito del usuario, 06-10): Bodega envía UN solo correo
 * con la tabla de tallas de los postulantes que ya completaron su Etapa 2,
 * en vez de recibir un correo por postulante. Lo ideal es enviarlo a las
 * 14:00, cuando ya no entran más postulantes; si alguien completa después,
 * queda pendiente y sale en un envío aparte (ver
 * functions.php::tallasSinEnviar()). Cada persona incluida queda marcada en
 * trazabilidad_logs, así que apretar el botón dos veces no repite a nadie.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Bodega']);
exigirModuloActivo(MODULO_BODEGA_ACTIVO, 'Bodega');
exigirMetodo('POST');
exigirCsrfValido();

$pdo = obtenerConexion();

$personas = tallasSinEnviar($pdo);
if (!$personas) {
    responderError('No hay tallas pendientes de enviar.', 409);
}

try {
    $enviados = enviarTallasABodega($pdo, $personas, $usuario['id']);
} catch (\Throwable $e) {
    error_log('bodega/enviar_tallas error: ' . $e->getMessage());
    responderError('No fue posible enviar el correo de tallas. Intenta nuevamente.', 500);
}

if ($enviados === 0) {
    responderError('No se pudo enviar el correo (sin destinatarios activos o el servicio de correo falló). Intenta nuevamente.', 502);
}

$total = count($personas);
responderOk([
    'mensaje' => "Correo de tallas enviado con {$total} " . ($total === 1 ? 'postulante' : 'postulantes') . ' a ' . $enviados . ' destinatario(s).',
    'postulantes' => $total,
    'destinatarios' => $enviados,
]);
