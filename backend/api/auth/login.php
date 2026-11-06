<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
exigirMetodo('POST');

$body = leerJsonBody();
$correo = limpiarTexto($body['correo'] ?? '', 150);
$password = (string)($body['password'] ?? '');

if ($correo === '' || $password === '') {
    responderError('Correo y contraseña son obligatorios.', 422);
}

$pdo = obtenerConexion();

// v10.27: máximo 10 intentos fallidos por correo cada 15 minutos.
$claveIntentos = mb_strtolower($correo);
if (intentosBloqueado($pdo, 'login', $claveIntentos)) {
    responderBloqueoIntentos();
}

$stmt = $pdo->prepare('SELECT id, nombre, correo, password, rol, activo FROM usuarios WHERE correo = :correo LIMIT 1');
$stmt->execute(['correo' => $correo]);
$usuario = $stmt->fetch();

// Mensaje generico en ambos casos de fallo: evita revelar si el correo existe.
if (!$usuario || !$usuario['activo'] || !password_verify($password, $usuario['password'])) {
    registrarIntentoFallido($pdo, 'login', $claveIntentos);
    responderError('Credenciales incorrectas.', 401);
}
limpiarIntentosFallidos($pdo, 'login', $claveIntentos);

// Regenerar el id de sesion en cada login (previene session fixation).
session_regenerate_id(true);

$_SESSION['usuario'] = [
    'id'     => (int)$usuario['id'],
    'nombre' => $usuario['nombre'],
    'correo' => $usuario['correo'],
    'rol'    => $usuario['rol'],
];

// v10.31: si la clave es temporal (la puso el Desarrollador), la sesión queda
// marcada y solo se puede cambiar la clave hasta hacerlo (ver requireLogin()).
// A prueba de fallos: si la columna aún no existe, se ignora.
$debeCambiar = false;
try {
    $stmtMarca = $pdo->prepare('SELECT debe_cambiar_password FROM usuarios WHERE id = :id');
    $stmtMarca->execute(['id' => $usuario['id']]);
    $debeCambiar = (bool)$stmtMarca->fetchColumn();
} catch (PDOException $e) {
    $debeCambiar = false;
}
if ($debeCambiar) {
    $_SESSION['debe_cambiar'] = true;
} else {
    unset($_SESSION['debe_cambiar']);
}

responderOk([
    'usuario'    => $_SESSION['usuario'],
    'csrf_token' => generarCsrfToken(),
    'debe_cambiar_password' => $debeCambiar,
]);
