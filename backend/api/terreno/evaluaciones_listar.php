<?php
/**
 * v10.31 - Jefe de Terreno: trabajadores contratados (para evaluar al
 * finiquitarlos) con la evaluación de recontratación que ya tengan.
 * A prueba de fallos: sin la tabla de evaluaciones (migración sin correr)
 * responde lista vacía con tabla_disponible = false.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Jefe_Terreno']);
exigirMetodo('GET');

$pdo = obtenerConexion();
try {
    $stmt = $pdo->query(
        "SELECT p.id, p.rut, p.nombre_completo, c.nombre_cargo, p.estado,
                COALESCE(p.contrato_firmado_at, p.actualizado_at) AS contratado_at,
                e.recomienda, e.comentario, e.fecha_termino, e.actualizado_at AS evaluado_at,
                u.nombre AS evaluado_por_nombre
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
           LEFT JOIN evaluaciones_trabajador e ON e.postulacion_id = p.id
           LEFT JOIN usuarios u ON u.id = e.evaluado_por
          WHERE p.estado IN ('Contratado', 'Proceso_completo')
          ORDER BY (e.id IS NULL) DESC, contratado_at DESC
          LIMIT 300"
    );
    $trabajadores = $stmt->fetchAll();
    $tabla = true;
} catch (PDOException $e) {
    $trabajadores = [];
    $tabla = false;
}

responderOk(['trabajadores' => $trabajadores, 'tabla_disponible' => $tabla]);
