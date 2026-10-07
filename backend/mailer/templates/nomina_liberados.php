<?php
/**
 * v10.21 (pedido explícito del usuario, hallado en el piloto del 16-09):
 * correo ÚNICO con la nómina de trabajadores liberados (tabla), en vez de
 * un correo por trabajador. Lo envía Bodega desde su panel (ver
 * bodega/enviar_nomina.php) a Jefe de Terreno, Capataces, Administrador de
 * Contrato, JAO, Prevención y Bodega.
 * Variables esperadas: $fecha, $total, $obra, $filasHtml (ya escapado)
 */
$textoTotal = $total === 1 ? '1 trabajador liberado' : "{$total} trabajadores liberados";
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#1f2937">
  <h2 style="margin:0 0 4px;color:#059669">✔ Nómina de trabajadores liberados</h2>
  <p style="margin:0 0 16px;color:#6b7280;font-size:13px">{$obra} · {$fecha} · {$textoTotal}</p>
  <p style="font-size:14px;line-height:1.5">
    Las siguientes personas completaron todo el proceso de ingreso
    (contrato firmado, IRL realizada y kit de EPP entregado) y quedaron
    <b>liberadas para ejercer sus funciones</b>. Capataz y Jefe de Terreno
    pueden ir a buscarlas a Bodega o a la sala de reuniones y confirmar la
    recepción en el sistema.
  </p>
  <table style="width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb">
    <thead>
      <tr style="background:#111827;color:#ffffff">
        <th style="padding:9px 10px;text-align:left;font-size:12px">N°</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">Nombre</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">RUT</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">Cargo</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">Liberado</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">Seleccionado por</th>
      </tr>
    </thead>
    <tbody>
      {$filasHtml}
    </tbody>
  </table>
  <p style="font-size:14px"><b>Total: {$textoTotal}.</b></p>
  <p style="font-size:12px;color:#6b7280">
    Este correo fue generado automáticamente por el sistema de reclutamiento ICAFAL.
  </p>
</div>
HTML;
