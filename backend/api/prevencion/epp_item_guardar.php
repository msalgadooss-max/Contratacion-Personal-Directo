<?php
/**
 * v10.49 - Crea o edita un elemento del catálogo de EPP (nombre, categoría, si usa talla, activo).
 * No se borran elementos (podrían estar en kits ya entregados): se desactivan.
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
$id = (int)($body['id'] ?? 0);
$nombre = limpiarTexto((string)($body['nombre'] ?? ''), 120);
$categoria = limpiarTexto((string)($body['categoria'] ?? ''), 40) ?: 'General';
$tipoTalla = (string)($body['tipo_talla'] ?? 'ninguna');
$activo = array_key_exists('activo', $body) ? (int)(bool)$body['activo'] : 1;

if ($nombre === '' || mb_strlen($nombre) < 3) {
    responderError('Escribe el nombre del elemento (al menos 3 letras).', 422);
}
if (!in_array($tipoTalla, ['ninguna', 'calzado', 'overol'], true)) {
    responderError('Tipo de talla inválido.', 422);
}

$pdo = obtenerConexion();
if (!usaKitEpp($pdo)) {
    responderError('Falta aplicar la actualización de base de datos de los kits de EPP (migración v10.49).', 409);
}

try {
    if ($id > 0) {
        $pdo->prepare('UPDATE epp_items SET nombre = :n, categoria = :c, tipo_talla = :t, activo = :a WHERE id = :id')
            ->execute(['n' => $nombre, 'c' => $categoria, 't' => $tipoTalla, 'a' => $activo, 'id' => $id]);
    } else {
        $orden = (int)$pdo->query('SELECT COALESCE(MAX(orden), 0) + 10 FROM epp_items')->fetchColumn();
        $pdo->prepare('INSERT INTO epp_items (nombre, categoria, tipo_talla, activo, orden) VALUES (:n, :c, :t, :a, :o)')
            ->execute(['n' => $nombre, 'c' => $categoria, 't' => $tipoTalla, 'a' => $activo, 'o' => $orden]);
        $id = (int)$pdo->lastInsertId();
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        responderError('Ya existe un elemento con ese nombre.', 409);
    }
    error_log('epp_item_guardar error: ' . $e->getMessage());
    responderError('No fue posible guardar el elemento.', 500);
}

responderOk(['id' => $id, 'mensaje' => 'Elemento guardado.']);
