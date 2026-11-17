<?php
/**
 * v10.40 - Cupos que ve un Capataz para seleccionar: por cada cargo, los que tiene
 * RESERVADOS para él (los pidió su Profesional de Terreno) y los generales (los que
 * quedan sin reservar para nadie). Un Capataz solo puede seleccionar para un cargo
 * mientras tenga de alguno de los dos (ver terreno/aprobar.php).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Capataz']);
exigirMetodo('GET');

$pdo = obtenerConexion();
responderOk(['cargos' => cuposDelCapataz($pdo, (int)$usuario['id']), 'obra' => OBRA_NOMBRE]);
