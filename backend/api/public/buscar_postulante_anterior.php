<?php
/**
 * v10.15 (pedido explícito del usuario, item 3 de la lista post-prueba):
 * "plan para personal recontratado". En la Etapa 1 (pública, sin
 * sesión), alguien que ya postuló/trabajó antes puede optar por
 * "recuperar sus datos" en vez de tipear todo de nuevo -- pero como este
 * endpoint es público, se exige el mismo doble factor que ya usa
 * seguimiento.php (documento + correo) para no permitir que cualquiera
 * descubra datos de otra persona probando RUTs al azar.
 *
 * Devuelve solo lo necesario para prellenar el formulario de Etapa 1
 * (nombre/apellido/teléfono/correo) y, si existe, una referencia a un CV
 * reutilizable -- postular.php vuelve a validar el mismo documento+correo
 * antes de copiar ese archivo, así que este id no sirve por sí solo para
 * robar el CV de otra persona.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rut.php';

exigirMetodo('POST');

$body = leerJsonBody();
$documentoCrudo = trim((string)($body['numero_documento'] ?? ''));
$correo = filter_var(trim((string)($body['correo'] ?? '')), FILTER_VALIDATE_EMAIL);

if ($documentoCrudo === '' || !$correo) {
    responderError('Tu documento y tu correo son obligatorios.', 422);
}

$documentoRut = normalizarRut($documentoCrudo);

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    'SELECT p.id, p.tipo_documento, p.nombre, p.apellido, p.segundo_apellido, p.telefono, p.correo,
            p.cv_ruta_archivo, p.creado_at, c.nombre_cargo
       FROM postulaciones p
       JOIN cargos c ON c.id = p.cargo_id
      WHERE (p.rut = :doc_crudo OR p.rut = :doc_rut) AND LOWER(p.correo) = LOWER(:correo)
      ORDER BY p.creado_at DESC
      LIMIT 1'
);
$stmt->execute(['doc_crudo' => $documentoCrudo, 'doc_rut' => $documentoRut, 'correo' => $correo]);
$postulacion = $stmt->fetch();

if (!$postulacion) {
    // Respuesta "ok" con encontrado=false (no un 404): que alguien no
    // tenga postulaciones anteriores es un caso normal y esperado, no un
    // error -- el frontend simplemente deja el formulario en blanco.
    responderOk(['encontrado' => false]);
}

responderOk([
    'encontrado' => true,
    'tipo_documento' => $postulacion['tipo_documento'],
    'nombre' => $postulacion['nombre'],
    'apellido' => $postulacion['apellido'],
    'segundo_apellido' => $postulacion['segundo_apellido'],
    'telefono' => $postulacion['telefono'],
    'correo' => $postulacion['correo'],
    'cargo_anterior' => $postulacion['nombre_cargo'],
    'fecha_anterior' => $postulacion['creado_at'],
    'reutilizar_cv_de' => $postulacion['cv_ruta_archivo'] !== null ? (int)$postulacion['id'] : null,
]);
