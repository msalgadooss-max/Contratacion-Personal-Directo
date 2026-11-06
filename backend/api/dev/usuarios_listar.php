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
$stmt = $pdo->query(
    "SELECT u.id, u.nombre, u.correo, u.rol, u.activo, u.creado_at, jt.nombre AS jefe_terreno_nombre
       FROM usuarios u
       LEFT JOIN usuarios jt ON jt.id = u.jefe_terreno_id
      ORDER BY u.activo DESC,
               FIELD(u.rol, 'Desarrollador','Jefe_Terreno','Capataz','Admin_Contrato','Jefe_Administrativo','Prevencionista','Jefe_Bodega','Porteria','Gerencia'),
               u.nombre"
);

$usuarios = array_map(function ($u) {
    $u['id'] = (int)$u['id'];
    $u['activo'] = (bool)$u['activo'];
    return $u;
}, $stmt->fetchAll());

responderOk(['usuarios' => $usuarios]);
