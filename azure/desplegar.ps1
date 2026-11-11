<#
.SYNOPSIS
  Publica una version nueva de la plataforma en Azure: construye la imagen en
  el registro (sin Docker local), apunta la app a ella y la reinicia.

.EXAMPLE
  .\azure\desplegar.ps1 -App icafal-contratacionmod-xxxxx
  .\azure\desplegar.ps1 -SoloImagen            # solo construye y sube la imagen

  Para volver atras: .\azure\desplegar.ps1 -App <app> -Etiqueta v10.33-613d3d5 -SinConstruir
#>
[CmdletBinding()]
param(
  [string]$App,
  [string]$GrupoRecursos = 'rg_contratacionmod-maqueta',
  [string]$Registro      = 'icafalcontratacionmodpruebas',
  [string]$Etiqueta      = '',
  [switch]$SoloImagen,
  [switch]$SinConstruir
)
$ErrorActionPreference = 'Stop'
$az = 'C:\Program Files\Microsoft SDKs\Azure\CLI2\wbin\az.cmd'
if (-not (Test-Path $az)) { $az = 'az' }
$repo = Split-Path -Parent $PSScriptRoot
Set-Location $repo

if (-not $SoloImagen -and -not $App) { throw 'Indica -App <nombre de la app> (o -SoloImagen).' }

$sucio = (git status --porcelain)
if ($sucio -and -not $SinConstruir) { Write-Host 'AVISO: hay cambios sin guardar en git; la imagen incluye el estado actual del disco.' -ForegroundColor Yellow }

if (-not $Etiqueta) {
  $sha = (git rev-parse --short HEAD).Trim()
  $Etiqueta = "v$(Get-Date -Format 'yyyyMMdd-HHmm')-$sha"
}
$imagen = "$Registro.azurecr.io/contratacionmod:$Etiqueta"

if (-not $SinConstruir) {
  Write-Host "== Construyendo $imagen en la nube..." -ForegroundColor Cyan
  # Contexto limpio: solo lo que git conoce (sin node_modules, documentos .docx, etc.).
  $tmp = Join-Path $env:TEMP "icafal-ctx-$Etiqueta"
  if (Test-Path $tmp) { Remove-Item -Recurse -Force $tmp }
  New-Item -ItemType Directory $tmp | Out-Null
  $lista = (git ls-files -c -o --exclude-standard)
  foreach ($f in $lista) {
    if (-not (Test-Path -LiteralPath $f)) { continue }
    $dest = Join-Path $tmp $f
    New-Item -ItemType Directory -Force (Split-Path $dest) | Out-Null
    Copy-Item -LiteralPath $f -Destination $dest
  }
  & $az acr build -r $Registro -t "contratacionmod:$Etiqueta" -t 'contratacionmod:latest' -f Dockerfile $tmp
  if ($LASTEXITCODE -ne 0) { throw 'La construccion de la imagen fallo.' }
  Remove-Item -Recurse -Force $tmp
}
if ($SoloImagen) { Write-Host "Imagen lista: $imagen"; return }

Write-Host "== Apuntando $App a $Etiqueta..." -ForegroundColor Cyan
& $az webapp config container set -g $GrupoRecursos -n $App --container-image-name $imagen --container-registry-url "https://$Registro.azurecr.io" -o none
if ($LASTEXITCODE -ne 0) { throw 'No se pudo cambiar la imagen de la app.' }
& $az webapp restart -g $GrupoRecursos -n $App
$host_ = (& $az webapp show -g $GrupoRecursos -n $App --query defaultHostName -o tsv)

Write-Host "== Esperando que responda https://$host_ ..." -ForegroundColor Cyan
$ok = $false
for ($i = 1; $i -le 60; $i++) {
  try {
    $r = Invoke-WebRequest -Uri "https://$host_/frontend/public/index.html" -UseBasicParsing -TimeoutSec 20
    if ($r.StatusCode -eq 200) { $ok = $true; break }
  } catch { }
  Start-Sleep -Seconds 10
}
if ($ok) { Write-Host "LISTO: https://$host_  (version $Etiqueta)" -ForegroundColor Green }
else { Write-Host "No respondio en 10 minutos. Revisa: az webapp log tail -g $GrupoRecursos -n $App" -ForegroundColor Red; exit 1 }
