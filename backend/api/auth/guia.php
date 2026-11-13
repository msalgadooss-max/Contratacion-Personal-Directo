<?php
/**
 * v10.36 - Guía rápida (PDF) del propio perfil.
 *
 * Entrega a cada usuario SOLO la guía de su rol: el rol sale de la sesión, nunca de
 * un parámetro, así que nadie puede pedir la guía de otro perfil. Los PDF viven en
 * backend/guias/ (esa carpeta no sale por HTTP directo: ver el vhost del Dockerfile)
 * y se generan desde la guía de usuario del proyecto (una hoja por rol).
 * El Desarrollador no tiene guía propia.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

iniciarSesionSegura();
$usuario = requireLogin();
exigirMetodo('GET');

const GUIA_POR_ROL = [
    'Jefe_Terreno'        => 'terreno',
    'Capataz'             => 'capataz',
    'Admin_Contrato'      => 'admin_contrato',
    'Jefe_Administrativo' => 'jao',
    'Prevencionista'      => 'prevencion',
    'Jefe_Bodega'         => 'bodega',
    'Porteria'            => 'porteria',
    'Gerencia'            => 'gerencia',
];

$clave = GUIA_POR_ROL[$usuario['rol']] ?? null;
$ruta = $clave ? __DIR__ . '/../../guias/guia_' . $clave . '.pdf' : null;
if ($ruta === null || !is_file($ruta)) {
    responderError('No hay guía rápida para tu perfil.', 404);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Guia_rapida_' . $clave . '.pdf"');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, max-age=300');
readfile($ruta);
exit;
