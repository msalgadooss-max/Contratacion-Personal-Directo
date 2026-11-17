<?php
/**
 * v7 - Cierre operativo del proceso completo (reunión Ricardo, 31-ago).
 * Lista a quienes Bodega ya marcó 'Contratado' (le entregó su kit de
 * EPP) y todavía nadie fue a buscar. Jefe_Terreno y Capataz ven la
 * misma lista -- cualquiera de los dos puede hacer el recuento y
 * confirmar que se los llevó al frente de trabajo.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno', 'Capataz']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$stmt = $pdo->query(
    'SELECT p.id, p.rut, p.nombre_completo, c.nombre_cargo, p.actualizado_at
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE p.estado = "Contratado" AND p.recibido_terreno_at IS NULL'
      . ($usuario['rol'] === 'Jefe_Terreno' ? condicionGentePT($pdo, (int)$usuario['id']) : condicionGenteCapataz($pdo, (int)$usuario['id'])) . '
      ORDER BY p.actualizado_at ASC'
);

responderOk(['postulaciones' => $stmt->fetchAll()]);
