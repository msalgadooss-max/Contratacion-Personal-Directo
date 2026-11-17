<?php
/**
 * v6.9 - Solicitudes de cupo de Jefe_Terreno pendientes de aprobación.
 * Ver admin_contrato/solicitudes_cupo_aprobar.php y .../_rechazar.php.
 * Una solicitud aprobada es lo que en la reunión con Ricardo (28-ago) se
 * llamó "vacante": recién ahí se abren cupos reales para ese cargo.
 *
 * v6.10 - LEFT JOIN (antes era JOIN): una solicitud por un cargo nuevo
 * (cargo_id NULL, cargo_nuevo_nombre con texto) no tiene fila en cargos
 * todavía -- se crea recién al aprobar.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Admin_Contrato']);
exigirMetodo('GET');

$pdo = obtenerConexion();
// v10.40: a que Capataz va dirigida la solicitud (si la migracion ya esta aplicada).
$conCap = usaCuposPorCapataz($pdo);
$selCap = $conCap ? 'capu.nombre' : 'NULL';
$joinCap = $conCap ? 'LEFT JOIN usuarios capu ON capu.id = s.capataz_id' : '';
$stmt = $pdo->query(
    "SELECT s.id, s.cantidad, s.creado_at, {$selCap} AS para_capataz_nombre,
            COALESCE(c.nombre_cargo, s.cargo_nuevo_nombre) AS nombre_cargo,
            (s.cargo_id IS NULL) AS es_cargo_nuevo,
            u.nombre AS solicitado_por_nombre
       FROM solicitudes_cupo s
       LEFT JOIN cargos c ON c.id = s.cargo_id
       LEFT JOIN usuarios u ON u.id = s.usuario_id
       {$joinCap}
      WHERE s.estado = 'Pendiente'
      ORDER BY s.creado_at ASC"
);

$solicitudes = array_map(function ($s) {
    $s['es_cargo_nuevo'] = (bool)$s['es_cargo_nuevo'];
    return $s;
}, $stmt->fetchAll());

responderOk(['solicitudes' => $solicitudes]);
