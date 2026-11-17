<?php
/**
 * v10.37 - Regenerar el enlace de Etapa 2 de una persona ya seleccionada, desde el
 * panel (antes había que usar el Web Shell de Render).
 *
 * Lo usan el Jefe de Terreno (botón en su pestaña "Postulantes"), el JAO y el
 * Desarrollador. Solo sirve para una persona en 'Pre_aprobado_terreno' que todavía
 * no completó su Etapa 2. Genera un enlace nuevo de 7 días (el anterior deja de
 * funcionar) y lo devuelve para copiarlo o mandarlo por WhatsApp; si se pide, también
 * lo reenvía por correo. Queda anotado en la bitácora quién lo hizo.
 *
 * Entrada (JSON): postulacion_id  o  rut   [+ enviar_correo: true]
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/rut.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno', 'Jefe_Administrativo', 'Desarrollador']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
$rutCrudo = trim((string)($body['rut'] ?? ''));
$enviarCorreo = !empty($body['enviar_correo']);

$pdo = obtenerConexion();

if ($postulacionId > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.estado, p.nombre_completo, p.correo,
                (SELECT COUNT(*) FROM datos_contratacion d WHERE d.postulacion_id = p.id) > 0 AS etapa2_completada
           FROM postulaciones p WHERE p.id = :id'
    );
    $stmt->execute(['id' => $postulacionId]);
} elseif ($rutCrudo !== '') {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.estado, p.nombre_completo, p.correo,
                (SELECT COUNT(*) FROM datos_contratacion d WHERE d.postulacion_id = p.id) > 0 AS etapa2_completada
           FROM postulaciones p
          WHERE p.rut = :crudo OR p.rut = :rut
          ORDER BY p.creado_at DESC, p.id DESC LIMIT 1'
    );
    $stmt->execute(['crudo' => $rutCrudo, 'rut' => normalizarRut($rutCrudo)]);
} else {
    responderError('Indica el RUT o la postulación.', 422);
}

$p = $stmt->fetch();
if (!$p) {
    responderError('No se encontró esa postulación.', 404);
}
if ($p['estado'] !== 'Pre_aprobado_terreno' || (bool)$p['etapa2_completada']) {
    $motivo = (bool)$p['etapa2_completada']
        ? 'ya completó su Etapa 2'
        : ($p['estado'] === 'Pendiente' ? 'todavía no ha sido seleccionada por un Capataz' : 'no está en la etapa de completar datos');
    responderError('No se puede generar el enlace: la persona ' . $motivo . '.', 409);
}

try {
    if ($enviarCorreo) {
        // Genera el token y reenvía el correo con el enlace (mismo correo de siempre).
        otorgarAccesoEtapa2($pdo, (int)$p['id'], (int)$usuario['id']);
    } else {
        renovarTokenEtapa2($pdo, (int)$p['id']);
    }
} catch (Throwable $e) {
    error_log('enlace_etapa2 error: ' . $e->getMessage());
    responderError('No fue posible generar el enlace. Intenta nuevamente.', 500);
}

$stmtT = $pdo->prepare('SELECT token_privado, token_expira_at FROM postulaciones WHERE id = :id');
$stmtT->execute(['id' => $p['id']]);
$t = $stmtT->fetch();

registrarLog($pdo, (int)$p['id'], (int)$usuario['id'],
    'Enlace de Etapa 2 regenerado por ' . $usuario['nombre'] . ($enviarCorreo ? ' (reenviado por correo).' : '.'));

responderOk([
    'mensaje'     => 'Enlace nuevo generado. El anterior dejó de funcionar.' . ($enviarCorreo ? ' También se reenvió por correo.' : ''),
    'url_etapa2'  => BASE_URL . '/frontend/public/completar.html?token=' . $t['token_privado'],
    'expira_at'   => $t['token_expira_at'],
    'nombre'      => $p['nombre_completo'],
]);
