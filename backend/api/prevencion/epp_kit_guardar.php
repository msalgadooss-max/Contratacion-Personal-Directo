<?php
/**
 * v10.49 - Guarda el kit de EPP de un cargo (o el KIT BASE con cargo_id = 0).
 *   { cargo_id, items: { "<item_id>": cantidad, ... } }   cantidad 0 / ausente = no lo lleva
 *   { cargo_id, restablecer: true }                       el cargo vuelve a usar solo el kit base
 * Para un cargo solo se guardan las DIFERENCIAS contra el kit base; así, si Prevención cambia el kit
 * base después, todos los cargos sin cambios propios lo heredan.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
requireRol(['Prevencionista']);
exigirModuloActivo(MODULO_PREVENCION_ACTIVO, 'Prevención');
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$cargoId = (int)($body['cargo_id'] ?? -1);
if ($cargoId < 0) {
    responderError('cargo_id inválido.', 422);
}

$pdo = obtenerConexion();
if (!usaKitEpp($pdo)) {
    responderError('Falta aplicar la actualización de base de datos de los kits de EPP (migración v10.49).', 409);
}
if ($cargoId > 0) {
    $existe = $pdo->prepare('SELECT COUNT(*) FROM cargos WHERE id = :id');
    $existe->execute(['id' => $cargoId]);
    if (!(int)$existe->fetchColumn()) {
        responderError('Cargo no encontrado.', 404);
    }
}

$restablecer = !empty($body['restablecer']);
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM epp_kit_cargo WHERE cargo_id = :c')->execute(['c' => $cargoId]);

    if (!$restablecer) {
        $pedido = [];
        foreach ((array)($body['items'] ?? []) as $itemId => $cant) {
            $pedido[(int)$itemId] = max(0, min(99, (int)$cant));
        }
        $validos = array_map('intval', $pdo->query('SELECT id FROM epp_items')->fetchAll(PDO::FETCH_COLUMN));
        $ins = $pdo->prepare('INSERT INTO epp_kit_cargo (cargo_id, item_id, cantidad) VALUES (:c, :i, :n)');
        if ($cargoId === 0) {
            foreach ($validos as $itemId) {
                if (($pedido[$itemId] ?? 0) > 0) {
                    $ins->execute(['c' => 0, 'i' => $itemId, 'n' => $pedido[$itemId]]);
                }
            }
        } else {
            $base = [];
            foreach ($pdo->query('SELECT item_id, cantidad FROM epp_kit_cargo WHERE cargo_id = 0') as $r) {
                $base[(int)$r['item_id']] = (int)$r['cantidad'];
            }
            foreach ($validos as $itemId) {
                $quiere = $pedido[$itemId] ?? 0;
                if ($quiere !== ($base[$itemId] ?? 0)) {
                    $ins->execute(['c' => $cargoId, 'i' => $itemId, 'n' => $quiere]);
                }
            }
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('epp_kit_guardar error: ' . $e->getMessage());
    responderError('No fue posible guardar el kit.', 500);
}

responderOk(['mensaje' => $restablecer ? 'El cargo vuelve a usar el kit base.' : 'Kit guardado.']);
