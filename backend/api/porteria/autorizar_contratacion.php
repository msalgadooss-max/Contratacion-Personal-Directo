<?php
/**
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09): Portería
 * autoriza el paso de la persona a contratación (8 am, día de
 * contratación). Deja la marca ACCION_INGRESO_CONTRATACION en
 * trazabilidad_logs, le avisa por correo al JAO y le habilita su botón de
 * firma de contrato. No cambia `estado`.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/rut.php';

iniciarSesionSegura();
$usuario = requireRol(['Porteria']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$rutCrudo = trim((string)($body['rut'] ?? ''));
if ($rutCrudo === '') {
    responderError('Ingresa el RUT o documento de la persona.', 422);
}

$pdo = obtenerConexion();
$fase = faseParaPorteria($pdo, $rutCrudo, normalizarRut($rutCrudo));
if (!$fase) {
    responderError('No se encontró ninguna postulación con ese RUT o documento.', 404);
}
$postulacionId = $fase['id'];

$pdo->beginTransaction();
try {
    marcarPasoAContratacion($pdo, $postulacionId, $usuario['id']);
    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('porteria/autorizar_contratacion error: ' . $e->getMessage());
    responderError('No fue posible autorizar el paso a contratación.', 500);
}

// Fuera de la transacción: que un fallo de correo no deshaga la autorización.
try {
    notificarPasoContratacionAJao($pdo, $postulacionId);
} catch (\Throwable $e) {
    error_log('notificarPasoContratacionAJao error: ' . $e->getMessage());
}

responderOk(['mensaje' => 'Paso a contratación autorizado. El JAO ya fue avisado.']);
