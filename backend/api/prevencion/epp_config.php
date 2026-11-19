<?php
/**
 * v10.49 - Panel "Kits de EPP" de Prevención: catálogo de elementos, kit base y diferencias por cargo.
 * GET. Devuelve todo lo necesario para pintar el editor (una sola consulta).
 * v10.51: cada fila del kit trae cantidad ("q") y color ("c", solo elementos con colores); el catálogo
 * trae "colores" e "icono".
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Prevencionista']);
exigirModuloActivo(MODULO_PREVENCION_ACTIVO, 'Prevención');
exigirMetodo('GET');

$pdo = obtenerConexion();
if (!usaKitEpp($pdo)) {
    responderOk(['disponible' => false, 'items' => [], 'cargos' => [], 'base' => new stdClass(), 'por_cargo' => new stdClass()]);
}

$conColor = usaColorEpp($pdo);
$items = $pdo->query($conColor
    ? 'SELECT id, nombre, categoria, tipo_talla, colores, icono, activo FROM epp_items ORDER BY orden, id'
    : "SELECT id, nombre, categoria, tipo_talla, 0 AS colores, 'extra' AS icono, activo FROM epp_items ORDER BY orden, id"
)->fetchAll();
foreach ($items as &$i) {
    $i['id'] = (int)$i['id'];
    $i['activo'] = (bool)$i['activo'];
    $i['colores'] = (int)$i['colores'] === 1;
}
unset($i);

$cargos = $pdo->query('SELECT id, nombre_cargo FROM cargos WHERE activo = 1 ORDER BY nombre_cargo')->fetchAll();
$base = [];
$porCargo = [];
$filas = $pdo->query($conColor ? 'SELECT cargo_id, item_id, cantidad, color FROM epp_kit_cargo'
                               : 'SELECT cargo_id, item_id, cantidad, NULL AS color FROM epp_kit_cargo');
foreach ($filas as $r) {
    $fila = ['q' => (int)$r['cantidad'], 'c' => colorEppValido($r['color'])];
    if ((int)$r['cargo_id'] === 0) {
        $base[(int)$r['item_id']] = $fila;
    } else {
        $porCargo[(int)$r['cargo_id']][(int)$r['item_id']] = $fila;
    }
}
foreach ($cargos as &$c) {
    $c['id'] = (int)$c['id'];
    $c['personalizado'] = isset($porCargo[$c['id']]);
}
unset($c);

responderOk([
    'disponible' => true,
    'colores_disponibles' => $conColor,
    'items' => $items,
    'cargos' => $cargos,
    'base' => (object)$base,
    'por_cargo' => (object)$porCargo,
]);
