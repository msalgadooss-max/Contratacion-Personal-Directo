<?php
/**
 * Fase 5 - Dashboard Jefe de Bodega.
 * Necesita las tallas para preparar el kit de EPP, por lo que SI hace
 * join con datos_contratacion, pero con allowlist explícita de
 * columnas: solo las 3 tallas. Nunca se seleccionan afp/banco/etc,
 * aunque esas columnas existan en la misma fila.
 *
 * v7: Bodega ve a la persona apenas Prevención hace la inducción (día de
 * postulación) -- así arma el kit con anticipación.
 *
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09): el
 * botón de entrega se habilita recién cuando se cumplieron DOS cosas del
 * día de contratación, en este orden: el JAO firmó el contrato Y
 * Prevención registró la IRL (segundo check, ver prevencion/marcar_irl.php).
 * `espera` le dice al frontend qué falta ('firma', 'irl' o null).
 * Además devuelve `liberados_pendientes`: los trabajadores que Bodega ya
 * liberó y que todavía no fueron incluidos en una nómina enviada por
 * correo (ver enviar_nomina.php).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Jefe_Bodega']);
exigirModuloActivo(MODULO_BODEGA_ACTIVO, 'Bodega');
exigirMetodo('GET');

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    'SELECT p.id, p.rut, p.nombre_completo, c.nombre_cargo, p.contrato_firmado_at,
            d.talla_calzado, d.talla_overol,
            (SELECT COUNT(*) FROM trazabilidad_logs t
              WHERE t.postulacion_id = p.id AND t.accion = :accion_irl) > 0 AS irl_realizada
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
       JOIN datos_contratacion d ON d.postulacion_id = p.id
      WHERE p.estado = "Induccion_ok"
      ORDER BY p.actualizado_at ASC'
);
$stmt->execute(['accion_irl' => ACCION_IRL_REALIZADA]);

$postulaciones = array_map(function ($p) {
    $firmado = $p['contrato_firmado_at'] !== null;
    $irl = (bool)$p['irl_realizada'];
    $p['irl_realizada'] = $irl;
    $p['puede_entregar'] = $firmado && $irl;
    $p['espera'] = !$firmado ? 'firma' : (!$irl ? 'irl' : null);
    return $p;
}, $stmt->fetchAll());

// v10.22: las tallas pendientes son un extra del panel -- si esa consulta
// fallara, el resto del panel (entrega de EPP, nómina) debe seguir andando.
try {
    $tallasPendientes = tallasSinEnviar($pdo);
} catch (\Throwable $e) {
    error_log('bodega/listar tallasSinEnviar error: ' . $e->getMessage());
    $tallasPendientes = [];
}

responderOk([
    'postulaciones' => $postulaciones,
    'liberados_pendientes' => liberadosSinNomina($pdo),
    'tallas_pendientes' => $tallasPendientes,
]);
