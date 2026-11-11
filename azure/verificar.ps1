<#
.SYNOPSIS
  Revisa que la plataforma en Azure este sana: paginas de ambas obras, que
  las carpetas internas esten bloqueadas, y el registro de arranque.
  No envia credenciales ni modifica nada.
#>
[CmdletBinding()]
param(
  [Parameter(Mandatory)][string]$App,
  [string]$GrupoRecursos = 'rg_contratacionmod-maqueta'
)
$ErrorActionPreference = 'Stop'
$az = 'C:\Program Files\Microsoft SDKs\Azure\CLI2\wbin\az.cmd'
if (-not (Test-Path $az)) { $az = 'az' }

$h = (& $az webapp show -g $GrupoRecursos -n $App --query defaultHostName -o tsv)
$base = "https://$h"
$fallas = 0
function Probar($nombre, $ruta, $esperado) {
  try { $c = (Invoke-WebRequest -Uri "$base$ruta" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 0 -ErrorAction Stop).StatusCode }
  catch { $c = [int]$_.Exception.Response.StatusCode }
  $bien = ($c -eq $esperado)
  if (-not $bien) { $script:fallas++ }
  '{0} {1,-48} esperado {2} obtuvo {3}' -f $(if ($bien) { 'OK  ' } else { 'FALLA' }), $nombre, $esperado, $c
}
Probar 'Obra H57 (formulario publico)'        '/frontend/public/index.html' 200
Probar 'Obra H58 (formulario publico)'        '/h58/frontend/public/index.html' 200
Probar 'Cargos H57 (API + base de datos)'     '/backend/api/public/cargos_disponibles.php' 200
Probar 'Cargos H58 (API + base de datos)'     '/h58/backend/api/public/cargos_disponibles.php' 200
Probar 'Documentos NO accesibles por URL'     '/backend/uploads/cv/x.pdf' 403
Probar 'Configuracion NO accesible'           '/backend/config/config.php' 403
Probar 'Esquema SQL NO accesible'             '/database/schema.sql' 403
Probar 'Obra inexistente (API) = 404'         '/h99/backend/api/public/cargos_disponibles.php' 404

Write-Host "`n== Registro de arranque (ultimas lineas relevantes)"
$zip = Join-Path $env:TEMP "icafal-logs-$App.zip"
& $az webapp log download -g $GrupoRecursos -n $App --log-file $zip 2>$null | Out-Null
if (Test-Path $zip) {
  $dir = Join-Path $env:TEMP "icafal-logs-$App"
  if (Test-Path $dir) { Remove-Item -Recurse -Force $dir }
  Expand-Archive $zip $dir
  Get-ChildItem $dir -Recurse -Filter '*docker.log' | Sort-Object LastWriteTime | Select-Object -Last 1 | ForEach-Object {
    Select-String -Path $_.FullName -Pattern '\[entrypoint\]' | Select-Object -Last 14 | ForEach-Object { '   ' + $_.Line.Substring($_.Line.IndexOf('[entrypoint]')) }
  }
} else { Write-Host '   (no se pudo bajar el registro todavia)' }

if ($fallas -eq 0) { Write-Host "`nTODO OK" -ForegroundColor Green } else { Write-Host "`n$fallas verificaciones fallaron" -ForegroundColor Red; exit 1 }
