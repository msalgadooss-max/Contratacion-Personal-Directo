<?php
/**
 * v10.28 - El Jefe de Terreno reasigna a una persona que todavía espera
 * selección: se la pasa a otro Capataz, o la devuelve al "banco común"
 * (capataz_id = 0) para que cualquier Capataz pueda seleccionarla en
 * persona. Existe porque un Capataz solo ve a sus referidos: si ese
 * Capataz no está en obra, el Jefe de Terreno no deja a nadie atascado.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
$capatazId = (int)($body['capataz_id'] ?? 0);
if ($postulacionId <= 0 || $capatazId < 0) {
    responderError('Solicitud inválida.', 422);
}

$pdo = obtenerConexion();

$nombreCapataz = null;
if ($capatazId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = :id AND rol = 'Capataz' AND activo = 1");
    $stmt->execute(['id' => $capatazId]);
    $nombreCapataz = $stmt->fetchColumn();
    if ($nombreCapataz === false) {
        responderError('Ese Capataz no existe o no está activo.', 404);
    }
}

$stmt = $pdo->prepare(
    "SELECT estado FROM postulaciones WHERE id = :id"
);
$stmt->execute(['id' => $postulacionId]);
$estado = $stmt->fetchColumn();
if ($estado === false) {
    responderError('Postulación no encontrada.', 404);
}
if ($estado !== 'Pendiente') {
    responderError('La persona ya no está esperando selección.', 409);
}

$stmt = $pdo->prepare("UPDATE postulaciones SET capataz_esperado_id = :cap WHERE id = :id AND estado = 'Pendiente'");
$stmt->execute(['cap' => $capatazId > 0 ? $capatazId : null, 'id' => $postulacionId]);

registrarLog(
    $pdo,
    $postulacionId,
    $usuario['id'],
    $capatazId > 0
        ? 'Jefe de Terreno reasignó a la persona al Capataz ' . $nombreCapataz . '.'
        : 'Jefe de Terreno devolvió a la persona al banco común (cualquier Capataz puede seleccionarla).'
);

responderOk(['mensaje' => $capatazId > 0 ? 'Reasignado a ' . $nombreCapataz . '.' : 'Devuelto al banco común.']);
