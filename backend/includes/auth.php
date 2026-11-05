<?php
/**
 * Manejo de sesion y control de acceso por rol.
 * Cada endpoint interno debe llamar requireRol([...]) apenas empieza,
 * ANTES de tocar la base de datos, para garantizar que ningun rol pueda
 * ejecutar una accion o leer una tabla fuera de su fase del flujo.
 */

require_once __DIR__ . '/../config/config.php'; // define SESSION_NAME, etc.
require_once __DIR__ . '/functions.php';

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        // v10.27: detrás del proxy de Render PHP ve HTTP plano; el
        // protocolo real llega en X-Forwarded-Proto.
        'secure'   => !empty($_SERVER['HTTPS'])
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Devuelve el usuario logueado (array) o null. */
function usuarioActual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/** Corta la ejecucion con 401 si no hay sesion activa. */
function requireLogin(): array
{
    $usuario = usuarioActual();
    if (!$usuario) {
        responderError('Debe iniciar sesion.', 401);
    }
    return $usuario;
}

/**
 * Corta la ejecucion con 401/403 si no hay sesion o el rol no esta
 * autorizado para el endpoint actual. Devuelve el usuario para que el
 * endpoint pueda usar su id (por ejemplo, para fijarUsuarioContextoBD).
 */
function requireRol(array $rolesPermitidos): array
{
    $usuario = requireLogin();
    if (!in_array($usuario['rol'], $rolesPermitidos, true)) {
        responderError('No tiene permisos para esta accion.', 403);
    }
    return $usuario;
}
