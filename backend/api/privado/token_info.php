<?php
/**
 * Valida un token privado (link enviado en Fase 2) y devuelve los datos
 * minimos para prellenar el formulario. No requiere sesion: la propia
 * posesion del token (largo, aleatorio, enviado solo al correo del
 * postulante) actua como credencial de un solo uso.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

exigirMetodo('GET');

$token = limpiarTexto($_GET['token'] ?? '', 64);
if ($token === '') {
    responderError('Token no proporcionado.', 422);
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    'SELECT id, nombre_completo, rut, estado, token_expira_at
       FROM postulaciones
      WHERE token_privado = :token
      LIMIT 1'
);
$stmt->execute(['token' => $token]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    responderError('El enlace no es válido.', 404);
}

if ($postulacion['estado'] !== 'Pre_aprobado_terreno') {
    responderError('Este enlace ya fue utilizado o la postulación no está en la etapa correspondiente.', 409);
}

if (strtotime($postulacion['token_expira_at']) < time()) {
    responderError('El enlace expiró. Solicita uno nuevo a tu contacto en la empresa.', 410);
}

// v10.15 (pedido explícito del usuario, item 3 de la lista post-prueba):
// "preparar un plan para personal recontratado". Si esta misma persona
// (mismo RUT) ya completó la Etapa 2 en una postulación anterior, se le
// devuelven esos datos para prellenar el formulario -- ella misma
// revisa y confirma que sigan vigentes (AFP/Isapre pueden haber
// cambiado), en vez de tener que escribir todo de nuevo desde cero.
// Seguro: solo se activa dentro de este mismo endpoint, que ya exige
// poseer un token privado válido y sin usar -- no es una búsqueda
// pública por RUT.
$stmtHistorial = $pdo->prepare(
    'SELECT p.id, p.creado_at, c.nombre_cargo,
            d.fecha_nacimiento, d.sexo, d.nacionalidad, d.estado_civil,
            d.direccion_exacta, d.region, d.comuna, d.ciudad, d.pais,
            d.afp, d.isapre_fonasa, d.banco, d.tipo_cuenta, d.numero_cuenta,
            d.estudios, d.talla_calzado, d.talla_overol,
            d.contacto_emergencia_nombre, d.contacto_emergencia_telefono
       FROM postulaciones p
       JOIN datos_contratacion d ON d.postulacion_id = p.id
       JOIN cargos c ON c.id = p.cargo_id
      WHERE p.rut = :rut AND p.id != :id
      ORDER BY p.creado_at DESC
      LIMIT 1'
);
$stmtHistorial->execute(['rut' => $postulacion['rut'], 'id' => $postulacion['id']]);
$historial = $stmtHistorial->fetch();

$datosAnteriores = null;
$documentosAnteriores = [];
if ($historial) {
    $datosAnteriores = [
        'fecha_nacimiento' => $historial['fecha_nacimiento'],
        'sexo' => $historial['sexo'],
        'nacionalidad' => $historial['nacionalidad'],
        'estado_civil' => $historial['estado_civil'],
        'direccion_exacta' => $historial['direccion_exacta'],
        'region' => $historial['region'],
        'comuna' => $historial['comuna'],
        'ciudad' => $historial['ciudad'],
        'pais' => $historial['pais'],
        'afp' => $historial['afp'],
        'isapre_fonasa' => $historial['isapre_fonasa'],
        'banco' => $historial['banco'],
        'tipo_cuenta' => $historial['tipo_cuenta'],
        'numero_cuenta' => $historial['numero_cuenta'],
        'estudios' => $historial['estudios'],
        'talla_calzado' => $historial['talla_calzado'],
        'talla_overol' => $historial['talla_overol'],
        'contacto_emergencia_nombre' => $historial['contacto_emergencia_nombre'],
        'contacto_emergencia_telefono' => $historial['contacto_emergencia_telefono'],
        'cargo_anterior' => $historial['nombre_cargo'],
        'fecha_anterior' => $historial['creado_at'],
    ];

    $stmtDocs = $pdo->prepare('SELECT tipo, subido_at FROM postulacion_documentos WHERE postulacion_id = :id');
    $stmtDocs->execute(['id' => $historial['id']]);
    $documentosAnteriores = $stmtDocs->fetchAll();
}

responderOk([
    'postulacion' => [
        'nombre_completo' => $postulacion['nombre_completo'],
        'rut'             => $postulacion['rut'],
    ],
    'datos_anteriores'      => $datosAnteriores,
    'documentos_anteriores' => $documentosAnteriores,
]);
