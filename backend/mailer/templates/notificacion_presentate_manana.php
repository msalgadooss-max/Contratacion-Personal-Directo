<?php
/**
 * v10.14 (pedido explícito del usuario, item 17 de la lista post-prueba):
 * correo al postulante confirmando que avanzó y que debe volver mañana
 * a las 8am para el cierre (contratación + entrega de EPP).
 *
 * Quién lo dispara cambia según si Prevención participa o no en esta
 * etapa (ver ambos call sites):
 *   - Prevención pausada: admin_general/verificar_identidad.php, apenas
 *     el JAO verifica identidad (día 1) -- ese es el único gate.
 *   - Prevención activa: prevencion/marcar_induccion.php, recién cuando
 *     Prevención confirma que la inducción ODI YA se hizo -- por eso el
 *     texto no menciona la inducción como algo pendiente para mañana,
 *     en ambos casos ya quedó resuelta el día de hoy.
 * Variables esperadas: $nombreCompleto
 */
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937">
  <h2 style="color:#111827">Avanzaste en tu proceso</h2>
  <p>Hola {$nombreCompleto}, tus datos y documentos ya fueron revisados.</p>
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;margin:20px 0">
    <p style="margin:0;font-weight:bold;color:#1e3a8a">Debes presentarte mañana a las 08:00</p>
    <p style="margin:8px 0 0;color:#1e3a8a">Para ser contratado y recibir tu kit de EPP.</p>
  </div>
  <p style="font-size:12px;color:#6b7280">
    Este correo fue generado automáticamente por el sistema de reclutamiento ICAFAL.
  </p>
</div>
HTML;
