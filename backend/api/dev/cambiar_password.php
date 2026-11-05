<?php
/**
 * v10.27 - El Desarrollador cambia la clave de cualquier usuario interno
 * (o la suya) desde su panel. Reemplaza las claves de demostración
 * ("Clave123") por claves reales antes de usar la plataforma con
 * postulantes de verdad.
 *
 * Solo rol Desarrollador (no aplica al "entrar como": ahí la sesión ya
 * tiene el rol prestado). La clave nueva nunca se guarda en claro ni se
 * escribe en ningún log; solo queda constancia de QUIÉN la cambió y a
 * QUIÉN, en el log de errores del servidor.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$dev = requireRol(['Desarrollador']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$usuarioId = (int)($body['usuario_id'] ?? 0);
$nueva = (string)($body['password_nueva'] ?? '');

if ($usuarioId <= 0) {
    responderError('usuario_id inválido.', 422);
}
if (mb_strlen($nueva) < 8) {
    responderError('La clave nueva debe tener al menos 8 caracteres.', 422);
}
if (mb_strlen($nueva) > 72) {
    // bcrypt ignora todo lo que pase de 72 bytes: mejor avisar.
    responderError('La clave nueva no puede superar los 72 caracteres.', 422);
}
if (strtolower($nueva) === 'clave123') {
    responderError('Esa es la clave de demostración: elige una distinta.', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare('SELECT id, nombre, correo FROM usuarios WHERE id = :id AND activo = 1');
$stmt->execute(['id' => $usuarioId]);
$objetivo = $stmt->fetch();
if (!$objetivo) {
    responderError('Usuario no encontrado o inactivo.', 404);
}

$stmt = $pdo->prepare('UPDATE usuarios SET password = :hash WHERE id = :id');
$stmt->execute(['hash' => password_hash($nueva, PASSWORD_BCRYPT), 'id' => $usuarioId]);

// Al cambiar la clave, se levanta cualquier bloqueo por intentos fallidos.
limpiarIntentosFallidos($pdo, 'login', mb_strtolower($objetivo['correo']));

error_log(sprintf('cambiar_password: dev #%d cambió la clave de usuario #%d (%s)', $dev['id'], $objetivo['id'], $objetivo['correo']));

responderOk(['mensaje' => 'Clave actualizada para ' . $objetivo['nombre'] . '.']);
