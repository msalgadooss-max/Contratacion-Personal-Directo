<?php
/**
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09):
 * SEGUNDO check de Prevención -- la IRL del día de contratación (8 am),
 * después de que el JAO firmó el contrato. No cambia `estado` (sigue en
 * 'Induccion_ok'); deja una marca en trazabilidad_logs
 * (ACCION_IRL_REALIZADA) que habilita a Bodega para entregar el kit
 * (ver bodega/listar.php y bodega/marcar_epp.php), y le avisa a Bodega
 * en ese momento ("Entrega EPP ahora").
 *
 * El primer check (inducción del día de postulación) es otro endpoint:
 * marcar_induccion.php.
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
                  WHERE t.postulacion_id = p.id AND t.accion = :accion_irl) AS ya_marcada
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
           LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
          WHERE p.id = :id
          FOR UPDATE'
    );
    $stmtCheck->execute(['accion_irl' => ACCION_IRL_REALIZADA, 'id' => $postulacionId]);
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
    if ((int)$postulacion['ya_marcada'] > 0) {
        throw new RuntimeException('La IRL de esta persona ya estaba registrada.|409');
    }

    registrarLog($pdo, $postulacionId, $usuario['id'], ACCION_IRL_REALIZADA);

    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('prevencion/marcar_irl error: ' . $e->getMessage());
    responderError('No fue posible registrar la IRL.', 500);
}

// Fuera de la transacción: que un fallo de correo no deshaga la IRL.
if (MODULO_BODEGA_ACTIVO) {
    try {
        notificarEntregaEppAhora($pdo, $postulacion);
    } catch (\Throwable $e) {
        error_log('notificarEntregaEppAhora error: ' . $e->getMessage());
    }
}

responderOk(['mensaje' => 'IRL registrada. La persona pasa a Bodega para la entrega del kit.']);
