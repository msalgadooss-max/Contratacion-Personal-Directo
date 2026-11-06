<?php
/**
 * v10.31 - Cada usuario cambia SU PROPIA clave (la que le dio el
 * Desarrollador es temporal). Si la cuenta tiene la marca "debe cambiar su
 * clave", este es el único endpoint (junto con me.php y logout.php) que
 * funciona hasta que la cambie -- ver requireLogin().
 *
 * Pide la clave actual (aunque haya entrado con ella hace un momento: evita
 * que alguien con el computador desbloqueado se la cambie), con el mismo
 * límite de 10 intentos fallidos / 15 min del login.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireLogin();
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$actual = (string)($body['password_actual'] ?? '');
$nueva = (string)($body['password_nueva'] ?? '');

$pdo = obtenerConexion();
$claveLimite = 'cambio:' . $usuario['id'];
if (intentosBloqueado($pdo, 'login', $claveLimite)) {
    responderBloqueoIntentos();
}

$stmt = $pdo->prepare('SELECT password FROM usuarios WHERE id = :id AND activo = 1');
$stmt->execute(['id' => $usuario['id']]);
$hashActual = $stmt->fetchColumn();
if ($hashActual === false || !password_verify($actual, $hashActual)) {
    registrarIntentoFallido($pdo, 'login', $claveLimite);
    responderError('La clave actual no es correcta.', 422);
}

if (mb_strlen($nueva) < 8) {
    responderError('La clave nueva debe tener al menos 8 caracteres.', 422);
}
if (mb_strlen($nueva) > 72) {
    responderError('La clave nueva no puede superar los 72 caracteres.', 422);
}
if (strtolower($nueva) === 'clave123') {
    responderError('Esa es la clave de demostración: elige una distinta.', 422);
}
if (hash_equals($actual, $nueva)) {
    responderError('La clave nueva tiene que ser distinta a la actual.', 422);
}

$stmt = $pdo->prepare('UPDATE usuarios SET password = :hash WHERE id = :id');
$stmt->execute(['hash' => password_hash($nueva, PASSWORD_BCRYPT), 'id' => $usuario['id']]);

// La marca de "clave temporal" se baja aparte: si la migración aún no corrió, no es un error.
try {
    $pdo->prepare('UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = :id')->execute(['id' => $usuario['id']]);
} catch (PDOException $e) {
    error_log('cambiar_password: ' . $e->getMessage());
}
unset($_SESSION['debe_cambiar']);
limpiarIntentosFallidos($pdo, 'login', $claveLimite);

responderOk(['mensaje' => 'Clave actualizada.', 'rol' => $usuario['rol']]);
