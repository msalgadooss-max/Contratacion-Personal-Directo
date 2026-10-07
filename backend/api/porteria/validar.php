<?php
/**
 * Fase 6 - Punto de acceso en Portería.
 * El QR mostrado en el propio celular del postulante (cuando su
 * seguimiento está en verde) codifica una URL a esta consulta con
 * rut + codigo_seguimiento. El guardia la escanea con la camara de su
 * telefono (no requiere login ni app especial) y ve solo lo minimo:
 * Nombre, RUT, Cargo y Estado. Nunca datos de datos_contratacion.
 *
 * Al exigir AMBOS valores (rut + codigo) se evita que alguien adivine
 * el estado de otra persona solo con su RUT.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rut.php';

exigirMetodo('GET');

// El QR lo genera nuestro propio seguimiento.js con el valor exacto de
// p.rut tal como quedo guardado -- no hace falta re-normalizar como
// RUT chileno, que ademas rompería documentos tipo 'Otro'.
$rut = trim((string)($_GET['rut'] ?? ''));
$codigo = strtoupper(limpiarTexto($_GET['codigo'] ?? '', 10));

if ($rut === '' || $codigo === '') {
    responderError('Datos de acceso inválidos.', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    'SELECT p.nombre_completo, p.rut, p.estado, p.identidad_verificada_at, c.nombre_cargo
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE p.rut = :rut AND p.codigo_seguimiento = :codigo
      LIMIT 1'
);
$stmt->execute(['rut' => $rut, 'codigo' => $codigo]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    responderError('Credencial no encontrada.', 404);
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
    // v10.21: a las 8 am del día de contratación la persona ya está en
    // 'Induccion_ok' (Prevención marcó su inducción el día anterior), no
    // en 'Aprobado_admin' -- antes quedaba "NO AUTORIZADO" justo a la
    // hora en que vuelve a firmar.
    // v10.14 (pedido explícito del usuario, item 18): día 2 -- ya se
    // presentó el día 1 (identidad verificada) y vuelve para su
    // proceso de contratación (IRL + entrega de EPP). Mismo QR que usó
    // el día 1, distinto mensaje: se le deja pasar igual, aunque el
    // trámite formal todavía no cierre.
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
