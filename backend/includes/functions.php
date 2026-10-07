<?php
/**
 * Helpers comunes a todos los endpoints de la API.
 */

// v9.1: sin esto, mb_substr() y el resto de las funciones mbstring usan
// la codificacion interna por defecto de PHP (no necesariamente UTF-8),
// lo que puede corromper o directamente perder acentos/"ñ" al pasar por
// limpiarTexto() -- detectado probando nombres reales (ej. "José Muñoz")
// antes de pasar el piloto a producción con personas reales.
mb_internal_encoding('UTF-8');

header('Content-Type: application/json; charset=utf-8');

/** Responde JSON y termina la ejecucion. */
function responderJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function responderError(string $mensaje, int $status = 400): never
{
    responderJson(['ok' => false, 'error' => $mensaje], $status);
}

function responderOk(array $data = [], int $status = 200): never
{
    responderJson(array_merge(['ok' => true], $data), $status);
}

/** Lee y decodifica el body JSON de la peticion actual. */
function leerJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        responderError('Cuerpo de la peticion invalido (JSON mal formado).', 400);
    }
    return $data;
}

/** Exige que el metodo HTTP sea el esperado. */
function exigirMetodo(string $metodo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        responderError('Metodo no permitido.', 405);
    }
}

/** Sanitiza un string simple: recorta espacios y limita largo. */
function limpiarTexto(?string $valor, int $maxLargo = 255): string
{
    $valor = trim((string)$valor);
    return mb_substr($valor, 0, $maxLargo);
}

/**
 * Genera un codigo de seguimiento alfanumerico de 6 caracteres, evitando
 * caracteres ambiguos (0/O, 1/I/L), y garantiza que sea unico en la BD.
 */
function generarCodigoSeguimiento(PDO $pdo): string
{
    $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $codigo = '';
        for ($i = 0; $i < CODIGO_SEGUIMIENTO_LARGO; $i++) {
            $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $stmt = $pdo->prepare('SELECT id FROM postulaciones WHERE codigo_seguimiento = :codigo');
        $stmt->execute(['codigo' => $codigo]);
        $existe = $stmt->fetch();
    } while ($existe);

    return $codigo;
}

/** Genera un token privado criptograficamente seguro (64 hex = 32 bytes). */
function generarTokenPrivado(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Inserta manualmente una entrada de trazabilidad para acciones que NO
 * son un cambio de estado de la columna `estado` (ese caso ya lo cubre
 * el trigger trg_postulaciones_log_estado). Ejemplos: creacion de la
 * postulacion, envio de datos privados, exportacion de carga masiva.
 */
function registrarLog(PDO $pdo, int $postulacionId, ?int $usuarioId, string $accion): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO trazabilidad_logs (postulacion_id, usuario_id, accion, fecha_hora)
         VALUES (:pid, :uid, :accion, NOW())'
    );
    $stmt->execute([
        'pid'    => $postulacionId,
        'uid'    => $usuarioId,
        'accion' => $accion,
    ]);
}

/**
 * v3.1: estado que el Jefe Administrativo exige como requisito previo
 * para "Finalizar Contratación". 'Aprobado_admin' solo se alcanza
 * cuando Admin_Contrato autorizo Y el postulante completo su Etapa 2
 * -- ver intentarAvanzarAAprobadoAdmin().
 */
function estadoPrevioAContratado(): string
{
    if (MODULO_BODEGA_ACTIVO) {
        return 'EPP_listo';
    }
    if (MODULO_PREVENCION_ACTIVO) {
        return 'Induccion_ok';
    }
    return 'Aprobado_admin';
}

/**
 * v2/v3.1: linea de tiempo publica (frontend/seguimiento) ajustada al
 * orden real del pipeline y a los modulos realmente activos. Ya no
 * incluye 'Datos_completados' como paso propio porque en v3.1 esa
 * marca no es un estado (ver nota de arriba) -- el frontend la
 * enciende por separado usando el campo `etapa2_completada` que
 * devuelve seguimiento.php.
 */
function ordenEstadosActivos(): array
{
    // v7: Prevención y Bodega dejaron de ser módulos opcionales -- el
    // flujo nuevo (candados Portería/JAO/Prevención) los necesita
    // siempre activos, así que la línea de tiempo ya no depende de
    // MODULO_PREVENCION_ACTIVO/MODULO_BODEGA_ACTIVO.
    return ['Pendiente', 'Pre_aprobado_terreno', 'Aprobado_admin', 'Induccion_ok', 'Contratado', 'Proceso_completo'];
}

/**
 * v6.5: se llama cuando el postulante termina su Etapa 2.
 *
 * v10.13 (pedido explícito del usuario, tras describir de nuevo el
 * proceso completo): ya NO exige que Admin_Contrato haya autorizado
 * (admin_autorizado_at). Su explicación fue textual: "el rol del
 * administrador terminó" al aprobar los cupos -- no necesita autorizar
 * de nuevo, uno por uno, a cada postulante. Ese botón/pestaña
 * "Autorizar Contratación" se retira de su panel (ver
 * admin_contrato.html); admin_contrato/autorizar.php queda sin usar
 * pero no se borra, por si hace falta reactivarlo. Ahora esta función
 * avanza a 'Aprobado_admin' apenas el postulante completa sus datos,
 * sin depender de ningún otro gatillo.
 */
function intentarAvanzarAAprobadoAdmin(PDO $pdo, int $postulacionId): void
{
    $stmt = $pdo->prepare(
        'SELECT p.estado, p.nombre_completo, p.rut, p.correo, p.codigo_seguimiento, c.nombre_cargo,
                d.talla_calzado, d.talla_overol,
                (SELECT COUNT(*) FROM datos_contratacion d2 WHERE d2.postulacion_id = p.id) AS tiene_datos
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
           LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
          WHERE p.id = :id
          FOR UPDATE'
    );
    $stmt->execute(['id' => $postulacionId]);
    $postulacion = $stmt->fetch();

    if (!$postulacion || $postulacion['estado'] !== 'Pre_aprobado_terreno') {
        return; // ya avanzo (u otro caso) -- nada que hacer.
    }
    if ((int)$postulacion['tiene_datos'] === 0) {
        return; // todavia no completa su Etapa 2 -- se espera.
    }

    $stmtUpdate = $pdo->prepare('UPDATE postulaciones SET estado = "Aprobado_admin" WHERE id = :id AND estado = "Pre_aprobado_terreno"');
    $stmtUpdate->execute(['id' => $postulacionId]);

    notificarAprobacionAJao($pdo, $postulacion);
    // v4.1: en el mismo momento (ya con datos_contratacion garantizado
    // -- por eso las tallas de EPP ya estan disponibles), se avisa
    // tambien a Prevencion y Bodega.
    notificarPrevencionYBodega($pdo, $postulacion);
    // v10.7: el QR de "ingreso a faena" (notificarIngresoFaena()) ya NO
    // se manda aca -- se movio a terreno/aprobar.php, junto con el link
    // de Etapa 2, para que Porteria pueda dejarlo pasar a la sala de
    // espera ANTES de que llene sus datos, no despues.
}

/**
 * v6.5: punto unico donde una postulacion recibe acceso a la Fase 2
 * (datos personales/bancarios + documentos). Genera el token, deja el
 * estado en 'Pre_aprobado_terreno' (ya lo estaba) y envia el correo con
 * el link privado.
 *
 * v10.7 (pedido explicito del usuario, tras describir el proceso
 * completo en persona): ahora la llama terreno/aprobar.php, justo
 * cuando el Capataz selecciona a la persona en porteria -- para que
 * llene sus datos ahi mismo, en la sala de espera. Antes la llamaba
 * admin_contrato/autorizar.php (flujo SECUENCIAL: Admin_Contrato
 * autorizaba y recien ahi el postulante se enteraba); esa autorizacion
 * ahora es un dato puramente interno que ya no condiciona nada de lo
 * que ve o puede hacer el postulante (ver admin_contrato/autorizar.php
 * e intentarAvanzarAAprobadoAdmin() mas abajo).
 *
 * v6.6: $usuarioId ahora acepta null -- reenviar_etapa2.php tambien la
 * llama cuando es el propio postulante (sin sesion interna) quien pide
 * un enlace nuevo porque el correo original no le llego.
 */
function otorgarAccesoEtapa2(PDO $pdo, int $postulacionId, ?int $usuarioId): void
{
    $stmt = $pdo->prepare('SELECT nombre_completo, correo FROM postulaciones WHERE id = :id');
    $stmt->execute(['id' => $postulacionId]);
    $postulacion = $stmt->fetch();
    if (!$postulacion) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }

    $token = generarTokenPrivado();
    $expira = (new DateTime())->modify('+' . TOKEN_PRIVADO_HORAS_VALIDEZ . ' hours')->format('Y-m-d H:i:s');

    fijarUsuarioContextoBD($pdo, $usuarioId);
    $stmtUpdate = $pdo->prepare(
        'UPDATE postulaciones
            SET estado = "Pre_aprobado_terreno", token_privado = :token, token_expira_at = :expira
          WHERE id = :id'
    );
    $stmtUpdate->execute(['token' => $token, 'expira' => $expira, 'id' => $postulacionId]);

    require_once __DIR__ . '/../mailer/Mailer.php';
    $urlFormularioPrivado = BASE_URL . '/frontend/public/completar.html?token=' . $token;
    $nombreCompleto = $postulacion['nombre_completo'];
    $html = (function () use ($nombreCompleto, $urlFormularioPrivado) {
        return require __DIR__ . '/../mailer/templates/link_privado.php';
    })();
    Mailer::enviar($postulacion['correo'], $nombreCompleto, 'Completa tus datos de contratación - ICAFAL', $html);
}

/**
 * v3: notifica a todos los Jefe_Administrativo con nombre, RUT y cargo
 * de la persona recien aprobada -- para que sepan que ya pueden
 * revisarla sin tener que entrar al dashboard a cada rato.
 *
 * v10.13: ya no se llama "al autorizar Admin_Contrato" (ese paso se
 * retiró) -- ahora se llama desde intentarAvanzarAAprobadoAdmin(),
 * apenas el postulante completa su Etapa 2.
 */
function notificarAprobacionAJao(PDO $pdo, array $postulacion): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    $stmt = $pdo->query("SELECT nombre, correo FROM usuarios WHERE rol = 'Jefe_Administrativo' AND activo = 1");
    $destinatarios = $stmt->fetchAll();
    if (!$destinatarios) {
        return;
    }

    $nombreCompleto = $postulacion['nombre_completo'];
    $rut = $postulacion['rut'];
    $cargo = $postulacion['nombre_cargo'];
    $html = (function () use ($nombreCompleto, $rut, $cargo) {
        return require __DIR__ . '/../mailer/templates/notificacion_jao.php';
    })();

    foreach ($destinatarios as $jao) {
        Mailer::enviar($jao['correo'], $jao['nombre'], 'Postulante listo para tu revisión - ICAFAL', $html);
    }
}

/**
 * v10.13 (pedido explícito del usuario, tras describir de nuevo el
 * proceso completo): aviso temprano a cada Jefe_Administrativo apenas
 * el Capataz selecciona a alguien en portería -- "viene en camino",
 * antes de que complete su Etapa 2. Se llama desde
 * terreno/aprobar.php (rama Capataz), junto con otorgarAccesoEtapa2()
 * y notificarIngresoFaena(). Distinto de notificarAprobacionAJao(), que
 * sigue avisando más adelante cuando esa misma persona ya está lista
 * para revisión.
 */
function notificarSeleccionAJao(PDO $pdo, int $postulacionId): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    $stmt = $pdo->query("SELECT nombre, correo FROM usuarios WHERE rol = 'Jefe_Administrativo' AND activo = 1");
    $destinatarios = $stmt->fetchAll();
    if (!$destinatarios) {
        return;
    }

    $stmtPostulacion = $pdo->prepare(
        'SELECT p.nombre_completo, p.rut, c.nombre_cargo
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
          WHERE p.id = :id'
    );
    $stmtPostulacion->execute(['id' => $postulacionId]);
    $postulacion = $stmtPostulacion->fetch();
    if (!$postulacion) {
        return;
    }

    $nombreCompleto = $postulacion['nombre_completo'];
    $rut = $postulacion['rut'];
    $cargo = $postulacion['nombre_cargo'];
    $html = (function () use ($nombreCompleto, $rut, $cargo) {
        return require __DIR__ . '/../mailer/templates/notificacion_seleccion_jao.php';
    })();

    foreach ($destinatarios as $jao) {
        Mailer::enviar($jao['correo'], $jao['nombre'], 'Postulante seleccionado - viene en camino - ICAFAL', $html);
    }
}

/**
 * v10.8 (pedido explícito del usuario, tras describir el proceso
 * completo): cuando Admin_Contrato aprueba una solicitud de cupos, se
 * avisa por correo a quien la pidió (Jefe_Terreno) y a todos los
 * Capataz activos -- antes nadie se enteraba salvo entrando a revisar
 * el dashboard a cada rato. Se llama desde
 * admin_contrato/solicitudes_cupo_aprobar.php, fuera de su transacción
 * (igual que el resto de los "notificar*", para no hacer fallar la
 * aprobación si el envío de correo falla).
 *
 * v10.13: también al JAO -- con esto termina la parte de Admin_Contrato
 * en el proceso, así que el JAO se entera de una vez que hay cupos
 * habilitados, sin esperar a una autorización aparte por cada persona.
 */
function notificarCuposAprobados(
    PDO $pdo,
    ?int $usuarioSolicitanteId,
    string $nombreCargo,
    int $cantidadAprobada,
    int $cantidadPedida,
    ?string $observacion
): void {
    require_once __DIR__ . '/../mailer/Mailer.php';

    $destinatarios = [];
    if ($usuarioSolicitanteId !== null) {
        $stmtSolicitante = $pdo->prepare('SELECT nombre, correo FROM usuarios WHERE id = :id AND activo = 1');
        $stmtSolicitante->execute(['id' => $usuarioSolicitanteId]);
        $solicitante = $stmtSolicitante->fetch();
        if ($solicitante) {
            $destinatarios[] = $solicitante;
        }
    }
    // v10.13: tambien al JAO -- pedido explicito del usuario ("una vez
    // autoriza el administrador el JAO ya sabe que hay cupos
    // habilitados"), asi sabe con anticipacion que viene gente en camino.
    $stmtDestinatarios = $pdo->query("SELECT nombre, correo FROM usuarios WHERE rol IN ('Capataz', 'Jefe_Administrativo') AND activo = 1");
    foreach ($stmtDestinatarios->fetchAll() as $destinatario) {
        $destinatarios[] = $destinatario;
    }
    if (!$destinatarios) {
        return;
    }

    $cantidadDistinta = $cantidadAprobada !== $cantidadPedida
        ? " (se pidieron {$cantidadPedida})"
        : '';
    $bloqueObservacion = $observacion !== null && $observacion !== ''
        ? '<p style="background:#f3f4f6;border-radius:6px;padding:10px 14px;color:#374151"><strong>Observación:</strong> ' . htmlspecialchars($observacion, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    $html = (function () use ($nombreCargo, $cantidadAprobada, $cantidadDistinta, $bloqueObservacion) {
        return require __DIR__ . '/../mailer/templates/notificacion_cupos_aprobados.php';
    })();

    // v10.8: dedupe por correo -- si Jefe_Terreno pidió los cupos y
    // además es (raro, pero posible) el mismo correo de un Capataz, no
    // le llega dos veces.
    $yaEnviados = [];
    foreach ($destinatarios as $destinatario) {
        $correo = strtolower($destinatario['correo']);
        if (isset($yaEnviados[$correo])) {
            continue;
        }
        $yaEnviados[$correo] = true;
        Mailer::enviar($destinatario['correo'], $destinatario['nombre'], "Cupos aprobados: {$nombreCargo} - ICAFAL", $html);
    }
}

/**
 * v7: correo al propio postulante con el QR de "ingreso a faena" --
 * Portería lo escanea con la cámara de su celular o tablet (el QR
 * codifica una URL pública, no requiere app ni login) y confirma en
 * persona que la persona llegó. Recién con eso el JAO puede empezar a
 * verificar sus documentos (ver admin_general/verificar_identidad.php).
 * No es lo mismo que el QR de "acceso a la obra" del correo de
 * contratación exitosa (notificarContratacionExitosa) -- ese es el
 * cierre del día 2, este es la entrada del día 1.
 *
 * v10.9 (pedido explícito del usuario, tras describir el proceso
 * completo): antes este correo salía recién cuando la postulación
 * llegaba a 'Aprobado_admin' (Admin_Contrato autorizó Y el postulante
 * ya había completado su Etapa 2 a distancia) -- eso no calzaba con una
 * sola visita continua: el postulante no podía ni entrar a la sala de
 * espera a llenar sus datos sin que antes existiera este QR. Ahora sale
 * ANTES, junto con el link de Etapa 2 (ver terreno/aprobar.php), así
 * que recibe la firma como parámetro (todavía no existe fila en
 * datos_contratacion en este momento, así que ya no puede armar el
 * array `$postulacion` ella sola desde afuera) y hace su propia
 * consulta a la base para tener nombre/rut/correo/cargo frescos.
 */
function notificarIngresoFaena(PDO $pdo, int $postulacionId): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';

    $stmt = $pdo->prepare(
        'SELECT p.nombre_completo, p.rut, p.correo, p.codigo_seguimiento, c.nombre_cargo
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
          WHERE p.id = :id'
    );
    $stmt->execute(['id' => $postulacionId]);
    $postulacion = $stmt->fetch();
    if (!$postulacion) {
        return;
    }

    $urlValidacion = BASE_URL . '/frontend/public/ingreso_faena.html'
        . '?rut=' . urlencode($postulacion['rut'])
        . '&codigo=' . urlencode($postulacion['codigo_seguimiento']);
    // v10.14: la imagen del QR ahora es una URL real (backend/api/public/qr_imagen.php),
    // no un data: URI incrustado -- Gmail y otros clientes bloquean las
    // imagenes data: URI en correos HTML por seguridad, asi que antes el
    // QR simplemente no aparecia.
    $qrImagenUrl = BASE_URL . '/backend/api/public/qr_imagen.php?u=' . urlencode($urlValidacion);

    $nombreCompleto = $postulacion['nombre_completo'];
    $cargo = $postulacion['nombre_cargo'];
    $html = (function () use ($nombreCompleto, $cargo, $qrImagenUrl, $urlValidacion) {
        return require __DIR__ . '/../mailer/templates/ingreso_faena_qr.php';
    })();

    Mailer::enviar($postulacion['correo'], $nombreCompleto, 'Preséntate en portería - Código de ingreso - ICAFAL', $html);
}

/**
 * v4: cuenta cuantas aprobaciones (Pendiente/En_banco -> Pre_aprobado_terreno)
 * ha hecho un Jefe_Terreno especifico durante el dia calendario de HOY,
 * usando trazabilidad_logs (que ya registra usuario_id y fecha_hora para
 * cada cambio de estado via el trigger o via registrarLog en el caso del
 * banco). Se llama ANTES de otorgarAccesoEtapa2() en aprobar.php y
 * banco_invitar.php para exigir el tope de LIMITE_APROBACIONES_DIARIAS_TERRENO.
 */
function contarAprobacionesHoy(PDO $pdo, int $usuarioId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM trazabilidad_logs
          WHERE usuario_id = :uid
            AND DATE(fecha_hora) = CURDATE()
            AND accion IN (
                'Cambio de estado: Pendiente -> Pre_aprobado_terreno',
                'Cambio de estado: En_banco -> Pre_aprobado_terreno'
            )"
    );
    $stmt->execute(['uid' => $usuarioId]);
    return (int)$stmt->fetchColumn();
}

/**
 * v4: corta la ejecucion con un error claro si el Jefe_Terreno ya llego
 * al tope diario de aprobaciones. Debe llamarse ANTES de tocar la BD
 * (antes de otorgarAccesoEtapa2()) para no dejar la postulacion a medio
 * camino si el limite ya se alcanzo.
 */
function exigirCupoDiarioAprobaciones(PDO $pdo, int $usuarioId): void
{
    $usadas = contarAprobacionesHoy($pdo, $usuarioId);
    if ($usadas >= LIMITE_APROBACIONES_DIARIAS_TERRENO) {
        responderError(
            'Alcanzaste el límite de ' . LIMITE_APROBACIONES_DIARIAS_TERRENO .
            ' aprobaciones para hoy (' . $usadas . '/' . LIMITE_APROBACIONES_DIARIAS_TERRENO . '). ' .
            'Podrás aprobar nuevamente mañana.',
            429
        );
    }
}

/**
 * v4.1: al mismo tiempo que se notifica al JAO (ver
 * notificarAprobacionAJao), se avisa a Prevencionista y Jefe_Bodega de
 * que hay una nueva contratación en camino. Prevención solo necesita
 * nombre/RUT/cargo para agendar la inducción; Bodega además recibe las
 * tallas de calzado y overol para preparar el kit de EPP. Se envía
 * aunque los módulos de Prevención/Bodega estén pausados (MODULO_*),
 * porque avisarles con anticipación es útil igual aunque su paso
 * formal en el pipeline no esté activo todavía.
 */
function notificarPrevencionYBodega(PDO $pdo, array $postulacion): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    // v10.22 (pedido explícito del usuario, 06-10): Bodega YA NO recibe un
    // correo por persona -- recibe uno solo con la tabla de tallas del día,
    // que ella misma envía desde su panel (ver tallasSinEnviar() y
    // bodega/enviar_tallas.php). Prevención conserva su aviso individual.
    $stmt = $pdo->query("SELECT nombre, correo, rol FROM usuarios WHERE rol = 'Prevencionista' AND activo = 1");
    $destinatarios = $stmt->fetchAll();
    if (!$destinatarios) {
        return;
    }

    $nombreCompleto = $postulacion['nombre_completo'];
    $rut = $postulacion['rut'];
    $cargo = $postulacion['nombre_cargo'];
    $tallaCalzado = $postulacion['talla_calzado'] ?? '-';
    $tallaOverol = $postulacion['talla_overol'] ?? '-';

    foreach ($destinatarios as $destinatario) {
        $esBodega = $destinatario['rol'] === 'Jefe_Bodega';
        $html = (function () use ($nombreCompleto, $rut, $cargo, $tallaCalzado, $tallaOverol, $esBodega) {
            return require __DIR__ . '/../mailer/templates/notificacion_prevencion_bodega.php';
        })();
        Mailer::enviar($destinatario['correo'], $destinatario['nombre'], 'Nueva contratación autorizada - ICAFAL', $html);
    }
}

/**
 * v10 - Etapa 1 del piloto: Bodega no tiene candado digital (ver
 * MODULO_BODEGA_ACTIVO), así que notificarPrevencionYBodega() de
 * arriba solo le avisa CON ANTICIPACIÓN (día 1) para que prepare el
 * kit. Faltaba el segundo aviso: el día 2, cuando el JAO firma el
 * contrato y la persona ya está físicamente en la obra, Bodega
 * necesita saber que debe entregar el EPP AHORA, no solo que viene
 * en camino. Se llama desde firmar_contrato.php, solo cuando esa misma
 * acción está cerrando el ciclo completo (Etapa 1).
 */
function notificarEntregaEppAhora(PDO $pdo, array $postulacion): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    $stmt = $pdo->query("SELECT nombre, correo FROM usuarios WHERE rol = 'Jefe_Bodega' AND activo = 1");
    $destinatarios = $stmt->fetchAll();
    if (!$destinatarios) {
        return;
    }

    $nombreCompleto = $postulacion['nombre_completo'];
    $rut = $postulacion['rut'];
    $cargo = $postulacion['nombre_cargo'];
    $tallaCalzado = $postulacion['talla_calzado'] ?? '-';
    $tallaOverol = $postulacion['talla_overol'] ?? '-';
    $html = (function () use ($nombreCompleto, $rut, $cargo, $tallaCalzado, $tallaOverol) {
        return require __DIR__ . '/../mailer/templates/notificacion_bodega_entrega_ahora.php';
    })();

    foreach ($destinatarios as $destinatario) {
        Mailer::enviar($destinatario['correo'], $destinatario['nombre'], 'Entrega EPP ahora - trabajador en obra - ICAFAL', $html);
    }
}

/**
 * v5: nombres cortos y legibles de cada tipo de documento de la Etapa 2,
 * usados tanto en el correo de rechazo como en la pantalla de
 * subsanación pública. Centralizado aquí para no repetir el mapeo en
 * cada endpoint que lo necesita.
 */
function etiquetasDocumentos(): array
{
    return [
        'cedula_identidad'          => 'Cédula de Identidad (frente)',
        'cedula_identidad_reverso'  => 'Cédula de Identidad (reverso)',
        'certificado_afp'           => 'Certificado de AFP',
        'certificado_salud'         => 'Certificado de Fonasa/Isapre',
        'ultimo_finiquito'          => 'Último Finiquito',
        'certificado_residencia'    => 'Certificado de Residencia',
    ];
}

/**
 * v5: traduce una entrada cruda de trazabilidad_logs.accion a una frase
 * en lenguaje natural para mostrar en una bitácora de actividad legible
 * (ver bitacora.php). La mayoría de las acciones ya se guardan como
 * frases naturales (se devuelven tal cual); solo "Cambio de estado: X
 * -> Y", que es la única forma técnica/ENUM, se reescribe.
 */
function traducirAccionLog(string $accion): string
{
    if (preg_match('/^Cambio de estado: (\w+) -> (\w+)$/', $accion, $m)) {
        return match ($m[2]) {
            'Pre_aprobado_terreno' => 'Fue pre-aprobado por el Jefe de Terreno.',
            'Aprobado_admin' => 'Pasó a revisión del Jefe Administrativo (ya con datos completos y autorización del Administrador de Contrato).',
            'Induccion_ok' => 'Realizó la inducción de seguridad.',
            'EPP_listo' => 'Su kit de EPP quedó listo.',
            'Contratado' => '✔ Fue contratado.',
            'Proceso_completo' => '✔ Recibido en terreno -- proceso completo.',
            'Rechazado' => 'La postulación fue rechazada.',
            'En_banco' => 'Quedó en el Banco de Postulantes.',
            default => "Cambió de estado a \"{$m[2]}\".",
        };
    }
    return $accion;
}

/**
 * v6.1 - Correo final al postulante cuando el JAO finaliza la
 * contratación: incluye el QR de acceso (mismo destino que usa
 * porteria/validar.php) embebido en el correo, para que lo presente en
 * Portería sin depender de que vuelva a entrar a su seguimiento.
 */
function notificarContratacionExitosa(PDO $pdo, array $postulacion): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';

    $urlValidacion = BASE_URL . '/frontend/public/porteria_resultado.html'
        . '?rut=' . urlencode($postulacion['rut'])
        . '&codigo=' . urlencode($postulacion['codigo_seguimiento']);
    // v10.14: URL de imagen real en vez de data: URI -- ver mismo cambio
    // y motivo en notificarIngresoFaena().
    $qrImagenUrl = BASE_URL . '/backend/api/public/qr_imagen.php?u=' . urlencode($urlValidacion);

    $nombreCompleto = $postulacion['nombre_completo'];
    $cargo = $postulacion['nombre_cargo'];
    $html = (function () use ($nombreCompleto, $cargo, $qrImagenUrl, $urlValidacion) {
        return require __DIR__ . '/../mailer/templates/contratacion_exitosa_qr.php';
    })();

    Mailer::enviar($postulacion['correo'], $nombreCompleto, 'Proceso de contratación exitoso - ICAFAL', $html);
}

/**
 * v6.1 - Correo al postulante cuando Jefe_Terreno o Admin_Contrato
 * rechazan su postulación. Ver mailer/templates/postulacion_no_continua.php
 * para el detalle de por qué el texto está redactado como está.
 */
function notificarPostulacionNoContinua(array $postulacion): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    $nombreCompleto = $postulacion['nombre_completo'];
    $cargo = $postulacion['nombre_cargo'];
    $html = (function () use ($nombreCompleto, $cargo) {
        return require __DIR__ . '/../mailer/templates/postulacion_no_continua.php';
    })();
    Mailer::enviar($postulacion['correo'], $nombreCompleto, 'Resultado de tu postulación - ICAFAL', $html);
}

/**
 * v6.1 - Carpeta local con los documentos del trabajador, para el
 * equipo de RRHH que trabaja con carpetas en el propio servidor/PC
 * ademas de la app. Vive fuera del webroot real (junto a uploads/),
 * protegida por su propio .htaccess -- es un espejo de conveniencia,
 * NUNCA la fuente de verdad (esa sigue siendo backend/uploads/ + la BD).
 *
 * Nombre de carpeta: <codigo_ficha o codigo_seguimiento>_<apellido>_<segundo_apellido>_<nombre>
 * (código de ficha si el JAO ya lo asignó; si no, se usa el código de
 * seguimiento como identificador provisorio y se renombra más tarde --
 * ver renombrarCarpetaConCodigoFicha()).
 */
function nombreCarpetaPostulante(array $p, string $prefijo): string
{
    $limpiar = function (string $s): string {
        $s = preg_replace('/[^A-Za-z0-9]+/u', '_', trim($s));
        return trim($s, '_');
    };
    $partes = array_filter([$limpiar($prefijo), $limpiar($p['apellido'] ?? ''), $limpiar($p['segundo_apellido'] ?? ''), $limpiar($p['nombre'] ?? '')]);
    return implode('_', $partes) ?: ('postulacion_' . $p['id']);
}

function carpetaBasePostulantes(): string
{
    $carpeta = __DIR__ . '/../carpetas_postulantes';
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0750, true);
        file_put_contents($carpeta . '/.htaccess', "Require all denied\n");
    }
    return $carpeta;
}

/**
 * v6.1 - Se llama justo después de guardar los documentos de Etapa 2:
 * crea "<carpeta_postulante>/Documentos Personales/" y copia ahí cada
 * documento recién subido (copia, no mueve -- el original en
 * backend/uploads/ sigue siendo la fuente de verdad para la app).
 */
function generarCarpetaDocumentosPersonales(PDO $pdo, int $postulacionId): void
{
    $stmt = $pdo->prepare('SELECT id, nombre, apellido, segundo_apellido, cv_ruta_archivo FROM postulaciones WHERE id = :id');
    $stmt->execute(['id' => $postulacionId]);
    $p = $stmt->fetch();
    if (!$p) {
        return;
    }

    $stmtCodigo = $pdo->prepare('SELECT codigo_seguimiento FROM postulaciones WHERE id = :id');
    $stmtCodigo->execute(['id' => $postulacionId]);
    $codigoSeguimiento = $stmtCodigo->fetchColumn();

    $stmtJao = $pdo->prepare('SELECT codigo_ficha FROM datos_jao WHERE postulacion_id = :id');
    $stmtJao->execute(['id' => $postulacionId]);
    $codigoFicha = $stmtJao->fetchColumn();

    $prefijo = $codigoFicha ?: $codigoSeguimiento;
    $nombreCarpeta = nombreCarpetaPostulante($p, $prefijo);
    $rutaDocsPersonales = carpetaBasePostulantes() . '/' . $nombreCarpeta . '/Documentos Personales';
    if (!is_dir($rutaDocsPersonales)) {
        mkdir($rutaDocsPersonales, 0750, true);
    }

    $etiquetas = etiquetasDocumentos();
    $stmtDocs = $pdo->prepare('SELECT tipo, ruta_archivo FROM postulacion_documentos WHERE postulacion_id = :id');
    $stmtDocs->execute(['id' => $postulacionId]);
    foreach ($stmtDocs->fetchAll() as $doc) {
        $origen = __DIR__ . '/../uploads/' . $doc['ruta_archivo'];
        if (!is_file($origen)) {
            continue;
        }
        $extension = pathinfo($origen, PATHINFO_EXTENSION);
        $etiquetaArchivo = str_replace(['/', '\\'], '-', $etiquetas[$doc['tipo']] ?? $doc['tipo']);
        $nombreDestino = $etiquetaArchivo . '.' . $extension;
        copy($origen, $rutaDocsPersonales . '/' . $nombreDestino);
    }

    // v10.15 (pedido explícito del usuario, item 4 de la lista post-prueba):
    // el CV de la Etapa 1 (foto o PDF) vive en postulaciones.cv_ruta_archivo,
    // no en postulacion_documentos -- por eso nunca quedaba copiado acá.
    // Si el postulante marcó "No tengo CV" este campo queda NULL y no hay
    // nada que copiar.
    if (!empty($p['cv_ruta_archivo'])) {
        $origenCv = __DIR__ . '/../uploads/' . $p['cv_ruta_archivo'];
        if (is_file($origenCv)) {
            $extensionCv = pathinfo($origenCv, PATHINFO_EXTENSION);
            copy($origenCv, $rutaDocsPersonales . '/CV.' . $extensionCv);
        }
    }
}

/**
 * v6.1 - Se llama cuando el JAO guarda/actualiza el código de ficha
 * (guardar_datos_jao.php): si la carpeta del postulante todavía tiene
 * el nombre provisorio (con el código de seguimiento), la renombra para
 * usar el código de ficha real.
 */
function renombrarCarpetaConCodigoFicha(PDO $pdo, int $postulacionId, string $codigoFichaNuevo): void
{
    $stmt = $pdo->prepare('SELECT id, nombre, apellido, segundo_apellido, codigo_seguimiento FROM postulaciones WHERE id = :id');
    $stmt->execute(['id' => $postulacionId]);
    $p = $stmt->fetch();
    if (!$p) {
        return;
    }

    $base = carpetaBasePostulantes();
    $nombreViejoProvisorio = nombreCarpetaPostulante($p, $p['codigo_seguimiento']);
    $nombreNuevo = nombreCarpetaPostulante($p, $codigoFichaNuevo);

    if ($nombreViejoProvisorio === $nombreNuevo) {
        return; // nada que renombrar
    }
    $rutaVieja = $base . '/' . $nombreViejoProvisorio;
    $rutaNueva = $base . '/' . $nombreNuevo;
    if (is_dir($rutaVieja) && !is_dir($rutaNueva)) {
        rename($rutaVieja, $rutaNueva);
    }
}

/**
 * v2: fecha hasta la que se conservan los datos de alguien que quedo
 * "En_banco" (postulo con interes en un cargo sin cupos disponibles),
 * en linea con el deber de informar plazos de conservacion de la
 * Ley 19.628. Se calcula, no se guarda, para que cambiar
 * BANCO_RETENCION_MESES no requiera tocar filas existentes.
 */
function fechaRetencionBanco(string $creadoAt): string
{
    $fecha = new DateTime($creadoAt);
    $fecha->modify('+' . BANCO_RETENCION_MESES . ' months');
    return $fecha->format('Y-m-d');
}

/**
 * v2: corta la ejecucion si el modulo (Prevencion/Bodega) esta
 * pausado. Se llama justo despues de requireRol(), antes de tocar
 * cualquier tabla -- el rol y sus endpoints siguen existiendo, solo
 * quedan sin uso mientras el flag este en false.
 */
function exigirModuloActivo(bool $activo, string $nombreModulo): void
{
    if (!$activo) {
        responderError("El módulo de $nombreModulo no está disponible en esta versión de la demo.", 503);
    }
}

/** true si el cierre de remuneraciones (Buk u otro) esta activo hoy. */
/**
 * v10.14 (pedido explícito del usuario, item 15): ya no es solo el
 * interruptor manual `activo` -- también cuenta como cierre activo si
 * la fecha de hoy cae dentro de la ventana `desde`/`hasta` que
 * programó el JAO.
 */
function cierreRemuneracionesActivo(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT activo, desde, hasta, quincena_desde, quincena_hasta FROM cierre_remuneraciones WHERE id = 1');
    $fila = $stmt->fetch();
    if (!$fila) {
        return false;
    }
    if ((bool)$fila['activo']) {
        return true; // cierre manual de emergencia -- se ignoran las fechas.
    }
    $hoy = date('Y-m-d');
    if ($fila['desde'] !== null && $fila['hasta'] !== null) {
        // v10.14 (corrección, pedido explícito del usuario): desde/hasta
        // es la VENTANA EN QUE SÍ SE PUEDE CONTRATAR -- "podemos contratar
        // a un trabajador en este rango de fechas solamente". Fuera de
        // ese rango (antes de desde, o después de hasta) es cuando
        // remuneraciones está cerrado. (Antes esto estaba al revés:
        // bloqueaba DENTRO del rango, en vez de fuera.)
        if ($hoy < $fila['desde'] || $hoy > $fila['hasta']) {
            return true;
        }
    }
    // v10.15 (pedido explícito del usuario, item 5 de la lista post-
    // prueba): "en la quincena también se cierra el proceso unos días,
    // más acotado pero se cierra" -- a diferencia de desde/hasta de
    // arriba (ventana permitida), este es un rango BLOQUEADO: si hoy cae
    // adentro, el cierre está activo aunque también estemos dentro de la
    // ventana mensual permitida.
    if ($fila['quincena_desde'] !== null && $fila['quincena_hasta'] !== null) {
        if ($hoy >= $fila['quincena_desde'] && $hoy <= $fila['quincena_hasta']) {
            return true;
        }
    }
    return false;
}

/**
 * v10.14: "considerar que estos cupos serán liberados el día primero
 * del mes siguiente" -- el mensaje exacto que pidió el usuario para
 * cuando Jefe_Terreno solicita cupos estando dentro de la ventana de
 * cierre. Devuelve null si no hay cierre programado con fecha `hasta`.
 */
function mensajeCierreRemuneraciones(PDO $pdo): ?string
{
    $stmt = $pdo->query('SELECT activo, desde, hasta, quincena_desde, quincena_hasta FROM cierre_remuneraciones WHERE id = 1');
    $fila = $stmt->fetch();
    if (!$fila || !cierreRemuneracionesActivo($pdo)) {
        return null;
    }
    // v10.14 (corrección): el mensaje de liberación se calcula desde HOY,
    // no desde `hasta` -- si ya pasamos la fecha `hasta`, el primer día
    // del mes siguiente A `hasta` podría quedar en el pasado.
    $liberacion = (new DateTime())->modify('first day of next month')->format('d-m-Y');
    $hoy = date('Y-m-d');

    // v10.15 (item 5): si el motivo real es el cierre de quincena (aunque
    // también estemos dentro de la ventana mensual), el mensaje lo dice
    // explícitamente -- si no, cae al mensaje de ventana mensual de abajo.
    if ($fila['quincena_desde'] !== null && $fila['quincena_hasta'] !== null
        && $hoy >= $fila['quincena_desde'] && $hoy <= $fila['quincena_hasta']
    ) {
        $hastaQuincenaTexto = (new DateTime($fila['quincena_hasta']))->format('d-m-Y');
        return "Estamos en el cierre de quincena de remuneraciones (hasta el {$hastaQuincenaTexto}). Tu solicitud queda registrada, pero considera que estos cupos se liberarán apenas termine ese cierre.";
    }

    if ($fila['desde'] === null || $fila['hasta'] === null) {
        return "Estamos fuera del período habilitado para contratar. Tu solicitud queda registrada, pero considera que estos cupos serán liberados el {$liberacion}.";
    }
    $desdeTexto = (new DateTime($fila['desde']))->format('d-m-Y');
    $hastaTexto = (new DateTime($fila['hasta']))->format('d-m-Y');
    return "Estamos fuera del período habilitado para contratar (ventana habilitada: {$desdeTexto} al {$hastaTexto}). Tu solicitud queda registrada, pero considera que estos cupos serán liberados el {$liberacion}.";
}

/**
 * v2: valida y guarda un archivo subido (ej. el CV en Fase 0) fuera
 * del webroot, con nombre generado aleatoriamente (nunca el nombre
 * original) para evitar colisiones y ataques de path traversal.
 * Devuelve la ruta relativa a guardar en la BD, o lanza RuntimeException
 * con un mensaje apto para mostrar al usuario si algo no es valido.
 */
function guardarArchivoSubido(array $archivo, string $subcarpeta, string $etiquetaCampo = 'el archivo'): string
{
    $permitidos = [
        'application/pdf' => 'pdf',
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
    ];
    $maxBytes = 8 * 1024 * 1024; // 8 MB, suficiente para una foto de celular

    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("Debes adjuntar $etiquetaCampo (PDF, JPG o PNG).");
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("No fue posible recibir $etiquetaCampo. Intenta nuevamente.");
    }
    if ($archivo['size'] > $maxBytes) {
        throw new RuntimeException("$etiquetaCampo supera el tamaño máximo permitido (8 MB).");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    if (!isset($permitidos[$mime])) {
        throw new RuntimeException('Formato no permitido. Sube tu CV en PDF, JPG o PNG.');
    }

    $carpetaBase = __DIR__ . '/../uploads/' . $subcarpeta;
    if (!is_dir($carpetaBase)) {
        mkdir($carpetaBase, 0750, true);
    }

    $nombreArchivo = bin2hex(random_bytes(16)) . '.' . $permitidos[$mime];
    $rutaCompleta = $carpetaBase . '/' . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $rutaCompleta)) {
        throw new RuntimeException('No fue posible guardar el archivo. Intenta nuevamente.');
    }

    return $subcarpeta . '/' . $nombreArchivo;
}

/**
 * v6.9 - Cuando el JAO finaliza la contratación (queda 100% cerrada:
 * contrato + IRL + EPP), se avisa a quien hizo la selección inicial en
 * portería (Capataz o Jefe_Terreno, el que haya sido) para que vaya a
 * buscar al trabajador y lo lleve a su puesto -- pedido de Ricardo en
 * la reunión 28-ago, para cerrar el ciclo completo de "dueños de etapa".
 * Si esa persona ya no está activa, o por si acaso, también se avisa a
 * todos los Jefe_Terreno activos (salvo que la persona ya sea uno de
 * ellos, para no duplicar el correo).
 */
function notificarLiberacionTrabajador(PDO $pdo, array $postulacion): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';

    $stmtSeleccionador = $pdo->prepare(
        "SELECT u.id, u.nombre, u.correo, u.rol
           FROM trazabilidad_logs t
           JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.postulacion_id = :id
            AND t.accion LIKE 'Cambio de estado: % -> Pre_aprobado_terreno'
          ORDER BY t.fecha_hora ASC
          LIMIT 1"
    );
    $stmtSeleccionador->execute(['id' => $postulacion['id']]);
    $seleccionador = $stmtSeleccionador->fetch();

    $destinatarios = [];
    if ($seleccionador) {
        $destinatarios[$seleccionador['id']] = $seleccionador;
    }

    $stmtTerreno = $pdo->query("SELECT id, nombre, correo, rol FROM usuarios WHERE rol = 'Jefe_Terreno' AND activo = 1");
    foreach ($stmtTerreno->fetchAll() as $jt) {
        $destinatarios[$jt['id']] = $destinatarios[$jt['id']] ?? $jt;
    }

    if (!$destinatarios) {
        return;
    }

    $nombreCompleto = $postulacion['nombre_completo'];
    $rut = $postulacion['rut'];
    $cargo = $postulacion['nombre_cargo'];
    $html = (function () use ($nombreCompleto, $rut, $cargo) {
        return require __DIR__ . '/../mailer/templates/notificacion_liberacion_trabajador.php';
    })();

    foreach ($destinatarios as $destinatario) {
        Mailer::enviar($destinatario['correo'], $destinatario['nombre'], 'Trabajador listo para ingresar a terreno - ICAFAL', $html);
    }
}

/**
 * v10.14 (pedido explícito del usuario, item 17 de la lista post-prueba):
 * correo al postulante apenas el JAO verifica su identidad (día 1) --
 * antes admin_general/verificar_identidad.php no le avisaba nada. Le
 * dice que avanzó y qué sigue (volver mañana 8am).
 */
function notificarPresentarseManana(PDO $pdo, int $postulacionId): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    $stmt = $pdo->prepare('SELECT nombre_completo, correo FROM postulaciones WHERE id = :id');
    $stmt->execute(['id' => $postulacionId]);
    $postulacion = $stmt->fetch();
    if (!$postulacion) {
        return;
    }

    $nombreCompleto = $postulacion['nombre_completo'];
    $html = (function () use ($nombreCompleto) {
        return require __DIR__ . '/../mailer/templates/notificacion_presentate_manana.php';
    })();

    Mailer::enviar($postulacion['correo'], $nombreCompleto, 'Avanzaste en tu proceso - preséntate mañana - ICAFAL', $html);
}

/**
 * v10.21 (pedido explícito del usuario, hallado en el piloto del 16-09):
 * el aviso de "trabajador liberado" llegaba UNO POR UNO a Capataz y Jefe
 * de Terreno -- con varios trabajadores el mismo día eran decenas de
 * correos sueltos. Ahora Bodega envía UNA nómina consolidada (tabla en el
 * cuerpo del correo) con todos los liberados que todavía no fueron
 * avisados (ver bodega/enviar_nomina.php).
 *
 * Marca que se deja en trazabilidad_logs por cada trabajador incluido, así
 * nunca aparece dos veces en una nómina y no hace falta ninguna columna
 * nueva en la base de datos.
 */
const ACCION_NOMINA_LIBERADOS = 'Incluido en la nómina de liberados enviada por correo.';

/**
 * v10.21: marca del SEGUNDO check de Prevención (IRL, día de contratación,
 * después de que el JAO firma el contrato) -- el primero es la inducción
 * del día de postulación (estado 'Induccion_ok', ver marcar_induccion.php).
 * Bodega solo puede entregar el kit si esta marca existe (ver
 * bodega/marcar_epp.php). Se guarda en trazabilidad_logs (igual que la
 * marca de la nómina) para no necesitar ninguna columna nueva en la base
 * de datos viva.
 */
const ACCION_IRL_REALIZADA = 'Prevención registró la IRL realizada.';

/**
 * v10.22 (pedido explícito del usuario, 06-10): Bodega recibe UN solo
 * correo con la tabla de tallas de quienes completaron su Etapa 2 (día de
 * postulación), en vez de un correo por postulante. Idealmente se envía a
 * las 14:00, cuando ya no entran más postulantes; si alguien completa
 * después, queda como pendiente y sale en el siguiente envío, aparte.
 * Marca por postulante en trazabilidad_logs (sin columnas nuevas).
 */
const ACCION_TALLAS_ENVIADAS = 'Tallas incluidas en el correo consolidado a Bodega.';

/**
 * Personas con Etapa 2 completa (Aprobado_admin / Induccion_ok) hoy o ayer
 * cuyas tallas todavía no fueron incluidas en un correo a Bodega.
 */
function tallasSinEnviar(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT x.id, x.nombre_completo, x.rut, x.nombre_cargo, x.talla_calzado, x.talla_overol, x.completado_at
           FROM (
                SELECT p.id, p.nombre_completo, p.rut, c.nombre_cargo, d.talla_calzado, d.talla_overol,
                       (SELECT MAX(t.fecha_hora) FROM trazabilidad_logs t
                         WHERE t.postulacion_id = p.id
                           AND t.accion LIKE 'Cambio de estado: % -> Aprobado_admin') AS completado_at
                  FROM postulaciones p
                  JOIN cargos c ON c.id = p.cargo_id
                  JOIN datos_contratacion d ON d.postulacion_id = p.id
                 WHERE p.estado IN ('Aprobado_admin', 'Induccion_ok')
                   AND NOT EXISTS (
                        SELECT 1 FROM trazabilidad_logs n
                         WHERE n.postulacion_id = p.id AND n.accion = :accion_tallas
                   )
           ) x
          WHERE x.completado_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          ORDER BY x.completado_at ASC, x.id ASC"
    );
    $stmt->execute(['accion_tallas' => ACCION_TALLAS_ENVIADAS]);
    return $stmt->fetchAll();
}

/**
 * Envía UN correo (tabla de tallas) a los Jefes de Bodega activos y marca a
 * cada persona incluida -- solo si salió al menos a un destinatario, para
 * poder reintentar si falla el envío. Devuelve a cuántos destinatarios se
 * envió.
 */
function enviarTallasABodega(PDO $pdo, array $personas, ?int $usuarioId): int
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    if (!$personas) {
        return 0;
    }

    $stmt = $pdo->query("SELECT nombre, correo FROM usuarios WHERE rol = 'Jefe_Bodega' AND activo = 1");
    $destinatarios = [];
    foreach ($stmt->fetchAll() as $u) {
        $clave = strtolower(trim($u['correo']));
        if ($clave !== '' && !isset($destinatarios[$clave])) {
            $destinatarios[$clave] = $u;
        }
    }
    if (!$destinatarios) {
        return 0;
    }

    $esc = fn ($v) => htmlspecialchars((string)($v === null || $v === '' ? '-' : $v), ENT_QUOTES, 'UTF-8');
    $filasHtml = '';
    foreach (array_values($personas) as $i => $p) {
        $fondo = $i % 2 === 0 ? '#ffffff' : '#f9fafb';
        $celda = 'padding:9px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;';
        $filasHtml .= '<tr style="background:' . $fondo . '">'
            . '<td style="' . $celda . 'color:#6b7280">' . ($i + 1) . '</td>'
            . '<td style="' . $celda . 'font-weight:bold">' . $esc($p['nombre_completo']) . '</td>'
            . '<td style="' . $celda . 'font-family:monospace">' . $esc($p['rut']) . '</td>'
            . '<td style="' . $celda . '">' . $esc($p['nombre_cargo']) . '</td>'
            . '<td style="' . $celda . 'font-weight:bold;text-align:center">' . $esc($p['talla_calzado']) . '</td>'
            . '<td style="' . $celda . 'font-weight:bold;text-align:center">' . $esc($p['talla_overol']) . '</td>'
            . '</tr>';
    }

    $fecha = (new DateTime())->format('d-m-Y');
    $hora = (new DateTime())->format('H:i');
    $total = count($personas);
    $obra = OBRA_NOMBRE;
    $html = (function () use ($fecha, $hora, $total, $obra, $filasHtml) {
        return require __DIR__ . '/../mailer/templates/tallas_bodega.php';
    })();

    $asunto = "Tallas de postulantes para preparar kits de EPP - {$fecha} ({$total}) - ICAFAL";
    $enviados = 0;
    foreach ($destinatarios as $d) {
        if (Mailer::enviar($d['correo'], $d['nombre'], $asunto, $html)) {
            $enviados++;
        }
    }

    if ($enviados > 0) {
        foreach ($personas as $p) {
            registrarLog($pdo, (int)$p['id'], $usuarioId, ACCION_TALLAS_ENVIADAS);
        }
    }
    return $enviados;
}

/**
 * Trabajadores ya liberados por Bodega (Contratado / Proceso_completo) hoy
 * o ayer que todavía no fueron incluidos en ninguna nómina enviada. Se
 * limita a los últimos dos días para no arrastrar contrataciones antiguas
 * (de pilotos anteriores) que nunca tuvieron nómina.
 */
function liberadosSinNomina(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT x.id, x.nombre_completo, x.rut, x.nombre_cargo, x.liberado_at, x.seleccionado_por
           FROM (
                SELECT p.id, p.nombre_completo, p.rut, c.nombre_cargo,
                       (SELECT MAX(t.fecha_hora) FROM trazabilidad_logs t
                         WHERE t.postulacion_id = p.id
                           AND t.accion LIKE 'Cambio de estado: % -> Contratado') AS liberado_at,
                       (SELECT u.nombre FROM trazabilidad_logs t2
                          JOIN usuarios u ON u.id = t2.usuario_id
                         WHERE t2.postulacion_id = p.id
                           AND t2.accion LIKE 'Cambio de estado: % -> Pre_aprobado_terreno'
                         ORDER BY t2.fecha_hora ASC, t2.id ASC
                         LIMIT 1) AS seleccionado_por
                  FROM postulaciones p
                  JOIN cargos c ON c.id = p.cargo_id
                 WHERE p.estado IN ('Contratado', 'Proceso_completo')
                   AND NOT EXISTS (
                        SELECT 1 FROM trazabilidad_logs n
                         WHERE n.postulacion_id = p.id AND n.accion = :accion_nomina
                   )
           ) x
          WHERE x.liberado_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          ORDER BY x.liberado_at ASC, x.id ASC"
    );
    $stmt->execute(['accion_nomina' => ACCION_NOMINA_LIBERADOS]);
    return $stmt->fetchAll();
}

/**
 * Envía UN solo correo (tabla con todos los liberados recibidos) a cada
 * rol que participa del proceso, y deja la marca en trazabilidad_logs de
 * cada trabajador incluido -- pero solo si el correo salió al menos a un
 * destinatario, para que un fallo de envío permita reintentar.
 * Devuelve a cuántos destinatarios se les envió.
 */
function enviarNominaLiberados(PDO $pdo, array $liberados, ?int $usuarioId): int
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    if (!$liberados) {
        return 0;
    }

    $stmt = $pdo->query(
        "SELECT nombre, correo FROM usuarios
          WHERE rol IN ('Jefe_Terreno', 'Capataz', 'Admin_Contrato', 'Jefe_Administrativo', 'Prevencionista', 'Jefe_Bodega')
            AND activo = 1"
    );
    $destinatarios = [];
    foreach ($stmt->fetchAll() as $u) {
        $clave = strtolower(trim($u['correo']));
        if ($clave !== '' && !isset($destinatarios[$clave])) {
            $destinatarios[$clave] = $u;
        }
    }
    if (!$destinatarios) {
        return 0;
    }

    $esc = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $filasHtml = '';
    foreach (array_values($liberados) as $i => $l) {
        $hora = !empty($l['liberado_at']) ? (new DateTime($l['liberado_at']))->format('H:i') : '-';
        $fondo = $i % 2 === 0 ? '#ffffff' : '#f9fafb';
        $celda = 'padding:9px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;';
        $filasHtml .= '<tr style="background:' . $fondo . '">'
            . '<td style="' . $celda . 'color:#6b7280">' . ($i + 1) . '</td>'
            . '<td style="' . $celda . 'font-weight:bold">' . $esc($l['nombre_completo']) . '</td>'
            . '<td style="' . $celda . 'font-family:monospace">' . $esc($l['rut']) . '</td>'
            . '<td style="' . $celda . '">' . $esc($l['nombre_cargo']) . '</td>'
            . '<td style="' . $celda . '">' . $hora . '</td>'
            . '<td style="' . $celda . '">' . $esc($l['seleccionado_por'] ?? '-') . '</td>'
            . '</tr>';
    }

    $fecha = (new DateTime())->format('d-m-Y');
    $total = count($liberados);
    $obra = OBRA_NOMBRE;
    $html = (function () use ($fecha, $total, $obra, $filasHtml) {
        return require __DIR__ . '/../mailer/templates/nomina_liberados.php';
    })();

    $asunto = "Nómina de trabajadores liberados - {$fecha} ({$total}) - ICAFAL";
    $enviados = 0;
    foreach ($destinatarios as $d) {
        if (Mailer::enviar($d['correo'], $d['nombre'], $asunto, $html)) {
            $enviados++;
        }
    }

    if ($enviados > 0) {
        foreach ($liberados as $l) {
            registrarLog($pdo, (int)$l['id'], $usuarioId, ACCION_NOMINA_LIBERADOS);
        }
    }
    return $enviados;
}

/**
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09): el día
 * de contratación (8 am) la persona pasa PRIMERO por Portería, que ve en
 * qué fase está (con su cédula) y "autoriza su paso a contratación". Ese
 * paso (marca ACCION_INGRESO_CONTRATACION en trazabilidad_logs, sin
 * columna nueva) le avisa al JAO y es lo que habilita su botón de firma
 * (ver admin_general/listar.php y firmar_contrato.php).
 */
const ACCION_INGRESO_CONTRATACION = 'Portería autorizó el paso a contratación.';

/**
 * Para la pantalla de Portería: fase en lenguaje natural + si se puede
 * autorizar su paso a contratación. Devuelve null si no existe ninguna
 * postulación con ese RUT/documento. Solo expone nombre, RUT, cargo y fase.
 */
function faseParaPorteria(PDO $pdo, string $rutCrudo, string $rutNormalizado): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.nombre_completo, p.rut, p.estado, p.identidad_verificada_at,
                p.contrato_firmado_at, c.nombre_cargo,
                (SELECT COUNT(*) FROM trazabilidad_logs t
                  WHERE t.postulacion_id = p.id AND t.accion = :accion_irl) > 0 AS irl_realizada,
                (SELECT COUNT(*) FROM trazabilidad_logs t2
                  WHERE t2.postulacion_id = p.id AND t2.accion = :accion_paso) > 0 AS paso_autorizado
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
          WHERE p.rut = :rut_crudo OR p.rut = :rut_norm
          ORDER BY p.id DESC
          LIMIT 1'
    );
    $stmt->execute([
        'accion_irl' => ACCION_IRL_REALIZADA,
        'accion_paso' => ACCION_INGRESO_CONTRATACION,
        'rut_crudo' => $rutCrudo,
        'rut_norm' => $rutNormalizado,
    ]);
    $p = $stmt->fetch();
    if (!$p) {
        return null;
    }

    $firmado = $p['contrato_firmado_at'] !== null;
    $paso = (bool)$p['paso_autorizado'];
    $irl = (bool)$p['irl_realizada'];

    $fase = match (true) {
        $p['estado'] === 'Pendiente' => 'Postulación recibida, todavía sin seleccionar por el Capataz',
        $p['estado'] === 'Pre_aprobado_terreno' => 'Seleccionado, le falta completar sus datos y documentos (Etapa 2)',
        $p['estado'] === 'Aprobado_admin' && $p['identidad_verificada_at'] === null => 'En revisión del Jefe Administrativo (verificación de documentos)',
        $p['estado'] === 'Aprobado_admin' => 'Verificado por el JAO, le falta la inducción con Prevención',
        $p['estado'] === 'Induccion_ok' && !$firmado && !$paso => 'LISTO PARA CONTRATACIÓN -- falta autorizar su paso',
        $p['estado'] === 'Induccion_ok' && !$firmado => 'Paso ya autorizado, esperando la firma de contrato (JAO)',
        $p['estado'] === 'Induccion_ok' && !$irl => 'Contrato firmado, esperando la IRL con Prevención',
        $p['estado'] === 'Induccion_ok' => 'IRL realizada, esperando la entrega de su kit en Bodega',
        in_array($p['estado'], ['Contratado', 'Proceso_completo'], true) => 'Contratación completada',
        $p['estado'] === 'Rechazado' => 'Postulación rechazada',
        default => (string)$p['estado'],
    };

    return [
        'id' => (int)$p['id'],
        'nombre_completo' => $p['nombre_completo'],
        'rut' => $p['rut'],
        'cargo' => $p['nombre_cargo'],
        'fase' => $fase,
        'puede_autorizar' => $p['estado'] === 'Induccion_ok' && !$firmado && !$paso,
        'ya_autorizado' => $paso,
    ];
}

/**
 * Marca el paso de la persona a contratación (lo llama Portería, o el JAO
 * manualmente si Portería no alcanzó). Debe llamarse dentro de una
 * transacción. Lanza RuntimeException('mensaje|status') si no corresponde.
 * $nota es un registro extra opcional en la bitácora (ej. "manual").
 */
function marcarPasoAContratacion(PDO $pdo, int $postulacionId, ?int $usuarioId, ?string $nota = null): void
{
    $stmt = $pdo->prepare(
        'SELECT p.estado, p.contrato_firmado_at,
                (SELECT COUNT(*) FROM trazabilidad_logs t
                  WHERE t.postulacion_id = p.id AND t.accion = :accion) AS ya_autorizado
           FROM postulaciones p
          WHERE p.id = :id
          FOR UPDATE'
    );
    $stmt->execute(['accion' => ACCION_INGRESO_CONTRATACION, 'id' => $postulacionId]);
    $p = $stmt->fetch();

    if (!$p) {
        throw new RuntimeException('Postulación no encontrada.|404');
    }
    if ($p['estado'] !== 'Induccion_ok') {
        throw new RuntimeException('Esta persona todavía no completa su inducción del día de postulación.|409');
    }
    if ($p['contrato_firmado_at'] !== null) {
        throw new RuntimeException('Esta persona ya firmó su contrato.|409');
    }
    if ((int)$p['ya_autorizado'] > 0) {
        throw new RuntimeException('El paso a contratación de esta persona ya estaba autorizado.|409');
    }

    if ($nota !== null) {
        registrarLog($pdo, $postulacionId, $usuarioId, $nota);
    }
    registrarLog($pdo, $postulacionId, $usuarioId, ACCION_INGRESO_CONTRATACION);
}

/**
 * Aviso al JAO apenas Portería autoriza el paso de alguien a contratación:
 * ya le aparece disponible para firma de contrato en su panel.
 */
function notificarPasoContratacionAJao(PDO $pdo, int $postulacionId): void
{
    require_once __DIR__ . '/../mailer/Mailer.php';
    $stmt = $pdo->query("SELECT nombre, correo FROM usuarios WHERE rol = 'Jefe_Administrativo' AND activo = 1");
    $destinatarios = $stmt->fetchAll();
    if (!$destinatarios) {
        return;
    }

    $stmtPostulacion = $pdo->prepare(
        'SELECT p.nombre_completo, p.rut, c.nombre_cargo
           FROM postulaciones p
           JOIN cargos c ON c.id = p.cargo_id
          WHERE p.id = :id'
    );
    $stmtPostulacion->execute(['id' => $postulacionId]);
    $postulacion = $stmtPostulacion->fetch();
    if (!$postulacion) {
        return;
    }

    $nombreCompleto = $postulacion['nombre_completo'];
    $rut = $postulacion['rut'];
    $cargo = $postulacion['nombre_cargo'];
    $html = (function () use ($nombreCompleto, $rut, $cargo) {
        return require __DIR__ . '/../mailer/templates/notificacion_paso_contratacion_jao.php';
    })();

    foreach ($destinatarios as $jao) {
        Mailer::enviar($jao['correo'], $jao['nombre'], 'Postulante listo para firma de contrato - ICAFAL', $html);
    }
}
