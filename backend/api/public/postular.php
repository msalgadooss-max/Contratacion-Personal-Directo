<?php
/**
 * Etapa 1 - Postulacion publica (accedida via QR).
 * Genera el codigo_seguimiento y dispara el correo de confirmacion.
 * No requiere sesion.
 *
 * v3 - Campos alineados a "Template Empleados.xls" (columnas VERDES):
 * tipo_documento, numero de documento, apellido, segundo_apellido,
 * nombre, telefono, correo. Ademas los campos propios de este sistema
 * que Buk no modela: cargo postulado, CV y consentimiento Ley 19.628.
 *
 * v10.14 (pedido explicito del usuario): region/comuna se sacan de aca
 * -- se pedian dos veces (aca y de nuevo en Etapa 2), asi que se dejan
 * solo en Etapa 2 (completar.js/guardar_datos.php), junto con ciudad,
 * pais y direccion exacta. Las columnas postulaciones.region/comuna
 * quedan en el esquema (no se borran) pero ahora nacen vacias; quien
 * necesite la comuna real de alguien que aun no completa Etapa 2 no la
 * va a tener hasta ese momento.
 *
 * v2 - Banco de Postulantes: si el cargo elegido existe pero no tiene
 * cupos_activos, la postulacion queda en estado 'En_banco' en vez de
 * ser rechazada (ver backend/api/terreno/banco_invitar.php).
 *
 * v10.5 (Mejorar APP, punto 2) - El postulante YA NO elige cargo: el
 * Capataz se lo asigna despues, en persona, al seleccionarlo en terreno
 * (ver terreno/aprobar.php). Toda postulacion nueva nace apuntando al
 * cargo interno "Por asignar" y siempre en estado 'Pendiente' -- el
 * chequeo de cupos se movio integro al momento en que el Capataz asigna
 * el cargo real, asi que el camino de "Banco de Postulantes" de aqui ya
 * no se activa nunca (queda su tabla/flujo intactos por si se retoma).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rut.php';
require_once __DIR__ . '/../../includes/listas_buk.php';
require_once __DIR__ . '/../../mailer/Mailer.php';

exigirMetodo('POST');

$tipoDocumento    = $_POST['tipo_documento'] ?? '';
$numeroDocumento  = limpiarTexto($_POST['numero_documento'] ?? '', 20);
$apellido         = limpiarTexto($_POST['apellido'] ?? '', 100);
$segundoApellido  = limpiarTexto($_POST['segundo_apellido'] ?? '', 100);
$nombre           = limpiarTexto($_POST['nombre'] ?? '', 100);
$telefono         = limpiarTexto($_POST['telefono'] ?? '', 20);
$correo           = filter_var(trim((string)($_POST['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
$consentimiento   = (bool)($_POST['consentimiento_ley19628'] ?? false);

$listas = listasBuk();

// --- Validaciones server-side (nunca confiar solo en el frontend) -------
$errores = [];
if (!in_array($tipoDocumento, $listas['tipo_documento'], true)) {
    $errores[] = 'Tipo de documento inválido.';
} elseif (!validarDocumento($tipoDocumento, $numeroDocumento)) {
    $errores[] = $tipoDocumento === 'RUT' ? 'RUT inválido.' : 'Debes indicar tu número de documento.';
}
if ($apellido === '')                        $errores[] = 'Apellido es obligatorio.';
if ($segundoApellido === '')                 $errores[] = 'Segundo apellido es obligatorio.';
if ($nombre === '')                          $errores[] = 'Nombre es obligatorio.';
if ($telefono === '')                        $errores[] = 'Teléfono es obligatorio.';
if (!$correo)                                $errores[] = 'Correo inválido.';
if (!$consentimiento)                        $errores[] = 'Debe aceptar el tratamiento de datos personales (Ley 19.628).';

if ($errores) {
    responderError(implode(' ', $errores), 422);
}

$numeroDocumento = normalizarDocumento($tipoDocumento, $numeroDocumento);
$nombreCompleto = trim($nombre . ' ' . $apellido . ' ' . $segundoApellido);

$pdo = obtenerConexion();

// v10.5: ya no se le pide cargo al postulante -- nace apuntando al
// cargo interno "Por asignar" (nunca visible para el, ver comentario de
// cabecera) y siempre en estado 'Pendiente'. El Capataz reemplaza este
// cargo por el real, y ahi recien se valida que tenga cupos.
$stmtCargoAsignar = $pdo->prepare("SELECT id FROM cargos WHERE nombre_cargo = 'Por asignar' LIMIT 1");
$stmtCargoAsignar->execute();
$cargoAsignar = $stmtCargoAsignar->fetch();
if (!$cargoAsignar) {
    error_log('postular.php: falta el cargo interno "Por asignar" en la tabla cargos.');
    responderError('No fue posible registrar tu postulación. Intenta más tarde.', 500);
}
$cargoId = (int)$cargoAsignar['id'];
$estadoInicial = 'Pendiente';

// v10.15 (corrección, pedido explícito del usuario, item 3 de la lista
// post-prueba -- "plan para personal recontratado"): antes esto
// bloqueaba una postulación nueva si el RUT existía en CUALQUIER
// estado, para siempre -- alguien que ya trabajó y terminó su ciclo
// (Rechazado, o Contratado/Proceso_completo de un ciclo anterior) nunca
// podía volver a postular, ni siquiera meses después. Ahora solo se
// bloquea si hay una postulación de este RUT todavía EN CURSO (nadie
// necesita dos postulaciones activas al mismo tiempo); una vez que su
// ciclo anterior terminó -- para bien (Contratado/Proceso_completo) o
// para mal (Rechazado) -- puede volver a postular con normalidad.
$stmtDup = $pdo->prepare(
    "SELECT id FROM postulaciones
      WHERE rut = :rut AND estado NOT IN ('Rechazado', 'Contratado', 'Proceso_completo')"
);
$stmtDup->execute(['rut' => $numeroDocumento]);
if ($stmtDup->fetch()) {
    responderError('Ya existe una postulación registrada con ese documento. Usa el módulo de seguimiento para ver su estado.', 409);
}

// v6.9: "No tengo CV" -- si el postulante no adjunta CV, puede en vez de
// eso contar su ultima experiencia en 3 campos simples (pedido de
// Ricardo, reunion 28-ago, para no bloquear a quien nunca ha trabajado
// o no trae su CV a mano). Se exige uno de los dos caminos, no ninguno.
$experienciaCargo = limpiarTexto($_POST['experiencia_cargo'] ?? '', 150);
$experienciaFecha = limpiarTexto($_POST['experiencia_fecha'] ?? '', 100);
$experienciaDescripcion = limpiarTexto($_POST['experiencia_descripcion'] ?? '', 1000);
$tieneArchivoCv = ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

$cvRuta = null;
$experienciaSinCv = null;

// v10.15 (pedido explícito del usuario, item 3): "personal recontratado"
// -- si vino de la búsqueda de "recuperar mis datos" (ver
// buscar_postulante_anterior.php) y eligió reusar el CV de esa
// postulación anterior, se vuelve a validar acá mismo que ese RUT+correo
// coincidan EXACTAMENTE con lo que se está enviando ahora -- así este id
// no sirve por sí solo para copiarse el CV de otra persona.
$reutilizarCvDe = (int)($_POST['reutilizar_cv_de'] ?? 0);
if (!$tieneArchivoCv && $reutilizarCvDe > 0) {
    $stmtCvAnterior = $pdo->prepare(
        'SELECT rut, correo, cv_ruta_archivo FROM postulaciones WHERE id = :id'
    );
    $stmtCvAnterior->execute(['id' => $reutilizarCvDe]);
    $postulacionAnterior = $stmtCvAnterior->fetch();
    if ($postulacionAnterior
        && $postulacionAnterior['cv_ruta_archivo'] !== null
        && $postulacionAnterior['rut'] === $numeroDocumento
        && strtolower($postulacionAnterior['correo']) === strtolower($correo)
    ) {
        $origenCv = __DIR__ . '/../../uploads/' . $postulacionAnterior['cv_ruta_archivo'];
        $extensionCv = pathinfo($origenCv, PATHINFO_EXTENSION);
        if (is_file($origenCv) && in_array($extensionCv, ['pdf', 'jpg', 'png'], true)) {
            $carpetaCv = __DIR__ . '/../../uploads/cv';
            if (!is_dir($carpetaCv)) {
                mkdir($carpetaCv, 0750, true);
            }
            $nombreNuevo = bin2hex(random_bytes(16)) . '.' . $extensionCv;
            if (copy($origenCv, $carpetaCv . '/' . $nombreNuevo)) {
                $cvRuta = 'cv/' . $nombreNuevo;
                $tieneArchivoCv = true; // deja de exigirse el archivo nuevo mas abajo.
            }
        }
    }
    // Si la validación falla por cualquier motivo, simplemente no se
    // reutiliza nada -- sigue el camino normal (exigir CV nuevo o "no
    // tengo CV"), sin mostrar ningún error por esto.
}

if ($cvRuta !== null) {
    // Ya se resolvió arriba por reutilización -- no hacer nada más.
} elseif ($tieneArchivoCv) {
    try {
        $cvRuta = guardarArchivoSubido($_FILES['cv'], 'cv', 'tu CV');
    } catch (RuntimeException $e) {
        responderError($e->getMessage(), 422);
    }
} elseif ($experienciaDescripcion !== '') {
    $partes = [];
    if ($experienciaCargo !== '') $partes[] = $experienciaCargo;
    if ($experienciaFecha !== '') $partes[] = $experienciaFecha;
    $experienciaSinCv = (implode(' · ', $partes) !== '' ? implode(' · ', $partes) . "\n" : '') . $experienciaDescripcion;
} else {
    responderError('Debes adjuntar tu CV, o marcar "No tengo CV" y contarnos tu experiencia.', 422);
}

$codigoSeguimiento = generarCodigoSeguimiento($pdo);

$stmt = $pdo->prepare(
    'INSERT INTO postulaciones
        (tipo_documento, rut, nombre_completo, nombre, apellido, segundo_apellido,
         telefono, correo, region, comuna, cargo_id, obra,
         codigo_seguimiento, estado, consentimiento_ley19628, cv_ruta_archivo, experiencia_sin_cv)
     VALUES
        (:tipo_documento, :rut, :nombre_completo, :nombre, :apellido, :segundo_apellido,
         :telefono, :correo, :region, :comuna, :cargo_id, :obra,
         :codigo, :estado, 1, :cv_ruta, :experiencia_sin_cv)'
);
$stmt->execute([
    'tipo_documento'   => $tipoDocumento,
    'rut'              => $numeroDocumento,
    'nombre_completo'  => $nombreCompleto,
    'nombre'           => $nombre,
    'apellido'         => $apellido,
    'segundo_apellido' => $segundoApellido !== '' ? $segundoApellido : null,
    'telefono'         => $telefono,
    'correo'           => $correo,
    // v10.14: ya no se piden en Etapa 1 -- nacen vacios, se completan
    // recien en Etapa 2 (ver datos_contratacion.region/comuna).
    'region'           => '',
    'comuna'           => '',
    'cargo_id'         => $cargoId,
    'obra'             => OBRA_NOMBRE,
    'codigo'           => $codigoSeguimiento,
    'estado'           => $estadoInicial,
    'cv_ruta'          => $cvRuta,
    'experiencia_sin_cv' => $experienciaSinCv,
]);

$postulacionId = (int)$pdo->lastInsertId();
registrarLog($pdo, $postulacionId, null, 'Postulación creada por el postulante (Etapa 1). Cargo pendiente de asignar por el Capataz.');

// --- Correo de confirmacion (no bloqueante: si falla, igual respondemos) --
$urlSeguimiento = BASE_URL . '/frontend/public/seguimiento.html?rut=' . urlencode($numeroDocumento);
$html = (function () use ($nombreCompleto, $codigoSeguimiento, $urlSeguimiento) {
    return require __DIR__ . '/../../mailer/templates/confirmacion_postulacion.php';
})();
Mailer::enviar($correo, $nombreCompleto, 'Confirmación de tu postulación - ICAFAL', $html);

responderOk([
    'mensaje' => 'Postulación registrada correctamente.',
    'en_banco'           => false,
    'codigo_seguimiento' => $codigoSeguimiento,
], 201);
