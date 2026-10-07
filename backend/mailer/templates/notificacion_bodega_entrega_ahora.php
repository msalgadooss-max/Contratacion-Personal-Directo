<?php
/**
 * v10 - Correo a Bodega en el momento del cierre real (día 2, ver
 * functions.php::notificarEntregaEppAhora()): el trabajador ya está en
 * la obra y el contrato ya se firmó -- a diferencia del aviso de
 * notificacion_prevencion_bodega.php (que llega un día antes, para que
 * Bodega prepare el kit con tiempo), este es la señal de "entrégalo
 * ahora mismo".
 * Variables esperadas: $nombreCompleto, $rut, $cargo, $tallaCalzado, $tallaOverol
 */
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937">
  <h2 style="color:#0F6B4C">✔ Entrega el EPP ahora</h2>
  <p>El contrato ya se firmó y la IRL quedó realizada: la persona va camino a Bodega por su kit. Entrégaselo con estos datos:</p>
  <table style="width:100%;border-collapse:collapse;margin:16px 0">
    <tr>
      <td style="padding:8px 0;color:#6b7280;width:140px">Nombre</td>
      <td style="padding:8px 0;font-weight:bold">{$nombreCompleto}</td>
    </tr>
    <tr>
      <td style="padding:8px 0;color:#6b7280">RUT</td>
      <td style="padding:8px 0;font-weight:bold">{$rut}</td>
    </tr>
    <tr>
      <td style="padding:8px 0;color:#6b7280">Cargo</td>
      <td style="padding:8px 0;font-weight:bold">{$cargo}</td>
    </tr>
    <tr>
      <td style="padding:8px 0;color:#6b7280">N° de calzado</td>
      <td style="padding:8px 0;font-weight:bold">{$tallaCalzado}</td>
    </tr>
    <tr>
      <td style="padding:8px 0;color:#6b7280">Talla de overol</td>
      <td style="padding:8px 0;font-weight:bold">{$tallaOverol}</td>
    </tr>
  </table>
  <p style="font-size:12px;color:#6b7280">
    Este correo fue generado automáticamente por el sistema de reclutamiento ICAFAL.
  </p>
</div>
HTML;
