<?php
/**
 * Fase 1 - Dashboard Jefe de Terreno / Capataz.
 * IMPORTANTE: esta consulta NUNCA hace JOIN con datos_contratacion.
 * Solo se ven los datos publicos de la postulacion (los mismos que
 * llenó el postulante en Fase 0).
 *
 * v10.14 (pedido explícito del usuario, item 5 de la lista post-prueba):
 * "el Jefe de Terreno no debe aprobar, el que selecciona es el
 * Capataz" -- se elimina el paso intermedio de "primer filtro"
 * (aprobado_jt_at). Ambos roles ven exactamente la misma lista de
 * postulaciones 'Pendiente' recién llegadas por el QR: el Capataz la
 * usa para arrastrar y seleccionar (ver terreno/aprobar.php), el Jefe
 * de Terreno solo la mira -- su pestaña "Banco de Postulantes" en el
 * frontend la muestra de solo lectura, sin ningún botón de acción.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno', 'Capataz']);
exigirMetodo('GET');

$pdo = obtenerConexion();

// v10.19 (pedido explícito del usuario, tras la reunión con la obra del
// 16-09): "match" declarado por el postulante en Etapa 1 -- se muestra
// el nombre del Capataz esperado (si declaró alguno) para que se
// identifique de inmediato quién lo está esperando.
$stmt = $pdo->prepare(
    "SELECT p.id, p.tipo_documento, p.rut, p.nombre_completo, p.telefono, p.correo, p.comuna,
            c.nombre_cargo, p.creado_at,
            (p.cv_ruta_archivo IS NOT NULL) AS tiene_cv,
            p.experiencia_sin_cv,
            p.capataz_esperado_id,
            cap.nombre AS capataz_esperado_nombre
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
       LEFT JOIN usuarios cap ON cap.id = p.capataz_esperado_id
      WHERE p.estado = 'Pendiente'
      ORDER BY p.creado_at ASC"
);
$stmt->execute();

$postulaciones = array_map(function ($p) {
    $p['tiene_cv'] = (bool)$p['tiene_cv'];
    return $p;
}, $stmt->fetchAll());

responderOk([
    'postulaciones' => $postulaciones,
    'aprobaciones_hoy' => contarAprobacionesHoy($pdo, $usuario['id']),
    'limite_aprobaciones_diarias' => LIMITE_APROBACIONES_DIARIAS_TERRENO,
]);
