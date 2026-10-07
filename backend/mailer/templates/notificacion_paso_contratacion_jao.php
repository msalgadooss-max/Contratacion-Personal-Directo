<?php
/**
 * v10.21 (pedido explícito del usuario, tras el piloto del 16-09): correo
 * a cada Jefe_Administrativo apenas Portería autoriza el paso de una
 * persona a contratación (8 am del día de contratación) -- ya aparece
 * disponible para firma de contrato en su panel.
 * Variables esperadas: $nombreCompleto, $rut, $cargo
 */
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937">
  <h2 style="color:#059669">✔ Listo para firma de contrato</h2>
  <p>Portería acaba de autorizar el paso de esta persona a contratación. Ya aparece disponible en tu panel para la firma del contrato:</p>
  <table style="width:100%;border-collapse:collapse;margin:16px 0">
    <tr>
      <td style="padding:8px 0;color:#6b7280;width:120px">Nombre</td>
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
  </table>
  <p style="font-size:12px;color:#6b7280">
    Este correo fue generado automáticamente por el sistema de reclutamiento ICAFAL.
  </p>
</div>
HTML;
