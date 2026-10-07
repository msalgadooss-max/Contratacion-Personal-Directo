<?php
/**
 * Fase 4 - Dashboard Experto en Prevención.
 * Ve candidatos que ya completaron sus datos privados, pero esta
 * consulta NO trae columnas de datos_contratacion: el prevencionista
 * solo necesita identificar a la persona, no ver AFP/banco/etc.
 *
 * Prevención tiene DOS check, en dos días distintos (pedido explícito
 * del usuario, tras el piloto del 16-09):
 *
 *   1) `postulaciones` -- DÍA DE POSTULACIÓN: después de que el JAO
 *      verificó la identidad (identidad_verificada_at), Prevención marca
 *      la inducción (marcar_induccion.php, estado Aprobado_admin ->
 *      Induccion_ok). Ahí termina el día y el postulante recibe el
 *      correo "preséntate mañana a las 8".
 *   2) `irl_pendientes` -- DÍA DE CONTRATACIÓN (8 am): después de que el
 *      JAO firmó el contrato (contrato_firmado_at), Prevención hace la
 *      IRL y la marca (marcar_irl.php). Recién ahí Bodega puede
 *      entregar el kit.
 *
 * v10.21: los cursos del catálogo (cursos_induccion) ya no se consultan
 * ni se muestran -- quedan desactivados/ocultos por ahora (las tablas y
 * endpoints siguen existiendo, por si se retoma la idea de las cápsulas).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Prevencionista']);
exigirModuloActivo(MODULO_PREVENCION_ACTIVO, 'Prevención');
exigirMetodo('GET');

$pdo = obtenerConexion();

$stmtInduccion = $pdo->query(
    'SELECT p.id, p.rut, p.nombre_completo, c.nombre_cargo, p.actualizado_at
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE p.estado = "Aprobado_admin" AND p.identidad_verificada_at IS NOT NULL
      ORDER BY p.actualizado_at ASC'
);

$stmtIrl = $pdo->prepare(
    'SELECT p.id, p.rut, p.nombre_completo, c.nombre_cargo, p.contrato_firmado_at
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE p.estado = "Induccion_ok"
        AND p.contrato_firmado_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM trazabilidad_logs t
             WHERE t.postulacion_id = p.id AND t.accion = :accion_irl
        )
      ORDER BY p.contrato_firmado_at ASC'
);
$stmtIrl->execute(['accion_irl' => ACCION_IRL_REALIZADA]);

responderOk([
    'postulaciones' => $stmtInduccion->fetchAll(),
    'irl_pendientes' => $stmtIrl->fetchAll(),
]);
