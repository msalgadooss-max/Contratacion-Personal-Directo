<?php
/**
 * v10.33 - Estado del sistema para el panel Desarrollador. Hoy informa si el
 * "modo prueba" de correo está redirigiendo los correos: en ese modo TODOS los
 * correos del sistema (de todas las obras) llegan a un solo buzón de prueba en
 * vez de a las personas reales. Si se olvida encendido con postulantes reales,
 * nadie recibe sus avisos y nada lo delata, por eso el panel lo muestra en rojo.
 *
 * Regla idéntica a Mailer::aplicarModoPrueba(): redirige solo si
 * EMAIL_MODO_PRUEBA=1 Y EMAIL_PRUEBA_DESTINO no está vacío.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('GET');

$modo = getenv('EMAIL_MODO_PRUEBA') === '1';
$destino = trim((string)getenv('EMAIL_PRUEBA_DESTINO'));

responderOk([
    'correo_redirigiendo' => $modo && $destino !== '',
    'correo_destino'      => ($modo && $destino !== '') ? $destino : '',
    // Encendido pero sin destino: el Mailer lo ignora y los correos salen a las personas reales.
    'correo_modo_sin_destino' => $modo && $destino === '',
    'obra_codigo'         => OBRA_CODIGO,
    'obra_nombre'         => OBRA_NOMBRE,
]);
