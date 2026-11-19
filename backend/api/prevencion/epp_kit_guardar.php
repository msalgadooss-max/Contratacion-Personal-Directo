<?php
/**
 * v10.49 - Guarda el kit de EPP de un cargo (o el KIT BASE con cargo_id = 0).
 *   { cargo_id, items: { "<item_id>": {q: cantidad, c: "rojo"|"amarillo"|"azul"|null}, ... } }
 *       cantidad 0 / ausente = no lo lleva. (Por compatibilidad también se acepta "<item_id>": cantidad.)
 *   { cargo_id, restablecer: true }   el cargo vuelve a usar solo el kit base
 * Para un cargo solo se guardan las DIFERENCIAS contra el kit base (otra cantidad, otro color, uno extra
 * o uno quitado); así, si Prevención cambia el kit base después, todos los cargos sin cambios propios lo heredan.
 * v10.51: el color solo vale en elementos que vienen en colores (epp_items.colores = 1).
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

$conColor = usaColorEpp($pdo);
$restablecer = !empty($body['restablecer']);
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM epp_kit_cargo WHERE cargo_id = :c')->execute(['c' => $cargoId]);

    if (!$restablecer) {
        // Catálogo: qué elementos existen y cuáles vienen en colores.
        $catalogo = [];
        foreach ($pdo->query($conColor ? 'SELECT id, colores FROM epp_items' : 'SELECT id, 0 AS colores FROM epp_items') as $r) {
            $catalogo[(int)$r['id']] = (int)$r['colores'] === 1;
        }
        // Lo pedido, normalizado: [item_id => ['q' => n, 'c' => color|null]]
        $pedido = [];
        foreach ((array)($body['items'] ?? []) as $itemId => $v) {
            $itemId = (int)$itemId;
            if (!isset($catalogo[$itemId])) {
                continue;
            }
            $q = is_array($v) ? (int)($v['q'] ?? 0) : (int)$v;
            $c = (is_array($v) && $catalogo[$itemId]) ? colorEppValido($v['c'] ?? null) : null;
            $pedido[$itemId] = ['q' => max(0, min(99, $q)), 'c' => $c];
        }

        $sqlIns = $conColor ? 'INSERT INTO epp_kit_cargo (cargo_id, item_id, cantidad, color) VALUES (:c, :i, :n, :col)'
                            : 'INSERT INTO epp_kit_cargo (cargo_id, item_id, cantidad) VALUES (:c, :i, :n)';
        $ins = $pdo->prepare($sqlIns);
        $guardar = function (int $itemId, array $fila) use ($ins, $conColor, $cargoId) {
            $params = ['c' => $cargoId, 'i' => $itemId, 'n' => $fila['q']];
            if ($conColor) {
                $params['col'] = $fila['c'];
            }
            $ins->execute($params);
        };

        if ($cargoId === 0) {
            foreach ($pedido as $itemId => $fila) {
                if ($fila['q'] > 0) {
                    $guardar($itemId, $fila);
                }
            }
        } else {
            $base = [];
            $selBase = $conColor ? 'SELECT item_id, cantidad, color FROM epp_kit_cargo WHERE cargo_id = 0'
                                 : 'SELECT item_id, cantidad, NULL AS color FROM epp_kit_cargo WHERE cargo_id = 0';
            foreach ($pdo->query($selBase) as $r) {
                $base[(int)$r['item_id']] = ['q' => (int)$r['cantidad'], 'c' => colorEppValido($r['color'])];
            }
            foreach (array_keys($catalogo) as $itemId) {
                $quiere = $pedido[$itemId] ?? ['q' => 0, 'c' => null];
                $heredado = $base[$itemId] ?? ['q' => 0, 'c' => null];
                $igual = $quiere['q'] === $heredado['q'] && ($quiere['q'] === 0 || $quiere['c'] === $heredado['c']);
                if (!$igual) {
                    $guardar($itemId, $quiere);
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
