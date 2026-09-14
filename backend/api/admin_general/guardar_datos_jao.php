<?php
/**
 * v3 - El JAO llena los campos AMARILLOS de la plantilla Buk antes de
 * poder finalizar la contratación. Se puede guardar varias veces
 * (upsert) mientras la postulación no esté Contratada, por si el JAO
 * necesita corregir algo antes del cierre.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/listas_buk.php';

iniciarSesionSegura();
$usuario = requireRol(['Jefe_Administrativo']);
exigirMetodo('POST');
exigirCsrfValido();

$body = leerJsonBody();
$postulacionId = (int)($body['postulacion_id'] ?? 0);
if ($postulacionId <= 0) {
    responderError('postulacion_id inválido.', 422);
}

$listas = listasBuk();

function exigirValorEnListaJao(string $valor, array $lista, string $nombreCampo): void
{
    if (!in_array($valor, $lista, true)) {
        responderError("Valor inválido para '$nombreCampo'.", 422);
    }
}

$campos = [
    'codigo_ficha'          => limpiarTexto($body['codigo_ficha'] ?? '', 50),
    'ingreso_compania'      => limpiarTexto($body['ingreso_compania'] ?? '', 10),
    'forma_pago'            => limpiarTexto($body['forma_pago'] ?? '', 60),
    'regimen_previsional'   => limpiarTexto($body['regimen_previsional'] ?? '', 60),
    'afc'                   => limpiarTexto($body['afc'] ?? '', 60),
    'jubilado'              => limpiarTexto($body['jubilado'] ?? 'No', 10),
    'escala_sueldo'         => limpiarTexto($body['escala_sueldo'] ?? '', 60),
    'proceso'               => limpiarTexto($body['proceso'] ?? '', 60),
    'tipo_transfer'         => limpiarTexto($body['tipo_transfer'] ?? '', 60),
    'recomendado'           => limpiarTexto($body['recomendado'] ?? 'No', 10),
    'retencion_judicial'    => limpiarTexto($body['retencion_judicial'] ?? '', 20),
    'discapacidad'          => limpiarTexto($body['discapacidad'] ?? 'No', 10),
    'invalidez'             => limpiarTexto($body['invalidez'] ?? 'No', 60),
];
// Opcionales / de texto libre.
$bonoObra = limpiarTexto($body['bono_obra'] ?? '', 50);
$fechaReconocimiento = limpiarTexto($body['fecha_reconocimiento'] ?? '', 10);
$seguroCovidFecha = limpiarTexto($body['seguro_covid_fecha_inicio'] ?? '', 10);
$fechaNotifDiscapacidad = limpiarTexto($body['fecha_notif_discapacidad'] ?? '', 10);
$fechaNotifInvalidez = limpiarTexto($body['fecha_notif_invalidez'] ?? '', 10);
// v10.14 (pedido explícito del usuario): solo aplican si el postulante
// está en una Isapre real -- el frontend solo muestra estos 2 campos en
// ese caso, pero igual se valida acá por si llegan de otra forma.
$planIsapreUf = limpiarTexto($body['plan_isapre_uf'] ?? '', 20);
$planIsaprePesos = limpiarTexto($body['plan_isapre_pesos'] ?? '', 20);
if ($planIsapreUf !== '' && !is_numeric($planIsapreUf)) {
    responderError('Plan Isapre UF debe ser un número.', 422);
}
if ($planIsaprePesos !== '' && !ctype_digit($planIsaprePesos)) {
    responderError('Plan Isapre Pesos debe ser un número entero.', 422);
}

foreach ($campos as $nombreCampo => $valor) {
    if ($valor === '') {
        responderError("El campo '$nombreCampo' es obligatorio.", 422);
    }
}
if (!DateTime::createFromFormat('Y-m-d', $campos['ingreso_compania'])) {
    responderError('Fecha de ingreso a la compañía inválida.', 422);
}

exigirValorEnListaJao($campos['forma_pago'], $listas['forma_pago'], 'Forma de Pago');
exigirValorEnListaJao($campos['regimen_previsional'], $listas['regimen_previsional'], 'Régimen Previsional');
exigirValorEnListaJao($campos['afc'], $listas['afc'], 'AFC');
exigirValorEnListaJao($campos['jubilado'], $listas['jubilado'], 'Jubilado');
exigirValorEnListaJao($campos['escala_sueldo'], $listas['escala_sueldo'], 'Escala Sueldo');
exigirValorEnListaJao($campos['proceso'], $listas['proceso'], 'Proceso');
exigirValorEnListaJao($campos['tipo_transfer'], $listas['tipo_transfer'], 'Tipo Transfer');
exigirValorEnListaJao($campos['recomendado'], $listas['recomendado'], 'Recomendado');
exigirValorEnListaJao($campos['retencion_judicial'], $listas['retencion_judicial'], 'Retención Judicial');
exigirValorEnListaJao($campos['discapacidad'], $listas['discapacidad'], 'Discapacidad');
exigirValorEnListaJao($campos['invalidez'], $listas['invalidez'], 'Invalidez');

$pdo = obtenerConexion();

$stmtCheck = $pdo->prepare('SELECT estado FROM postulaciones WHERE id = :id');
$stmtCheck->execute(['id' => $postulacionId]);
$estadoActual = $stmtCheck->fetchColumn();
if ($estadoActual === false) {
    responderError('Postulación no encontrada.', 404);
}
if ($estadoActual === 'Contratado') {
    responderError('Esta postulación ya fue contratada; no se pueden editar sus datos de nómina.', 409);
}

$stmt = $pdo->prepare(
    'INSERT INTO datos_jao
        (postulacion_id, codigo_ficha, ingreso_compania, forma_pago, regimen_previsional, afc,
         jubilado, escala_sueldo, proceso, tipo_transfer, fecha_reconocimiento, recomendado,
         bono_obra, retencion_judicial, seguro_covid_fecha_inicio, plan_isapre_uf, plan_isapre_pesos,
         discapacidad, fecha_notif_discapacidad, invalidez, fecha_notif_invalidez, creado_por)
     VALUES
        (:postulacion_id, :codigo_ficha, :ingreso_compania, :forma_pago, :regimen_previsional, :afc,
         :jubilado, :escala_sueldo, :proceso, :tipo_transfer, :fecha_reconocimiento, :recomendado,
         :bono_obra, :retencion_judicial, :seguro_covid_fecha_inicio, :plan_isapre_uf, :plan_isapre_pesos,
         :discapacidad, :fecha_notif_discapacidad, :invalidez, :fecha_notif_invalidez, :creado_por)
     ON DUPLICATE KEY UPDATE
        codigo_ficha = VALUES(codigo_ficha), ingreso_compania = VALUES(ingreso_compania),
        forma_pago = VALUES(forma_pago), regimen_previsional = VALUES(regimen_previsional),
        afc = VALUES(afc), jubilado = VALUES(jubilado), escala_sueldo = VALUES(escala_sueldo),
        proceso = VALUES(proceso), tipo_transfer = VALUES(tipo_transfer),
        fecha_reconocimiento = VALUES(fecha_reconocimiento), recomendado = VALUES(recomendado),
        bono_obra = VALUES(bono_obra), retencion_judicial = VALUES(retencion_judicial),
        seguro_covid_fecha_inicio = VALUES(seguro_covid_fecha_inicio),
        plan_isapre_uf = VALUES(plan_isapre_uf), plan_isapre_pesos = VALUES(plan_isapre_pesos),
        discapacidad = VALUES(discapacidad), fecha_notif_discapacidad = VALUES(fecha_notif_discapacidad),
        invalidez = VALUES(invalidez), fecha_notif_invalidez = VALUES(fecha_notif_invalidez)'
);
$stmt->execute([
    'postulacion_id' => $postulacionId,
    'codigo_ficha' => $campos['codigo_ficha'],
    'ingreso_compania' => $campos['ingreso_compania'],
    'forma_pago' => $campos['forma_pago'],
    'regimen_previsional' => $campos['regimen_previsional'],
    'afc' => $campos['afc'],
    'jubilado' => $campos['jubilado'],
    'escala_sueldo' => $campos['escala_sueldo'],
    'proceso' => $campos['proceso'],
    'tipo_transfer' => $campos['tipo_transfer'],
    'fecha_reconocimiento' => $fechaReconocimiento !== '' ? $fechaReconocimiento : null,
    'recomendado' => $campos['recomendado'],
    'bono_obra' => $bonoObra !== '' ? $bonoObra : null,
    'retencion_judicial' => $campos['retencion_judicial'],
    'seguro_covid_fecha_inicio' => $seguroCovidFecha !== '' ? $seguroCovidFecha : null,
    'plan_isapre_uf' => $planIsapreUf !== '' ? $planIsapreUf : null,
    'plan_isapre_pesos' => $planIsaprePesos !== '' ? $planIsaprePesos : null,
    'discapacidad' => $campos['discapacidad'],
    'fecha_notif_discapacidad' => $fechaNotifDiscapacidad !== '' ? $fechaNotifDiscapacidad : null,
    'invalidez' => $campos['invalidez'],
    'fecha_notif_invalidez' => $fechaNotifInvalidez !== '' ? $fechaNotifInvalidez : null,
    'creado_por' => $usuario['id'],
]);

registrarLog($pdo, $postulacionId, $usuario['id'], 'Jefe Administrativo completó los datos de nómina (ficha Buk).');

// v6.1: la carpeta local de documentos se creó con el código de
// seguimiento como nombre provisorio (ver guardar_datos.php) -- ahora
// que ya existe el código de ficha real, se renombra.
renombrarCarpetaConCodigoFicha($pdo, $postulacionId, $campos['codigo_ficha']);

responderOk(['mensaje' => 'Datos de nómina guardados. Ya puedes finalizar la contratación.']);
