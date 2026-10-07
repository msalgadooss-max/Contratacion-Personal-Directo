<?php
/**
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09): el día
 * de contratación (8 am) la persona llega a Portería, que la busca por su
 * cédula (RUT) y ve en qué fase está. Si está lista para contratación,
 * Portería autoriza su paso (ver autorizar_contratacion.php).
 *
 * Requiere sesión de Portería (a diferencia de consultar.php, que es
 * público con RUT + código): como acá se busca solo por RUT, se limita al
 * personal autenticado, y solo devuelve nombre, RUT, cargo y fase.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rut.php';

iniciarSesionSegura();
requireRol(['Porteria']);
exigirMetodo('GET');

$rutCrudo = trim((string)($_GET['rut'] ?? ''));
if ($rutCrudo === '') {
    responderError('Ingresa el RUT o documento de la persona.', 422);
}

$pdo = obtenerConexion();
$fase = faseParaPorteria($pdo, $rutCrudo, normalizarRut($rutCrudo));
if (!$fase) {
    responderError('No se encontró ninguna postulación con ese RUT o documento.', 404);
}

responderOk($fase);
