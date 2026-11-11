<#
.SYNOPSIS
  Crea (o completa) la plataforma Contratacion MOD en Azure: MySQL Flexible
  Server + App Service (contenedor) + Azure Files para los documentos.

.DESCRIPTION
  Idempotente: se puede correr varias veces; lo que ya existe se reutiliza y
  los secretos ya generados NO se regeneran (viven en
  %USERPROFILE%\.icafal\azure-secretos.env, fuera del repositorio).

  Requisitos previos (una sola vez):
    * az login con un usuario Contributor del grupo de recursos.
    * Proveedor Microsoft.DBforMySQL registrado en la suscripcion (lo hace un
      administrador de la suscripcion: az provider register -n Microsoft.DBforMySQL).
    * La imagen ya subida al registro (azure\desplegar.ps1 -SoloImagen, o az acr build).

  Recursos que crea (con costo, ver azure\README.md): MySQL B1ms 20 GB, App
  Service plan B1 Linux, cuenta de almacenamiento Standard LRS con un recurso
  compartido de archivos. Reutiliza el registro de contenedores y la identidad
  administrada que ya existen en el grupo de recursos.

.PARAMETER SoloMostrar
  No ejecuta nada: imprime lo que haria (los secretos salen enmascarados).
#>
[CmdletBinding()]
param(
  [string]$Suscripcion   = '48b7e9b6-4826-42e6-a480-9e9a645104a2',
  [string]$GrupoRecursos = 'rg_contratacionmod-maqueta',
  [string]$Region        = 'chilecentral',
  [string]$Registro      = 'icafalcontratacionmodpruebas',
  [string]$Identidad     = 'id-contratacionmod-maqueta',
  [string]$Imagen        = 'contratacionmod:latest',
  [string]$Sufijo        = '',
  [int]$RetencionRespaldoDias = 14,
  [switch]$SoloMostrar
)

$ErrorActionPreference = 'Stop'
$az = 'C:\Program Files\Microsoft SDKs\Azure\CLI2\wbin\az.cmd'
if (-not (Test-Path $az)) { $az = 'az' }

# ---------------------------------------------------------------- utilidades
function Paso($t) { Write-Host "`n== $t" -ForegroundColor Cyan }
function Ok($t)   { Write-Host "   $t" -ForegroundColor Green }
function Aviso($t){ Write-Host "   $t" -ForegroundColor Yellow }

function Invoke-Az {
  param([string[]]$A, [switch]$Json, [switch]$Permitir, [string[]]$Secretos = @())
  if ($SoloMostrar) {
    $txt = ($A -join ' ')
    foreach ($s in $Secretos) { if ($s) { $txt = $txt.Replace($s, '********') } }
    Write-Host "   [az] $txt" -ForegroundColor DarkGray
    return $null
  }
  $out = & $az @A
  if ($LASTEXITCODE -ne 0) {
    if ($Permitir) { return $null }
    throw "az $($A[0..2] -join ' ') fallo (codigo $LASTEXITCODE)"
  }
  if ($Json -and $out) { return ($out | Out-String | ConvertFrom-Json) }
  return $out
}

function Existe([string[]]$A) {
  # true si el comando (un "show") termina bien. Siempre false en modo SoloMostrar.
  if ($SoloMostrar) { return $false }
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  & $az @A -o none 2>$null
  $ok = ($LASTEXITCODE -eq 0)
  $ErrorActionPreference = $prev
  return $ok
}

function Nueva-Clave([int]$largo = 28) {
  # Letras y numeros (sin simbolos que compliquen archivos de opciones o shells).
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
  $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
  $bytes = New-Object byte[] ($largo * 2)
  $rng.GetBytes($bytes)
  $sb = New-Object System.Text.StringBuilder
  for ($i = 0; $sb.Length -lt $largo; $i++) {
    if ($bytes[$i] -lt 232) { [void]$sb.Append($chars[$bytes[$i] % $chars.Length]) }   # 232 = 4*58: sin sesgo
    if ($i -ge $bytes.Length - 1) { $rng.GetBytes($bytes); $i = -1 }
  }
  return $sb.ToString()
}

# ------------------------------------------------------------------ secretos
$dirSecretos = Join-Path $env:USERPROFILE '.icafal'
$archivoSecretos = Join-Path $dirSecretos 'azure-secretos.env'
$sec = @{}
if (Test-Path $archivoSecretos) {
  Get-Content $archivoSecretos | ForEach-Object { if ($_ -match '^\s*([A-Za-z0-9_]+)=(.*)$') { $sec[$Matches[1]] = $Matches[2] } }
}
$nuevos = $false
foreach ($k in 'DB_ADMIN_PASS', 'DB_PASS') { if (-not $sec[$k]) { $sec[$k] = Nueva-Clave; $nuevos = $true } }
if (-not $sec['SUFIJO']) { $sec['SUFIJO'] = $(if ($Sufijo) { $Sufijo } else { (Nueva-Clave 5).ToLower() }); $nuevos = $true }
$suf = $sec['SUFIJO']
if ($nuevos -and -not $SoloMostrar) {
  New-Item -ItemType Directory -Force $dirSecretos | Out-Null
  ($sec.GetEnumerator() | Sort-Object Name | ForEach-Object { "$($_.Name)=$($_.Value)" }) | Set-Content -Encoding ASCII $archivoSecretos
  # Solo el usuario actual puede leerlo.
  & icacls $archivoSecretos /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
  Ok "Secretos nuevos guardados en $archivoSecretos (solo tu usuario puede leerlo)"
}

# -------------------------------------------------------------------- nombres
$nMysql   = "icafal-cmod-db-$suf"
$nPlan    = 'asp-icafal-contratacionmod'
$nApp     = "icafal-contratacionmod-$suf"
$nStorage = "icafalcmod$suf"          # solo minusculas y numeros, max 24
$nShare   = 'documentos'
$admin    = 'icafaladmin'
$appUser  = 'icafal_app'
$nubeAcr  = "$Registro.azurecr.io"

Paso 'Plan'
Write-Host "   Suscripcion:   $Suscripcion"
Write-Host "   Region:        $Region"
Write-Host "   Grupo:         $GrupoRecursos"
Write-Host "   MySQL:         $nMysql  (8.4, Burstable B1ms, 20 GB, respaldos $RetencionRespaldoDias dias)"
Write-Host "   App Service:   $nApp en plan $nPlan (B1 Linux)"
Write-Host "   Documentos:    cuenta $nStorage, recurso compartido '$nShare'"
Write-Host "   Imagen:        $nubeAcr/$Imagen (identidad $Identidad)"

Invoke-Az @('account', 'set', '--subscription', $Suscripcion) | Out-Null

# ------------------------------------------------------ 0) proveedor de MySQL
Paso '0) Proveedor Microsoft.DBforMySQL'
if (-not $SoloMostrar) {
  $estado = & $az provider show -n Microsoft.DBforMySQL --query registrationState -o tsv
  if ($estado -ne 'Registered') {
    throw "Microsoft.DBforMySQL esta '$estado'. Un administrador de la suscripcion debe correr: az provider register -n Microsoft.DBforMySQL --wait"
  }
  Ok 'registrado'
}

# ------------------------------------------------------------ 1) almacenamiento
Paso '1) Almacenamiento para documentos (Azure Files)'
if (-not $SoloMostrar -and -not (Existe @('storage', 'account', 'show', '-g', $GrupoRecursos, '-n', $nStorage))) {
  Invoke-Az @('storage', 'account', 'create', '-g', $GrupoRecursos, '-n', $nStorage, '-l', $Region,
    '--sku', 'Standard_LRS', '--kind', 'StorageV2', '--min-tls-version', 'TLS1_2',
    '--allow-blob-public-access', 'false', '--https-only', 'true', '-o', 'none') | Out-Null
  Ok "cuenta $nStorage creada"
} elseif ($SoloMostrar) {
  Invoke-Az @('storage', 'account', 'create', '-g', $GrupoRecursos, '-n', $nStorage, '-l', $Region, '--sku', 'Standard_LRS') | Out-Null
} else { Ok "cuenta $nStorage ya existe" }
$claveStorage = $null
if (-not $SoloMostrar) {
  $claveStorage = (& $az storage account keys list -g $GrupoRecursos -n $nStorage --query '[0].value' -o tsv)
  Invoke-Az @('storage', 'share-rm', 'create', '--storage-account', $nStorage, '-g', $GrupoRecursos, '-n', $nShare, '--quota', '100', '-o', 'none') -Permitir | Out-Null
  # Papelera de recursos compartidos: un borrado accidental se puede deshacer 14 dias.
  Invoke-Az @('storage', 'account', 'file-service-properties', 'update', '--account-name', $nStorage, '-g', $GrupoRecursos,
    '--enable-delete-retention', 'true', '--delete-retention-days', '14', '-o', 'none') -Permitir | Out-Null
  Ok "recurso compartido '$nShare' listo (papelera 14 dias)"
}

# ------------------------------------------------------------------- 2) MySQL
Paso '2) MySQL Flexible Server'
if (-not (Existe @('mysql', 'flexible-server', 'show', '-g', $GrupoRecursos, '-n', $nMysql))) {
  Invoke-Az @('mysql', 'flexible-server', 'create', '-g', $GrupoRecursos, '-n', $nMysql, '-l', $Region,
    '--admin-user', $admin, '--admin-password', $sec['DB_ADMIN_PASS'],
    '--sku-name', 'Standard_B1ms', '--tier', 'Burstable', '--storage-size', '20', '--version', '8.4',
    '--backup-retention', "$RetencionRespaldoDias", '--high-availability', 'Disabled',
    '--public-access', 'None', '--yes', '-o', 'none') -Secretos @($sec['DB_ADMIN_PASS']) | Out-Null
  Ok "servidor $nMysql creado"
} else { Ok "servidor $nMysql ya existe" }

# Parametros del servidor. Los triggers de auditoria (trazabilidad_logs) exigen
# log_bin_trust_function_creators=ON porque Azure no da SUPER a nadie.
$params = @{
  'require_secure_transport'        = 'ON'
  'tls_version'                     = 'TLSv1.2,TLSv1.3'
  'log_bin_trust_function_creators' = 'ON'
  'character_set_server'            = 'utf8mb4'
  'collation_server'                = 'utf8mb4_unicode_ci'
}
foreach ($p in $params.Keys) {
  Invoke-Az @('mysql', 'flexible-server', 'parameter', 'set', '-g', $GrupoRecursos, '--server-name', $nMysql,
    '--name', $p, '--value', $params[$p], '-o', 'none') -Permitir | Out-Null
}
Ok 'parametros del servidor aplicados'
$hostMysql = "$nMysql.mysql.database.azure.com"

# ------------------------------------------------------------- 3) App Service
Paso '3) App Service'
if (-not (Existe @('appservice', 'plan', 'show', '-g', $GrupoRecursos, '-n', $nPlan))) {
  Invoke-Az @('appservice', 'plan', 'create', '-g', $GrupoRecursos, '-n', $nPlan, '-l', $Region,
    '--is-linux', '--sku', 'B1', '-o', 'none') | Out-Null
  Ok "plan $nPlan creado"
} else { Ok "plan $nPlan ya existe" }

$idUai = $null; $clientUai = $null
if (-not $SoloMostrar) {
  $uai = Invoke-Az @('identity', 'show', '-g', $GrupoRecursos, '-n', $Identidad, '-o', 'json') -Json
  $idUai = $uai.id; $clientUai = $uai.clientId
}

if (-not (Existe @('webapp', 'show', '-g', $GrupoRecursos, '-n', $nApp))) {
  Invoke-Az @('webapp', 'create', '-g', $GrupoRecursos, '-p', $nPlan, '-n', $nApp,
    '--container-image-name', "$nubeAcr/$Imagen", '-o', 'none') | Out-Null
  Ok "app $nApp creada"
} else { Ok "app $nApp ya existe" }
if (-not $SoloMostrar) {
  Invoke-Az @('webapp', 'identity', 'assign', '-g', $GrupoRecursos, '-n', $nApp, '--identities', $idUai, '-o', 'none') | Out-Null
  # La app baja la imagen del registro con la identidad administrada (sin claves guardadas).
  Invoke-Az @('webapp', 'config', 'set', '-g', $GrupoRecursos, '-n', $nApp,
    '--generic-configurations', 'acrUseManagedIdentityCreds=true', "acrUserManagedIdentityID=$clientUai", '-o', 'none') | Out-Null
  Invoke-Az @('webapp', 'config', 'container', 'set', '-g', $GrupoRecursos, '-n', $nApp,
    '--container-image-name', "$nubeAcr/$Imagen", '--container-registry-url', "https://$nubeAcr", '-o', 'none') | Out-Null
}
Invoke-Az @('webapp', 'config', 'set', '-g', $GrupoRecursos, '-n', $nApp,
  '--always-on', 'true', '--http20-enabled', 'true', '--min-tls-version', '1.2', '--ftps-state', 'Disabled', '-o', 'none') | Out-Null
Invoke-Az @('webapp', 'update', '-g', $GrupoRecursos, '-n', $nApp, '--https-only', 'true', '-o', 'none') | Out-Null

$hostApp = $null
if (-not $SoloMostrar) { $hostApp = (& $az webapp show -g $GrupoRecursos -n $nApp --query defaultHostName -o tsv) } else { $hostApp = "$nApp.azurewebsites.net" }

# Documentos: recurso compartido montado en /mnt/documentos.
if (-not $SoloMostrar) {
  Invoke-Az @('webapp', 'config', 'storage-account', 'add', '-g', $GrupoRecursos, '-n', $nApp,
    '--custom-id', 'documentos', '--storage-type', 'AzureFiles', '--share-name', $nShare,
    '--account-name', $nStorage, '--access-key', $claveStorage, '--mount-path', '/mnt/documentos', '-o', 'none') -Permitir -Secretos @($claveStorage) | Out-Null
}

# Variables de la plataforma (docker-entrypoint.sh las lee al arrancar).
$settings = @(
  'WEBSITES_PORT=8000',
  'WEBSITES_ENABLE_APP_SERVICE_STORAGE=false',
  'WEBSITES_CONTAINER_START_TIME_LIMIT=900',
  "BASE_URL=https://$hostApp",
  "DB_HOST=$hostMysql",
  "DB_ADMIN_USER=$admin",
  "DB_ADMIN_PASS=$($sec['DB_ADMIN_PASS'])",
  "DB_USER=$appUser",
  "DB_PASS=$($sec['DB_PASS'])",
  'DB_SSL=verify',
  'UPLOADS_BASE=/mnt/documentos'
)
# Correo: si dejaste BREVO_API_KEY (y opcionalmente SMTP_FROM_EMAIL) en el archivo de secretos, se aplican.
foreach ($k in 'BREVO_API_KEY', 'SMTP_FROM_EMAIL', 'EMAIL_MODO_PRUEBA', 'EMAIL_PRUEBA_DESTINO') { if ($sec[$k]) { $settings += "$k=$($sec[$k])" } }
Invoke-Az (@('webapp', 'config', 'appsettings', 'set', '-g', $GrupoRecursos, '-n', $nApp, '-o', 'none', '--settings') + $settings) `
  -Secretos @($sec['DB_ADMIN_PASS'], $sec['DB_PASS'], $sec['BREVO_API_KEY']) | Out-Null
Ok 'variables de la aplicacion aplicadas'
Invoke-Az @('webapp', 'log', 'config', '-g', $GrupoRecursos, '-n', $nApp, '--docker-container-logging', 'filesystem', '--level', 'information', '-o', 'none') -Permitir | Out-Null

# ------------------------------------------- 4) firewall: solo la app entra a MySQL
Paso '4) Firewall de MySQL (solo las IP de salida de la app)'
if (-not $SoloMostrar) {
  $ips = (& $az webapp show -g $GrupoRecursos -n $nApp --query possibleOutboundIpAddresses -o tsv).Split(',') | Sort-Object -Unique
  $i = 0
  foreach ($ip in $ips) {
    $i++
    Invoke-Az @('mysql', 'flexible-server', 'firewall-rule', 'create', '-g', $GrupoRecursos, '-n', $nMysql,
      '--rule-name', ("app-salida-{0:D2}" -f $i), '--start-ip-address', $ip, '--end-ip-address', $ip, '-o', 'none') | Out-Null
  }
  Ok "$i reglas creadas"
} else { Invoke-Az @('mysql', 'flexible-server', 'firewall-rule', 'create', '...una regla por IP de salida de la app...') | Out-Null }

# -------------------------------------------------- 5) archivo para uso desde este PC
if (-not $SoloMostrar) {
  $cnf = Join-Path $dirSecretos 'azure-mysql.cnf'
  @(
    '[client]',
    "host=$hostMysql",
    'port=3306',
    "user=$admin",
    "password=`"$($sec['DB_ADMIN_PASS'])`"",
    'default-character-set=utf8mb4',
    'ssl-mode=REQUIRED'
  ) | Set-Content -Encoding ASCII $cnf
  & icacls $cnf /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
  Ok "credenciales de administracion (para este PC) en $cnf"
}

Paso 'Listo'
Write-Host "   Direccion de la plataforma:  https://$hostApp"
Write-Host "   Obra H57:  https://$hostApp/frontend/public/index.html"
Write-Host "   Obra H58:  https://$hostApp/h58/frontend/public/index.html"
Write-Host "   La primera vez tarda unos minutos (baja la imagen y crea las bases)."
Write-Host "   Siguiente: azure\verificar.ps1 -App $nApp"
