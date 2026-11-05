<?php
/**
 * v3 - Expone las listas desplegables (Buk) y el mapa Región→Comuna al
 * frontend. Publico y sin datos sensibles: son catalogos fijos, no
 * informacion de ninguna persona.
 *
 * v10.5: se agrega el nombre de la obra (antes venia en
 * cargos_disponibles.php, que dejo de llamarse desde el formulario
 * publico al quitarse la eleccion de cargo -- Mejorar APP, punto 2).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/listas_buk.php';

exigirMetodo('GET');

// v10.19 (pedido explícito del usuario, tras la reunión con la obra del
// 16-09): lista de Capataces activos para que el postulante pueda
// declarar en Etapa 1 quién lo está esperando ("match"). Solo nombre e
// id -- ningún dato sensible, es exactamente lo mismo que vería
// cualquiera parado en la entrada de la obra.
$pdo = obtenerConexion();
$capataces = $pdo->query(
    "SELECT id, nombre, jefe_terreno_id FROM usuarios WHERE rol = 'Capataz' AND activo = 1 ORDER BY nombre ASC"
)->fetchAll();

// v10.28: si la persona llega por el enlace de un referente (?ref=<id>),
// se devuelve quién la invitó (solo el nombre) y la lista de Capataces
// se ajusta:
//   - referente Capataz       -> la lista queda solo con él (ya está decidido)
//   - referente Jefe de Terreno -> solo los Capataces de su equipo (si tiene
//                                  alguno configurado; si no, todos)
$referente = null;
$refId = (int)($_GET['ref'] ?? 0);
if ($refId > 0) {
    $stmtRef = $pdo->prepare(
        "SELECT id, nombre, rol FROM usuarios WHERE id = :id AND activo = 1 AND rol IN ('Capataz', 'Jefe_Terreno')"
    );
    $stmtRef->execute(['id' => $refId]);
    $ref = $stmtRef->fetch();
    if ($ref) {
        $referente = ['nombre' => $ref['nombre'], 'rol' => $ref['rol']];
        if ($ref['rol'] === 'Capataz') {
            $capataces = array_values(array_filter($capataces, fn ($c) => (int)$c['id'] === (int)$ref['id']));
        } else {
            $delEquipo = array_values(array_filter($capataces, fn ($c) => (int)$c['jefe_terreno_id'] === (int)$ref['id']));
            if ($delEquipo) {
                $capataces = $delEquipo;
            }
        }
    }
}
$capataces = array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre']], $capataces);

responderOk([
    'listas' => listasBuk(),
    'regiones_comunas' => regionesConComunas(),
    'obra' => OBRA_NOMBRE,
    'capataces' => $capataces,
    'referente' => $referente,
]);
