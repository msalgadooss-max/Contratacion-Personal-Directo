<?php
/**
 * v10.8 - Correo enviado a Jefe_Terreno (quien pidió los cupos) y a
 * todos los Capataz activos cuando Admin_Contrato aprueba una solicitud
 * de cupos. Antes de esto, nadie se enteraba de que ya podían empezar
 * a seleccionar gente para ese cargo salvo entrando a revisar a mano.
 * Variables esperadas: $nombreCargo, $cantidadAprobada, $cantidadPedida,
 * $observacion (puede venir null)
 */
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937">
  <h2 style="color:#111827">Cupos aprobados: {$nombreCargo}</h2>
  <p>El Administrador de Contrato aprobó <strong>{$cantidadAprobada} cupo(s)</strong> de
  <strong>{$nombreCargo}</strong>{$cantidadDistinta}.</p>
  {$bloqueObservacion}
  {$bloqueDestino}
  <p>Ya están disponibles para que el Capataz los seleccione en portería.</p>
  <p style="font-size:12px;color:#6b7280">
    Este correo fue generado automáticamente por el sistema de reclutamiento ICAFAL.
  </p>
</div>
HTML;
