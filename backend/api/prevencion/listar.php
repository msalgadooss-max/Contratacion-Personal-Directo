<?php
/**
 * Fase 4 - Dashboard Experto en Prevención.
 * Ve candidatos que ya completaron sus datos privados, pero esta
 * consulta NO trae columnas de datos_contratacion: el prevencionista
 * solo necesita identificar a la persona para dictar la charla ODI,
 * no ver AFP/banco/etc.
 *
 * v7: candado -- solo se ven (y se puede actuar sobre) postulantes a
 * quienes el JAO YA verificó la identidad (día 1). Antes filtraba por
 * el estado 'Datos_completados', que en la práctica nunca se alcanzaba
 * (el flujo secuencial deja el estado en 'Aprobado_admin' hasta que
 * Prevención marca la IRL) -- este cambio corrige ese bug de paso, y de
 * paso agrega el candado que pidió Ricardo.
 *
 * v9: reemplaza el conteo de "videos vistos" por el catálogo completo
 * de cursos -- cuántos aprobó, cuántos tiene pendientes de revisión
 * (ya envió su evaluación).
 *
 * v10.20 (pedido explícito del usuario): marcar_induccion.php ya NO
 * exige que todos los cursos estén Aprobados (no hay forma real de
 * rendirlos en este piloto) -- puede_marcar_induccion queda siempre en
 * true para cualquiera que aparezca en esta lista (ya pasó el candado
 * real, que es identidad_verificada_at). Los cursos siguen viéndose,
 * solo que ya no bloquean.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Prevencionista']);
exigirModuloActivo(MODULO_PREVENCION_ACTIVO, 'Prevención');
exigirMetodo('GET');

$pdo = obtenerConexion();
$stmt = $pdo->query(
    'SELECT p.id, p.rut, p.nombre_completo, c.nombre_cargo, p.actualizado_at,
            (SELECT COUNT(*) FROM cursos_induccion WHERE activo = 1) AS cursos_total,
            (SELECT COUNT(*) FROM postulacion_cursos pc
               JOIN cursos_induccion ci ON ci.id = pc.curso_id AND ci.activo = 1
              WHERE pc.postulacion_id = p.id AND pc.estado = "Aprobado") AS cursos_aprobados,
            (SELECT COUNT(*) FROM postulacion_cursos pc
               JOIN cursos_induccion ci ON ci.id = pc.curso_id AND ci.activo = 1
              WHERE pc.postulacion_id = p.id AND pc.enviado_at IS NOT NULL AND pc.estado = "Pendiente") AS cursos_por_revisar
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE p.estado = "Aprobado_admin" AND p.identidad_verificada_at IS NOT NULL
      ORDER BY p.actualizado_at ASC'
);
$postulaciones = array_map(function ($p) {
    $p['puede_marcar_induccion'] = true;
    return $p;
}, $stmt->fetchAll());

responderOk(['postulaciones' => $postulaciones]);
