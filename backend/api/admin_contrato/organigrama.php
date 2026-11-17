<?php
/**
 * v10.42 (pedido explícito del usuario) - Mapa de la gente del Administrador de Contrato:
 * cada Profesional de Terreno con sus Capataces agrupados por frente, y al mismo nivel
 * Prevención, JAO y Jefe de Bodega (que no tienen equipo a cargo). Solo lectura, sin
 * correos ni datos personales: solo nombres, frentes y cantidades.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Admin_Contrato']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$colFrente = usuariosTieneFrente($pdo) ? 'frente' : 'NULL';

$pts = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'Jefe_Terreno' AND activo = 1 ORDER BY nombre")->fetchAll();
$caps = $pdo->query("SELECT id, nombre, jefe_terreno_id, {$colFrente} AS frente FROM usuarios WHERE rol = 'Capataz' AND activo = 1 ORDER BY nombre")->fetchAll();

// Agrupa por frente (orden natural: 1-A, 1-B, 2-A...), con "sin frente" al final.
$agrupar = static function (array $lista): array {
    $mapa = [];
    foreach ($lista as $c) {
        $f = trim((string)($c['frente'] ?? ''));
        $mapa[$f][] = ['id' => (int)$c['id'], 'nombre' => $c['nombre']];
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

$profesionales = [];
foreach ($pts as $pt) {
    $suyos = array_values(array_filter($caps, static fn ($c) => (int)$c['jefe_terreno_id'] === (int)$pt['id']));
    $profesionales[] = ['id' => (int)$pt['id'], 'nombre' => $pt['nombre'], 'total_capataces' => count($suyos), 'frentes' => $agrupar($suyos)];
}
$sinPt = array_values(array_filter($caps, static fn ($c) => $c['jefe_terreno_id'] === null));

// Mismo nivel que los Profesionales de Terreno: roles sin equipo a cargo.
$etiquetas = ['Prevencionista' => 'Prevención de Riesgos', 'Jefe_Administrativo' => 'JAO · Jefe Administrativo de Obra', 'Jefe_Bodega' => 'Jefe de Bodega'];
$apoyo = [];
foreach ($etiquetas as $rol => $etiqueta) {
    $stmt = $pdo->prepare('SELECT nombre FROM usuarios WHERE rol = :r AND activo = 1 ORDER BY nombre');
    $stmt->execute(['r' => $rol]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nombre) {
        $apoyo[] = ['rol' => $rol, 'etiqueta' => $etiqueta, 'nombre' => $nombre];
    }
}

responderOk([
    'administrador' => ['nombre' => $usuario['nombre']],
    'obra' => OBRA_NOMBRE,
    'profesionales' => $profesionales,
    'sin_profesional' => $agrupar($sinPt),
    'total_sin_profesional' => count($sinPt),
    'apoyo' => $apoyo,
    'total_capataces' => count($caps),
]);
