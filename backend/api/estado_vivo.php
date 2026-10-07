<?php
/**
 * v6.5 - "Estado en vivo" / "Estado del proceso": una vista compacta,
 * igual para todos los roles internos, que muestra en qué fase visual
 * está cada trabajador activo (estilo rastreo de pedido). No expone
 * NINGÚN dato sensible de datos_contratacion -- solo nombre, cargo y
 * una etiqueta de fase, así que es seguro mostrarlo tal cual en
 * Terreno, Admin_Contrato, JAO y Gerencia por igual. También alimenta
 * la pestaña "Estado del proceso" de Admin_Contrato (misma data, vista
 * en tabla completa en vez de widget resumido).
 *
 * La fase se calcula, no se guarda: combina el `estado` con si ya
 * existe su fila en datos_contratacion (Etapa 2 completada o no).
 *
 * v10.14: Admin_Contrato ya no autoriza postulación por postulación
 * (su rol termina al aprobar cupos, ver solicitudes_cupo_aprobar.php)
 * ni el Jefe de Terreno pre-filtra (el Capataz selecciona directo, ver
 * terreno/aprobar.php) -- se retiraron ambos hitos sintéticos de este
 * archivo, que habían quedado desactualizados tras esos cambios.
 *
 * v10.10: se agrega el `estado` crudo a la respuesta (antes solo se
 * exponía la fase ya traducida a texto) para que el frontend pueda
 * decidir localmente si mostrar acciones que dependen del estado
 * exacto -- ej. "Deshacer selección" del Capataz, solo mientras sigue
 * en 'Pre_aprobado_terreno' (ver terreno/deshacer_seleccion.php).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Terreno', 'Capataz', 'Admin_Contrato', 'Prevencionista', 'Jefe_Bodega', 'Jefe_Administrativo', 'Gerencia']);
exigirMetodo('GET');

$pdo = obtenerConexion();
$stmt = $pdo->query(
    "SELECT p.id, p.nombre_completo, p.estado, p.contrato_firmado_at, p.identidad_verificada_at, c.nombre_cargo,
            (SELECT COUNT(*) FROM trazabilidad_logs t
              WHERE t.postulacion_id = p.id AND t.accion = " . $pdo->quote(ACCION_IRL_REALIZADA) . ") > 0 AS irl_realizada,
            (SELECT COUNT(*) FROM trazabilidad_logs tp
              WHERE tp.postulacion_id = p.id AND tp.accion = " . $pdo->quote(ACCION_INGRESO_CONTRATACION) . ") > 0 AS paso_autorizado,
            (SELECT COUNT(*) FROM datos_contratacion d WHERE d.postulacion_id = p.id) > 0 AS etapa2_completada,
            (SELECT COUNT(*) FROM postulacion_documentos pd
              WHERE pd.postulacion_id = p.id AND pd.rechazado_at IS NOT NULL AND pd.resubido_at IS NULL) > 0 AS documento_observado
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE p.estado NOT IN ('Rechazado', 'En_banco')
      ORDER BY p.actualizado_at DESC
      LIMIT 30"
);
$filas = $stmt->fetchAll();

// v10.14 (pedido explícito del usuario, tras ver este mismo panel
// desactualizado en plena prueba): este archivo no se había tocado en
// el resto de la sesión, y seguía mostrando dos hitos que ya no existen
// -- "Aprobado por Jefe de Terreno" (aprobado_jt_at, retirado en
// terreno/aprobar.php) y "Autorización del Administrador de Contrato"
// (admin_autorizado_at, retirado en terreno/aprobar.php v10.13). Como
// admin_autorizado_at ya NUNCA se vuelve a fijar para una postulación
// nueva, ese paso quedaba marcado "pendiente" para siempre y el widget
// seguía apuntando a Admin_Contrato como si tuviera algo que aprobar
// ahí -- exactamente el mismo problema de "sin acción de avanzar" que
// ya se corrigió para el módulo de inducción.
function faseVisual(array $p): string
{
    $completo = (bool)$p['etapa2_completada'];

    // v5: un documento observado (rechazado por el JAO, aún sin
    // corregir) manda por sobre la fase normal -- es lo mas urgente que
    // hay que saber sobre esta persona en este momento.
    if ((bool)$p['documento_observado']) {
        return 'Documento observado, esperando corrección del postulante';
    }

    return match ($p['estado']) {
        'Pendiente' => 'Postulación recibida, esperando selección del Capataz',
        'Pre_aprobado_terreno' => $completo
            ? 'Datos completos, a punto de pasar a revisión del Jefe Administrativo'
            : 'Seleccionado, completando datos y documentos (Etapa 2)',
        // v10.21: refleja los DOS días reales -- día de postulación (JAO
        // verifica -> Prevención marca la inducción -> "preséntate mañana")
        // y día de contratación a las 8 am (JAO firma -> Prevención marca
        // la IRL -> Bodega entrega el kit).
        'Aprobado_admin' => $p['identidad_verificada_at'] !== null
            ? 'Identidad verificada, esperando la inducción de Prevención'
            : 'En revisión Jefe Administrativo (verificación de documentos)',
        'Induccion_ok' => match (true) {
            $p['contrato_firmado_at'] === null && !(bool)$p['paso_autorizado'] => 'Inducción lista, vuelve a las 8 am (Portería debe autorizar su paso)',
            $p['contrato_firmado_at'] === null => 'Paso autorizado, esperando la firma de contrato (JAO)',
            !(bool)$p['irl_realizada'] => 'Contrato firmado, esperando la IRL con Prevención',
            default => 'IRL realizada, esperando la entrega del kit en Bodega',
        },
        'EPP_listo' => 'Kit de EPP listo, cierre final',
        'Contratado' => '✔ Contratado, esperando que lo vengan a buscar',
        'Proceso_completo' => '✔ Recibido en terreno -- proceso completo',
        default => $p['estado'],
    };
}

/**
 * v6 - Rol interno que tiene la "pelota" en este momento (o null si a
 * nadie del staff le toca actuar, ej. se espera al postulante). Sirve
 * para que, cuando el que mira el widget es justo ese rol, se le pueda
 * marcar "pendiente en tu bandeja" en vez de un genérico "en revisión".
 */
function rolPendiente(array $p): ?string
{
    if ((bool)$p['documento_observado']) {
        return null; // se espera al postulante, no a un rol interno
    }

    return match ($p['estado']) {
        'Pendiente' => 'Capataz',
        // v10.14: mientras se espera que el propio postulante complete
        // su Etapa 2, ningún rol interno tiene una acción pendiente acá
        // -- Admin_Contrato ya no autoriza uno por uno.
        'Pre_aprobado_terreno' => null,
        // v10.21: día de postulación: JAO verifica -> Prevención (inducción).
        // Día de contratación: JAO firma -> Prevención (IRL) -> Bodega.
        'Aprobado_admin' => $p['identidad_verificada_at'] !== null ? 'Prevencionista' : 'Jefe_Administrativo',
        'Induccion_ok' => match (true) {
            $p['contrato_firmado_at'] === null && !(bool)$p['paso_autorizado'] => 'Porteria',
            $p['contrato_firmado_at'] === null => 'Jefe_Administrativo',
            !(bool)$p['irl_realizada'] => 'Prevencionista',
            default => 'Jefe_Bodega',
        },
        'EPP_listo' => 'Jefe_Bodega',
        default => null,
    };
}

/**
 * v4 - Línea de progreso ("inicio y meta") para el detalle interactivo
 * del widget: mismos pasos que ve el propio postulante en
 * seguimiento.js::timelineHtml(), calculados aquí para no exponer otro
 * endpoint nuevo ni duplicar la consulta.
 */
function pasosProgreso(array $p): array
{
    $etiquetas = [
        'Pendiente' => 'Postulación recibida',
        'Pre_aprobado_terreno' => 'Seleccionado por el Capataz',
        'Aprobado_admin' => 'En revisión Jefe Administrativo',
        'Induccion_ok' => 'Inducción de seguridad',
        'EPP_listo' => 'Kit de EPP listo',
        'Contratado' => 'Contratado -- EPP entregado',
        'Proceso_completo' => 'Recibido en terreno -- proceso completo',
    ];
    // v10.14: en Etapa 1 del piloto (MODULO_PREVENCION_ACTIVO=false),
    // firmar_contrato.php salta directo de 'Aprobado_admin' a
    // 'Contratado' -- mostrar "Inducción de seguridad"/"Kit de EPP
    // listo" como pasos por venir es engañoso, porque nadie los va a
    // marcar mientras Prevención y Bodega sigan sin participar en la
    // app. Si una postulación vieja igual quedó en alguno de esos dos
    // estados, se usa la lista completa para no dejarla sin ningún paso
    // marcado.
    $orden = MODULO_PREVENCION_ACTIVO
        ? ordenEstadosActivos()
        : ['Pendiente', 'Pre_aprobado_terreno', 'Aprobado_admin', 'Contratado', 'Proceso_completo'];
    if (!in_array($p['estado'], $orden, true)) {
        $orden = ordenEstadosActivos();
    }
    $idxActual = array_search($p['estado'], $orden, true);
    $completo = (bool)$p['etapa2_completada'];

    $pasos = [];
    foreach ($orden as $idx => $estado) {
        $pasos[] = ['etiqueta' => $etiquetas[$estado] ?? $estado, 'completado' => $idxActual !== false && $idx <= $idxActual];
        // v10.14: el Jefe de Terreno ya no aprueba (el Capataz filtra
        // directo cualquier 'Pendiente', ver terreno/aprobar.php) y
        // Admin_Contrato ya no autoriza postulación por postulación (su
        // rol termina al aprobar cupos) -- se retiran esos dos hitos
        // sintéticos. Solo queda "Datos completados" como sub-paso real
        // dentro de Etapa 2.
        if ($estado === 'Pre_aprobado_terreno') {
            $yaSuperado = $idxActual !== false && $idx < $idxActual;
            $pasos[] = ['etiqueta' => 'Datos completados por el postulante', 'completado' => $completo || $yaSuperado];
        }
    }
    return $pasos;
}

$resultado = array_map(function ($p) use ($usuario) {
    return [
        'id' => (int)$p['id'],
        'nombre_completo' => $p['nombre_completo'],
        'nombre_cargo' => $p['nombre_cargo'],
        'estado' => $p['estado'],
        'fase' => faseVisual($p),
        'contratado' => in_array($p['estado'], ['Contratado', 'Proceso_completo'], true),
        'pasos' => pasosProgreso($p),
        // v6: true si el rol que está mirando el widget es justo el que
        // tiene que actuar ahora sobre esta persona.
        'pendiente_de_ti' => rolPendiente($p) === $usuario['rol'],
    ];
}, $filas);

responderOk(['trabajadores' => $resultado]);
