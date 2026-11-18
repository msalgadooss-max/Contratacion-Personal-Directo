<?php
/**
 * v10.30 - Panel de Desarrollador: lista de TODOS los usuarios internos
 * (activos e inactivos) para administrarlos: crear, editar, desactivar.
 * Nunca devuelve la contraseña.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$colFrente = usuariosTieneFrente($pdo) ? 'u.frente' : 'NULL';
// v10.48: quién todavía tiene la clave temporal (no la ha cambiado). A prueba de bases sin la migración v10.31.
try {
    $pdo->query('SELECT debe_cambiar_password FROM usuarios LIMIT 1');
    $colTemporal = 'u.debe_cambiar_password';
} catch (PDOException $e) {
    $colTemporal = '0';
}
$stmt = $pdo->query(
    "SELECT u.id, u.nombre, u.correo, u.rol, u.activo, u.creado_at, u.jefe_terreno_id, {$colFrente} AS frente, {$colTemporal} AS clave_temporal, jt.nombre AS jefe_terreno_nombre
       FROM usuarios u
       LEFT JOIN usuarios jt ON jt.id = u.jefe_terreno_id
      ORDER BY u.activo DESC,
               FIELD(u.rol, 'Desarrollador','Jefe_Terreno','Capataz','Admin_Contrato','Jefe_Administrativo','Prevencionista','Jefe_Bodega','Porteria','Gerencia'),
               u.nombre"
);

$usuarios = array_map(function ($u) {
    $u['id'] = (int)$u['id'];
    $u['activo'] = (bool)$u['activo'];
    $u['clave_temporal'] = (bool)$u['clave_temporal'];
    $u['jefe_terreno_id'] = $u['jefe_terreno_id'] !== null ? (int)$u['jefe_terreno_id'] : null;
    return $u;
}, $stmt->fetchAll());

responderOk(['usuarios' => $usuarios]);
