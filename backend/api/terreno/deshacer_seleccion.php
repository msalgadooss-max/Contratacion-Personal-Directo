<?php
/**
 * v10.10 (pedido explícito del usuario, rol Capataz) - "En caso que por
 * X motivo el Capataz se equivoque de cargo, [que] pueda
 * seleccionar/deseleccionar al postulante". Deshace una selección
 * reciente: vuelve la postulación a 'Pendiente' (reaparece en la lista
 * de selección del Capataz) y el cargo al interno "Por asignar" -- así
 * el Capataz puede volver a arrastrarla a la caja correcta.
 *
 * Solo funciona mientras la postulación sigue exactamente en
 * 'Pre_aprobado_terreno' (el mismo paso que dejó la selección del
 * Capataz) -- si ya avanzó más allá (Admin_Contrato autorizó, o el
 * postulante ya completó Etapa 2), ya no es un "me equivoqué recién",
 * es una contratación en curso, y deshacerla a mano traería más
 * problemas que el error original.
 *
 * El correo con el link de Etapa 2 y el QR de portería que ya se le
 * mandaron al postulante (ver terreno/aprobar.php) no se pueden
 * "desenviar" -- por eso se invalida el token: si esa persona hace
 * clic en el link viejo, ya no funciona.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Capataz']);
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
    $stmtCheck = $pdo->prepare('SELECT estado, cargo_id FROM postulaciones WHERE id = :id FOR UPDATE');
    $stmtCheck->execute(['id' => $postulacionId]);
    $postulacion = $stmtCheck->fetch();

    if (!$postulacion) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }
    if ($postulacion['estado'] !== 'Pre_aprobado_terreno') {
        throw new RuntimeException('Esta postulación ya avanzó más allá de la selección -- ya no se puede deshacer desde acá.|409');
    }

    $stmtCargoAsignar = $pdo->prepare("SELECT id FROM cargos WHERE nombre_cargo = 'Por asignar' LIMIT 1");
    $stmtCargoAsignar->execute();
    $cargoAsignar = $stmtCargoAsignar->fetch();
    if (!$cargoAsignar) {
        throw new RuntimeException('No fue posible deshacer la selección (falta el cargo interno "Por asignar").|500');
    }

    fijarUsuarioContextoBD($pdo, $usuario['id']);
    $stmt = $pdo->prepare(
        'UPDATE postulaciones
            SET estado = "Pendiente", cargo_id = :cargo_id, token_privado = NULL, token_expira_at = NULL
          WHERE id = :id AND estado = "Pre_aprobado_terreno"'
    );
    $stmt->execute(['cargo_id' => (int)$cargoAsignar['id'], 'id' => $postulacionId]);

    // v10.14: terreno/aprobar.php ahora descuenta el cupo del cargo real
    // justo al seleccionar (no recién en 'Contratado') -- así que
    // deshacer la selección tiene que devolver ese cupo al cargo del
    // que salió, o se perdería silenciosamente.
    $stmtDevolver = $pdo->prepare('UPDATE cargos SET cupos_activos = cupos_activos + 1 WHERE id = :id');
    $stmtDevolver->execute(['id' => (int)$postulacion['cargo_id']]);

    registrarLog($pdo, $postulacionId, $usuario['id'], 'Capataz deshizo la selección (vuelve a la lista de selección en terreno).');

    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('terreno/deshacer_seleccion error: ' . $e->getMessage());
    responderError('No fue posible deshacer la selección.', 500);
}

responderOk(['mensaje' => 'Selección deshecha. Vuelve a aparecer en tu lista para elegir el cargo correcto.']);
