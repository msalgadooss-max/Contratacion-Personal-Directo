<?php
/**
 * v7 - Segunda acción del JAO, separada de la verificación de
 * documentos (día 1, ver verificar_identidad.php). Esta se hace al día
 * siguiente, a las 8am, cuando el trabajador vuelve a firmar su
 * contrato. Reemplaza lo que antes hacía admin_general/finalizar.php.
 *
 * v9.2 - Etapa 1 del piloto (reunión con Jorge, semana del 7 al 11 de
 * septiembre): Prevención y Bodega quedan fuera del alcance de ESTA
 * etapa como candados del sistema -- en la vida real, Prevención igual
 * hace la charla IRL al día siguiente y Bodega igual entrega el EPP con
 * firma en papel, pero ninguna de las dos aprueba nada DENTRO de la
 * app todavía (eso es Etapa 2). Por eso el requisito de 'Induccion_ok'
 * y el cierre vía Bodega se vuelven CONDICIONALES a los mismos flags
 * MODULO_PREVENCION_ACTIVO / MODULO_BODEGA_ACTIVO que ya existían:
 *
 *   - Con Prevención activa (Etapa 2): se exige 'Induccion_ok' como
 *     antes, y quien cierra a 'Contratado' sigue siendo Bodega.
 *   - Con Prevención INACTIVA (Etapa 1, piloto de esta semana): basta
 *     con 'Aprobado_admin' + identidad ya verificada -- se salta el
 *     paso de cursos por completo.
 *   - Si además Bodega está INACTIVA (Etapa 1): esta misma acción hace
 *     lo que en Etapa 2 hace bodega/marcar_epp.php -- descuenta el
 *     cupo y deja la postulación en 'Contratado', porque no hay ningún
 *     otro candado digital que vaya a hacerlo.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Administrativo']);
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
        'Cierre de mes de remuneraciones activo: no se pueden firmar contratos hasta que se reabra. El resto del proceso sigue funcionando con normalidad.',
        423
    );
}

$pdo->beginTransaction();

try {
    $stmtCheck = $pdo->prepare(
        'SELECT p.id, p.estado, p.identidad_verificada_at, p.contrato_firmado_at,
                p.cargo_id, p.rut, p.codigo_seguimiento, p.correo, p.nombre_completo,
                c.cupos_activos, c.nombre_cargo,
                d.talla_calzado, d.talla_overol
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
           LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
          WHERE p.id = :id
          FOR UPDATE'
    );
    $stmtCheck->execute(['id' => $postulacionId]);
    $postulacion = $stmtCheck->fetch();

    if (!$postulacion) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }

    $estadoRequerido = MODULO_PREVENCION_ACTIVO ? 'Induccion_ok' : 'Aprobado_admin';
    if ($postulacion['estado'] !== $estadoRequerido) {
        throw new RuntimeException("La postulación no está en estado {$estadoRequerido}.|409");
    }
    if ($postulacion['identidad_verificada_at'] === null) {
        throw new RuntimeException('Debes verificar la identidad (RUT vs. cédula) antes de firmar el contrato.|409');
    }
    if ($postulacion['contrato_firmado_at'] !== null) {
        throw new RuntimeException('El contrato ya estaba firmado.|409');
    }

    $stmtJao = $pdo->prepare('SELECT id FROM datos_jao WHERE postulacion_id = :id');
    $stmtJao->execute(['id' => $postulacionId]);
    if (!$stmtJao->fetch()) {
        throw new RuntimeException('Debes completar los datos de nómina antes de firmar el contrato.|409');
    }

    $stmtDocPendiente = $pdo->prepare(
        'SELECT COUNT(*) FROM postulacion_documentos
          WHERE postulacion_id = :id AND rechazado_at IS NOT NULL AND resubido_at IS NULL'
    );
    $stmtDocPendiente->execute(['id' => $postulacionId]);
    if ((int)$stmtDocPendiente->fetchColumn() > 0) {
        throw new RuntimeException('Hay un documento observado pendiente de corrección por el postulante.|409');
    }

    // v9.2 - Etapa 1: sin Bodega activa como candado digital, esta misma
    // acción cierra el ciclo completo (queda Contratado).
    // v10.14: el cupo de esta persona ya se reservó/descontó al momento
    // de la selección del Capataz (ver terreno/aprobar.php) -- volver a
    // exigir cupos_activos > 0 recién acá no corresponde (ya está
    // reservado para ella) y podría bloquear injustamente el cierre si
    // otros cupos del mismo cargo se agotaron mientras tanto.
    $cierraAquiMismo = !MODULO_BODEGA_ACTIVO;

    $nuevoEstado = $cierraAquiMismo ? 'Contratado' : $postulacion['estado'];
    $stmt = $pdo->prepare(
        'UPDATE postulaciones
            SET contrato_firmado_at = NOW(), contrato_firmado_por = :uid, estado = :estado
          WHERE id = :id'
    );
    $stmt->execute(['uid' => $usuario['id'], 'estado' => $nuevoEstado, 'id' => $postulacionId]);

    registrarLog(
        $pdo,
        $postulacionId,
        $usuario['id'],
        $cierraAquiMismo
            ? 'Firmó el contrato (día 2). Etapa 1 del piloto: cierra la contratación directamente (Bodega todavía no participa en la app).'
            : 'Firmó el contrato (día 2). Pasa a Bodega para entrega de EPP.'
    );

    $pdo->commit();

    if ($cierraAquiMismo) {
        // v9.2: mismos correos de cierre que Bodega dispara en Etapa 2,
        // fuera de la transacción para no hacer fallar el cierre si el
        // envío de correo falla.
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
        try {
            notificarEntregaEppAhora($pdo, $postulacion);
        } catch (\Throwable $e) {
            error_log('notificarEntregaEppAhora error: ' . $e->getMessage());
        }
    }
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('firmar_contrato error: ' . $e->getMessage());
    responderError('No fue posible registrar la firma del contrato.', 500);
}

responderOk([
    'mensaje' => $cierraAquiMismo
        ? 'Contrato firmado. Contratación cerrada -- avisa a Capataz o Jefe de Terreno para que lo vayan a buscar.'
        : 'Contrato firmado. Pasa a Bodega para la entrega de EPP.',
]);
