<?php
/**
 * v4 - Lista de cargos activos con sus cupos actuales, para que
 * Jefe_Terreno vea el estado de dotacion antes de solicitar mas cupos.
 * No expone nada de datos_contratacion; solo la tabla cargos.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$stmt = $pdo->query(
    'SELECT id, nombre_cargo, cupos_totales, cupos_activos
       FROM cargos
      WHERE activo = 1
      ORDER BY nombre_cargo ASC'
);

$cargos = $stmt->fetchAll();

// v10.40: los Capataces de su equipo (para pedir cupos "para" uno) y los cupos que cada uno
// tiene reservados hoy.
$miEquipo = [];
$reservados = [];
if (usaCuposPorCapataz($pdo)) {
    $colFrente = usuariosTieneFrente($pdo) ? 'frente' : 'NULL';
    $stmtEq = $pdo->prepare("SELECT id, nombre, {$colFrente} AS frente FROM usuarios WHERE rol = 'Capataz' AND activo = 1 AND jefe_terreno_id = :pt ORDER BY nombre");
    $stmtEq->execute(['pt' => $usuario['id']]);
    $miEquipo = $stmtEq->fetchAll();
    $stmtRes = $pdo->prepare(
        "SELECT u.id AS capataz_id, u.nombre AS capataz, c.nombre_cargo, cc.reservados
           FROM cupos_capataz cc
           JOIN usuarios u ON u.id = cc.capataz_id
           JOIN cargos c ON c.id = cc.cargo_id
          WHERE u.jefe_terreno_id = :pt AND cc.reservados > 0
          ORDER BY u.nombre, c.nombre_cargo"
    );
    $stmtRes->execute(['pt' => $usuario['id']]);
    $reservados = $stmtRes->fetchAll();
}

responderOk(['cargos' => $cargos, 'obra' => OBRA_NOMBRE, 'mi_equipo' => $miEquipo, 'reservados' => $reservados]);
