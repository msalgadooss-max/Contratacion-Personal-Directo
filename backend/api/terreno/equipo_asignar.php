<?php
/**
 * v10.28 - El Jefe de Terreno suma un Capataz a su equipo, o lo quita.
 *   accion = agregar : solo si el Capataz no tiene Jefe de Terreno
 *                      (o ya es de este mismo Jefe de Terreno)
 *   accion = quitar  : solo si el Capataz es de este Jefe de Terreno
 * Un Capataz pertenece a un solo Jefe de Terreno a la vez.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$capatazId = (int)($body['capataz_id'] ?? 0);
$accion = (string)($body['accion'] ?? '');
if ($capatazId <= 0 || !in_array($accion, ['agregar', 'quitar'], true)) {
    responderError('Solicitud inválida.', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare("SELECT id, nombre, jefe_terreno_id FROM usuarios WHERE id = :id AND rol = 'Capataz' AND activo = 1");
$stmt->execute(['id' => $capatazId]);
$capataz = $stmt->fetch();
if (!$capataz) {
    responderError('Ese Capataz no existe o no está activo.', 404);
}

$duenio = $capataz['jefe_terreno_id'] !== null ? (int)$capataz['jefe_terreno_id'] : null;

if ($accion === 'agregar') {
    if ($duenio !== null && $duenio !== (int)$usuario['id']) {
        responderError('Ese Capataz ya está a cargo de otro Jefe de Terreno.', 409);
    }
    $stmt = $pdo->prepare('UPDATE usuarios SET jefe_terreno_id = :jt WHERE id = :id');
    $stmt->execute(['jt' => $usuario['id'], 'id' => $capatazId]);
    $mensaje = $capataz['nombre'] . ' ahora es parte de tu equipo.';
} else {
    if ($duenio !== (int)$usuario['id']) {
        responderError('Ese Capataz no está en tu equipo.', 409);
    }
    $stmt = $pdo->prepare('UPDATE usuarios SET jefe_terreno_id = NULL WHERE id = :id');
    $stmt->execute(['id' => $capatazId]);
    $mensaje = $capataz['nombre'] . ' salió de tu equipo.';
}

error_log(sprintf('equipo_asignar: JT #%d %s Capataz #%d', $usuario['id'], $accion, $capatazId));
responderOk(['mensaje' => $mensaje]);
