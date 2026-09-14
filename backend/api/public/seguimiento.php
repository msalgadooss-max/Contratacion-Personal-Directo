<?php
/**
 * Modulo de seguimiento publico. El postulante se identifica con
 * RUT + correo (dos datos que solo el conoce, y que ya escribio al
 * postular -- no depende de que haya guardado o recuerde el codigo de
 * 6 caracteres que se le mostro una sola vez). Recibe su estado actual
 * mapeado a una linea de tiempo, sin exponer nunca datos de la tabla
 * datos_contratacion.
 *
 * v10: cambio de codigo_seguimiento -> correo como segundo factor
 * (pedido explicito, pensando en el segundo QR de la sala de espera:
 * la gente siempre sabe su correo, no siempre guarda el codigo). El
 * codigo_seguimiento sigue existiendo y sigue viajando en la respuesta
 * -- lo siguen usando el QR de porteria, el link de induccion y
 * reenviar_etapa2.php, que no cambian.
 *
 * Cuando el estado es 'EPP_listo', 'Contratado' o 'Proceso_completo' se
 * marca autorizado_ingreso=true; el frontend pinta la pantalla en VERDE
 * y genera el QR de portería con esos mismos datos minimos.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rut.php';

exigirMetodo('POST');

$body = leerJsonBody();
$documentoCrudo = trim((string)($body['rut'] ?? ''));
$correo = filter_var(trim((string)($body['correo'] ?? '')), FILTER_VALIDATE_EMAIL);

if ($documentoCrudo === '' || !$correo) {
    responderError('Tu documento y tu correo son obligatorios.', 422);
}

// v3: el formulario no sabe de antemano si es RUT u "Otro" documento,
// asi que se prueban ambas formas contra lo guardado (que para RUT
// siempre quedo normalizado con guion al postular).
$documentoRut = normalizarRut($documentoCrudo);

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    'SELECT p.id, p.nombre_completo, p.rut, p.estado, p.codigo_seguimiento, p.creado_at, p.admin_autorizado_at,
            p.token_privado, p.token_expira_at,
            c.nombre_cargo,
            (SELECT COUNT(*) FROM datos_contratacion d WHERE d.postulacion_id = p.id) > 0 AS etapa2_completada,
            (SELECT COUNT(*) FROM postulacion_documentos pd
              WHERE pd.postulacion_id = p.id AND pd.rechazado_at IS NOT NULL AND pd.resubido_at IS NULL) > 0 AS documento_observado
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE (p.rut = :doc_crudo OR p.rut = :doc_rut) AND LOWER(p.correo) = LOWER(:correo)
      LIMIT 1'
);
$stmt->execute(['doc_crudo' => $documentoCrudo, 'doc_rut' => $documentoRut, 'correo' => $correo]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    responderError('No se encontró una postulación con esos datos.', 404);
}

// v2: orden dinamico segun los modulos realmente activos (ver
// includes/functions.php) -- si Prevencion/Bodega estan pausados, no
// se muestran esos pasos en la linea de tiempo.
$ordenEstados = ordenEstadosActivos();

$estadoActual = $postulacion['estado'];
// v7: 'Proceso_completo' es 'Contratado' + recepción en terreno ya
// confirmada -- sigue siendo un estado autorizado/verde, no debe volver
// a NO-autorizado justo cuando el proceso terminó con éxito.
$autorizadoIngreso = in_array($estadoActual, ['EPP_listo', 'Contratado', 'Proceso_completo'], true);
$enBanco = $estadoActual === 'En_banco';

// v6.6: si al postulante no le llegó (o no encuentra) el correo con el
// enlace de Etapa 2, este mismo módulo de seguimiento -- ya protegido
// por RUT + código de seguimiento -- le permite continuar sin depender
// del correo. "puede_completar_etapa2" marca que ya le corresponde
// (el Capataz ya lo seleccionó y aún no completa sus datos); "url_etapa2"
// solo viene si además tiene un token vigente ahora mismo -- si no,
// el frontend ofrece "generar mi enlace" (ver reenviar_etapa2.php).
//
// v10.7: ya NO depende de admin_autorizado_at -- esa autorización pasó
// a ser un dato interno (ver admin_contrato/autorizar.php) que no debe
// condicionar nada de lo que ve o puede hacer el propio postulante.
$puedeCompletarEtapa2 = $estadoActual === 'Pre_aprobado_terreno'
    && !$postulacion['etapa2_completada'];
$tokenVigente = $postulacion['token_privado'] !== null
    && $postulacion['token_expira_at'] !== null
    && strtotime($postulacion['token_expira_at']) > time();

responderOk([
    'postulacion' => [
        'nombre_completo'    => $postulacion['nombre_completo'],
        'rut'                => $postulacion['rut'],
        'cargo'              => $postulacion['nombre_cargo'],
        'estado'             => $estadoActual,
        'rechazado'          => $estadoActual === 'Rechazado',
        'en_banco'           => $enBanco,
        'retencion_hasta'    => $enBanco ? fechaRetencionBanco($postulacion['creado_at']) : null,
        // v6.5: esta bandera permite iluminar "Datos completados" en la
        // linea de tiempo aunque el `estado` en si todavia no haya
        // llegado a 'Aprobado_admin' (falta que el JAO reciba y procese).
        'etapa2_completada'  => (bool)$postulacion['etapa2_completada'],
        // v6.5: ilumina "Autorización Administrador de contrato" en la
        // linea de tiempo -- en el flujo secuencial esto SIEMPRE se
        // enciende antes que 'etapa2_completada', porque es justo esta
        // autorización la que le entrega al postulante el acceso a
        // Etapa 2 (ver admin_contrato/autorizar.php).
        'admin_autorizado'   => $postulacion['admin_autorizado_at'] !== null,
        // v5: aviso de respaldo por si el correo de rechazo no llega --
        // el link de subsanación real igual se manda solo por correo,
        // por seguridad (no queremos exponerlo a quien solo tiene RUT
        // y codigo_seguimiento, que es informacion mas facil de adivinar).
        'documento_observado' => (bool)$postulacion['documento_observado'],
        'orden_estados'      => $ordenEstados,
        'codigo_seguimiento' => $postulacion['codigo_seguimiento'],
        'autorizado_ingreso' => $autorizadoIngreso,
        'creado_at'          => $postulacion['creado_at'],
        'puede_completar_etapa2' => $puedeCompletarEtapa2,
        'url_etapa2' => ($puedeCompletarEtapa2 && $tokenVigente)
            ? BASE_URL . '/frontend/public/completar.html?token=' . $postulacion['token_privado']
            : null,
        // v6.9: la inducción en video queda disponible apenas el
        // postulante completa su Etapa 2 (no hace falta esperar a que el
        // JAO verifique identidad) -- así puede ir viéndolos en la sala
        // de espera del día 1, antes de presentarse al día 2.
        // v9.2: en Etapa 1 del piloto, Prevención (y el catálogo de
        // cursos) todavía no participa -- no tiene sentido invitar al
        // postulante a un flujo que nadie del otro lado va a revisar.
        // v10.14: se saca la dependencia de admin_autorizado_at -- ese
        // campo ya no se vuelve a fijar desde que Admin_Contrato dejó de
        // autorizar postulación por postulación (ver terreno/aprobar.php
        // v10.13), así que esta condición nunca se cumplía. El equivalente
        // real hoy es haber llegado a 'Aprobado_admin' (o más allá).
        'puede_ver_induccion' => MODULO_PREVENCION_ACTIVO
            && in_array($estadoActual, ['Aprobado_admin', 'Induccion_ok'], true)
            && !$autorizadoIngreso,
    ],
]);
