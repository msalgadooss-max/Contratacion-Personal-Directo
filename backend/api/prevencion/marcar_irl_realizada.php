<?php
/**
 * v10.46 (pedido explícito del usuario, prueba real del 17-11): SEGUNDA confirmación de la IRL del Día 1.
 * La primera (marcar_irl.php) ahora significa "presentes en la sala"; esta confirma que la IRL SE REALIZÓ.
 * Recién aquí la persona queda habilitada para Bodega (ver bodega/listar.php y bodega/marcar_epp.php), y se
 * le avisa a Bodega ("Entrega EPP ahora", si ese aviso está activo). No cambia `estado` (sigue en 'Induccion_ok').
 * Requiere: contrato firmado y la confirmación previa de "presente en la sala".
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
$pdo->beginTransaction();

try {
    $stmtCheck = $pdo->prepare(
        'SELECT p.estado, p.contrato_firmado_at, p.nombre_completo, p.rut, c.nombre_cargo,
                d.talla_calzado, d.talla_overol,
                (SELECT COUNT(*) FROM trazabilidad_logs t
                  WHERE t.postulacion_id = p.id AND t.accion IN (:presente_a, :presente_b)) AS presente,
                (SELECT COUNT(*) FROM trazabilidad_logs t2
                  WHERE t2.postulacion_id = p.id AND t2.accion = :completada) AS ya_completada
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
           LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
          WHERE p.id = :id
          FOR UPDATE'
    );
    $stmtCheck->execute([
        'presente_a' => ACCION_IRL_REALIZADA, 'presente_b' => ACCION_IRL_PRESENTES,
        'completada' => ACCION_IRL_COMPLETADA, 'id' => $postulacionId,
    ]);
    $postulacion = $stmtCheck->fetch();

    if (!$postulacion) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }
    if ($postulacion['estado'] !== 'Induccion_ok') {
        throw new RuntimeException('La postulación no está en estado Induccion_ok.|409');
    }
    if ($postulacion['contrato_firmado_at'] === null) {
        throw new RuntimeException('El JAO todavía no firma el contrato de esta persona.|409');
    }
    if ((int)$postulacion['presente'] === 0) {
        throw new RuntimeException('Primero confirma que la persona está presente en la sala.|409');
    }
    if ((int)$postulacion['ya_completada'] > 0) {
        throw new RuntimeException('La IRL de esta persona ya estaba confirmada.|409');
    }

    registrarLog($pdo, $postulacionId, $usuario['id'], ACCION_IRL_COMPLETADA);

    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('prevencion/marcar_irl_realizada error: ' . $e->getMessage());
    responderError('No fue posible confirmar la IRL.', 500);
}

// Fuera de la transacción: que un fallo de correo no deshaga la confirmación.
if (MODULO_BODEGA_ACTIVO) {
    try {
        notificarEntregaEppAhora($pdo, $postulacion);
    } catch (\Throwable $e) {
        error_log('notificarEntregaEppAhora error: ' . $e->getMessage());
    }
}

responderOk(['mensaje' => 'IRL confirmada. La persona pasa a Bodega para la entrega del kit.']);
