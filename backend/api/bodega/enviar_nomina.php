<?php
/**
 * v10.21 (pedido explícito del usuario, hallado en el piloto del 16-09):
 * Bodega envía UNA nómina consolidada (tabla en el cuerpo del correo)
 * con todos los trabajadores que ya liberó y que todavía no fueron
 * avisados -- en vez de un correo suelto por cada trabajador. Va a Jefe
 * de Terreno, Capataces, Administrador de Contrato, JAO, Prevención y
 * Bodega (ver functions.php::enviarNominaLiberados()).
 *
 * Cada trabajador incluido queda marcado en trazabilidad_logs, así que
 * apretar el botón dos veces no manda dos veces la misma persona.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Bodega']);
exigirModuloActivo(MODULO_BODEGA_ACTIVO, 'Bodega');
exigirMetodo('POST');
exigirCsrfValido();

// Un envío por destinatario: si el servicio de correo está lento, el
// total puede superar el límite normal de 60 segundos del servidor.
set_time_limit(120);

$pdo = obtenerConexion();

$liberados = liberadosSinNomina($pdo);
if (!$liberados) {
    responderError('No hay trabajadores liberados pendientes de incluir en una nómina.', 409);
}

try {
    $enviados = enviarNominaLiberados($pdo, $liberados, $usuario['id']);
} catch (\Throwable $e) {
    error_log('bodega/enviar_nomina error: ' . $e->getMessage());
    responderError('No fue posible enviar la nómina. Intenta nuevamente.', 500);
}

if ($enviados === 0) {
    responderError('No se pudo enviar la nómina (sin destinatarios activos o el servicio de correo falló). Intenta nuevamente.', 502);
}

$total = count($liberados);
responderOk([
    'mensaje' => "Nómina enviada con {$total} " . ($total === 1 ? 'trabajador' : 'trabajadores') . " a {$enviados} destinatario(s).",
    'trabajadores' => $total,
    'destinatarios' => $enviados,
]);
