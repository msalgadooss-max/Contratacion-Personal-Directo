<?php
/**
 * v9 - El postulante envía sus respuestas (texto libre) a la evaluación
 * de un curso. No hay corrección automática: esto solo deja la
 * evaluación en 'Pendiente' de revisión -- Prevención es quien la lee y
 * decide Aprobado/Reprobado (ver prevencion/evaluar_curso.php). Si el
 * curso ya había sido Reprobado, reenviar respuestas lo vuelve a dejar
 * en 'Pendiente' para que Prevención lo revise de nuevo.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rut.php';

exigirMetodo('POST');

// v10.14: si Prevención vuelve a pausarse en el futuro, este aviso
// evita que alguien quede esperando una revisión que nunca va a llegar
// (ver el mismo bloque en induccion_listar.php).
if (!MODULO_PREVENCION_ACTIVO) {
    responderError('Todavía no necesitas hacer esto. Sigue tu proceso desde el módulo de seguimiento -- te avisaremos si se habilita este paso.', 409);
}

$body = leerJsonBody();
$documentoCrudo = trim((string)($body['rut'] ?? ''));
$codigo = strtoupper(limpiarTexto($body['codigo_seguimiento'] ?? '', 10));
$cursoId = (int)($body['curso_id'] ?? 0);
$respuestas = $body['respuestas'] ?? null;

if ($documentoCrudo === '' || $codigo === '' || $cursoId <= 0 || !is_array($respuestas)) {
    responderError('Faltan datos.', 422);
}

$respuestas = array_map(fn ($r) => limpiarTexto((string)$r, 1000), $respuestas);
if (count(array_filter($respuestas, fn ($r) => trim($r) !== '')) === 0) {
    responderError('Responde al menos una pregunta.', 422);
}

$documentoRut = normalizarRut($documentoCrudo);

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    'SELECT id, estado FROM postulaciones
      WHERE (rut = :doc_crudo OR rut = :doc_rut) AND codigo_seguimiento = :codigo
      LIMIT 1'
);
$stmt->execute(['doc_crudo' => $documentoCrudo, 'doc_rut' => $documentoRut, 'codigo' => $codigo]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    responderError('No se encontró una postulación con esos datos.', 404);
}
// v10.14: ver el mismo cambio en induccion_listar.php -- admin_autorizado_at
// ya no se vuelve a fijar nunca, el equivalente real es 'Aprobado_admin'.
if (!in_array($postulacion['estado'], ['Aprobado_admin', 'Induccion_ok'], true)) {
    responderError('Todavía no está disponible la inducción para tu proceso.', 409);
}

$stmtCurso = $pdo->prepare('SELECT id, preguntas_evaluacion FROM cursos_induccion WHERE id = :id AND activo = 1');
$stmtCurso->execute(['id' => $cursoId]);
$curso = $stmtCurso->fetch();
if (!$curso) {
    responderError('Curso no encontrado.', 404);
}

$stmtEstado = $pdo->prepare('SELECT visto_at, estado FROM postulacion_cursos WHERE postulacion_id = :pid AND curso_id = :cid');
$stmtEstado->execute(['pid' => $postulacion['id'], 'cid' => $cursoId]);
$actual = $stmtEstado->fetch();

if (!$actual || $actual['visto_at'] === null) {
    responderError('Primero debes ver el video de este curso.', 409);
}
if ($actual['estado'] === 'Aprobado') {
    responderError('Este curso ya fue aprobado, no hace falta reenviar la evaluación.', 409);
}

$stmtUpdate = $pdo->prepare(
    'UPDATE postulacion_cursos
        SET respuestas = :respuestas, enviado_at = NOW(), estado = "Pendiente",
            evaluado_at = NULL, evaluado_por = NULL, comentario_evaluador = NULL
      WHERE postulacion_id = :pid AND curso_id = :cid'
);
$stmtUpdate->execute([
    'respuestas' => json_encode(array_values($respuestas), JSON_UNESCAPED_UNICODE),
    'pid' => $postulacion['id'],
    'cid' => $cursoId,
]);

responderOk(['mensaje' => 'Evaluación enviada. Prevención la revisará antes de tu inducción presencial.']);
