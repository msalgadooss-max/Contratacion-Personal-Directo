<?php
/**
 * v10.49 - Panel "Kits de EPP" de Prevención: catálogo de elementos, kit base y diferencias por cargo.
 * GET. Devuelve todo lo necesario para pintar el editor (una sola consulta).
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

$items = $pdo->query('SELECT id, nombre, categoria, tipo_talla, activo FROM epp_items ORDER BY orden, id')->fetchAll();
foreach ($items as &$i) {
    $i['id'] = (int)$i['id'];
    $i['activo'] = (bool)$i['activo'];
}
unset($i);

$cargos = $pdo->query('SELECT id, nombre_cargo FROM cargos WHERE activo = 1 ORDER BY nombre_cargo')->fetchAll();
$base = [];
$porCargo = [];
foreach ($pdo->query('SELECT cargo_id, item_id, cantidad FROM epp_kit_cargo') as $r) {
    if ((int)$r['cargo_id'] === 0) {
        $base[(int)$r['item_id']] = (int)$r['cantidad'];
    } else {
        $porCargo[(int)$r['cargo_id']][(int)$r['item_id']] = (int)$r['cantidad'];
    }
}
foreach ($cargos as &$c) {
    $c['id'] = (int)$c['id'];
    $c['personalizado'] = isset($porCargo[$c['id']]);
}
unset($c);

responderOk([
    'disponible' => true,
    'items' => $items,
    'cargos' => $cargos,
    'base' => (object)$base,
    'por_cargo' => (object)$porCargo,
]);
