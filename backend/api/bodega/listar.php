<?php
/**
 * Fase 5 - Dashboard Jefe de Bodega.
 * Necesita las tallas para preparar el kit de EPP, por lo que SI hace
 * join con datos_contratacion, pero con allowlist explícita de
 * columnas: solo las 3 tallas. Nunca se seleccionan afp/banco/etc,
 * aunque esas columnas existan en la misma fila.
 *
 * v7: Bodega ve a la persona apenas Prevención hace la IRL (día 1) --
 * así arma el kit con anticipación, como pidió Ricardo -- pero recién
 * puede ENTREGARLO cuando el JAO ya firmó el contrato al día siguiente
 * (ver marcar_epp.php). `puede_entregar` le indica al frontend si
 * mostrar el botón activo o solo el aviso de "todavía no firma".
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Jefe_Bodega']);
exigirModuloActivo(MODULO_BODEGA_ACTIVO, 'Bodega');
exigirMetodo('GET');

$pdo = obtenerConexion();
$stmt = $pdo->query(
    'SELECT p.id, p.rut, p.nombre_completo, c.nombre_cargo, p.contrato_firmado_at,
            d.talla_calzado, d.talla_overol
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
       JOIN datos_contratacion d ON d.postulacion_id = p.id
      WHERE p.estado = "Induccion_ok"
      ORDER BY p.actualizado_at ASC'
);

$postulaciones = array_map(function ($p) {
    $p['puede_entregar'] = $p['contrato_firmado_at'] !== null;
    return $p;
}, $stmt->fetchAll());

responderOk(['postulaciones' => $postulaciones]);
