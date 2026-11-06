<?php
/**
 * v10.31 (pedido explícito del usuario) - Historial de un trabajador: todas
 * sus contrataciones anteriores (mismo RUT), con cargo, fechas, código de ficha
 * y la evaluación de recontratación que dejó el Jefe de Terreno.
 * Lo ven el JAO y el Jefe de Terreno (el comentario completo es solo para
 * ellos). Recibe el id de CUALQUIER postulación de esa persona.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Jefe_Administrativo', 'Jefe_Terreno']);
exigirMetodo('GET');

$postulacionId = (int)($_GET['postulacion_id'] ?? 0);
if ($postulacionId <= 0) {
    responderError('postulacion_id inválido.', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare('SELECT rut, nombre_completo FROM postulaciones WHERE id = :id');
$stmt->execute(['id' => $postulacionId]);
$persona = $stmt->fetch();
if (!$persona) {
    responderError('Postulación no encontrada.', 404);
}

$sql = "SELECT p.id, p.creado_at, p.estado, c.nombre_cargo, j.codigo_ficha,
               %s
          FROM postulaciones p
          JOIN cargos c ON c.id = p.cargo_id
          LEFT JOIN datos_jao j ON j.postulacion_id = p.id
          %s
         WHERE p.rut = :rut
         ORDER BY p.creado_at DESC, p.id DESC";
try {
    $stmt = $pdo->prepare(sprintf(
        $sql,
        'e.recomienda, e.comentario, e.fecha_termino, u.nombre AS evaluado_por_nombre',
        'LEFT JOIN evaluaciones_trabajador e ON e.postulacion_id = p.id LEFT JOIN usuarios u ON u.id = e.evaluado_por'
    ));
    $stmt->execute(['rut' => $persona['rut']]);
} catch (PDOException $e) {
    $stmt = $pdo->prepare(sprintf(
        $sql,
        'NULL AS recomienda, NULL AS comentario, NULL AS fecha_termino, NULL AS evaluado_por_nombre',
        ''
    ));
    $stmt->execute(['rut' => $persona['rut']]);
}

responderOk([
    'nombre_completo' => $persona['nombre_completo'],
    'rut' => $persona['rut'],
    'contrataciones' => $stmt->fetchAll(),
]);
