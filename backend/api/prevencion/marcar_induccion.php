<?php
/**
 * Cambio de estado: Aprobado_admin -> Induccion_ok.
 * Se marca cuando el prevencionista confirma que ya dictó la charla ODI.
 *
 * v7: candado -- requiere que el JAO ya haya verificado la identidad
 * (día 1). Antes exigía estado 'Datos_completados', que nunca se
 * alcanzaba en la práctica (ver nota en listar.php de este mismo módulo).
 *
 * v9: candado nuevo -- exigía que TODOS los cursos activos del catálogo
 * estuvieran 'Aprobado' para este postulante antes de poder marcar la
 * inducción.
 *
 * v10.20 (pedido explícito del usuario, hallado dos veces en pruebas
 * reales -- 16-09 y 22-09): ese candado de cursos bloqueaba a
 * Prevención sin que hubiera ninguna forma real, en este piloto, de que
 * el postulante rindiera esos cursos ("cursos 0 de 5" nunca podía
 * avanzar). "En esta etapa solo necesito, independiente de los cursos
 * que tenga, verificar" -- se retira el bloqueo: los cursos del
 * catálogo (si existen) quedan como información para Prevención, pero
 * ya NO impiden marcar la inducción. Verificar identidad (JAO, día 1)
 * sigue siendo un requisito real y aparte -- no se toca acá.
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

fijarUsuarioContextoBD($pdo, $usuario['id']);

$stmt = $pdo->prepare('UPDATE postulaciones SET estado = "Induccion_ok" WHERE id = :id');
$stmt->execute(['id' => $postulacionId]);

registrarLog($pdo, $postulacionId, $usuario['id'], 'Prevención registró la inducción ODI (charla presencial).');

// v10.14: con Prevención activa, este es el verdadero cierre del día 1
// (antes, con Prevención pausada, ese aviso salía apenas el JAO
// verificaba identidad -- ver admin_general/verificar_identidad.php).
try {
    notificarPresentarseManana($pdo, $postulacionId);
} catch (\Throwable $e) {
    error_log('notificarPresentarseManana error: ' . $e->getMessage());
}

responderOk(['mensaje' => 'Inducción ODI registrada correctamente.']);
