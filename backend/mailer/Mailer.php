<?php
/**
 * Envoltorio de envio de correo. Orden de prioridad:
 *   1) Brevo (API HTTPS) si BREVO_API_KEY esta configurada -- necesario
 *      en hostings que bloquean los puertos SMTP salientes (25/465/587),
 *      algo comun en planes gratuitos (ver v6.3).
 *   2) PHPMailer via SMTP si esta instalado (composer install).
 *   3) La funcion mail() nativa de PHP, como ultimo recurso.
 */

require_once __DIR__ . '/../config/config.php';

$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

final class Mailer
{
    /**
     * v10.17 (pedido explicito del usuario): modo prueba de correos --
     * para hacer una prueba de punta a punta sin que le lleguen
     * notificaciones reales a Ricardo/Jaime/JAO/etc. ni a nadie mas.
     * Se activa con variables de entorno (no config.php) para poder
     * prenderlo/apagarlo desde el dashboard de Render sin tocar codigo
     * ni redeploy: EMAIL_MODO_PRUEBA=1 y EMAIL_PRUEBA_DESTINO=tu@correo.
     * Reescribe el destinatario ANTES de elegir el proveedor de envio,
     * asi que aplica igual con Brevo, PHPMailer o mail() nativo. El
     * asunto original queda con el destinatario real entre corchetes,
     * para poder seguir viendo a quien le habria llegado cada correo.
     */
    private static function aplicarModoPrueba(string &$destinatario, string &$asunto): void
    {
        if (getenv('EMAIL_MODO_PRUEBA') !== '1') {
            return;
        }
        $destinoPrueba = getenv('EMAIL_PRUEBA_DESTINO');
        if ($destinoPrueba === false || trim($destinoPrueba) === '') {
            return;
        }
        $asunto = "[PRUEBA -> {$destinatario}] {$asunto}";
        $destinatario = trim($destinoPrueba);
    }

    /**
     * @return bool true si el correo se encolo/envio correctamente.
     */
    public static function enviar(string $destinatario, string $nombreDestinatario, string $asunto, string $htmlBody): bool
    {
        self::aplicarModoPrueba($destinatario, $asunto);

        if (defined('BREVO_API_KEY') && BREVO_API_KEY !== '') {
            return self::enviarConBrevo($destinatario, $nombreDestinatario, $asunto, $htmlBody);
        }
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return self::enviarConPhpMailer($destinatario, $nombreDestinatario, $asunto, $htmlBody);
        }
        return self::enviarConMailNativo($destinatario, $asunto, $htmlBody);
    }

    /**
     * v6.3 - Envio via la API HTTPS de Brevo (puerto 443, nunca
     * bloqueado por firewalls de hosting). No requiere ninguna
     * extension de PHP extra: usa file_get_contents con un contexto
     * HTTPS, que funciona con la instalacion por defecto de PHP.
     */
    private static function enviarConBrevo(string $destinatario, string $nombre, string $asunto, string $html): bool
    {
        $payload = json_encode([
            'sender' => ['name' => SMTP_FROM_NAME, 'email' => SMTP_FROM_EMAIL],
            'to' => [['email' => $destinatario, 'name' => $nombre]],
            'subject' => $asunto,
            'htmlContent' => $html,
        ], JSON_UNESCAPED_UNICODE);

        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'api-key: ' . BREVO_API_KEY,
                ]),
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $respuesta = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $contexto);
        $codigo = 0;
        foreach ($http_response_header ?? [] as $cabecera) {
            if (preg_match('#^HTTP/\S+\s(\d+)#', $cabecera, $m)) {
                $codigo = (int)$m[1];
            }
        }

        if ($codigo < 200 || $codigo >= 300) {
            error_log('Mailer (Brevo) error: HTTP ' . $codigo . ' - ' . ($respuesta ?: 'sin respuesta'));
            return false;
        }
        return true;
    }

    private static function enviarConPhpMailer(string $destinatario, string $nombre, string $asunto, string $html): bool
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            // Sin credenciales (ej. Mailpit en desarrollo local) se omite
            // la autenticación SMTP; en producción, con SMTP_USER definido,
            // se autentica normalmente contra el servidor real.
            $mail->SMTPAuth   = SMTP_USER !== '';
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            if (SMTP_SECURE !== '') {
                $mail->SMTPSecure = SMTP_SECURE;
            }
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            // v6.3: algunos hostings gratuitos bloquean el puerto SMTP
            // saliente sin avisar (conexion se queda colgada, no da
            // error). Con el servidor de desarrollo de PHP (una sola
            // peticion a la vez) eso congela TODA la app. Un timeout
            // corto asegura que, si el SMTP no responde, el envio
            // falle rapido en vez de bloquear el resto del sistema.
            $mail->Timeout     = 8;
            $mail->SMTPKeepAlive = false;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($destinatario, $nombre);

            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $html;
            $mail->AltBody  = strip_tags($html);

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Mailer (PHPMailer) error: ' . $e->getMessage());
            return false;
        }
    }

    private static function enviarConMailNativo(string $destinatario, string $asunto, string $html): bool
    {
        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>' . "\r\n";

        return @mail($destinatario, $asunto, $html, $headers);
    }
}
