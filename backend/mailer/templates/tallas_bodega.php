<?php
/**
 * v10.22 (pedido explícito del usuario, 06-10): correo ÚNICO a Bodega con la
 * tabla de tallas de los postulantes del día, en vez de un correo por
 * postulante (ver functions.php::enviarTallasABodega()).
 * Variables esperadas: $fecha, $hora, $total, $obra, $filasHtml (ya escapado)
 */
$textoTotal = $total === 1 ? '1 postulante' : "{$total} postulantes";
return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#1f2937">
  <h2 style="margin:0 0 4px;color:#111827">Tallas para preparar los kits de EPP</h2>
  <p style="margin:0 0 16px;color:#6b7280;font-size:13px">{$obra} · {$fecha} {$hora} · {$textoTotal}</p>
  <p style="font-size:14px;line-height:1.5">
    Estas personas completaron su postulación y se presentarán
    <b>mañana a las 8:00 am en la obra</b> para su contratación y la entrega
    del kit de EPP. Prepara los kits (calzado y overol) con las siguientes tallas:
  </p>
  <table style="width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb">
    <thead>
      <tr style="background:#111827;color:#ffffff">
        <th style="padding:9px 10px;text-align:left;font-size:12px">N°</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">Nombre</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">RUT</th>
        <th style="padding:9px 10px;text-align:left;font-size:12px">Cargo</th>
        <th style="padding:9px 10px;text-align:center;font-size:12px">N° calzado</th>
        <th style="padding:9px 10px;text-align:center;font-size:12px">Talla overol</th>
      </tr>
    </thead>
    <tbody>
      {$filasHtml}
    </tbody>
  </table>
  <p style="font-size:14px"><b>Total: {$textoTotal}.</b></p>
  <p style="font-size:12px;color:#6b7280">
    Si más personas completan su postulación después de este envío, recibirás
    otro correo aparte solo con ellas. Generado automáticamente por el sistema
    de reclutamiento ICAFAL.
  </p>
</div>
HTML;
