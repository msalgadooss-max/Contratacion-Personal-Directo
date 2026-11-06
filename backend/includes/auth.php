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

    // v10.30: si el Desarrollador desactivó a esta persona mientras tenía la
    // sesión abierta, se le corta el acceso en la siguiente petición (antes
    // seguía entrando hasta cerrar el navegador). A prueba de fallos: si la
    // consulta no se puede hacer, NO se bloquea a nadie.
    try {
        require_once __DIR__ . '/../config/database.php';
        $stmt = obtenerConexion()->prepare('SELECT activo FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $usuario['id']]);
        $activo = $stmt->fetchColumn();
        if ($activo !== false && (int)$activo === 0) {
            $_SESSION = [];
            session_destroy();
            responderError('Tu usuario fue desactivado. Habla con el administrador.', 401);
        }
    } catch (PDOException $e) {
        error_log('requireLogin activo check: ' . $e->getMessage());
    }

    // v10.31: con clave temporal solo funcionan me.php, logout.php y el cambio
    // de clave (carpeta /api/auth/); cualquier otra pantalla pide cambiarla primero.
    if (!empty($_SESSION['debe_cambiar']) && !str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/auth/')) {
        responderError('Debes cambiar tu clave temporal antes de continuar.', 403);
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
