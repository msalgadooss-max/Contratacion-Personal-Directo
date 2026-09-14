<?php
/**
 * v10.14 (pedido explícito del usuario, encontrado en un caso real de la
 * prueba en Padre Hurtado: "no tengo de dónde liberarlo... hay algo que
 * no está uniendo el proceso") - Salida manual para el JAO cuando
 * Portería nunca confirmó el "ingreso a faena" (ej. la persona ya está
 * físicamente ahí -- se hizo la prueba en una sala, no pasó por el QR
 * de portería -- o el guardia simplemente no alcanzó a escanearlo).
 *
 * Sin esto, la postulación queda en un callejón sin salida: el botón
 * "Verificar identidad" del JAO nunca se habilita (exige
 * ingreso_faena_at), y Prevención tampoco puede verla en su lista
 * (exige identidad_verificada_at) -- nadie tiene una acción disponible.
 *
 * A diferencia de porteria/marcar_ingreso.php (que no requiere sesión y
 * se identifica con RUT + código de seguimiento, pensado para el
 * guardia en la entrada), este endpoint lo usa el JAO desde su propio
 * panel, donde ya identificó a la persona por nombre/RUT en su lista --
 * no necesita el código de seguimiento para nada.
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
$pdo->beginTransaction();

try {
    $stmtCheck = $pdo->prepare('SELECT estado, ingreso_faena_at FROM postulaciones WHERE id = :id FOR UPDATE');
    $stmtCheck->execute(['id' => $postulacionId]);
    $postulacion = $stmtCheck->fetch();

    if (!$postulacion) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }
    if ($postulacion['ingreso_faena_at'] !== null) {
        throw new RuntimeException('El ingreso de esta persona ya estaba confirmado.|409');
    }
    if (in_array($postulacion['estado'], ['Pendiente', 'En_banco', 'Rechazado'], true)) {
        throw new RuntimeException('Esta postulación todavía no está lista para confirmar ingreso a faena.|409');
    }

    fijarUsuarioContextoBD($pdo, $usuario['id']);
    $stmtUpdate = $pdo->prepare('UPDATE postulaciones SET ingreso_faena_at = NOW() WHERE id = :id');
    $stmtUpdate->execute(['id' => $postulacionId]);

    registrarLog($pdo, $postulacionId, $usuario['id'], 'JAO confirmó manualmente el ingreso a faena (Portería no lo había registrado).');

    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('admin_general/confirmar_ingreso_faena error: ' . $e->getMessage());
    responderError('No fue posible confirmar el ingreso.', 500);
}

responderOk(['mensaje' => 'Ingreso a faena confirmado. Ya puedes verificar su identidad.']);
