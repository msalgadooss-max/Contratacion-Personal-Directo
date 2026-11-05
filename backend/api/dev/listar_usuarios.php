<?php
/**
 * v5 - Panel de Desarrollador: lista los usuarios internos activos
 * (sin contraseña) para poder "entrar como" cualquiera de ellos y
 * revisar su dashboard sin tener que pedir cada clave por separado.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('GET');

// v10.14 (pedido explícito del usuario): "me aparece aún en el rol
// entrega EPP, ese sácalo" -- Prevención y Bodega no participan en esta
// etapa del piloto (ver MODULO_PREVENCION_ACTIVO/MODULO_BODEGA_ACTIVO),
// así que no tiene sentido ofrecerlos como opción para "entrar como" y
// hacer parecer que son un paso más del proceso a probar.
$rolesExcluidos = ["'Desarrollador'"];
if (!MODULO_PREVENCION_ACTIVO) {
    $rolesExcluidos[] = "'Prevencionista'";
}
if (!MODULO_BODEGA_ACTIVO) {
    $rolesExcluidos[] = "'Jefe_Bodega'";
}
// v10.27: para cambiar claves se necesitan TODOS los usuarios activos
// (también Desarrollador y los roles de módulos aún apagados).
if (($_GET['todos'] ?? '') === '1') {
    $rolesExcluidos = ["'__ninguno__'"];
}

$pdo = obtenerConexion();
$stmt = $pdo->query(
    "SELECT id, nombre, correo, rol
       FROM usuarios
      WHERE activo = 1 AND rol NOT IN (" . implode(',', $rolesExcluidos) . ")
      ORDER BY FIELD(rol, 'Desarrollador','Jefe_Terreno','Capataz','Admin_Contrato','Jefe_Administrativo','Prevencionista','Jefe_Bodega','Porteria','Gerencia'), nombre"
);

responderOk(['usuarios' => $stmt->fetchAll()]);
