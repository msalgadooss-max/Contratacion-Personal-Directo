<?php
/**
 * Devuelve el usuario logueado (o 401) y un csrf_token vigente.
 * El frontend lo usa en cada dashboard para verificar sesion+rol antes
 * de mostrar la pantalla (guard de rol tambien en el cliente, ademas
 * del control real que hace cada endpoint en el servidor).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireLogin();

responderOk([
    'usuario'    => $usuario,
    'csrf_token' => generarCsrfToken(),
    // v5: si esto viene true, el frontend muestra el banner "modo
    // desarrollador" con el link para volver (ver dev/entrar_como.php).
    'modo_desarrollador' => isset($_SESSION['dev_original']),
    // v10.31: clave temporal pendiente de cambiar.
    'debe_cambiar_password' => !empty($_SESSION['debe_cambiar']),
    'dev_nombre'         => $_SESSION['dev_original']['nombre'] ?? null,
]);
