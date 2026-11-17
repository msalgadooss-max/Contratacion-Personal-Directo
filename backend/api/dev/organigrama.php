<?php
/**
 * v10.39 (pedido explícito del usuario) - Organigrama de terreno para el panel de
 * Desarrollador: cada Profesional de Terreno (rol Jefe_Terreno) con todos sus
 * Capataces, agrupados por frente (los que comparten frente quedan juntos).
 * También devuelve los Capataces que todavía no tienen Profesional de Terreno.
 * Solo lectura.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$colFrente = usuariosTieneFrente($pdo) ? 'u.frente' : 'NULL';

$pts = $pdo->query(
    "SELECT id, nombre, correo FROM usuarios WHERE rol = 'Jefe_Terreno' AND activo = 1 ORDER BY nombre"
)->fetchAll();

$caps = $pdo->query(
    "SELECT u.id, u.nombre, u.correo, u.jefe_terreno_id, {$colFrente} AS frente,
            (SELECT COUNT(*) FROM postulaciones p
              WHERE p.estado = 'Pendiente' AND p.capataz_esperado_id = u.id) AS esperando
       FROM usuarios u
      WHERE u.rol = 'Capataz' AND u.activo = 1
      ORDER BY u.nombre"
)->fetchAll();

// Agrupa por frente respetando el orden en que aparece cada frente (por nombre),
// y deja "sin frente" al final.
$agrupar = static function (array $lista): array {
    $mapa = [];
    foreach ($lista as $c) {
        $f = trim((string)($c['frente'] ?? ''));
        $mapa[$f][] = ['id' => (int)$c['id'], 'nombre' => $c['nombre'], 'correo' => $c['correo'], 'esperando' => (int)$c['esperando']];
    }
    uksort($mapa, static function ($a, $b) {
        if ($a === '' || $b === '') {
            return $a === '' ? 1 : -1;
        }
        return strnatcasecmp($a, $b);
    });
    $out = [];
    foreach ($mapa as $f => $items) {
        $out[] = ['frente' => $f === '' ? null : $f, 'capataces' => $items];
    }
    return $out;
};

$resultado = [];
foreach ($pts as $pt) {
    $suyos = array_values(array_filter($caps, static fn ($c) => (int)$c['jefe_terreno_id'] === (int)$pt['id']));
    $resultado[] = [
        'id' => (int)$pt['id'],
        'nombre' => $pt['nombre'],
        'total_capataces' => count($suyos),
        'frentes' => $agrupar($suyos),
    ];
}
$sinPt = array_values(array_filter($caps, static fn ($c) => $c['jefe_terreno_id'] === null));

responderOk([
    'profesionales' => $resultado,
    'sin_profesional' => $agrupar($sinPt),
    'total_sin_profesional' => count($sinPt),
]);
