<?php
/**
 * v5 - Correo con el link público de postulación, enviado desde el
 * panel de Desarrollador como alternativa al QR (ver dev/enviar_link.php).
 * Variables esperadas: $nombre, $urlPostulacion
 */
$obraNombre = OBRA_NOMBRE; // v10.32: antes decia siempre H57
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937">
  <h2 style="color:#111827">Postula a ICAFAL</h2>
  <p>Hola {$nombre},</p>
  <p>Te invitamos a postular a la {$obraNombre}. Puedes completar tu postulación desde tu celular o computador con el siguiente enlace:</p>
  <p style="text-align:center;margin:24px 0">
    <a href="{$urlPostulacion}" style="background:#2563eb;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;display:inline-block">
      Ir a la postulación
    </a>
  </p>
  <p style="font-size:12px;color:#6b7280">
    Este correo fue generado automáticamente por el sistema de reclutamiento ICAFAL.
  </p>
</div>
HTML;
