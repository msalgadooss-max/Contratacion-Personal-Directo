<?php
/**
 * v10.24 (pedido explícito del usuario, 06-10): UN solo correo para el
 * postulante cuando el Capataz lo selecciona -- junta lo que antes eran dos
 * correos seguidos: "¡Buenas noticias!" con el link de Etapa 2
 * (link_privado.php) y "Preséntate en portería" con el QR
 * (ingreso_faena_qr.php). Se mantiene la regla v10.2: nunca decirle
 * "contratación" al postulante acá, todavía está postulando.
 * Variables esperadas: $nombreCompleto, $cargo, $urlFormularioPrivado,
 * $qrImagenUrl, $urlValidacion
 */
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937">
  <h2 style="color:#111827">¡Buenas noticias, {$nombreCompleto}!</h2>
  <p>Tu postulación avanzó a la siguiente etapa para el cargo de
  <strong>{$cargo}</strong>. Quedan dos pasos:</p>

  <p style="margin:18px 0 6px;font-weight:bold;color:#1d4e89">1. Muestra este código en portería</p>
  <div style="background:#eaf1fa;border:1px solid #bcd4ee;border-radius:10px;padding:18px;text-align:center;margin:0 0 20px">
    <img src="{$qrImagenUrl}" alt="Código QR de ingreso" width="180" height="180" style="display:block;margin:0 auto;background:#fff;padding:8px;border-radius:8px">
    <p style="margin:12px 0 0;font-size:13px;color:#1d4e89">Muéstraselo a Portería al llegar.</p>
    <p style="margin:8px 0 0;font-size:12px;"><a href="{$urlValidacion}" style="color:#1d4e89">¿No ves el código? Toca aquí</a></p>
  </div>

  <p style="margin:18px 0 6px;font-weight:bold;color:#15803d">2. Completa tus datos y documentos</p>
  <p style="margin:0">Necesitamos tus datos personales y previsionales, y tus
  documentos.</p>
  <p style="text-align:center;margin:20px 0">
    <a href="{$urlFormularioPrivado}" style="background:#16a34a;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">
      Completar mis datos
    </a>
  </p>

  <p>El botón es <strong>personal e intransferible</strong>. Si tienes
  problemas para abrirlo, solicita uno nuevo a tu contacto en la empresa.</p>
  <p style="font-size:12px;color:#6b7280">
    Este correo fue generado automáticamente. Si no reconoces este
    proceso, ignora este mensaje.
  </p>
</div>
HTML;
