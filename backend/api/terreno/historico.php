<?php
/**
 * v3.2 - Histórico de Jefe de Terreno: "Postulantes" (antes "Personal
 * Aprobado en Proceso") y "Personal Contratado". Se puede filtrar por
 * rango de fechas y por quién seleccionó (útil cuando haya más de un
 * Capataz).
 *
 * "Quién seleccionó" y "cuándo" no son columnas propias: se leen de
 * trazabilidad_logs, tomando la PRIMERA vez que la postulación entró a
 * 'Pre_aprobado_terreno'.
 *
 * v10.14 (pedido explícito del usuario, item 5 de la lista post-prueba):
 * quien hace esa transición ahora es siempre el Capataz (Jefe_Terreno
 * ya no aprueba nada) -- el filtro "aprobado por" y su lista de
 * nombres pasan de Jefe_Terreno a Capataz.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
// v10.14 (pedido explícito del usuario, item 9): el Capataz también
// usa este endpoint (solo vista=contratados) para su propia pestaña
// "Personal Contratado".
$usuario = requireRol(['Jefe_Terreno', 'Capataz']);
exigirMetodo('GET');

$vista = $_GET['vista'] ?? 'en_proceso';
if (!in_array($vista, ['en_proceso', 'contratados'], true)) {
    responderError('Vista inválida.', 422);
}

$desde = limpiarTexto($_GET['desde'] ?? '', 10);
$hasta = limpiarTexto($_GET['hasta'] ?? '', 10);
$aprobadoPor = (int)($_GET['aprobado_por'] ?? 0);

$pdo = obtenerConexion();

$estadosEnProceso = ['Pre_aprobado_terreno', 'Datos_completados', 'Aprobado_admin', 'Induccion_ok', 'EPP_listo'];
// v7: 'Proceso_completo' (Contratado + recepción en terreno confirmada)
// sigue siendo "Personal Contratado" para esta pestaña.
$condicionEstado = $vista === 'contratados'
    ? 'p.estado IN ("Contratado", "Proceso_completo")'
    : 'p.estado IN (' . implode(',', array_fill(0, count($estadosEnProceso), '?')) . ')';

$params = $vista === 'contratados' ? [] : $estadosEnProceso;

$sql = "SELECT p.id, p.tipo_documento, p.rut, p.nombre_completo, p.comuna, p.estado,
               c.nombre_cargo,
               ap.fecha_hora AS fecha_aprobacion, ap.usuario_id AS aprobado_por_id,
               u.nombre AS aprobado_por_nombre
          FROM postulaciones p
          JOIN cargos c ON c.id = p.cargo_id
          JOIN (
                SELECT postulacion_id,
                       MIN(fecha_hora) AS fecha_hora,
                       SUBSTRING_INDEX(GROUP_CONCAT(usuario_id ORDER BY fecha_hora SEPARATOR ','), ',', 1) AS usuario_id
                  FROM trazabilidad_logs
                 WHERE accion IN (
                        'Cambio de estado: Pendiente -> Pre_aprobado_terreno',
                        'Cambio de estado: En_banco -> Pre_aprobado_terreno'
                       )
                 GROUP BY postulacion_id
               ) ap ON ap.postulacion_id = p.id
          LEFT JOIN usuarios u ON u.id = ap.usuario_id
         WHERE $condicionEstado";

if ($desde !== '') {
    $sql .= ' AND ap.fecha_hora >= ?';
    $params[] = $desde . ' 00:00:00';
}
if ($hasta !== '') {
    $sql .= ' AND ap.fecha_hora <= ?';
    $params[] = $hasta . ' 23:59:59';
}
if ($aprobadoPor > 0) {
    $sql .= ' AND ap.usuario_id = ?';
    $params[] = $aprobadoPor;
}
// v10.40 (pedido explicito del usuario): cada Profesional de Terreno ve solo a SU gente
// (las personas cuyo capataz es de su equipo).
if ($usuario['rol'] === 'Jefe_Terreno') {
    $sql .= condicionGentePT($pdo, (int)$usuario['id']);
} else {
    // v10.41: el Capataz ve solo a su cuadrilla (las personas que él seleccionó).
    $sql .= condicionGenteCapataz($pdo, (int)$usuario['id']);
}
$sql .= ' ORDER BY ap.fecha_hora DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$stmtCapataces = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'Capataz' AND activo = 1"
    . ($usuario['rol'] === 'Jefe_Terreno' && usaCuposPorCapataz($pdo) ? ' AND jefe_terreno_id = ' . (int)$usuario['id'] : '') . ' ORDER BY nombre');

responderOk([
    'postulaciones' => $stmt->fetchAll(),
    'jefes_terreno' => $stmtCapataces->fetchAll(),
]);
