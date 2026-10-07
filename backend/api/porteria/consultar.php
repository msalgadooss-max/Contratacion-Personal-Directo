<?php
/**
 * v4 - Consulta autenticada para el rol Porteria: mismo resultado
 * minimo que porteria/validar.php (nombre, RUT, cargo, estado de
 * acceso), pero detrás de login -- para cuando el guardia prefiere
 * escribir el RUT + código a mano en su propio dashboard en vez de
 * escanear el QR del postulante. Nunca toca datos_contratacion.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rut.php';

iniciarSesionSegura();
requireRol(['Porteria']);
exigirMetodo('POST');

$body = leerJsonBody();
$rutCrudo = trim((string)($body['rut'] ?? ''));
$codigo = strtoupper(limpiarTexto($body['codigo'] ?? '', 10));

if ($rutCrudo === '' || $codigo === '') {
    responderError('RUT y código de seguimiento son obligatorios.', 422);
}

$rutNormalizado = normalizarRut($rutCrudo);

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    'SELECT p.nombre_completo, p.rut, p.estado, p.identidad_verificada_at, c.nombre_cargo
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE (p.rut = :rut_crudo OR p.rut = :rut_norm) AND p.codigo_seguimiento = :codigo
      LIMIT 1'
);
$stmt->execute(['rut_crudo' => $rutCrudo, 'rut_norm' => $rutNormalizado, 'codigo' => $codigo]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    responderError('No se encontró ninguna credencial con esos datos.', 404);
}

// v7: el JAO ya firma el contrato el día 2 ANTES de que Bodega entregue
// el EPP (que es lo que deja el estado en 'Contratado'), así que a
// esta altura el trámite administrativo ya terminó -- lo único que
// falta es que Capataz/Jefe_Terreno lo vengan a buscar. 'Proceso_completo'
// (recepción ya confirmada) sigue autorizado para entrar a la obra.
$autorizado = in_array($postulacion['estado'], ['EPP_listo', 'Contratado', 'Proceso_completo'], true);
$mensaje = null;
if ($autorizado) {
    $mensaje = in_array($postulacion['estado'], ['Contratado', 'Proceso_completo'], true)
        ? 'Proceso de contratación completado. Ingreso a la obra autorizado -- su Capataz o Jefe de Terreno lo viene a buscar.'
        : 'Ingreso permitido a la obra.';
} elseif (in_array($postulacion['estado'], ['Aprobado_admin', 'Induccion_ok'], true) && $postulacion['identidad_verificada_at'] !== null) {
    // v10.21: mismo caso que porteria/validar.php -- a las 8 am del día
    // de contratación vuelve para firmar contrato, IRL y entrega de EPP;
    // ya está en 'Induccion_ok' y antes aparecía "NO AUTORIZADO".
    $autorizado = true;
    $mensaje = 'Se presenta hoy para su proceso de contratación (firma de contrato, IRL y entrega de EPP) -- puede pasar a sala de espera.';
}

responderOk([
    'nombre_completo' => $postulacion['nombre_completo'],
    'rut'             => $postulacion['rut'],
    'cargo'           => $postulacion['nombre_cargo'],
    'estado_acceso'   => $autorizado ? 'AUTORIZADO' : 'NO AUTORIZADO',
    'mensaje'         => $mensaje,
]);
