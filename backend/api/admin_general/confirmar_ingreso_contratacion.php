<?php
/**
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09): salida
 * manual para el JAO cuando Portería no alcanzó a autorizar el paso de la
 * persona a contratación (8 am) -- ej. la persona ya está físicamente con
 * el JAO, o el guardia no tiene el sistema a mano. Mismo problema que ya
 * apareció con el ingreso a faena del día de postulación (ver
 * confirmar_ingreso_faena.php): sin esto, la persona queda sin acción
 * posible. Deja la misma marca que Portería (el botón de firma se habilita
 * igual) más una nota que aclara que fue manual.
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
$pdo->beginTransaction();
try {
    marcarPasoAContratacion(
        $pdo,
        $postulacionId,
        $usuario['id'],
        'JAO confirmó manualmente el paso a contratación (Portería no lo había autorizado).'
    );
    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('admin_general/confirmar_ingreso_contratacion error: ' . $e->getMessage());
    responderError('No fue posible confirmar el paso a contratación.', 500);
}

responderOk(['mensaje' => 'Paso a contratación confirmado. Ya puedes firmar su contrato.']);
