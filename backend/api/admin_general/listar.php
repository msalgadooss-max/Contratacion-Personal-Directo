<?php
/**
 * Dashboard Jefe Administrativo (JAO).
 *
 * v7: el JAO tiene ahora DOS acciones separadas sobre la misma persona
 * -- verificar documentos (día 1, requiere que Portería ya confirmó el
 * ingreso a faena) y firmar el contrato (día 2, requiere que Prevención
 * ya hizo la IRL). Por eso este listado muestra a TODOS los que están
 * en cualquiera de los dos tramos ('Aprobado_admin' o 'Induccion_ok') y
 * todavía no firman contrato -- visibilidad completa desde el
 * comienzo, aunque la acción de cada uno esté bloqueada hasta que
 * corresponda (ver puede_verificar / puede_firmar en cada fila).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Jefe_Administrativo']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$stmt = $pdo->query(
    'SELECT p.id, p.tipo_documento, p.rut, p.nombre_completo, p.telefono, p.correo, p.comuna,
            p.estado, p.ingreso_faena_at, p.contrato_firmado_at,
            p.identidad_verificada_at, uv.nombre AS identidad_verificada_por_nombre,
            c.nombre_cargo,
            d.fecha_nacimiento, d.estado_civil, d.sexo, d.nacionalidad, d.direccion_exacta,
            d.region, d.comuna AS comuna_etapa2, d.ciudad, d.pais,
            d.afp, d.afp_alerta_jao, d.isapre_fonasa, d.estudios, d.banco, d.tipo_cuenta, d.numero_cuenta,
            d.contacto_emergencia_nombre, d.contacto_emergencia_telefono,
            d.talla_calzado, d.talla_overol,
            j.id AS datos_jao_id
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
       JOIN datos_contratacion d ON d.postulacion_id = p.id
       LEFT JOIN datos_jao j ON j.postulacion_id = p.id
       LEFT JOIN usuarios uv ON uv.id = p.identidad_verificada_por
      WHERE p.estado IN (\'Aprobado_admin\', \'Induccion_ok\')
        AND p.contrato_firmado_at IS NULL
      ORDER BY p.actualizado_at ASC'
);
$postulaciones = $stmt->fetchAll();

$idsMostrados = array_column($postulaciones, 'id');
$documentosPorPostulacion = [];
if ($idsMostrados) {
    $in = implode(',', array_fill(0, count($idsMostrados), '?'));
    // v5: se trae el detalle de rechazo de cada documento (no solo el
    // tipo) para que el dashboard muestre el estado de observación por
    // documento y el motivo indicado por el JAO.
    $stmtDocs = $pdo->prepare(
        "SELECT postulacion_id, tipo, rechazado_at, motivo_rechazo, resubido_at
           FROM postulacion_documentos WHERE postulacion_id IN ($in)"
    );
    $stmtDocs->execute($idsMostrados);
    foreach ($stmtDocs->fetchAll() as $fila) {
        $documentosPorPostulacion[$fila['postulacion_id']][] = [
            'tipo'            => $fila['tipo'],
            'observado'       => $fila['rechazado_at'] !== null && $fila['resubido_at'] === null,
            'motivo_rechazo'  => $fila['motivo_rechazo'],
            'resubido'        => $fila['resubido_at'] !== null,
        ];
    }
}
foreach ($postulaciones as &$p) {
    $detalleDocs = $documentosPorPostulacion[$p['id']] ?? [];
    $p['documentos'] = array_column($detalleDocs, 'tipo');
    $p['documentos_detalle'] = $detalleDocs;
    $tieneDocumentoObservado = (bool)array_filter($detalleDocs, fn ($d) => $d['observado']);
    $p['tiene_documento_observado'] = $tieneDocumentoObservado;
    $p['tiene_datos_jao'] = $p['datos_jao_id'] !== null;
    $p['afp_alerta_jao'] = (bool)$p['afp_alerta_jao'];
    $p['identidad_verificada'] = $p['identidad_verificada_at'] !== null;
    $p['ingreso_faena_confirmado'] = $p['ingreso_faena_at'] !== null;

    // v7: qué acción corresponde mostrarle al JAO para esta fila.
    // v9.2: en Etapa 1 del piloto (MODULO_PREVENCION_ACTIVO=false),
    // "Induccion_ok" nunca se alcanza -- basta con 'Aprobado_admin'.
    $estadoParaFirmar = MODULO_PREVENCION_ACTIVO ? 'Induccion_ok' : 'Aprobado_admin';
    $p['puede_verificar'] = $p['estado'] === 'Aprobado_admin'
        && $p['ingreso_faena_at'] !== null
        && $p['identidad_verificada_at'] === null;
    $p['puede_firmar'] = $p['estado'] === $estadoParaFirmar
        && $p['identidad_verificada_at'] !== null
        && !$tieneDocumentoObservado
        && $p['tiene_datos_jao'];

    unset($p['datos_jao_id']);
}
unset($p);

responderOk([
    'postulaciones' => $postulaciones,
    'cierre_remuneraciones_activo' => cierreRemuneracionesActivo($pdo),
    // v9.2: para que el dashboard sepa si está en Etapa 1 del piloto
    // (Prevención inactiva -- no hace falta esperar 'Induccion_ok').
    'modulo_prevencion_activo' => MODULO_PREVENCION_ACTIVO,
]);
