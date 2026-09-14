<?php
/**
 * v7 - Entrega del kit de EPP, día 2. Este es ahora el candado final
 * del proceso: reemplaza lo que antes hacía admin_general/finalizar.php
 * -- descuenta el cupo (dentro de una transacción con FOR UPDATE) y
 * deja a la postulación en 'Contratado'. Requiere que el JAO ya haya
 * firmado el contrato (contrato_firmado_at) el mismo día 2.
 *
 * Cambio de estado: Induccion_ok -> Contratado.
 * A partir de este cambio, el módulo de seguimiento público del
 * postulante pasa a color verde y se le avisa a Capataz/Jefe_Terreno
 * (rol pendiente en el widget "Estado en vivo") que vayan a buscarlo.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Bodega']);
exigirModuloActivo(MODULO_BODEGA_ACTIVO, 'Bodega');
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
if ($postulacionId <= 0) {
    responderError('postulacion_id inválido.', 422);
}

$pdo = obtenerConexion();

if (cierreRemuneracionesActivo($pdo)) {
    responderError(
        'Cierre de mes de remuneraciones activo: no se pueden cerrar contrataciones hasta que se reabra. El resto del proceso sigue funcionando con normalidad.',
        423
    );
}

$pdo->beginTransaction();

try {
    $stmtCheck = $pdo->prepare(
        'SELECT p.id, p.estado, p.cargo_id, p.contrato_firmado_at,
                p.rut, p.codigo_seguimiento, p.correo, p.nombre_completo,
                c.cupos_activos, c.nombre_cargo
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
          WHERE p.id = :id
          FOR UPDATE'
    );
    $stmtCheck->execute(['id' => $postulacionId]);
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
    // v10.14: el cupo ya se reservó/descontó al momento de la selección
    // del Capataz (ver terreno/aprobar.php), no corresponde volver a
    // exigirlo acá -- ver el mismo cambio en admin_general/firmar_contrato.php.

    fijarUsuarioContextoBD($pdo, $usuario['id']);

    $stmt = $pdo->prepare('UPDATE postulaciones SET estado = "Contratado" WHERE id = :id');
    $stmt->execute(['id' => $postulacionId]);

    $pdo->commit();

    // v6.1/v7: correos finales, fuera de la transacción (que no falle
    // el cierre si el envío de correo falla).
    try {
        notificarContratacionExitosa($pdo, $postulacion);
    } catch (\Throwable $e) {
        error_log('notificarContratacionExitosa error: ' . $e->getMessage());
    }
    try {
        notificarLiberacionTrabajador($pdo, $postulacion);
    } catch (\Throwable $e) {
        error_log('notificarLiberacionTrabajador error: ' . $e->getMessage());
    }
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('bodega/marcar_epp error: ' . $e->getMessage());
    responderError('No fue posible cerrar la contratación.', 500);
}

responderOk(['mensaje' => 'EPP entregado. Contratación cerrada -- avisa a Capataz o Jefe de Terreno para que lo vayan a buscar.']);
