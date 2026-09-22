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
$stmtCapataces = $pdo->query(
    "SELECT id, nombre FROM usuarios WHERE rol = 'Capataz' AND activo = 1 ORDER BY nombre ASC"
);

responderOk([
    'listas' => listasBuk(),
    'regiones_comunas' => regionesConComunas(),
    'obra' => OBRA_NOMBRE,
    'capataces' => $stmtCapataces->fetchAll(),
]);
