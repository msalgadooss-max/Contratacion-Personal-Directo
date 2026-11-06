<?php
/**
 * v10.30 - El Desarrollador edita a un usuario interno: nombre, correo, rol, o
 * lo desactiva / reactiva. No se elimina a nadie (borrarlo dejaría sin dueño
 * su historial de selecciones y firmas): se desactiva, y ya no puede entrar
 * (ni mantener una sesión abierta, ver requireLogin()).
 *
 * Reglas:
 *   - No se puede tocar a un Desarrollador desde aquí (ni a uno mismo).
 *   - Al desactivar a un Capataz, o cambiarle el rol, sus personas que
 *     esperan selección vuelven al banco común (nadie queda atascado) y sale
 *     del equipo de su Jefe de Terreno.
 *   - Al desactivar a un Jefe de Terreno (o cambiarle el rol), sus Capataces
 *     quedan sin asignar.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

iniciarSesionSegura();
$dev = requireRol(['Desarrollador']);
exigirMetodo('POST');
exigirCsrfValido();

$ROLES = [
    'Jefe_Terreno', 'Capataz', 'Admin_Contrato', 'Jefe_Administrativo',
    'Prevencionista', 'Jefe_Bodega', 'Porteria', 'Gerencia',
];

$body = leerJsonBody();
$id = (int)($body['usuario_id'] ?? 0);
if ($id <= 0) {
    responderError('usuario_id inválido.', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare('SELECT id, nombre, correo, rol, activo FROM usuarios WHERE id = :id');
$stmt->execute(['id' => $id]);
$u = $stmt->fetch();
if (!$u) {
    responderError('Usuario no encontrado.', 404);
}
if ($u['rol'] === 'Desarrollador') {
    responderError('Un usuario Desarrollador no se puede editar desde aquí.', 403);
}

$nombre = array_key_exists('nombre', $body) ? limpiarTexto($body['nombre'], 150) : $u['nombre'];
$correo = $u['correo'];
if (array_key_exists('correo', $body)) {
    $correo = filter_var(trim((string)$body['correo']), FILTER_VALIDATE_EMAIL);
    if (!$correo || mb_strlen($correo) > 150) {
        responderError('Escribe un correo válido.', 422);
    }
}
$rol = array_key_exists('rol', $body) ? (string)$body['rol'] : $u['rol'];
$activo = array_key_exists('activo', $body) ? (int)(bool)$body['activo'] : (int)$u['activo'];

if ($nombre === '') {
    responderError('El nombre no puede quedar vacío.', 422);
}
if (!in_array($rol, $ROLES, true)) {
    responderError('Elige un rol válido.', 422);
}
if (strtolower($correo) !== strtolower($u['correo'])) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE LOWER(correo) = LOWER(:correo) AND id <> :id');
    $stmt->execute(['correo' => $correo, 'id' => $id]);
    if ((int)$stmt->fetchColumn() > 0) {
        responderError('Ya existe otro usuario con ese correo.', 409);
    }
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE usuarios SET nombre = :nombre, correo = :correo, rol = :rol, activo = :activo WHERE id = :id');
    $stmt->execute(['nombre' => $nombre, 'correo' => $correo, 'rol' => $rol, 'activo' => $activo, 'id' => $id]);

    $dejaDeSerCapataz = $u['rol'] === 'Capataz' && ($rol !== 'Capataz' || !$activo);
    if ($dejaDeSerCapataz) {
        $pdo->prepare("UPDATE postulaciones SET capataz_esperado_id = NULL WHERE capataz_esperado_id = :id AND estado = 'Pendiente'")
            ->execute(['id' => $id]);
        $pdo->prepare('UPDATE usuarios SET jefe_terreno_id = NULL WHERE id = :id')->execute(['id' => $id]);
    }
    $dejaDeSerJefe = $u['rol'] === 'Jefe_Terreno' && ($rol !== 'Jefe_Terreno' || !$activo);
    if ($dejaDeSerJefe) {
        $pdo->prepare('UPDATE usuarios SET jefe_terreno_id = NULL WHERE jefe_terreno_id = :id')->execute(['id' => $id]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('usuario_actualizar error: ' . $e->getMessage());
    responderError('No fue posible guardar los cambios.', 500);
}

error_log(sprintf('usuario_actualizar: dev #%d modificó usuario #%d (rol %s, activo %d)', $dev['id'], $id, $rol, $activo));
responderOk(['mensaje' => $activo ? 'Cambios guardados.' : 'Usuario desactivado: ya no puede entrar.']);
