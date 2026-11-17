<?php
/**
 * v6.9 - Historial de las solicitudes de cupo que ha hecho ESTE
 * Jefe_Terreno, con su estado (Pendiente/Aprobada/Rechazada) -- para que
 * sepa si el Administrador de Contrato ya resolvió su pedido, sin tener
 * que preguntar. Ver admin_contrato/solicitudes_cupo_aprobar.php y
 * .../solicitudes_cupo_rechazar.php.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$conCap = usaCuposPorCapataz($pdo);
$stmt = $pdo->prepare(
    'SELECT ' . ($conCap ? 'capu.nombre' : 'NULL') . ' AS para_capataz_nombre, s.id, s.cantidad, s.estado, s.motivo_rechazo, s.creado_at, s.resuelta_at,
            COALESCE(c.nombre_cargo, s.cargo_nuevo_nombre) AS nombre_cargo,
            (s.cargo_id IS NULL AND s.estado = "Pendiente") AS es_cargo_nuevo,
            uR.nombre AS resuelta_por_nombre
       FROM solicitudes_cupo s
       LEFT JOIN cargos c ON c.id = s.cargo_id
       LEFT JOIN usuarios uR ON uR.id = s.resuelta_por
       ' . ($conCap ? 'LEFT JOIN usuarios capu ON capu.id = s.capataz_id' : '') . '
      WHERE s.usuario_id = :uid
      ORDER BY s.creado_at DESC
      LIMIT 50'
);
$stmt->execute(['uid' => $usuario['id']]);

$solicitudes = array_map(function ($s) {
    $s['es_cargo_nuevo'] = (bool)$s['es_cargo_nuevo'];
    return $s;
}, $stmt->fetchAll());

responderOk(['solicitudes' => $solicitudes]);
