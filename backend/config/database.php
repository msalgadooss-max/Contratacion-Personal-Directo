<?php
/**
 * Conexion PDO unica (singleton) a la base de datos.
 * EMULATE_PREPARES=false obliga a MySQL a preparar de verdad las
 * consultas -> refuerza la proteccion real contra inyeccion SQL.
 */

require_once __DIR__ . '/config.php';

function obtenerConexion(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST
            . (defined('DB_PORT') && DB_PORT !== '' ? ';port=' . DB_PORT : '')
            . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];

        // v10.34: base de datos EXTERNA (Azure Database for MySQL) exige TLS.
        //   'verify'   -> cifrado + valida el certificado del servidor (defecto
        //                 del modo externo).
        //   'required' -> cifrado sin validar el certificado (solo para
        //                 destrabar una conexion mientras se arregla la cadena
        //                 de certificados).
        //   'off'      -> sin TLS (base local en el mismo contenedor).
        $modoSsl = defined('DB_SSL_MODE') ? DB_SSL_MODE : 'off';
        if ($modoSsl !== 'off') {
            if (defined('DB_SSL_CA') && DB_SSL_CA !== '') {
                $opciones[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
            } else {
                // mysqlnd solo activa TLS si se le da alguna opcion SSL; sin
                // esto la conexion saldria sin cifrar y el servidor la rechaza.
                $opciones[PDO::MYSQL_ATTR_SSL_CIPHER] = 'DEFAULT';
            }
            $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = ($modoSsl === 'verify');
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
            // v9.1: refuerzo explícito -- el parámetro "charset=" del DSN
            // debería bastar, pero en el ambiente de Render se detectaron
            // nombres con tildes/"ñ" llegando corruptos a la base. Un
            // SET NAMES explícito deja la codificación de la sesión sin
            // ambigüedad, sin importar cómo el driver haya interpretado
            // el DSN.
            $pdo->exec('SET NAMES ' . DB_CHARSET . ' COLLATE utf8mb4_unicode_ci');
            // v10.34: la misma sesion en MariaDB (Render) y en MySQL 8.4 (Azure),
            // sin depender de los valores por defecto de cada servidor:
            //  * sql_mode: el que MariaDB trae por defecto (sin NO_AUTO_CREATE_USER,
            //    que MySQL 8 ya no acepta). Asi ONLY_FULL_GROUP_BY y NO_ZERO_DATE
            //    -- activos por defecto en MySQL, no en MariaDB -- no cambian el
            //    comportamiento de ninguna consulta ya probada.
            //  * time_zone: hora de Chile (con su horario de verano) como offset
            //    explicito; no requiere las tablas de zonas horarias del servidor.
            //    Es lo mismo que daba el contenedor de Render (TZ=America/Santiago).
            $offset = (new DateTime('now', new DateTimeZone('America/Santiago')))->format('P');
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone = '" . $offset . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => APP_DEBUG
                    ? 'Error de conexion a BD: ' . $e->getMessage()
                    : 'No fue posible conectar a la base de datos.',
            ]);
            exit;
        }
    }

    return $pdo;
}

/**
 * Deja registrado en la variable de sesion de MySQL que usuario esta
 * autenticado, para que los triggers de auditoria (trazabilidad_logs)
 * lo capturen automaticamente en cada UPDATE de estado.
 * Pasar null cuando la accion la origina el propio postulante (sin login).
 */
function fijarUsuarioContextoBD(PDO $pdo, ?int $usuarioId): void
{
    $stmt = $pdo->prepare('SET @current_user_id = :uid');
    $stmt->execute(['uid' => $usuarioId]);
}
