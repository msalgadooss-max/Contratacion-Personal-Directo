<?php
/**
 * v7 - Cierre del proceso completo: Jefe_Terreno o Capataz confirman
 * que fueron a buscar al trabajador (ya contratado y con su EPP) desde
 * sala de reuniones o Bodega. Cambio de estado: Contratado ->
 * Proceso_completo. Con esto se da por terminado el ciclo entero, desde
 * la postulación hasta el primer día en el frente de trabajo.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno', 'Capataz']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
if ($postulacionId <= 0) {
    responderError('postulacion_id inválido.', 422);
}

$pdo = obtenerConexion();

$filtroPT = $usuario['rol'] === 'Jefe_Terreno' ? condicionGentePT($pdo, (int)$usuario['id']) : condicionGenteCapataz($pdo, (int)$usuario['id']);
$stmtCheck = $pdo->prepare('SELECT p.estado, p.recibido_terreno_at FROM postulaciones p WHERE p.id = :id' . $filtroPT);
$stmtCheck->execute(['id' => $postulacionId]);
$postulacion = $stmtCheck->fetch();

if (!$postulacion) {
    responderError('Postulación no encontrada.', 404);
}
if ($postulacion['estado'] !== 'Contratado') {
    responderError('La postulación no está en estado Contratado.', 409);
}
if ($postulacion['recibido_terreno_at'] !== null) {
    responderError('Esta persona ya fue registrada como recibida.', 409);
}

fijarUsuarioContextoBD($pdo, $usuario['id']);

$stmt = $pdo->prepare(
    'UPDATE postulaciones
        SET recibido_terreno_at = NOW(), recibido_terreno_por = :uid, estado = "Proceso_completo"
      WHERE id = :id'
);
$stmt->execute(['uid' => $usuario['id'], 'id' => $postulacionId]);

responderOk(['mensaje' => 'Recepción confirmada. Proceso completo.']);
