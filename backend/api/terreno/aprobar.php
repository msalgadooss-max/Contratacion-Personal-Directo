<?php
/**
 * Selección de personal en terreno -- Capataz.
 *
 * v10.14 (pedido explícito del usuario, item 5 de la lista post-prueba):
 * "el Jefe de Terreno no debe aprobar, el que selecciona es el
 * Capataz". Se elimina el paso intermedio de "primer filtro"
 * (aprobado_jt_at) que antes hacía Jefe_Terreno -- el Capataz ahora
 * actúa directamente sobre cualquier postulación 'Pendiente' (ver
 * terreno/listar.php, que ya no distingue entre roles). Jefe de
 * Terreno pasa a ser un rol de solo lectura + solicitar cupos.
 *
 *   - v10.5 (Mejorar APP, punto 2): el postulante ya no elige cargo al
 *     postular (nace con el cargo interno "Por asignar"). Es justo en
 *     este paso, cuando el Capataz selecciona a la persona en persona,
 *     donde se asigna el cargo real -- por eso el chequeo de cupos
 *     (que antes vivía en public/postular.php) se movió para acá.
 *
 *   - v10.7 (pedido explícito del usuario, tras describir el proceso
 *     completo en detalle): el correo con el link de Etapa 2 ahora se
 *     envía JUSTO ACÁ, apenas el Capataz selecciona a la persona en
 *     portería -- para que lo llene ahí mismo, en la sala de espera, con
 *     su celular. Antes salía recién cuando el Administrador de
 *     Contrato autorizaba por separado (otro rol, otro momento); esa
 *     autorización ahora es un dato puramente interno que el postulante
 *     nunca ve ni es notificado (ver admin_contrato/autorizar.php).
 *
 *   - v10.9 (mismo pedido, misma descripción del proceso): el correo con
 *     el QR de "ingreso a faena" -- el que Portería escanea para dejarlo
 *     pasar a la sala de espera -- también sale JUSTO ACÁ, junto con el
 *     de Etapa 2. Antes salía mucho después (recién cuando la
 *     postulación llegaba a 'Aprobado_admin', es decir, después de que
 *     el postulante ya había completado su Etapa 2 a distancia), lo que
 *     no calzaba con una sola visita continua: portería no podía dejarlo
 *     entrar a llenar sus datos porque ese QR todavía no existía.
 *
 *   - v10.13 (mismo pedido, tras describir de nuevo el proceso): el JAO
 *     recibe acá un aviso temprano de que esta persona viene en camino
 *     (notificarSeleccionAJao()) -- el rol de Admin_Contrato termina al
 *     aprobar los cupos (ver solicitudes_cupo_aprobar.php), ya no
 *     autoriza cada postulación una por una.
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
$cargoId = (int)($body['cargo_id'] ?? 0);
if ($cargoId <= 0) {
    responderError('Debes indicar el cargo que le vas a asignar.', 422);
}

$pdo = obtenerConexion();

exigirCupoDiarioAprobaciones($pdo, $usuario['id']);

$pdo->beginTransaction();

try {
    $stmtCheck = $pdo->prepare('SELECT estado FROM postulaciones WHERE id = :id FOR UPDATE');
    $stmtCheck->execute(['id' => $postulacionId]);
    $postulacion = $stmtCheck->fetch();

    if (!$postulacion) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }
    if ($postulacion['estado'] !== 'Pendiente') {
        throw new RuntimeException('La postulación ya no está en estado Pendiente.|409');
    }

    // v10.5: el cargo real recién se valida y se fija acá -- antes de
    // esto la postulación apuntaba al cargo interno "Por asignar".
    $stmtCargo = $pdo->prepare('SELECT id, cupos_activos FROM cargos WHERE id = :id AND activo = 1 FOR UPDATE');
    $stmtCargo->execute(['id' => $cargoId]);
    $cargo = $stmtCargo->fetch();
    if (!$cargo) {
        throw new RuntimeException('El cargo seleccionado no existe.|404');
    }
    if ((int)$cargo['cupos_activos'] <= 0) {
        throw new RuntimeException('Ese cargo ya no tiene cupos disponibles. Elige otro.|409');
    }

    // v10.14 (pedido explícito del usuario, nota de mano "Mejorar Post.
    // Piloto": "cuando el capataz arrastra un postulante a un cargo
    // disponible, se debe restar de los disponibles"): antes, el cupo
    // recién se descontaba mucho más tarde -- cuando el JAO cerraba el
    // contrato, días después (ver el trigger `trg_postulaciones_
    // descuenta_cupo`, ahora retirado). Eso significaba que, mientras
    // tanto, el mismo cupo podía "prestarse" a más de una persona: el
    // Capataz seguía viendo el cupo disponible y podía arrastrar a
    // alguien más antes de que el primero llegara a Contratado. Ahora se
    // reserva/descuenta AQUÍ MISMO, en el momento de la selección --
    // terreno/deshacer_seleccion.php lo devuelve si el Capataz se
    // equivoca de cargo.
    $stmtDescontar = $pdo->prepare('UPDATE cargos SET cupos_activos = cupos_activos - 1 WHERE id = :id AND cupos_activos > 0');
    $stmtDescontar->execute(['id' => $cargoId]);
    if ($stmtDescontar->rowCount() === 0) {
        throw new RuntimeException('Ese cargo ya no tiene cupos disponibles. Elige otro.|409');
    }

    fijarUsuarioContextoBD($pdo, $usuario['id']);
    $stmt = $pdo->prepare(
        'UPDATE postulaciones SET estado = "Pre_aprobado_terreno", cargo_id = :cargo_id
          WHERE id = :id AND estado = "Pendiente"'
    );
    $stmt->execute(['cargo_id' => $cargoId, 'id' => $postulacionId]);

    // v10.7: recién ahora el postulante recibe su primer correo para
    // seguir avanzando -- el link de Etapa 2 (datos personales + subir
    // documentos), para completarlo ahí mismo en la sala de espera.
    otorgarAccesoEtapa2($pdo, $postulacionId, $usuario['id']);

    // v10.9: y también el QR de "ingreso a faena" -- el que Portería
    // escanea para dejarlo pasar a la sala de espera a llenar esos
    // datos. Antes de esto no tenía ninguna forma de que Portería lo
    // dejara entrar.
    try {
        notificarIngresoFaena($pdo, $postulacionId);
    } catch (\Throwable $e) {
        error_log('notificarIngresoFaena error: ' . $e->getMessage());
    }

    // v10.13: aviso temprano al JAO ("viene en camino") -- el rol de
    // Admin_Contrato ya terminó su parte al aprobar los cupos, así que
    // el JAO se entera directamente acá, no por una autorización manual
    // aparte por cada postulante (ver notificarSeleccionAJao()).
    try {
        notificarSeleccionAJao($pdo, $postulacionId);
    } catch (\Throwable $e) {
        error_log('notificarSeleccionAJao error: ' . $e->getMessage());
    }

    $pdo->commit();
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('terreno/aprobar error: ' . $e->getMessage());
    responderError('No fue posible aprobar la postulación.', 500);
}

responderOk(['mensaje' => 'Seleccionado. Ya puede completar su Etapa 2.']);
