<?php
/**
 * Etapa 2 - El postulante completa sus datos de contratacion y sube
 * sus documentos legales, usando el link privado que le llego al ser
 * aprobado por Jefe_Terreno (ver includes/functions.php::otorgarAccesoEtapa2).
 *
 * Reglas clave:
 *  - Se valida el token (vigente y en estado 'Pre_aprobado_terreno')
 *    para evitar reenvios o uso fuera de plazo.
 *  - Al guardar, el estado pasa a 'Datos_completados' y el token se
 *    invalida (token_privado=NULL) -> es de un solo uso.
 *  - usuario_id = NULL en el log porque quien actua es el propio
 *    postulante, sin sesion interna.
 *  - v3: multipart/form-data (no JSON) porque incluye hasta 5 archivos.
 *    "Último Finiquito" es el unico documento opcional (alguien en su
 *    primer trabajo no tiene uno que subir).
 *  - v3: los campos con lista (sexo, estado civil, banco, AFP, etc.) se
 *    validan contra los valores EXACTOS de la plantilla Buk -- si
 *    alguien intenta mandar un valor que no esta en la lista (aunque
 *    haya manipulado el formulario), se rechaza.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/listas_buk.php';

exigirMetodo('POST');

$token = limpiarTexto($_POST['token'] ?? '', 64);
if ($token === '') {
    responderError('Token no proporcionado.', 422);
}

$listas = listasBuk();
$regionesComunas = regionesConComunas();

function exigirValorEnLista(string $valor, array $lista, string $nombreCampo): void
{
    if (!in_array($valor, $lista, true)) {
        responderError("Valor inválido para '$nombreCampo'.", 422);
    }
}

$campos = [
    'fecha_nacimiento'             => limpiarTexto($_POST['fecha_nacimiento'] ?? '', 10),
    'sexo'                         => limpiarTexto($_POST['sexo'] ?? '', 20),
    'nacionalidad'                 => limpiarTexto($_POST['nacionalidad'] ?? '', 60),
    'estado_civil'                 => limpiarTexto($_POST['estado_civil'] ?? '', 30),
    'direccion_exacta'             => limpiarTexto($_POST['direccion_exacta'] ?? '', 255),
    'region'                       => limpiarTexto($_POST['region'] ?? '', 100),
    'comuna'                       => limpiarTexto($_POST['comuna'] ?? '', 100),
    'ciudad'                       => limpiarTexto($_POST['ciudad'] ?? '', 100),
    'pais'                         => limpiarTexto($_POST['pais'] ?? 'Chile', 80),
    'banco'                        => limpiarTexto($_POST['banco'] ?? '', 100),
    'tipo_cuenta'                  => limpiarTexto($_POST['tipo_cuenta'] ?? '', 50),
    'numero_cuenta'                => limpiarTexto($_POST['numero_cuenta'] ?? '', 50),
    'afp'                          => limpiarTexto($_POST['afp'] ?? '', 100),
    'isapre_fonasa'                => limpiarTexto($_POST['isapre_fonasa'] ?? '', 100),
    'estudios'                     => limpiarTexto($_POST['estudios'] ?? '', 60),
    'contacto_emergencia_nombre'   => limpiarTexto($_POST['contacto_emergencia_nombre'] ?? '', 150),
    'contacto_emergencia_telefono' => limpiarTexto($_POST['contacto_emergencia_telefono'] ?? '', 20),
    'talla_calzado'                => limpiarTexto($_POST['talla_calzado'] ?? '', 10),
    'talla_overol'                 => limpiarTexto($_POST['talla_overol'] ?? '', 10),
];

foreach ($campos as $nombreCampo => $valor) {
    if ($valor === '') {
        responderError("El campo '$nombreCampo' es obligatorio.", 422);
    }
}
if (!DateTime::createFromFormat('Y-m-d', $campos['fecha_nacimiento'])) {
    responderError('Fecha de nacimiento inválida (formato esperado AAAA-MM-DD).', 422);
}

exigirValorEnLista($campos['sexo'], $listas['sexo'], 'Sexo');
exigirValorEnLista($campos['nacionalidad'], $listas['nacionalidad'], 'Nacionalidad');
exigirValorEnLista($campos['estado_civil'], $listas['estado_civil'], 'Estado civil');
exigirValorEnLista($campos['banco'], $listas['banco'], 'Banco');
exigirValorEnLista($campos['tipo_cuenta'], $listas['tipo_cuenta'], 'Tipo de cuenta');
exigirValorEnLista($campos['afp'], $listas['fondo_cotizacion'], 'AFP');
// v3.3: se acepta cualquier valor de la lista (incluye 4 regimenes
// previsionales antiguos ademas de las 7 AFP vigentes), pero si NO es
// una AFP vigente se marca para que el JAO reciba una alerta al abrir
// su dashboard -- no bloquea al postulante, solo avisa despues.
$afpAlertaJao = !in_array($campos['afp'], afpVigentes(), true);
exigirValorEnLista($campos['isapre_fonasa'], $listas['fonasa_isapre'], 'Fonasa/Isapre');
exigirValorEnLista($campos['estudios'], $listas['estudios'], 'Estudios');
if (!isset($regionesComunas[$campos['region']])) {
    responderError('Región inválida.', 422);
}
if (!in_array($campos['comuna'], $regionesComunas[$campos['region']], true)) {
    responderError('La comuna no corresponde a la región seleccionada.', 422);
}

// --- Documentos: 4 obligatorios + "Último Finiquito" opcional --------
$tiposDocumento = [
    'cedula_identidad'          => ['campo' => 'cedula', 'etiqueta' => 'el frente de tu Cédula de Identidad', 'obligatorio' => true],
    'cedula_identidad_reverso'  => ['campo' => 'cedula_reverso', 'etiqueta' => 'el reverso de tu Cédula de Identidad', 'obligatorio' => true],
    'certificado_afp'           => ['campo' => 'certificado_afp', 'etiqueta' => 'tu Certificado de AFP', 'obligatorio' => true],
    'certificado_salud'         => ['campo' => 'certificado_salud', 'etiqueta' => 'tu Certificado de Fonasa/Isapre', 'obligatorio' => true],
    'ultimo_finiquito'          => ['campo' => 'ultimo_finiquito', 'etiqueta' => 'tu Último Finiquito', 'obligatorio' => false],
    'certificado_residencia'    => ['campo' => 'certificado_residencia', 'etiqueta' => 'tu Certificado de Residencia', 'obligatorio' => true],
];

$documentosAGuardar = [];
try {
    foreach ($tiposDocumento as $tipo => $info) {
        $archivo = $_FILES[$info['campo']] ?? [];
        $sinArchivo = ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
        if ($sinArchivo && !$info['obligatorio']) {
            continue;
        }
        $documentosAGuardar[$tipo] = guardarArchivoSubido($archivo, 'documentos', $info['etiqueta']);
    }
} catch (RuntimeException $e) {
    responderError($e->getMessage(), 422);
}

$pdo = obtenerConexion();
$pdo->beginTransaction();

try {
    // SELECT ... FOR UPDATE evita condiciones de carrera si el
    // postulante enviara el formulario dos veces casi simultaneamente.
    $stmt = $pdo->prepare(
        'SELECT id, estado, token_expira_at
           FROM postulaciones
          WHERE token_privado = :token
          FOR UPDATE'
    );
    $stmt->execute(['token' => $token]);
    $postulacion = $stmt->fetch();

    if (!$postulacion) {
        throw new RuntimeException('El enlace no es válido.|404');
    }
    if ($postulacion['estado'] !== 'Pre_aprobado_terreno') {
        throw new RuntimeException('Este enlace ya fue utilizado.|409');
    }
    if (strtotime($postulacion['token_expira_at']) < time()) {
        throw new RuntimeException('El enlace expiró.|410');
    }

    $postulacionId = (int)$postulacion['id'];

    $stmtInsert = $pdo->prepare(
        'INSERT INTO datos_contratacion
            (postulacion_id, fecha_nacimiento, estado_civil, sexo, nacionalidad, direccion_exacta,
             region, comuna, ciudad, pais, afp, afp_alerta_jao, isapre_fonasa, estudios,
             banco, tipo_cuenta, numero_cuenta,
             contacto_emergencia_nombre, contacto_emergencia_telefono,
             talla_calzado, talla_overol)
         VALUES
            (:postulacion_id, :fecha_nacimiento, :estado_civil, :sexo, :nacionalidad, :direccion_exacta,
             :region, :comuna, :ciudad, :pais, :afp, :afp_alerta_jao, :isapre_fonasa, :estudios,
             :banco, :tipo_cuenta, :numero_cuenta,
             :contacto_emergencia_nombre, :contacto_emergencia_telefono,
             :talla_calzado, :talla_overol)'
    );
    $stmtInsert->execute(array_merge($campos, [
        'postulacion_id' => $postulacionId,
        'afp_alerta_jao' => $afpAlertaJao ? 1 : 0,
    ]));

    $stmtDoc = $pdo->prepare(
        'INSERT INTO postulacion_documentos (postulacion_id, tipo, ruta_archivo) VALUES (:pid, :tipo, :ruta)'
    );
    foreach ($documentosAGuardar as $tipo => $ruta) {
        $stmtDoc->execute(['pid' => $postulacionId, 'tipo' => $tipo, 'ruta' => $ruta]);
    }

    // Sin usuario logueado: la accion la origina el propio postulante.
    fijarUsuarioContextoBD($pdo, null);

    // v6.5: el token se invalida SIEMPRE (es de un solo uso), pero el
    // estado NO cambia aqui directamente -- en el flujo secuencial,
    // Admin_Contrato ya autorizó antes de que este token existiera
    // siquiera (ver admin_contrato/autorizar.php), así que solo falta
    // que intentarAvanzarAAprobadoAdmin() confirme esa condición (que
    // en la práctica siempre es true a esta altura) para avanzar.
    $stmtUpdate = $pdo->prepare(
        'UPDATE postulaciones SET token_privado = NULL, token_expira_at = NULL WHERE id = :id'
    );
    $stmtUpdate->execute(['id' => $postulacionId]);

    registrarLog($pdo, $postulacionId, null, 'Postulante completó formulario de datos y documentos (Etapa 2).');

    intentarAvanzarAAprobadoAdmin($pdo, $postulacionId);

    $pdo->commit();

    // v6.1: fuera de la transaccion (es solo un espejo local en disco,
    // no debe poder hacer fallar el guardado real si algo sale mal aqui).
    try {
        generarCarpetaDocumentosPersonales($pdo, $postulacionId);
    } catch (\Throwable $e) {
        error_log('generarCarpetaDocumentosPersonales error: ' . $e->getMessage());
    }
} catch (RuntimeException $e) {
    $pdo->rollBack();
    [$mensaje, $status] = explode('|', $e->getMessage());
    responderError($mensaje, (int)$status);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('guardar_datos error: ' . $e->getMessage());
    responderError('No fue posible guardar los datos. Intenta nuevamente.', 500);
}

responderOk(['mensaje' => 'Datos guardados correctamente. Continúa el proceso con tu contacto en la empresa.']);
