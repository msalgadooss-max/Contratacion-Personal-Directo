<?php
/**
 * v10.31 - Reintenta o descarta correos que no pudieron salir.
 *   accion = reintentar (id)   : vuelve a enviar ese correo
 *   accion = reintentar_todos  : reintenta todos los pendientes (máx. 25)
 *   accion = descartar (id)    : lo da por resuelto sin enviarlo
 * Al reenviarse con éxito, el contenido guardado se borra (no se acumulan
 * datos personales de correos ya entregados).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../mailer/Mailer.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$accion = (string)($body['accion'] ?? '');
$id = (int)($body['id'] ?? 0);
if (!in_array($accion, ['reintentar', 'reintentar_todos', 'descartar'], true)) {
    responderError('Acción inválida.', 422);
}

$pdo = obtenerConexion();

if ($accion === 'descartar') {
    if ($id <= 0) {
        responderError('Falta el correo a descartar.', 422);
    }
    $stmt = $pdo->prepare("UPDATE correos_fallidos SET estado = 'descartado', cuerpo = '' WHERE id = :id AND estado = 'fallido'");
    $stmt->execute(['id' => $id]);
    responderOk(['mensaje' => 'Correo descartado.']);
}

if ($accion === 'reintentar' && $id <= 0) {
    responderError('Falta el correo a reintentar.', 422);
}

$stmt = $accion === 'reintentar'
    ? $pdo->prepare("SELECT * FROM correos_fallidos WHERE id = :id AND estado = 'fallido'")
    : $pdo->prepare("SELECT * FROM correos_fallidos WHERE estado = 'fallido' ORDER BY id ASC LIMIT 25");
$stmt->execute($accion === 'reintentar' ? ['id' => $id] : []);
$filas = $stmt->fetchAll();
if (!$filas) {
    responderError('No hay correos pendientes para reintentar.', 404);
}

$enviados = 0;
$fallidos = 0;
foreach ($filas as $f) {
    // registrarFallo = false: este correo YA está en la tabla, no se duplica.
    $ok = Mailer::enviar($f['destinatario'], $f['nombre'], $f['asunto'], $f['cuerpo'], false);
    if ($ok) {
        $pdo->prepare("UPDATE correos_fallidos SET estado = 'reenviado', cuerpo = '', ultimo_intento_at = NOW(), intentos = intentos + 1 WHERE id = :id")
            ->execute(['id' => $f['id']]);
        $enviados++;
    } else {
        $pdo->prepare('UPDATE correos_fallidos SET ultimo_intento_at = NOW(), intentos = intentos + 1, error = :e WHERE id = :id')
            ->execute(['e' => mb_substr((string)(Mailer::$ultimoError ?? 'Sin detalle'), 0, 255), 'id' => $f['id']]);
        $fallidos++;
    }
}

responderOk([
    'mensaje' => $fallidos === 0
        ? "Se reenviaron {$enviados} correo(s)."
        : "Se reenviaron {$enviados}; {$fallidos} siguen sin poder salir (revisa la configuración de correo).",
    'enviados' => $enviados,
    'fallidos' => $fallidos,
]);
