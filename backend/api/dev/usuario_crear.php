<?php
/**
 * v10.30 - El Desarrollador da de alta a un usuario interno (Capataz, Jefe de
 * Terreno, JAO, etc.) desde su panel, sin tener que usar la consola de Render.
 *
 * No se pueden crear usuarios con rol Desarrollador desde aquí (ese rol da
 * acceso a todo y se administra solo a mano). La clave nunca se guarda en
 * claro; el Desarrollador la comunica a la persona (el botón "Generar" del
 * panel crea una clave segura).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$dev = requireRol(['Desarrollador']);
exigirMetodo('POST');
exigirCsrfValido();

const ROLES_ADMINISTRABLES = [
    'Jefe_Terreno', 'Capataz', 'Admin_Contrato', 'Jefe_Administrativo',
    'Prevencionista', 'Jefe_Bodega', 'Porteria', 'Gerencia',
];

$body = leerJsonBody();
$nombre = limpiarTexto($body['nombre'] ?? '', 150);
$correo = filter_var(trim((string)($body['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
$rol = (string)($body['rol'] ?? '');
$password = (string)($body['password'] ?? '');

if ($nombre === '') {
    responderError('Escribe el nombre de la persona.', 422);
}
if (!$correo || mb_strlen($correo) > 150) {
    responderError('Escribe un correo válido.', 422);
}
if (!in_array($rol, ROLES_ADMINISTRABLES, true)) {
    responderError('Elige un rol válido.', 422);
}
if (mb_strlen($password) < 8 || mb_strlen($password) > 72) {
    responderError('La clave debe tener entre 8 y 72 caracteres.', 422);
}
if (strtolower($password) === 'clave123') {
    responderError('Esa es la clave de demostración: elige una distinta.', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE LOWER(correo) = LOWER(:correo)');
$stmt->execute(['correo' => $correo]);
if ((int)$stmt->fetchColumn() > 0) {
    responderError('Ya existe un usuario con ese correo.', 409);
}

$stmt = $pdo->prepare('INSERT INTO usuarios (nombre, correo, password, rol) VALUES (:nombre, :correo, :hash, :rol)');
$stmt->execute([
    'nombre' => $nombre,
    'correo' => $correo,
    'hash'   => password_hash($password, PASSWORD_BCRYPT),
    'rol'    => $rol,
]);

$nuevoId = (int)$pdo->lastInsertId();
// v10.31: la clave inicial es temporal: la persona debe cambiarla en su primer ingreso.
try {
    $pdo->prepare('UPDATE usuarios SET debe_cambiar_password = 1 WHERE id = :id')->execute(['id' => $nuevoId]);
} catch (PDOException $e) {
    error_log('usuario_crear: no se pudo marcar clave temporal: ' . $e->getMessage());
}

error_log(sprintf('usuario_crear: dev #%d creó usuario #%d (%s, %s)', $dev['id'], $nuevoId, $correo, $rol));
responderOk(['mensaje' => 'Usuario creado: ' . $nombre . '. Deberá cambiar su clave al entrar por primera vez.', 'id' => $nuevoId]);
