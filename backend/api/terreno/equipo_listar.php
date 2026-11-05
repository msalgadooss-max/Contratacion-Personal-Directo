<?php
/**
 * v10.28 - "Mi equipo": el Jefe de Terreno ve los Capataces que tiene a
 * su cargo (organigrama) y los que todavía no están asignados a nadie.
 * Cada Capataz trae cuántas personas esperan que él las seleccione y
 * cuántas ya seleccionó hoy -- para ver de un vistazo cómo va cada uno.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno']);
exigirMetodo('GET');

$pdo = obtenerConexion();

$stmt = $pdo->query(
    "SELECT u.id, u.nombre, u.correo, u.jefe_terreno_id, jt.nombre AS jefe_terreno_nombre,
            (SELECT COUNT(*) FROM postulaciones p
              WHERE p.estado = 'Pendiente' AND p.capataz_esperado_id = u.id) AS esperando_referidos,
            (SELECT COUNT(*) FROM trazabilidad_logs t
              WHERE t.usuario_id = u.id AND t.accion LIKE 'Cambio de estado: % -> Pre_aprobado_terreno'
                AND DATE(t.fecha_hora) = CURDATE()) AS seleccionados_hoy
       FROM usuarios u
       LEFT JOIN usuarios jt ON jt.id = u.jefe_terreno_id
      WHERE u.rol = 'Capataz' AND u.activo = 1
      ORDER BY u.nombre ASC"
);

$mios = [];
$disponibles = [];
$deOtros = [];
foreach ($stmt->fetchAll() as $c) {
    $c['id'] = (int)$c['id'];
    $c['esperando_referidos'] = (int)$c['esperando_referidos'];
    $c['seleccionados_hoy'] = (int)$c['seleccionados_hoy'];
    if ($c['jefe_terreno_id'] === null) {
        $disponibles[] = $c;
    } elseif ((int)$c['jefe_terreno_id'] === (int)$usuario['id']) {
        $mios[] = $c;
    } else {
        $deOtros[] = $c;
    }
}

responderOk([
    'jefe' => ['id' => (int)$usuario['id'], 'nombre' => $usuario['nombre']],
    'mis_capataces' => $mios,
    'disponibles' => $disponibles,
    'de_otro_jefe' => $deOtros,
]);
