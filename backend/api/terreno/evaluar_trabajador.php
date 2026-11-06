<?php
/**
 * v10.31 (pedido explícito del usuario) - Al finiquitar a un trabajador, el
 * Jefe de Terreno deja una evaluación breve: ¿se recomienda recontratarlo?
 * Una por postulación (ciclo); se puede corregir después. Es información
 * INTERNA (Jefe de Terreno, JAO y -solo la recomendación- Capataz): nunca la
 * ve el postulante.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
$recomienda = (string)($body['recomienda'] ?? '');
$comentario = limpiarTexto($body['comentario'] ?? '', 500);
$fechaTermino = trim((string)($body['fecha_termino'] ?? ''));

if ($postulacionId <= 0 || !in_array($recomienda, ['si', 'con_reservas', 'no'], true)) {
    responderError('Elige si recomiendas recontratar a esta persona.', 422);
}
if ($fechaTermino !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $fechaTermino);
    if (!$d || $d->format('Y-m-d') !== $fechaTermino) {
        responderError('La fecha de término no es válida.', 422);
    }
} else {
    $fechaTermino = null;
}
if ($recomienda === 'no' && $comentario === '') {
    responderError('Si no la recomiendas, deja un comentario breve con el motivo (hechos concretos).', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare("SELECT rut, estado FROM postulaciones WHERE id = :id");
$stmt->execute(['id' => $postulacionId]);
$p = $stmt->fetch();
if (!$p) {
    responderError('Postulación no encontrada.', 404);
}
if (!in_array($p['estado'], ['Contratado', 'Proceso_completo'], true)) {
    responderError('Solo se evalúa a personas que ya fueron contratadas.', 409);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO evaluaciones_trabajador (postulacion_id, rut, recomienda, comentario, fecha_termino, evaluado_por)
         VALUES (:pid, :rut, :rec, :com, :fecha, :uid)
         ON DUPLICATE KEY UPDATE recomienda = VALUES(recomienda), comentario = VALUES(comentario),
                                 fecha_termino = VALUES(fecha_termino), evaluado_por = VALUES(evaluado_por)'
    );
    $stmt->execute([
        'pid' => $postulacionId, 'rut' => $p['rut'], 'rec' => $recomienda,
        'com' => $comentario !== '' ? $comentario : null, 'fecha' => $fechaTermino, 'uid' => $usuario['id'],
    ]);
} catch (PDOException $e) {
    error_log('evaluar_trabajador: ' . $e->getMessage());
    responderError('No fue posible guardar la evaluación (¿falta correr la migración v10.31?).', 500);
}

$etiquetas = ['si' => 'recomendado', 'con_reservas' => 'recomendado con reservas', 'no' => 'NO recomendado'];
registrarLog($pdo, $postulacionId, $usuario['id'], 'Evaluación de recontratación: ' . $etiquetas[$recomienda] . '.');

responderOk(['mensaje' => 'Evaluación guardada.']);
