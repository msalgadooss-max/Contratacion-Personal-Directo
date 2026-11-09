<?php
/**
 * Copiar este archivo a "config.php" (mismo directorio) y completar los
 * datos reales del hosting. "config.php" NO debe subirse a un repositorio
 * publico ni quedar accesible por HTTP (ver backend/.htaccess).
 */

// --- Base de datos -----------------------------------------------------
define('DB_HOST', 'localhost');
// DB_NAME lo define obras.php segun la obra (ver mas abajo).
define('DB_USER', 'usuario_bd');
define('DB_PASS', 'clave_bd');
define('DB_CHARSET', 'utf8mb4');

// --- URL base publica del sistema (sin slash final) ---------------------
// v10.32: dominio SIN prefijo de obra; BASE_URL (con prefijo) la arma obras.php.
define('BASE_URL_RAIZ', 'https://www.tuempresa.cl/rrhh');

// --- Correo saliente (SMTP) ---------------------------------------------
define('SMTP_HOST', 'smtp.tuempresa.cl');
define('SMTP_PORT', 587);
define('SMTP_USER', 'notificaciones@tuempresa.cl');
define('SMTP_PASS', 'clave_smtp');
define('SMTP_SECURE', 'tls'); // 'tls' o 'ssl'
define('SMTP_FROM_EMAIL', 'notificaciones@tuempresa.cl');
define('SMTP_FROM_NAME', 'RRHH ICAFAL');

// --- Envio via API HTTPS (opcional) -----------------------------------
// Si se define no vacia, el correo sale por la API de Brevo en vez de
// SMTP -- util en hostings que bloquean los puertos SMTP salientes.
define('BREVO_API_KEY', '');

// --- Reglas de negocio ---------------------------------------------------
define('TOKEN_PRIVADO_HORAS_VALIDEZ', 72); // horas de validez del link privado
define('TOKEN_SUBSANACION_HORAS_VALIDEZ', 72); // horas de validez del link para corregir un documento rechazado
define('CODIGO_SEGUIMIENTO_LARGO', 6);
define('BANCO_RETENCION_MESES', 6); // cuanto se conservan los datos de quien queda "En_banco"

// --- Obra activa (v10.32) ---------------------------------------------------
// Define DB_NAME, BASE_URL, OBRA_NOMBRE, SESSION_NAME y los datos de Buk segun
// la obra de la peticion (prefijo /h58/... -> obra h58; sin prefijo -> h57).
// Ver obras_registro.php para agregar o editar obras.
require __DIR__ . '/obras.php';

// --- Limite diario de aprobaciones de Jefe_Terreno (v4) ---------------------
// Maximo de aprobaciones (Pendiente/En_banco -> Pre_aprobado_terreno) que un
// mismo usuario Jefe_Terreno puede realizar por dia calendario. Se cuenta via
// trazabilidad_logs -- ver includes/functions.php::contarAprobacionesHoy().
define('LIMITE_APROBACIONES_DIARIAS_TERRENO', 25);

// --- Modulos activos ---------------------------------------------------
// v9.2: Etapa 1 del piloto -- Prevencion y Bodega quedan fuera del
// alcance de la app por ahora (siguen pasando en la vida real, en
// papel). admin_general/firmar_contrato.php cierra la contratacion el
// mismo cuando ambos estan en false. Volver a poner ambos en true
// cuando arranque Etapa 2 (Porteria -> JAO -> Prevencion -> firma ->
// Bodega -> recepcion, como en produccion hoy).
define('MODULO_PREVENCION_ACTIVO', false);
define('MODULO_BODEGA_ACTIVO', false);

// --- Modo debug (poner en false en produccion) -----------------------------
define('APP_DEBUG', false);
