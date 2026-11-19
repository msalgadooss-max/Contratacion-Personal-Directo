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

/**
 * Sanitiza un string simple: quita "<" y ">" (v10.25: defensa en profundidad
 * contra HTML inyectado desde formularios públicos -- el frontend ademas
 * escapa todo lo que viene de la BD), recorta espacios y limita largo.
 */
function limpiarTexto(?string $valor, int $maxLargo = 255): string
{
    $valor = str_replace(['<', '>'], '', (string)$valor);
    $valor = trim($valor);
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
function otorgarAccesoEtapa2(PDO $pdo, int $postulacionId, ?int $usuarioId, bool $incluirQr = false): void
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

    // v10.24 (pedido explícito del usuario, 06-10): cuando la llama el
    // Capataz (terreno/aprobar.php), el postulante recibe UN solo correo con
    // el QR de Portería y el link de Etapa 2, en vez de dos seguidos. Si por
    // cualquier motivo no se puede armar el QR, cae al correo de siempre
    // (solo el link) -- el postulante nunca se queda sin su enlace.
    if ($incluirQr) {
        try {
            $stmtQr = $pdo->prepare(
                'SELECT p.rut, p.codigo_seguimiento, c.nombre_cargo
                   FROM postulaciones p
                   JOIN cargos c ON c.id = p.cargo_id
                  WHERE p.id = :id'
            );
            $stmtQr->execute(['id' => $postulacionId]);
            $datosQr = $stmtQr->fetch();
            if ($datosQr) {
                $urlValidacion = BASE_URL . '/frontend/public/ingreso_faena.html'
                    . '?rut=' . urlencode($datosQr['rut'])
                    . '&codigo=' . urlencode($datosQr['codigo_seguimiento']);
                $qrImagenUrl = BASE_URL . '/backend/api/public/qr_imagen.php?u=' . urlencode($urlValidacion);
                $cargo = $datosQr['nombre_cargo'];
                $htmlCombinado = (function () use ($nombreCompleto, $cargo, $urlFormularioPrivado, $qrImagenUrl, $urlValidacion) {
                    return require __DIR__ . '/../mailer/templates/seleccion_con_qr.php';
                })();
                Mailer::enviar(
                    $postulacion['correo'],
                    $nombreCompleto,
                    '¡Buenas noticias! Preséntate en portería y completa tus datos - ICAFAL',
                    $htmlCombinado
                );
                return;
            }
        } catch (\Throwable $e) {
            error_log('otorgarAccesoEtapa2 correo combinado error: ' . $e->getMessage());
        }
    }

    $html = (function () use ($nombreCompleto, $urlFormularioPrivado) {
        return require __DIR__ . '/../mailer/templates/link_privado.php';
    })();
    Mailer::enviar($postulacion['correo'], $nombreCompleto, 'Completa tus datos de contratación - ICAFAL', $html);
}

/**
 * v10.38 - Interruptores de correo (variables de entorno de Render: se prenden y apagan sin
 * tocar código ni desplegar; todas las obras comparten la misma configuración).
 *  EMAIL_JAO_ACTIVO=1                 los 3 avisos individuales al JAO + "cupos aprobados" (apagado por defecto)
 *  EMAIL_BODEGA_AVISOS=1              "Entrega EPP ahora" a Bodega (apagado: Bodega solo recibe tallas y nómina)
 *  EMAIL_CONTRATACION_EXITOSA=1       "Proceso de contratación exitoso" al postulante (apagado por defecto)
 */
function interruptorCorreo(string $variable): bool
{
    return getenv($variable) === '1';
}

/**
 * v10.38: genera un Excel en memoria (para adjuntarlo a un correo). Devuelve null si falta
 * la librería (composer install) o algo falla: el correo igual sale, solo sin adjunto.
 * $filas = lista de listas en el mismo orden que $encabezados.
 */
function excelEnMemoria(string $titulo, array $encabezados, array $filas): ?string
{
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) {
        return null;
    }
    try {
        require_once $autoload;
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(mb_substr($titulo, 0, 30));
        foreach ($encabezados as $i => $h) {
            $hoja->setCellValue([$i + 1, 1], $h);
        }
        $hoja->getStyle('1:1')->getFont()->setBold(true);
        foreach ($filas as $r => $fila) {
            foreach (array_values($fila) as $c => $v) {
                $hoja->setCellValueExplicit([$c + 1, $r + 2], (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        foreach (range(1, count($encabezados)) as $c) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        ob_start();
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
        return (string)ob_get_clean();
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        error_log('excelEnMemoria error: ' . $e->getMessage());
        return null;
    }
}

function adjuntoExcel(string $nombreArchivo, ?string $bytes): array
{
    return $bytes === null ? [] : [['nombre' => $nombreArchivo, 'contenido' => $bytes,
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
}

function fechaIngresoTexto(?string $f): string
{
    return $f ? (new DateTime($f))->format('d-m-Y') : '-';
}

/**
 * v10.37: renueva el enlace de Etapa 2 de una persona YA seleccionada (estado
 * 'Pre_aprobado_terreno', sin Etapa 2 completada), sin mandar correo ni tocar el
 * estado. Lo usan (a) seguimiento.php, cuando el enlace venció y la persona ya se
 * identifico con RUT + correo, y (b) terreno/enlace_etapa2.php, para que el equipo
 * lo regenere sin usar el Web Shell. Devuelve el token nuevo.
 */
function renovarTokenEtapa2(PDO $pdo, int $postulacionId): string
{
    $token = generarTokenPrivado();
    $expira = (new DateTime())->modify('+' . TOKEN_PRIVADO_HORAS_VALIDEZ . ' hours')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'UPDATE postulaciones SET token_privado = :token, token_expira_at = :expira
          WHERE id = :id AND estado = "Pre_aprobado_terreno"'
    );
    $stmt->execute(['token' => $token, 'expira' => $expira, 'id' => $postulacionId]);
    return $token;
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
    if (!interruptorCorreo('EMAIL_JAO_ACTIVO')) {
        return; // v10.38: correos al JAO desactivados por ahora (se ve en su panel)
    }
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
    if (!interruptorCorreo('EMAIL_JAO_ACTIVO')) {
        return; // v10.38
    }
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
    ?string $observacion,
    ?int $capatazDestinoId = null
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
    // v10.38: sin correos al JAO por ahora (EMAIL_JAO_ACTIVO=1 los vuelve a activar).
    $rolesCupos = interruptorCorreo('EMAIL_JAO_ACTIVO') ? "'Capataz', 'Jefe_Administrativo'" : "'Capataz'";
    // v10.40: si los cupos van dirigidos a un Capataz, solo se avisa a el (y a quien los pidio).
    $stmtDestinatarios = $capatazDestinoId !== null
        ? $pdo->query('SELECT nombre, correo FROM usuarios WHERE id = ' . (int)$capatazDestinoId . ' AND activo = 1')
        : $pdo->query("SELECT nombre, correo FROM usuarios WHERE rol IN ($rolesCupos) AND activo = 1");
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
    $bloqueDestino = '';
    if ($capatazDestinoId !== null) {
        $stmtNom = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = :id');
        $stmtNom->execute(['id' => $capatazDestinoId]);
        $nomCap = htmlspecialchars((string)$stmtNom->fetchColumn(), ENT_QUOTES, 'UTF-8');
        $bloqueDestino = '<p style="background:#fff7ed;border-radius:6px;padding:10px 14px;color:#9a3412">Estos cupos quedaron <strong>reservados para el Capataz ' . $nomCap . '</strong> (los pidió su Profesional de Terreno para su cuadrilla).</p>';
    }
    $html = (function () use ($nombreCargo, $cantidadAprobada, $cantidadDistinta, $bloqueObservacion, $bloqueDestino) {
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
    if (!interruptorCorreo('EMAIL_BODEGA_AVISOS')) {
        return; // v10.38: Bodega recibe solo tallas (14:00) y nómina; esto lo ve en su panel
    }
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
        'cotizaciones_afp'          => 'Últimas 12 cotizaciones AFP',
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
            'Pre_aprobado_terreno' => 'Fue seleccionado en terreno por el Capataz.',
            'Aprobado_admin' => 'Pasó a revisión del Jefe Administrativo (ya con sus datos y documentos completos).',
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
    if (!interruptorCorreo('EMAIL_CONTRATACION_EXITOSA')) {
        return; // v10.38: el postulante recibe solo 3 correos + 1 si falta un documento
    }
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
    // v10.32: la obra principal (H57) sigue en la carpeta de siempre; las demas
    // obras usan una subcarpeta propia, para que las carpetas de una obra no se
    // mezclen con las de otra (aunque coincida el nombre de la persona).
    $carpeta = __DIR__ . '/../carpetas_postulantes' . (OBRA_CODIGO === 'h57' ? '' : '/' . OBRA_CODIGO);
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
    $stmt = $pdo->prepare('SELECT id, nombre, apellido, segundo_apellido, nombre_completo, rut, cv_ruta_archivo, experiencia_sin_cv FROM postulaciones WHERE id = :id');
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
    } elseif (!empty($p['experiencia_sin_cv'])) {
        // v10.29 (pedido explícito del usuario): sin CV adjunto, se arma un PDF
        // con portada "Experiencia laboral" + nombre + RUT + la experiencia que
        // la persona escribió, para que su carpeta siempre tenga un CV.pdf.
        require_once __DIR__ . '/pdf_simple.php';
        file_put_contents(
            $rutaDocsPersonales . '/CV.pdf',
            generarPdfExperiencia($p['nombre_completo'], $p['rut'], $p['experiencia_sin_cv'], OBRA_NOMBRE)
        );
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
        throw new RuntimeException('Formato no permitido para ' . $etiquetaCampo . '. Súbelo en PDF, JPG o PNG.');
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
// v10.46 (pedido del usuario, prueba real del 17-11): la IRL del Día 1 tiene DOS confirmaciones de Prevención.
//   1) Presentes en la sala  -> la marca ANTIGUA (ACCION_IRL_REALIZADA, ya guardada para quienes Paula marcó) pasa a
//      significar solo esto, y las nuevas se guardan como ACCION_IRL_PRESENTES. Nada se borra ni se migra.
//   2) IRL realizada         -> ACCION_IRL_COMPLETADA: esta es la que habilita a Bodega.
const ACCION_IRL_REALIZADA = 'Prevención registró la IRL realizada.';
const ACCION_IRL_PRESENTES = 'Prevención confirmó que la persona está presente en la sala (IRL).';
const ACCION_IRL_COMPLETADA = 'Prevención confirmó que la IRL se realizó.';

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
        "SELECT x.id, x.nombre_completo, x.rut, x.cargo_id, x.nombre_cargo, x.talla_calzado, x.talla_overol, x.ingreso_compania, x.completado_at
           FROM (
                SELECT p.id, p.nombre_completo, p.rut, p.cargo_id, c.nombre_cargo, d.talla_calzado, d.talla_overol, j.ingreso_compania,
                       (SELECT MAX(t.fecha_hora) FROM trazabilidad_logs t
                         WHERE t.postulacion_id = p.id
                           AND t.accion LIKE 'Cambio de estado: % -> Aprobado_admin') AS completado_at
                  FROM postulaciones p
                  JOIN cargos c ON c.id = p.cargo_id
                  JOIN datos_contratacion d ON d.postulacion_id = p.id
                  LEFT JOIN datos_jao j ON j.postulacion_id = p.id
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
    // v10.49: el kit de EPP de cada persona (según su cargo) y el total a preparar. Si algo falla, el correo sale igual.
    $kitTotales = [];
    try {
        $kitTotales = adjuntarKitEpp($pdo, $personas);
    } catch (\Throwable $e) {
        error_log('enviarTallasABodega kit EPP error: ' . $e->getMessage());
    }
    $kitTotalesHtml = '';
    if ($kitTotales) {
        $kitTotalesHtml = '<h3 style="margin:22px 0 6px;font-size:14px;color:#111827">Total a preparar</h3>'
            . '<table style="border-collapse:collapse;border:1px solid #e5e7eb;font-size:13px">';
        foreach ($kitTotales as $t) {
            $detalle = $t['tallas'] ? ' <span style="color:#6b7280">(' . $esc(implode(', ', array_map(fn ($k, $n) => "{$k}: {$n}", array_keys($t['tallas']), $t['tallas']))) . ')</span>' : '';
            $kitTotalesHtml .= '<tr><td style="padding:5px 10px;border-bottom:1px solid #e5e7eb">' . $esc($t['nombre']) . $detalle
                . '</td><td style="padding:5px 10px;border-bottom:1px solid #e5e7eb;font-weight:bold;text-align:right">' . (int)$t['total'] . '</td></tr>';
        }
        $kitTotalesHtml .= '</table>';
    }
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
            . '<td style="' . $celda . 'font-size:11px;color:#374151">' . $esc($p['kit_texto'] ?? '') . '</td>'
            . '</tr>';
    }

    $fecha = (new DateTime())->format('d-m-Y');
    $hora = (new DateTime())->format('H:i');
    $total = count($personas);
    $obra = OBRA_NOMBRE;
    $html = (function () use ($fecha, $hora, $total, $obra, $filasHtml, $kitTotalesHtml) {
        return require __DIR__ . '/../mailer/templates/tallas_bodega.php';
    })();

    $asunto = "Tallas de postulantes para preparar kits de EPP - {$fecha} ({$total}) - ICAFAL";
    // v10.38: el mismo listado, en Excel adjunto (nombre, RUT, cargo, fecha de ingreso y tallas).
    $excel = excelEnMemoria('Tallas EPP', ['N°', 'Nombre', 'RUT', 'Cargo', 'Fecha de ingreso', 'N° calzado', 'Talla overol', 'Kit de EPP'],
        array_map(fn ($i, $p) => [$i + 1, $p['nombre_completo'], $p['rut'], $p['nombre_cargo'], fechaIngresoTexto($p['ingreso_compania'] ?? null), $p['talla_calzado'], $p['talla_overol'], $p['kit_texto'] ?? ''],
            array_keys(array_values($personas)), array_values($personas)));
    $adjuntos = adjuntoExcel('Tallas_EPP_' . (new DateTime())->format('Ymd_Hi') . '.xlsx', $excel);
    $enviados = 0;
    foreach ($destinatarios as $d) {
        if (Mailer::enviar($d['correo'], $d['nombre'], $asunto, $html, true, $adjuntos)) {
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
 * v10.38 - Envío AUTOMÁTICO de las tallas a Bodega (antes solo salía si Bodega apretaba el botón).
 * No hay un proceso programado en el servidor, así que se revisa cuando los paneles consultan
 * (el "estado en vivo" y el panel de Bodega lo hacen cada pocos segundos). Un mismo envío no se
 * repite: cada persona incluida queda marcada en la bitácora.
 *
 *  TALLAS_HORA_ENVIO       hora fija del día, "HH:MM" (por defecto 14:00; "off" la desactiva). A esa hora
 *                          sale UN correo con todo lo pendiente (si hoy no salió ya uno).
 *  TALLAS_ESPERA_MINUTOS   si es > 0, sale un correo cuando pasan esos minutos sin que nadie nuevo
 *                          complete su Etapa 2 ("terminamos de generar a las personas"). Pensado para
 *                          pruebas en que Día 0 y Día 1 ocurren el mismo día. Por defecto 0 (apagado).
 */
function enviarTallasAutomatico(PDO $pdo): void
{
    $horaCfg = getenv('TALLAS_HORA_ENVIO');
    $hora = ($horaCfg === false || $horaCfg === '') ? '14:00' : strtolower(trim($horaCfg));
    $horaValida = (bool)preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $hora);
    $espera = (int)(getenv('TALLAS_ESPERA_MINUTOS') ?: 0);
    if (!$horaValida && $espera <= 0) {
        return;
    }

    $lock = 'icafal_tallas_' . DB_NAME;
    if (!(int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn()) {
        return; // otra consulta ya lo está haciendo
    }
    try {
        $personas = tallasSinEnviar($pdo);
        if (!$personas) {
            return;
        }
        $ahora = time();
        $dispara = false;
        if ($horaValida && $ahora >= strtotime(date('Y-m-d') . ' ' . $hora . ':00')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM trazabilidad_logs WHERE accion = :a AND fecha_hora >= CURDATE()');
            $stmt->execute(['a' => ACCION_TALLAS_ENVIADAS]);
            $dispara = (int)$stmt->fetchColumn() === 0;     // a la hora fija, una vez por día
        }
        if (!$dispara && $espera > 0) {
            $ultimo = 0;
            foreach ($personas as $p) {
                $ultimo = max($ultimo, (int)strtotime((string)$p['completado_at']));
            }
            $dispara = $ultimo > 0 && ($ahora - $ultimo) >= $espera * 60;
        }
        if ($dispara) {
            enviarTallasABodega($pdo, $personas, null);
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
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
        "SELECT x.id, x.nombre_completo, x.rut, x.nombre_cargo, x.liberado_at, x.seleccionado_por,
                x.ingreso_compania, x.talla_calzado, x.talla_overol
           FROM (
                SELECT p.id, p.nombre_completo, p.rut, c.nombre_cargo, j.ingreso_compania, d.talla_calzado, d.talla_overol,
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
                  LEFT JOIN datos_jao j ON j.postulacion_id = p.id
                  LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
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
 * v10.39 (pedido explicito del usuario): "que Bodega vea quienes ya estan
 * liberados para el dia siguiente y pueda descargar el Excel por dia".
 *
 * "Liberado" = Prevencion hizo el check del Dia 0 (estado Induccion_ok); desde ahi
 * la persona queda lista para ingresar al dia siguiente y para armar su kit. Se
 * incluyen tambien quienes ya avanzaron (EPP_listo, Contratado, Proceso_completo)
 * para que el historial del dia no desaparezca al entregar el EPP.
 * El dia de liberacion es el dia del check de Prevencion; "ingresa" es la fecha que
 * puso el JAO o, si todavia no la puso, el dia siguiente.
 * Incluye el capataz que lo selecciono, su Profesional de Terreno y el frente.
 * $desde / $hasta: 'Y-m-d' (ambos inclusive).
 */
function liberadosPorDia(PDO $pdo, string $desde, string $hasta): array
{
    $tieneFrente = usuariosTieneFrente($pdo);
    $colFrente = $tieneFrente ? 'cap.frente' : 'NULL';
    $stmt = $pdo->prepare(
        "SELECT x.* FROM (
            SELECT p.id, p.nombre_completo, p.rut, c.nombre_cargo, p.estado,
                   d.talla_calzado, d.talla_overol, j.ingreso_compania,
                   cap.nombre AS capataz, {$colFrente} AS frente,
                   COALESCE(pt.nombre, IF(cap.rol = 'Jefe_Terreno', cap.nombre, NULL)) AS profesional_terreno,
                   (SELECT MAX(t.fecha_hora) FROM trazabilidad_logs t
                     WHERE t.postulacion_id = p.id
                       AND t.accion LIKE 'Cambio de estado: % -> Induccion_ok') AS liberado_at
              FROM postulaciones p
              JOIN cargos c ON c.id = p.cargo_id
              LEFT JOIN datos_contratacion d ON d.postulacion_id = p.id
              LEFT JOIN datos_jao j ON j.postulacion_id = p.id
              LEFT JOIN usuarios cap ON cap.id = (
                    SELECT t2.usuario_id FROM trazabilidad_logs t2
                     WHERE t2.postulacion_id = p.id
                       AND t2.accion LIKE 'Cambio de estado: % -> Pre_aprobado_terreno'
                     ORDER BY t2.fecha_hora ASC, t2.id ASC LIMIT 1)
              LEFT JOIN usuarios pt ON pt.id = cap.jefe_terreno_id
             WHERE p.estado IN ('Induccion_ok', 'EPP_listo', 'Contratado', 'Proceso_completo')
         ) x
         WHERE DATE(x.liberado_at) BETWEEN :desde AND :hasta
         ORDER BY x.liberado_at ASC, x.id ASC"
    );
    $stmt->execute(['desde' => $desde, 'hasta' => $hasta]);
    $filas = $stmt->fetchAll();
    $etiquetas = [
        'Induccion_ok'     => 'Liberado: ingresa mañana',
        'EPP_listo'        => 'EPP entregado',
        'Contratado'       => 'Contratado',
        'Proceso_completo' => 'En terreno',
    ];
    foreach ($filas as &$f) {
        $f['id'] = (int)$f['id'];
        $f['estado_texto'] = $etiquetas[$f['estado']] ?? $f['estado'];
        $f['dia_liberacion'] = substr((string)$f['liberado_at'], 0, 10);
        $f['fecha_ingreso'] = $f['ingreso_compania']
            ?: (new DateTime($f['dia_liberacion']))->modify('+1 day')->format('Y-m-d');
    }
    unset($f);
    return $filas;
}

/** v10.39: la columna usuarios.frente existe solo despues de la migracion v10.39 (el codigo no se cae sin ella). */
function usuariosTieneFrente(PDO $pdo): bool
{
    static $r = null;
    if ($r === null) {
        $r = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'frente'")->fetch();
    }
    return $r;
}

/**
 * v10.40: true cuando ya se aplico la migracion v10.40 (cupos por capataz y capataz_id en
 * postulaciones). Mientras no, todo el codigo se comporta como antes (sin cupos por capataz
 * y sin filtro por Profesional de Terreno), para que un deploy antes de la migracion no
 * rompa la seleccion en terreno.
 */
function usaCuposPorCapataz(PDO $pdo): bool
{
    static $r = null;
    if ($r === null) {
        try {
            $r = (bool)$pdo->query("SHOW TABLES LIKE 'cupos_capataz'")->fetch()
                && (bool)$pdo->query("SHOW COLUMNS FROM postulaciones LIKE 'capataz_id'")->fetch()
                && (bool)$pdo->query("SHOW COLUMNS FROM solicitudes_cupo LIKE 'capataz_id'")->fetch();
        } catch (\Throwable $e) {
            $r = false;
        }
    }
    return $r;
}

/** v10.40: ids del equipo de un Profesional de Terreno: el mismo + sus Capataces activos. */
function idsEquipoDelPT(PDO $pdo, int $ptId): array
{
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE rol = 'Capataz' AND activo = 1 AND jefe_terreno_id = :pt");
    $stmt->execute(['pt' => $ptId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ids[] = $ptId;
    return $ids;
}

/**
 * v10.40: fragmento SQL (" AND ...") que deja ver a un Profesional de Terreno solo a SU gente:
 * las personas cuyo capataz es del equipo. Con $conPendientes tambien deja pasar a quienes
 * aun esperan seleccion (todavia no tienen capataz), salvo los referidos a un capataz ajeno.
 * Sin la migracion v10.40 no filtra nada. Los ids son enteros (seguro de interpolar).
 */
function condicionGentePT(PDO $pdo, int $ptId, bool $conPendientes = false, string $alias = 'p'): string
{
    if (!usaCuposPorCapataz($pdo)) {
        return '';
    }
    $in = implode(',', idsEquipoDelPT($pdo, $ptId));
    $cond = "{$alias}.capataz_id IN ($in)";
    if ($conPendientes) {
        $cond .= " OR ({$alias}.estado = 'Pendiente' AND ({$alias}.capataz_esperado_id IS NULL OR {$alias}.capataz_esperado_id IN ($in)))";
    }
    return " AND ($cond)";
}

/**
 * v10.41: igual que condicionGentePT pero para un Capataz: solo ve a SU cuadrilla (las
 * personas que el selecciono). Con $conPendientes tambien deja pasar a quienes aun esperan
 * seleccion (sin capataz, o referidos a el). Sin la migracion v10.40 no filtra nada.
 */
function condicionGenteCapataz(PDO $pdo, int $capatazId, bool $conPendientes = false, string $alias = 'p'): string
{
    if (!usaCuposPorCapataz($pdo)) {
        return '';
    }
    $cond = "{$alias}.capataz_id = " . (int)$capatazId;
    if ($conPendientes) {
        $cond .= " OR ({$alias}.estado = 'Pendiente' AND ({$alias}.capataz_esperado_id IS NULL OR {$alias}.capataz_esperado_id = " . (int)$capatazId . "))";
    }
    return " AND ($cond)";
}

/**
 * v10.40: cupos de un Capataz por cargo = los reservados para el + los generales (los que
 * quedan en el cargo sin reservar para nadie). Devuelve filas
 * [id, nombre_cargo, propios, generales, disponibles].
 */
function cuposDelCapataz(PDO $pdo, int $capatazId): array
{
    $cargos = $pdo->query('SELECT id, nombre_cargo, cupos_activos FROM cargos WHERE activo = 1 ORDER BY nombre_cargo')->fetchAll();
    $propios = [];
    $reservadoTotal = [];
    if (usaCuposPorCapataz($pdo)) {
        foreach ($pdo->query('SELECT capataz_id, cargo_id, reservados FROM cupos_capataz WHERE reservados > 0')->fetchAll() as $r) {
            $reservadoTotal[(int)$r['cargo_id']] = ($reservadoTotal[(int)$r['cargo_id']] ?? 0) + (int)$r['reservados'];
            if ((int)$r['capataz_id'] === $capatazId) {
                $propios[(int)$r['cargo_id']] = (int)$r['reservados'];
            }
        }
    }
    $out = [];
    foreach ($cargos as $c) {
        $id = (int)$c['id'];
        $generales = max(0, (int)$c['cupos_activos'] - ($reservadoTotal[$id] ?? 0));
        $mios = $propios[$id] ?? 0;
        $out[] = ['id' => $id, 'nombre_cargo' => $c['nombre_cargo'], 'propios' => $mios,
                  'generales' => $generales, 'disponibles' => $mios + $generales];
    }
    return $out;
}

/** Lee desde/hasta de la URL ('Y-m-d'); por defecto, hoy. Corrige el orden. */
function rangoFechasDeUrl(): array
{
    $valida = static function ($v): bool {
        if (!is_string($v)) {
            return false;
        }
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    };
    $hoy = date('Y-m-d');
    $desde = $valida($_GET['desde'] ?? null) ? $_GET['desde'] : $hoy;
    $hasta = $valida($_GET['hasta'] ?? null) ? $_GET['hasta'] : $desde;
    if ($hasta < $desde) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    return [$desde, $hasta];
}

/** Aplica los filtros opcionales de la URL (cargo, capataz, estado, texto) a la lista. */
function filtrarLiberadosDeUrl(array $filas): array
{
    $cargo = trim((string)($_GET['cargo'] ?? ''));
    $capataz = trim((string)($_GET['capataz'] ?? ''));
    $estado = trim((string)($_GET['estado'] ?? ''));
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    return array_values(array_filter($filas, function ($f) use ($cargo, $capataz, $estado, $q) {
        if ($cargo !== '' && $f['nombre_cargo'] !== $cargo) return false;
        if ($capataz !== '' && (string)$f['capataz'] !== $capataz) return false;
        if ($estado !== '' && $f['estado'] !== $estado) return false;
        if ($q !== '' && mb_strpos(mb_strtolower($f['nombre_completo'] . ' ' . $f['rut']), $q) === false) return false;
        return true;
    }));
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
    // v10.38: Excel adjunto con nombre, RUT, cargo, fecha de ingreso y tallas de cada uno.
    $excel = excelEnMemoria('Nómina liberados', ['N°', 'Nombre', 'RUT', 'Cargo', 'Fecha de ingreso', 'N° calzado', 'Talla overol', 'Liberado', 'Seleccionado por'],
        array_map(fn ($i, $l) => [$i + 1, $l['nombre_completo'], $l['rut'], $l['nombre_cargo'], fechaIngresoTexto($l['ingreso_compania'] ?? null),
            $l['talla_calzado'] ?? '-', $l['talla_overol'] ?? '-', !empty($l['liberado_at']) ? (new DateTime($l['liberado_at']))->format('d-m-Y H:i') : '-', $l['seleccionado_por'] ?? '-'],
            array_keys(array_values($liberados)), array_values($liberados)));
    $adjuntos = adjuntoExcel('Nomina_liberados_' . (new DateTime())->format('Ymd_Hi') . '.xlsx', $excel);
    $enviados = 0;
    foreach ($destinatarios as $d) {
        if (Mailer::enviar($d['correo'], $d['nombre'], $asunto, $html, true, $adjuntos)) {
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
        'accion_irl' => ACCION_IRL_COMPLETADA,
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
    if (!interruptorCorreo('EMAIL_JAO_ACTIVO')) {
        return; // v10.38
    }
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

/**
 * v10.27 - Límite de intentos fallidos (login y consultas públicas).
 *
 * Se cuenta por "clave" y no por IP: detrás del proxy de Render la IP del
 * cliente se puede falsear, y en la obra muchas personas comparten la
 * misma red. Para el login la clave es el correo; para seguimiento y
 * "recuperar mis datos" es el documento consultado -- así quien intenta
 * adivinar el correo/código de UNA persona se frena a los 10 intentos.
 *
 * A prueba de fallos: si la tabla todavía no existe (migración sin
 * correr) o la consulta falla, NO se bloquea a nadie -- el límite es una
 * protección extra, nunca debe tumbar el ingreso.
 */
const INTENTOS_MAXIMOS = 10;
const INTENTOS_VENTANA_MINUTOS = 15;

function intentosBloqueado(PDO $pdo, string $tipo, string $clave): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM intentos_fallidos
              WHERE tipo = :tipo AND clave = :clave
                AND creado_at > DATE_SUB(NOW(), INTERVAL ' . INTENTOS_VENTANA_MINUTOS . ' MINUTE)'
        );
        $stmt->execute(['tipo' => $tipo, 'clave' => mb_substr($clave, 0, 190)]);
        return (int)$stmt->fetchColumn() >= INTENTOS_MAXIMOS;
    } catch (Throwable $e) {
        error_log('intentosBloqueado: ' . $e->getMessage());
        return false;
    }
}

function registrarIntentoFallido(PDO $pdo, string $tipo, string $clave): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO intentos_fallidos (tipo, clave) VALUES (:tipo, :clave)');
        $stmt->execute(['tipo' => $tipo, 'clave' => mb_substr($clave, 0, 190)]);
        // Limpieza ocasional de filas viejas para que la tabla no crezca.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM intentos_fallidos WHERE creado_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        error_log('registrarIntentoFallido: ' . $e->getMessage());
    }
}

function limpiarIntentosFallidos(PDO $pdo, string $tipo, string $clave): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM intentos_fallidos WHERE tipo = :tipo AND clave = :clave');
        $stmt->execute(['tipo' => $tipo, 'clave' => mb_substr($clave, 0, 190)]);
    } catch (Throwable $e) {
        error_log('limpiarIntentosFallidos: ' . $e->getMessage());
    }
}

function responderBloqueoIntentos(): never
{
    responderError(
        'Demasiados intentos fallidos. Espera ' . INTENTOS_VENTANA_MINUTOS . ' minutos e inténtalo de nuevo.',
        429
    );
}

/**
 * v10.29 - Validación de formato de datos que antes entraban como texto libre.
 * Cada función devuelve el valor normalizado, o null si no es válido.
 */
function normalizarTelefono(string $t): ?string
{
    $t = preg_replace('/[\s\-\.\(\)]/', '', $t);
    if (!preg_match('/^\+?\d{8,15}$/', $t)) {
        return null;
    }
    if (preg_match('/^9\d{8}$/', $t)) {
        return '+56' . $t; // celular chileno escrito sin prefijo
    }
    if (preg_match('/^569\d{8}$/', $t)) {
        return '+' . $t;
    }
    return $t;
}

function normalizarTallaCalzado(string $t): ?string
{
    $t = str_replace(',', '.', trim($t));
    return preg_match('/^(3[4-9]|4[0-9]|50)(\.5)?$/', $t) ? $t : null;
}

function normalizarTallaOverol(string $t): ?string
{
    $t = strtoupper(str_replace([' ', '-'], '', trim($t)));
    $t = ['2XL' => 'XXL', '3XL' => 'XXXL'][$t] ?? $t;
    if (preg_match('/^(XS|S|M|L|XL|XXL|XXXL)$/', $t)) {
        return $t;
    }
    return preg_match('/^(3[4-9]|[4-6][0-9]|70)$/', $t) ? $t : null;
}

/**
 * v10.31 - Historial de una persona (por RUT): cuántas veces ya trabajó antes y
 * qué recomendación dejó el Jefe de Terreno la última vez que fue evaluada.
 *
 * Recibe una lista de postulaciones (cada una con 'id' y 'rut') y devuelve un
 * mapa [id => ['veces_anteriores' => n, 'recomendacion_anterior' => 'si'|'con_reservas'|'no'|null,
 * 'comentario_anterior' => string|null]]. A prueba de fallos: si la tabla de
 * evaluaciones aún no existe, devuelve solo el conteo; si nada funciona, [].
 */
function resumenHistorialPorRut(PDO $pdo, array $postulaciones): array
{
    $ruts = array_values(array_unique(array_filter(array_map(fn ($p) => $p['rut'] ?? null, $postulaciones))));
    if (!$ruts) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ruts), '?'));
    $filas = null;
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.rut, e.recomienda, e.comentario
               FROM postulaciones p
               LEFT JOIN evaluaciones_trabajador e ON e.postulacion_id = p.id
              WHERE p.rut IN ($in) AND p.estado IN ('Contratado', 'Proceso_completo')
              ORDER BY p.creado_at DESC, p.id DESC"
        );
        $stmt->execute($ruts);
        $filas = $stmt->fetchAll();
    } catch (PDOException $e) {
        try {
            $stmt = $pdo->prepare(
                "SELECT p.id, p.rut, NULL AS recomienda, NULL AS comentario
                   FROM postulaciones p
                  WHERE p.rut IN ($in) AND p.estado IN ('Contratado', 'Proceso_completo')"
            );
            $stmt->execute($ruts);
            $filas = $stmt->fetchAll();
        } catch (PDOException $e2) {
            error_log('resumenHistorialPorRut: ' . $e2->getMessage());
            return [];
        }
    }

    $resultado = [];
    foreach ($postulaciones as $p) {
        $veces = 0;
        $recomendacion = null;
        $comentario = null;
        foreach ($filas as $f) {
            if ($f['rut'] !== $p['rut'] || (int)$f['id'] === (int)$p['id']) {
                continue;
            }
            $veces++;
            if ($recomendacion === null && $f['recomienda'] !== null) {
                $recomendacion = $f['recomienda'];
                $comentario = $f['comentario'];
            }
        }
        $resultado[$p['id']] = [
            'veces_anteriores' => $veces,
            'recomendacion_anterior' => $recomendacion,
            'comentario_anterior' => $comentario,
        ];
    }
    return $resultado;
}

// ---------------------------------------------------------------------
// v10.49 - Kit de EPP por cargo (lo configura Prevención; Bodega lo ve desde el Día 0).
// Modelo: epp_items (catálogo) + epp_kit_cargo (cantidades). cargo_id = 0 es el KIT BASE; para un
// cargo solo se guardan las diferencias contra el base (extra / otra cantidad u otro color /
// cantidad 0 = no lo lleva).
// v10.51: el casco puede ir en color (rojo / amarillo / azul): epp_items.colores, epp_items.icono y
// epp_kit_cargo.color. Todo es a prueba de bases sin las migraciones: sin las tablas el kit es vacío,
// y sin las columnas nuevas simplemente no hay color.
// ---------------------------------------------------------------------

const EPP_COLORES = ['rojo', 'amarillo', 'azul'];

/** ¿Existen las tablas del kit de EPP? (migración v10.49) */
function usaKitEpp(PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $pdo->query('SELECT 1 FROM epp_items LIMIT 1');
            $pdo->query('SELECT 1 FROM epp_kit_cargo LIMIT 1');
            $existe = true;
        } catch (\Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** ¿Existen las columnas de color / icono? (migración v10.51) */
function usaColorEpp(PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $pdo->query('SELECT colores, icono FROM epp_items LIMIT 1');
            $pdo->query('SELECT color FROM epp_kit_cargo LIMIT 1');
            $existe = true;
        } catch (\Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** Color válido o null. */
function colorEppValido($c): ?string
{
    $c = is_string($c) ? strtolower(trim($c)) : '';
    return in_array($c, EPP_COLORES, true) ? $c : null;
}

/** Carga una vez por petición: catálogo activo, kit base y diferencias por cargo. */
function eppMapa(PDO $pdo): array
{
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }
    $mapa = ['items' => [], 'base' => [], 'cargo' => []];
    if (!usaKitEpp($pdo)) {
        return $mapa;
    }
    try {
        $conColor = usaColorEpp($pdo);
        $selItems = $conColor
            ? 'SELECT id, nombre, categoria, tipo_talla, colores, icono, orden FROM epp_items WHERE activo = 1 ORDER BY orden, id'
            : "SELECT id, nombre, categoria, tipo_talla, 0 AS colores, 'extra' AS icono, orden FROM epp_items WHERE activo = 1 ORDER BY orden, id";
        foreach ($pdo->query($selItems) as $i) {
            $mapa['items'][(int)$i['id']] = $i;
        }
        $selKit = $conColor ? 'SELECT cargo_id, item_id, cantidad, color FROM epp_kit_cargo'
                            : 'SELECT cargo_id, item_id, cantidad, NULL AS color FROM epp_kit_cargo';
        foreach ($pdo->query($selKit) as $r) {
            $fila = ['q' => (int)$r['cantidad'], 'c' => colorEppValido($r['color'])];
            if ((int)$r['cargo_id'] === 0) {
                $mapa['base'][(int)$r['item_id']] = $fila;
            } else {
                $mapa['cargo'][(int)$r['cargo_id']][(int)$r['item_id']] = $fila;
            }
        }
    } catch (\Throwable $e) {
        error_log('eppMapa error: ' . $e->getMessage());
        $mapa = ['items' => [], 'base' => [], 'cargo' => []];
    }
    return $mapa;
}

/**
 * Kit efectivo de un cargo: kit base + diferencias del cargo (cantidad 0 = lo quita).
 * Devuelve [['item_id','nombre','categoria','tipo_talla','colores','icono','cantidad','color'], ...] en el orden del catálogo.
 */
function kitEppDelCargo(PDO $pdo, int $cargoId): array
{
    $m = eppMapa($pdo);
    $filas = $m['base'];
    foreach (($m['cargo'][$cargoId] ?? []) as $itemId => $fila) {
        $filas[$itemId] = $fila;
    }
    $kit = [];
    foreach ($m['items'] as $itemId => $i) {
        $f = $filas[$itemId] ?? null;
        if ($f && $f['q'] > 0) {
            $kit[] = ['item_id' => $itemId, 'nombre' => $i['nombre'], 'categoria' => $i['categoria'],
                      'tipo_talla' => $i['tipo_talla'], 'colores' => (int)$i['colores'] === 1, 'icono' => $i['icono'],
                      'cantidad' => $f['q'], 'color' => ((int)$i['colores'] === 1) ? $f['c'] : null];
        }
    }
    return $kit;
}

/**
 * Agrega a cada persona (con cargo_id, talla_calzado, talla_overol) su kit ya resuelto:
 *   'kit'       => [['nombre','cantidad','talla','color','icono'], ...]  (talla = N° de calzado / talla de overol, o '')
 *   'kit_texto' => "Casco de seguridad ×1 (amarillo) · Zapato de seguridad ×1 (42) · ..."
 * y devuelve el total a preparar por elemento: [['nombre','categoria','total','tallas'=>['42'=>2,'rojo'=>1,...]], ...]
 * ('tallas' agrupa por talla o, en los elementos con colores, por color).
 */
function adjuntarKitEpp(PDO $pdo, array &$personas): array
{
    $totales = [];
    foreach ($personas as &$p) {
        $kit = [];
        foreach (kitEppDelCargo($pdo, (int)($p['cargo_id'] ?? 0)) as $it) {
            $talla = $it['tipo_talla'] === 'calzado' ? (string)($p['talla_calzado'] ?? '')
                   : ($it['tipo_talla'] === 'overol' ? (string)($p['talla_overol'] ?? '') : '');
            $color = (string)($it['color'] ?? '');
            $kit[] = ['nombre' => $it['nombre'], 'cantidad' => $it['cantidad'], 'talla' => $talla, 'color' => $color, 'icono' => $it['icono']];
            $k = $it['item_id'];
            $totales[$k] = $totales[$k] ?? ['nombre' => $it['nombre'], 'categoria' => $it['categoria'], 'total' => 0, 'tallas' => []];
            $totales[$k]['total'] += $it['cantidad'];
            $clave = $talla !== '' ? $talla : $color;
            if ($clave !== '') {
                $totales[$k]['tallas'][$clave] = ($totales[$k]['tallas'][$clave] ?? 0) + $it['cantidad'];
            }
        }
        $p['kit'] = $kit;
        $p['kit_texto'] = implode(' · ', array_map(function ($x) {
            $det = array_filter([$x['color'], $x['talla']], fn ($v) => $v !== '');
            return $x['nombre'] . ' ×' . $x['cantidad'] . ($det ? ' (' . implode(', ', $det) . ')' : '');
        }, $kit));
    }
    unset($p);
    foreach ($totales as &$t) {
        ksort($t['tallas'], SORT_NATURAL);
    }
    unset($t);
    return array_values($totales);
}
