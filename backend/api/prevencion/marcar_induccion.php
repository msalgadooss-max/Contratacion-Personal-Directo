<?php
/**
 * Cambio de estado: Aprobado_admin -> Induccion_ok.
 * Se marca cuando el prevencionista confirma que ya dictó la charla ODI.
 *
 * v7: candado -- requiere que el JAO ya haya verificado la identidad
 * (día 1). Antes exigía estado 'Datos_completados', que nunca se
 * alcanzaba en la práctica (ver nota en listar.php de este mismo módulo).
 *
 * v9: candado nuevo -- además exige que TODOS los cursos activos del
 * catálogo estén 'Aprobado' para este postulante. Ya no basta con haber
 * visto los videos: hay que haber aprobado la evaluación de cada uno
 * (ver evaluar_curso.php).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Prevencionista']);
exigirModuloActivo(MODULO_PREVENCION_ACTIVO, 'Prevención');
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
if ($postulacionId <= 0) {
    responderError('postulacion_id inválido.', 422);
}

$pdo = obtenerConexion();

$stmtCheck = $pdo->prepare('SELECT estado, identidad_verificada_at FROM postulaciones WHERE id = :id');
$stmtCheck->execute(['id' => $postulacionId]);
$postulacion = $stmtCheck->fetch();

if (!$postulacion) {
    responderError('Postulación no encontrada.', 404);
}
if ($postulacion['estado'] !== 'Aprobado_admin') {
    responderError('La postulación no está en estado Aprobado_admin.', 409);
}
if ($postulacion['identidad_verificada_at'] === null) {
    responderError('El JAO todavía no verifica la identidad de esta persona (día 1).', 409);
}

$stmtCursos = $pdo->prepare(
    'SELECT
        (SELECT COUNT(*) FROM cursos_induccion WHERE activo = 1) AS total,
        (SELECT COUNT(*) FROM postulacion_cursos pc
           JOIN cursos_induccion ci ON ci.id = pc.curso_id AND ci.activo = 1
          WHERE pc.postulacion_id = :pid AND pc.estado = "Aprobado") AS aprobados'
);
$stmtCursos->execute(['pid' => $postulacionId]);
$cursos = $stmtCursos->fetch();
if ((int)$cursos['total'] > 0 && (int)$cursos['aprobados'] < (int)$cursos['total']) {
    responderError(
        "Todavía faltan cursos por aprobar ({$cursos['aprobados']}/{$cursos['total']}).",
        409
    );
}

fijarUsuarioContextoBD($pdo, $usuario['id']);

$stmt = $pdo->prepare('UPDATE postulaciones SET estado = "Induccion_ok" WHERE id = :id');
$stmt->execute(['id' => $postulacionId]);

registrarLog($pdo, $postulacionId, $usuario['id'], 'Prevención registró la inducción ODI (charla + todos los cursos aprobados).');

// v10.14: con Prevención activa, este es el verdadero cierre del día 1
// (antes, con Prevención pausada, ese aviso salía apenas el JAO
// verificaba identidad -- ver admin_general/verificar_identidad.php).
try {
    notificarPresentarseManana($pdo, $postulacionId);
} catch (\Throwable $e) {
    error_log('notificarPresentarseManana error: ' . $e->getMessage());
}

responderOk(['mensaje' => 'Inducción ODI registrada correctamente.']);
