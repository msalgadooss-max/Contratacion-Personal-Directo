<?php
/**
 * v10.33 - Paso 1 de "Borrar datos de prueba": el Desarrollador escribe una lista
 * de RUT (los de prueba) y el sistema MUESTRA qué postulaciones coinciden, sin
 * borrar nada. Cada obra tiene su propia base, así que esto solo ve la obra en
 * la que el Desarrollador está trabajando.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('POST');
exigirCsrfValido();

/** "10.000.001-6", " 10000001-6 " -> "10000001-6". Devuelve [] si no hay ninguno válido. */
function normalizarListaRut(string $texto): array
{
    $partes = preg_split('/[\s,;]+/', strtoupper($texto), -1, PREG_SPLIT_NO_EMPTY);
    $ruts = [];
    foreach ($partes as $p) {
        $p = str_replace('.', '', $p);
        if (preg_match('/^\d{1,9}-[\dK]$/', $p)) {
            $ruts[$p] = true;
        }
    }
    return array_keys($ruts);
}

$body = leerJsonBody();
$ruts = normalizarListaRut((string)($body['ruts'] ?? ''));
if (!$ruts) {
    responderError('Escribe al menos un RUT válido (ej. 10000001-6), uno por línea.', 422);
}
if (count($ruts) > 100) {
    responderError('Son demasiados RUT a la vez (máximo 100).', 422);
}

$pdo = obtenerConexion();
$marcas = implode(',', array_fill(0, count($ruts), '?'));
$stmt = $pdo->prepare(
    "SELECT p.id, p.rut, p.nombre_completo, p.correo, p.estado, p.creado_at, c.nombre_cargo,
            (c.nombre_cargo <> 'Por asignar') AS ocupa_cupo
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE REPLACE(UPPER(p.rut), '.', '') IN ($marcas)
      ORDER BY p.id"
);
$stmt->execute($ruts);
$filas = $stmt->fetchAll();

$encontrados = array_map(fn($f) => strtoupper(str_replace('.', '', $f['rut'])), $filas);
$noEncontrados = array_values(array_diff($ruts, $encontrados));

responderOk([
    'obra'            => OBRA_NOMBRE,
    'postulaciones'   => $filas,
    'no_encontrados'  => $noEncontrados,
    'cupos_a_devolver' => count(array_filter($filas, fn($f) => (int)$f['ocupa_cupo'] === 1)),
]);
