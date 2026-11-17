<?php
/**
 * v6.9 - Jefe_Terreno SOLICITA una cantidad de cupos para un cargo
 * especifico (ej. "necesito 8 Jornal Concretero"). Desde la reunion con
 * Ricardo (28-ago): esto YA NO abre cupos de inmediato -- queda
 * "Pendiente" hasta que Admin_Contrato la apruebe (ver
 * admin_contrato/solicitudes_cupo_aprobar.php). Solo una solicitud
 * APROBADA es lo que en esa reunion se llamo "vacante": recien ahi se
 * suma a cupos_totales/cupos_activos del cargo y el postulante puede
 * ver ese cargo con cupos disponibles en el formulario publico.
 *
 * v6.10 - Si el cargo que necesita no esta en el catalogo, puede pedir
 * uno nuevo escribiendo el nombre (cargo_nuevo_nombre) en vez de elegir
 * un cargo_id existente. El cargo real NO se crea aqui -- se crea recien
 * al aprobar (para no llenar el catalogo con propuestas que nunca se
 * aprueban). Si el nombre escrito coincide con uno ya existente
 * (sin importar mayusculas/tildes de caja), se reusa ese cargo en vez de
 * proponer uno duplicado.
 *
 * v10.40 (pedido explicito del usuario): la solicitud puede ir dirigida a un Capataz
 * de su equipo (capataz_id). Al aprobarse, esos cupos quedan RESERVADOS para ese capataz
 * (ver admin_contrato/solicitudes_cupo_aprobar.php y terreno/aprobar.php). Sin capataz_id
 * es un cupo general que cualquiera de los capataces puede usar.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$cargoId = (int)($body['cargo_id'] ?? 0);
$cargoNuevoNombre = limpiarTexto($body['cargo_nuevo_nombre'] ?? '', 100);
$cantidad = (int)($body['cantidad'] ?? 0);
$capatazId = (int)($body['capataz_id'] ?? 0);

if ($cargoId <= 0 && $cargoNuevoNombre === '') {
    responderError('Debes seleccionar un cargo o escribir el nombre de uno nuevo.', 422);
}
if ($cargoId > 0 && $cargoNuevoNombre !== '') {
    responderError('Elige un cargo de la lista o escribe uno nuevo, no ambos.', 422);
}
if ($cantidad <= 0 || $cantidad > 500) {
    responderError('La cantidad de cupos debe ser un número entre 1 y 500.', 422);
}

$pdo = obtenerConexion();

// v10.40: el Capataz destino debe ser de SU equipo.
$nombreCapataz = null;
if ($capatazId > 0) {
    if (!usaCuposPorCapataz($pdo)) {
        responderError('Los cupos por capataz se activan al aplicar la actualización de la base de datos. Por ahora pide el cupo general.', 409);
    }
    $stmtCap = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = :id AND rol = 'Capataz' AND activo = 1 AND jefe_terreno_id = :pt");
    $stmtCap->execute(['id' => $capatazId, 'pt' => $usuario['id']]);
    $nombreCapataz = $stmtCap->fetchColumn();
    if ($nombreCapataz === false) {
        responderError('Ese Capataz no está en tu equipo.', 403);
    }
}
$insertarSolicitud = function (?int $cargoIdSol, ?string $cargoNuevo) use ($pdo, $cantidad, $usuario, $capatazId): void {
    if (usaCuposPorCapataz($pdo)) {
        $pdo->prepare('INSERT INTO solicitudes_cupo (cargo_id, cargo_nuevo_nombre, cantidad, usuario_id, capataz_id, estado) VALUES (:c, :n, :q, :u, :cap, "Pendiente")')
            ->execute(['c' => $cargoIdSol, 'n' => $cargoNuevo, 'q' => $cantidad, 'u' => $usuario['id'], 'cap' => $capatazId > 0 ? $capatazId : null]);
    } else {
        $pdo->prepare('INSERT INTO solicitudes_cupo (cargo_id, cargo_nuevo_nombre, cantidad, usuario_id, estado) VALUES (:c, :n, :q, :u, "Pendiente")')
            ->execute(['c' => $cargoIdSol, 'n' => $cargoNuevo, 'q' => $cantidad, 'u' => $usuario['id']]);
    }
};

if ($cargoId > 0) {
    $stmtCargo = $pdo->prepare('SELECT id, nombre_cargo FROM cargos WHERE id = :id AND activo = 1');
    $stmtCargo->execute(['id' => $cargoId]);
    $cargo = $stmtCargo->fetch();
    if (!$cargo) {
        responderError('El cargo seleccionado no existe.', 404);
    }

    $insertarSolicitud($cargoId, null);
    $nombreParaMensaje = $cargo['nombre_cargo'];
} else {
    // ¿Ya existe un cargo activo con ese mismo nombre (sin distinguir
    // mayúsculas/minúsculas)? Si es así, se reusa en vez de duplicar.
    $stmtExiste = $pdo->prepare('SELECT id, nombre_cargo FROM cargos WHERE activo = 1 AND LOWER(nombre_cargo) = LOWER(:nombre)');
    $stmtExiste->execute(['nombre' => $cargoNuevoNombre]);
    $existente = $stmtExiste->fetch();

    if ($existente) {
        $insertarSolicitud((int)$existente['id'], null);
        $nombreParaMensaje = $existente['nombre_cargo'] . ' (ya existía en el catálogo)';
    } else {
        $insertarSolicitud(null, $cargoNuevoNombre);
        $nombreParaMensaje = $cargoNuevoNombre . ' (cargo nuevo, no está en el catálogo todavía)';
    }
}

// v10.14 (pedido explícito del usuario, item 15): no se bloquea la
// solicitud durante el cierre de remuneraciones -- solo se avisa con
// claridad que los cupos, aunque se aprueben, van a demorar en
// liberarse.
$avisoCierre = mensajeCierreRemuneraciones($pdo);
$paraQuien = $nombreCapataz !== null ? " para el Capataz {$nombreCapataz}" : ' (cupo general de tu equipo)';
$mensaje = "Solicitud de $cantidad cupos para \"$nombreParaMensaje\"{$paraQuien} enviada. Queda pendiente de aprobación del Administrador de Contrato.";
if ($avisoCierre !== null) {
    $mensaje .= ' ' . $avisoCierre;
}

responderOk(['mensaje' => $mensaje]);
