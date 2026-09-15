<?php
/**
 * v2 - Cierre de remuneraciones.
 * GET: cualquier rol interno autenticado puede consultar el estado
 *      (Gerencia lo necesita para su panel de solo lectura).
 * POST: solo Jefe_Administrativo puede activarlo/desactivarlo, o
 *      programar una ventana de fechas -- es quien conoce la ventana
 *      real del software de remuneraciones.
 *
 * v10.14 (pedido explícito del usuario, item 15): "que el JAO enviara
 * una solicitud de aceptación de fechas de cierre" -- se agrega
 * desde/hasta, además del interruptor manual `activo` que ya existía
 * (queda como override rápido para un cierre de emergencia sin fechas
 * definidas).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$pdo = obtenerConexion();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireLogin();
    $stmt = $pdo->query('SELECT activo, desde, hasta, quincena_desde, quincena_hasta FROM cierre_remuneraciones WHERE id = 1');
    $fila = $stmt->fetch();
    responderOk([
        'activo'         => cierreRemuneracionesActivo($pdo),
        'desde'          => $fila['desde'] ?? null,
        'hasta'          => $fila['hasta'] ?? null,
        'quincena_desde' => $fila['quincena_desde'] ?? null,
        'quincena_hasta' => $fila['quincena_hasta'] ?? null,
    ]);
}

$usuario = requireRol(['Jefe_Administrativo']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$activo = (bool)($body['activo'] ?? false);
$desde = limpiarTexto($body['desde'] ?? '', 10);
$hasta = limpiarTexto($body['hasta'] ?? '', 10);
// v10.15 (pedido explícito del usuario, item 5): segundo cierre,
// independiente del mensual, para la quincena -- ambos opcionales, no
// se exige que estén los dos programados a la vez.
$quincenaDesde = limpiarTexto($body['quincena_desde'] ?? '', 10);
$quincenaHasta = limpiarTexto($body['quincena_hasta'] ?? '', 10);

if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
    responderError('La fecha "desde" no puede ser posterior a "hasta".', 422);
}
if ($quincenaDesde !== '' && $quincenaHasta !== '' && $quincenaDesde > $quincenaHasta) {
    responderError('En el cierre de quincena, la fecha "desde" no puede ser posterior a "hasta".', 422);
}

$stmt = $pdo->prepare(
    'UPDATE cierre_remuneraciones
        SET activo = :activo, desde = :desde, hasta = :hasta,
            quincena_desde = :quincena_desde, quincena_hasta = :quincena_hasta,
            actualizado_por = :uid
      WHERE id = 1'
);
$stmt->execute([
    'activo' => $activo ? 1 : 0,
    'desde' => $desde !== '' ? $desde : null,
    'hasta' => $hasta !== '' ? $hasta : null,
    'quincena_desde' => $quincenaDesde !== '' ? $quincenaDesde : null,
    'quincena_hasta' => $quincenaHasta !== '' ? $quincenaHasta : null,
    'uid' => $usuario['id'],
]);

// v10.14 (corrección, pedido explícito del usuario): desde/hasta es la
// ventana en que SÍ se puede contratar -- "podemos contratar a un
// trabajador en este rango de fechas solamente". Fuera de ese rango es
// cuando remuneraciones queda cerrado. v10.15: además, el cierre de
// quincena bloquea aunque estemos dentro de esa ventana.
$quedaActivo = cierreRemuneracionesActivo($pdo);
$partesMensaje = [];
if ($desde !== '' && $hasta !== '') {
    $partesMensaje[] = "Ventana mensual programada del {$desde} al {$hasta} (fuera de esas fechas, remuneraciones queda cerrado).";
}
if ($quincenaDesde !== '' && $quincenaHasta !== '') {
    $partesMensaje[] = "Cierre de quincena programado del {$quincenaDesde} al {$quincenaHasta}.";
}
if ($partesMensaje) {
    $mensaje = implode(' ', $partesMensaje)
        . ' No se podrá "Finalizar Contratación" mientras algún cierre esté activo; "Solicitar Cupos" sigue funcionando, con aviso de que los cupos se liberarán al terminar el cierre.'
        . ($quedaActivo ? ' Con la fecha de hoy, hay un cierre activo ahora mismo.' : '');
} else {
    $mensaje = $quedaActivo
        ? 'Cierre de remuneraciones activado manualmente: "Finalizar Contratación" queda bloqueado hasta que lo reabras.'
        : 'Cierre de remuneraciones desactivado: ya se pueden finalizar contrataciones con normalidad.';
}

responderOk([
    'activo'         => $quedaActivo,
    'desde'          => $desde !== '' ? $desde : null,
    'hasta'          => $hasta !== '' ? $hasta : null,
    'quincena_desde' => $quincenaDesde !== '' ? $quincenaDesde : null,
    'quincena_hasta' => $quincenaHasta !== '' ? $quincenaHasta : null,
    'mensaje'        => $mensaje,
]);
