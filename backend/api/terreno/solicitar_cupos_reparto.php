<?php
/**
 * v10.41 (pedido explícito del usuario) - El Profesional de Terreno REPARTE cupos de un
 * cargo entre varios Capataces de su equipo en un solo envío (ej. "3 para el Capataz A y 2
 * para el B"). Crea una solicitud por Capataz (la misma estructura de solicitar_cupo.php),
 * así el Administrador las aprueba una a una y cada Capataz recibe los suyos reservados.
 * Una línea sin capataz_id es cupo general del equipo. Todo o nada (transacción).
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
$lineas = $body['reparto'] ?? [];
if ($cargoId <= 0 || !is_array($lineas) || !$lineas) {
    responderError('Elige un cargo y la cantidad para cada Capataz.', 422);
}

$pdo = obtenerConexion();
if (!usaCuposPorCapataz($pdo)) {
    responderError('Los cupos por capataz se activan al aplicar la actualización de la base de datos.', 409);
}
$stmt = $pdo->prepare('SELECT nombre_cargo FROM cargos WHERE id = :id AND activo = 1');
$stmt->execute(['id' => $cargoId]);
$nombreCargo = $stmt->fetchColumn();
if ($nombreCargo === false) {
    responderError('El cargo seleccionado no existe.', 404);
}

$stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE rol = 'Capataz' AND activo = 1 AND jefe_terreno_id = :pt");
$stmt->execute(['pt' => $usuario['id']]);
$equipo = [];
foreach ($stmt->fetchAll() as $c) {
    $equipo[(int)$c['id']] = $c['nombre'];
}

$validas = [];
$vistos = [];
$total = 0;
foreach ($lineas as $l) {
    $cant = (int)($l['cantidad'] ?? 0);
    if ($cant <= 0) {
        continue;                       // las filas en blanco o en 0 se ignoran
    }
    if ($cant > 500) {
        responderError('Cada cantidad debe estar entre 1 y 500.', 422);
    }
    $capId = (int)($l['capataz_id'] ?? 0);
    if ($capId > 0 && !isset($equipo[$capId])) {
        responderError('Uno de los Capataces no está en tu equipo.', 403);
    }
    if (isset($vistos[$capId])) {
        responderError('Un Capataz aparece más de una vez en el reparto.', 422);
    }
    $vistos[$capId] = true;
    $validas[] = ['cap' => $capId > 0 ? $capId : null, 'cant' => $cant];
    $total += $cant;
}
if (!$validas) {
    responderError('Escribe al menos una cantidad mayor a 0.', 422);
}

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare('INSERT INTO solicitudes_cupo (cargo_id, cantidad, usuario_id, capataz_id, estado) VALUES (:c, :q, :u, :cap, "Pendiente")');
    foreach ($validas as $v) {
        $ins->execute(['c' => $cargoId, 'q' => $v['cant'], 'u' => $usuario['id'], 'cap' => $v['cap']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('solicitar_cupos_reparto error: ' . $e->getMessage());
    responderError('No fue posible enviar el reparto.', 500);
}

$partes = array_map(fn ($v) => $v['cant'] . ' para ' . ($v['cap'] !== null ? $equipo[$v['cap']] : 'todo el equipo'), $validas);
$mensaje = "Reparto enviado: {$total} cupos de \"{$nombreCargo}\" (" . implode('; ', $partes) . '). Quedan pendientes de aprobación del Administrador de Contrato.';
$aviso = mensajeCierreRemuneraciones($pdo);
responderOk(['mensaje' => $aviso !== null ? $mensaje . ' ' . $aviso : $mensaje, 'solicitudes' => count($validas)]);
