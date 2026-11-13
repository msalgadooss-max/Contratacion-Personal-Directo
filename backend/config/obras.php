<?php
/**
 * v10.32 - Activa la obra de ESTA peticion y define las constantes que
 * dependen de ella (base de datos, direccion base, nombre de sesion, datos
 * de la obra para Buk). Lo incluye config.php, que antes de incluirlo
 * define BASE_URL_RAIZ (dominio sin prefijo ni "/" final).
 *
 * Como se elige la obra:
 *   1) Variable de entorno ICAFAL_OBRA (herramientas por consola).
 *   2) Prefijo de la direccion: /h58/... -> obra h58.
 *   3) Si no hay prefijo -> h57, exactamente como funcionaba antes.
 *
 * Todo lo demas de la aplicacion sigue igual: usa DB_NAME, BASE_URL,
 * OBRA_NOMBRE, etc. sin saber que hay mas de una obra.
 */
if (!function_exists('icafalObras')) {
    function icafalObras(): array
    {
        return require __DIR__ . '/obras_registro.php';
    }

    function icafalDetectarObra(): string
    {
        $obras = icafalObras();

        $forzada = getenv('ICAFAL_OBRA');
        if ($forzada && isset($obras[$forzada])) {
            return $forzada;
        }

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        foreach ($obras as $codigo => $o) {
            if ($o['prefijo'] !== ''
                && preg_match('#^' . preg_quote($o['prefijo'], '#') . '(?:[/?\#]|$)#', $uri)) {
                return $codigo;
            }
        }
        // Un prefijo tipo /h99/... que NO esta registrado no debe caer en la obra
        // por defecto (mostraria datos de H57 bajo una direccion inventada): 404.
        if (PHP_SAPI !== 'cli' && preg_match('#^/h[0-9]+(?:[/?\#]|$)#', $uri)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Obra no encontrada.';
            exit;
        }
        return 'h57';
    }
}

$icafalCodigoObra = icafalDetectarObra();
$icafalObra = icafalObras()[$icafalCodigoObra];

define('OBRA_CODIGO', $icafalCodigoObra);
define('OBRA_PREFIJO', $icafalObra['prefijo']);
define('DB_NAME', $icafalObra['db']);
define('BASE_URL', rtrim(BASE_URL_RAIZ, '/') . $icafalObra['prefijo']);
// Sesion propia por obra: aunque alguien tenga abiertas las dos obras en
// el mismo navegador, cada una usa su propia cookie y no se pisan.
define('SESSION_NAME', 'icafal_rrhh_sesion' . ($icafalCodigoObra === 'h57' ? '' : '_' . $icafalCodigoObra));

define('OBRA_NOMBRE', $icafalObra['nombre']);
define('OBRA_SUBAREA_BUK', $icafalObra['buk']['subarea']);
define('OBRA_SUBAREA_NOMBRE_BUK', $icafalObra['buk']['subarea_nombre']);
define('OBRA_EMPRESA_BUK', $icafalObra['buk']['empresa']);
define('OBRA_CODIGO_CORTO_BUK', $icafalObra['buk']['codigo_corto']);
define('OBRA_SUPERVISOR_RUT_BUK', $icafalObra['buk']['supervisor_rut']);
define('OBRA_SUPERVISOR_FICHA_BUK', $icafalObra['buk']['supervisor_ficha']);
define('OBRA_HORARIO_SEMANAL_MOD_BUK', $icafalObra['buk']['horario_semanal']);
define('OBRA_BUK_TRABAJOS_NOV2026', ($icafalObra['buk']['plantilla_trabajos'] ?? 'sep2026') === 'nov2026');
define('OBRA_BUK_ASISTENCIA', (bool)($icafalObra['buk']['asistencia'] ?? true));

unset($icafalCodigoObra, $icafalObra);
