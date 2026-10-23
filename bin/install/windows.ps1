#Requires -Version 5.1
<#
.SYNOPSIS
  Socly one-click installer for Windows.
.DESCRIPTION
  Installs PHP 8.2 (if needed), Composer (local phar fallback), MariaDB,
  creates the database, writes .env, runs migrations and starts a local
  server. Complete the wizard at http://127.0.0.1:8080/install.
#>
[CmdletBinding()]
param(
    [int]$Port = 8080,
    [string]$Bind = '127.0.0.1',
    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbName = 'socly',
    [string]$DbUser = 'socly',
    [string]$DbPass = '',
    [string]$DbRootPass = '',
    [string]$Url = '',
    [ValidateSet('it', 'de', 'en')][string]$Locale = 'it',
    [switch]$NoOcr,
    [switch]$NoBrowser,
    [switch]$NoServe,
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$AppUrl = if ([string]::IsNullOrWhiteSpace($Url)) { "http://${Bind}:${Port}" } else { $Url.TrimEnd('/') }

function Write-Ok([string]$Message) { Write-Host "[OK] $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { Write-Host "[!] $Message" -ForegroundColor Yellow }
function Fail([string]$Message, [int]$Code = 1) {
    Write-Host "[X] $Message" -ForegroundColor Red
    exit $Code
}
function Has-Command([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}
function Test-PhpVersion([string]$PhpExe) {
    try {
        $v = & $PhpExe -r 'echo PHP_VERSION;' 2>$null
        return ([version]$v -ge [version]'8.2')
    } catch {
        return $false
    }
}
function Get-MissingExtensions([string]$PhpExe) {
    $code = '$r=["pdo_mysql","mbstring","openssl","json","fileinfo"]; $m=[]; foreach($r as $e){ if(!extension_loaded($e)) $m[]=$e; } echo implode(",",$m);'
    try {
        return (& $PhpExe -r $code 2>$null)
    } catch {
        return 'pdo_mysql,mbstring,openssl,json,fileinfo'
    }
}

if ($Check) {
    Write-Host 'Socly - verifica ambiente Windows'
    Write-Host "  Sistema:   $([System.Environment]::OSVersion.VersionString)"
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCmd) {
        $phpVersion = & $phpCmd.Source -r 'echo PHP_VERSION;' 2>$null
        Write-Host "  PHP:       $phpVersion ($($phpCmd.Source))"
        $missing = Get-MissingExtensions $phpCmd.Source
        if ([string]::IsNullOrWhiteSpace($missing)) {
            Write-Host '  Estensioni: pdo_mysql, mbstring, openssl, json, fileinfo OK'
        } else {
            Write-Host "  Estensioni: MANCANTI ($missing) - verra installato PHP 8.2 dedicato"
        }
    } else {
        Write-Host '  PHP:       assente (verra installato)'
    }
    if (Has-Command composer) {
        Write-Host "  Composer:  $((& composer --version 2>$null | Select-Object -First 1))"
    } else {
        Write-Host '  Composer:  assente (verra scaricato composer.phar)'
    }
    if (Has-Command mysql) {
        Write-Host "  MariaDB:   client presente ($((Get-Command mysql).Source))"
    } else {
        Write-Host '  MariaDB:   assente (verra installato con winget/choco)'
    }
    Write-Host "  Progetto:  $Root"
    Write-Host "  URL:       $AppUrl/install"
    exit 0
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Richiesta privilegi di amministratore...'
    $argString = "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`""
    foreach ($key in $PSBoundParameters.Keys) {
        $value = $PSBoundParameters[$key]
        if ($value -is [switch]) {
            if ($value.IsPresent) { $argString += " -$key" }
        } elseif ($null -ne $value -and "$value" -ne '') {
            $argString += " -$key `"$value`""
        }
    }
    Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $argString | Out-Null
    exit 0
}

function Ensure-Php {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd -and (Test-PhpVersion $cmd.Source)) {
        $missing = Get-MissingExtensions $cmd.Source
        if ([string]::IsNullOrWhiteSpace($missing)) {
            Write-Ok "PHP gia disponibile: $(& $cmd.Source -r 'echo PHP_VERSION;')"
            return $cmd.Source
        }
        Write-Warn "PHP presente ma estensioni mancanti ($missing): installo PHP 8.2 dedicato."
    }

    $phpDir = Join-Path $env:ProgramFiles 'Socly\php'
    New-Item -ItemType Directory -Force -Path $phpDir | Out-Null
    $zip = Join-Path $env:TEMP 'socly-php.zip'
    Write-Host 'Download PHP per Windows...'
    $releases = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
    $branch = $null
    foreach ($candidate in @('8.2', '8.3', '8.4')) {
        if ($releases.PSObject.Properties.Name -contains $candidate) {
            $branch = $candidate
            break
        }
    }
    if (-not $branch) { Fail 'Nessuna build PHP 8.2/8.3/8.4 trovata.' }
    $entry = $releases.$branch
    $zipPath = $null
    $sha256 = $null
    foreach ($key in @('nts-vs16-x64', 'nts-vs17-x64')) {
        if ($entry.PSObject.Properties.Name -contains $key) {
            $zipPath = $entry.$key.zip.path
            $sha256 = $entry.$key.zip.sha256
            break
        }
    }
    if (-not $zipPath) { Fail "Build PHP $branch NTS x64 non trovata." }
    Invoke-WebRequest -Uri ("https://windows.php.net/downloads/releases/" + $zipPath) -OutFile $zip -UseBasicParsing
    if ($sha256) {
        $actual = (Get-FileHash -Path $zip -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actual -ne $sha256.ToLowerInvariant()) {
            Remove-Item $zip -Force -ErrorAction SilentlyContinue
            Fail 'Checksum PHP non valido (download corrotto).'
        }
    }
    Expand-Archive -Path $zip -DestinationPath $phpDir -Force
    Remove-Item $zip -Force -ErrorAction SilentlyContinue

    $ini = Join-Path $phpDir 'php.ini'
    Copy-Item (Join-Path $phpDir 'php.ini-development') $ini -Force
    $lines = Get-Content $ini
    $lines = $lines -replace '^;extension_dir = "ext"', 'extension_dir = "ext"'
    foreach ($ext in @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'zip')) {
        $lines = $lines -replace "^;extension=$ext\s*$", "extension=$ext"
    }
    $lines += 'upload_max_filesize = 25M'
    $lines += 'post_max_size = 26M'
    Set-Content -Path $ini -Value $lines -Encoding ASCII

    $phpExe = Join-Path $phpDir 'php.exe'
    $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    if ($machinePath -notlike "*$phpDir*") {
        [Environment]::SetEnvironmentVariable('Path', ($machinePath.TrimEnd(';') + ';' + $phpDir), 'Machine')
    }
    $env:Path = "$phpDir;$env:Path"
    $missing = Get-MissingExtensions $phpExe
    if (-not [string]::IsNullOrWhiteSpace($missing)) {
        Fail "PHP installato ma estensioni ancora mancanti: $missing"
    }
    Write-Ok "PHP installato in $phpDir"
    return $phpExe
}

function Ensure-Composer([string]$PhpExe) {
    $cmd = Get-Command composer -ErrorAction SilentlyContinue
    if ($cmd) {
        return @{ Kind = 'exe'; Path = $cmd.Source }
    }
    $phar = Join-Path $Root 'storage\cache\composer.phar'
    New-Item -ItemType Directory -Force -Path (Split-Path $phar) | Out-Null
    Write-Host 'Download Composer (composer.phar)...'
    Invoke-WebRequest -Uri 'https://getcomposer.org/composer-stable.phar' -OutFile $phar -UseBasicParsing
    return @{ Kind = 'phar'; Path = $phar }
}

function Ensure-MariaDb {
    $cmd = Get-Command mysql -ErrorAction SilentlyContinue
    if ($cmd) {
        Write-Ok "Client MariaDB gia disponibile: $($cmd.Source)"
        return $cmd.Source
    }

    $existing = Get-ChildItem -Path "$env:ProgramFiles\MariaDB*" -Directory -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($existing) {
        $exe = Get-ChildItem -Path $existing.FullName -Recurse -Filter mysql.exe -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($exe) {
            $env:Path = "$($exe.DirectoryName);$env:Path"
            Write-Ok "Client MariaDB trovato: $($exe.FullName)"
            return $exe.FullName
        }
    }

    if (Has-Command winget) {
        Write-Host 'Installazione MariaDB con winget...'
        & winget install --id MariaDB.Server -e --accept-package-agreements --accept-source-agreements --silent
    } elseif (Has-Command choco) {
        Write-Host 'Installazione MariaDB con Chocolatey...'
        if ($DbRootPass) {
            & choco install mariadb -y --params "/Password:$DbRootPass"
        } else {
            & choco install mariadb -y
        }
    } else {
        Fail 'MariaDB non trovato. Installa MariaDB Server da https://mariadb.org/download/ e riprova.'
    }

    Start-Sleep -Seconds 5
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    $cmd = Get-Command mysql -ErrorAction SilentlyContinue
    if ($cmd) {
        Write-Ok "Client MariaDB installato: $($cmd.Source)"
        return $cmd.Source
    }
    $existing = Get-ChildItem -Path "$env:ProgramFiles\MariaDB*" -Directory -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($existing) {
        $exe = Get-ChildItem -Path $existing.FullName -Recurse -Filter mysql.exe -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($exe) {
            $env:Path = "$($exe.DirectoryName);$env:Path"
            return $exe.FullName
        }
    }
    Fail 'mysql.exe non trovato dopo l''installazione di MariaDB.'
}


Write-Host 'Socly - installazione one-click (Windows)'
$PhpExe = Ensure-Php
$Composer = Ensure-Composer $PhpExe
$Mysql = Ensure-MariaDb

$service = Get-Service -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -like 'MariaDB*' -or $_.DisplayName -like '*MariaDB*'
} | Select-Object -First 1
if ($service -and $service.Status -ne 'Running') {
    Start-Service -Name $service.Name
    Write-Ok "Servizio $($service.Name) avviato"
}

$rootArgs = @('-u', 'root')
if ($DbRootPass) { $rootArgs = @('-u', 'root', "-p$DbRootPass") }
$rootReady = $false
for ($i = 0; $i -lt 30; $i++) {
    & $Mysql @rootArgs -e 'SELECT 1' 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { $rootReady = $true; break }
    Start-Sleep -Seconds 1
}
if (-not $rootReady -and -not $DbRootPass) {
    $secure = Read-Host 'Password root MariaDB (Invio se vuota)' -AsSecureString
    $DbRootPass = [System.Net.NetworkCredential]::new('', $secure).Password
    $rootArgs = @('-u', 'root')
    if ($DbRootPass) { $rootArgs = @('-u', 'root', "-p$DbRootPass") }
    for ($i = 0; $i -lt 15; $i++) {
        & $Mysql @rootArgs -e 'SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $rootReady = $true; break }
        Start-Sleep -Seconds 1
    }
}
if (-not $rootReady) { Fail 'Accesso root a MariaDB non riuscito. Rilancia con -DbRootPass "..." oppure imposta la password root.' }
Write-Ok 'Accesso root MariaDB verificato'

if ([string]::IsNullOrWhiteSpace($DbPass)) { $DbPass = [guid]::NewGuid().ToString('N') }
if ($DbName -notmatch '^[A-Za-z0-9_]+$') { Fail 'Nome database non valido: usa lettere, numeri e _' }
if ($DbUser -notmatch '^[A-Za-z0-9_]+$') { Fail 'Nome utente database non valido: usa lettere, numeri e _' }
if ($DbPass -notmatch '^[A-Za-z0-9._-]+$') { Fail 'Password database non valida: usa lettere, numeri, punto, trattino, underscore' }

$sql = "CREATE DATABASE IF NOT EXISTS $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
    "CREATE USER IF NOT EXISTS '$DbUser'@'localhost' IDENTIFIED BY '$DbPass'; " +
    "CREATE USER IF NOT EXISTS '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPass'; " +
    "ALTER USER '$DbUser'@'localhost' IDENTIFIED BY '$DbPass'; " +
    "ALTER USER '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPass'; " +
    "GRANT ALL PRIVILEGES ON $DbName.* TO '$DbUser'@'localhost'; " +
    "GRANT ALL PRIVILEGES ON $DbName.* TO '$DbUser'@'127.0.0.1'; " +
    'FLUSH PRIVILEGES;'
& $Mysql @rootArgs -e $sql
if ($LASTEXITCODE -ne 0) {
    Fail "Creazione database non riuscita. Esegui manualmente:$([Environment]::NewLine)$sql"
}
Write-Ok "Database $DbName e utente $DbUser pronti"

$envFile = Join-Path $Root '.env'
if (-not (Test-Path $envFile)) {
    Copy-Item (Join-Path $Root '.env.example') $envFile -Force
}

Write-Ok 'Dipendenze PHP (Composer)'
Push-Location $Root
try {
    if ($Composer.Kind -eq 'exe') {
        & $Composer.Path install --no-dev --optimize-autoloader --no-interaction
    } else {
        & $PhpExe $Composer.Path install --no-dev --optimize-autoloader --no-interaction
    }
    if ($LASTEXITCODE -ne 0) {
        Write-Warn 'composer install non riuscito: provo composer update'
        if ($Composer.Kind -eq 'exe') {
            & $Composer.Path update --no-dev --optimize-autoloader --no-interaction
        } else {
            & $PhpExe $Composer.Path update --no-dev --optimize-autoloader --no-interaction
        }
    }
} finally {
    Pop-Location
}

Write-Ok 'Configurazione applicazione'
$env:SOCLY_DB_PASS = $DbPass
try {
    & $PhpExe (Join-Path $Root 'bin\install\bootstrap.php') `
        "--db-host=$DbHost" "--db-port=$DbPort" "--db-name=$DbName" "--db-user=$DbUser" `
        "--url=$AppUrl" "--locale=$Locale"
    $bootstrapCode = $LASTEXITCODE
} finally {
    Remove-Item Env:SOCLY_DB_PASS -ErrorAction SilentlyContinue
}
if ($bootstrapCode -ne 0) { Fail "Configurazione applicazione non riuscita (exit $bootstrapCode)." }

foreach ($dir in @('logs', 'cache', 'sessions', 'uploads', 'documents', 'runts')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $Root "storage\$dir") | Out-Null
}

if ($NoServe) {
    Write-Ok 'Installazione completata (server non avviato).'
    Write-Host "Apri $AppUrl/install per completare il wizard."
    exit 0
}

$log = Join-Path $Root 'storage\logs\install-server.log'
$errLog = Join-Path $Root 'storage\logs\install-server.err.log'
$proc = Start-Process -FilePath $PhpExe `
    -ArgumentList @('-S', "${Bind}:${Port}", '-t', 'public', 'public/router.php') `
    -WorkingDirectory $Root -RedirectStandardOutput $log -RedirectStandardError $errLog `
    -PassThru -WindowStyle Hidden
Set-Content -Path (Join-Path $Root 'storage\cache\install-server.pid') -Value $proc.Id

$localUrl = "http://${Bind}:${Port}/install"
$ready = $false
for ($i = 0; $i -lt 40; $i++) {
    try {
        $resp = Invoke-WebRequest -Uri $localUrl -UseBasicParsing -TimeoutSec 3
        if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 400) { $ready = $true; break }
    } catch {
    }
    Start-Sleep -Milliseconds 500
}
if (-not $ready) {
    Get-Content $log -Tail 30 -ErrorAction SilentlyContinue | Write-Host
    Fail "Il server locale non risponde su $localUrl"
}

if (-not $NoBrowser) { Start-Process $localUrl | Out-Null }

$pidFile = Join-Path $Root 'storage\cache\install-server.pid'
Write-Host ''
Write-Ok 'Socly e installato e il server locale e attivo.'
Write-Host "  Wizard:   $localUrl"
Write-Host "  Log:      $log"
Write-Host "  Stop:     Stop-Process -Id (Get-Content '$pidFile')"
Write-Host ''
Write-Host 'Completa il wizard (associazione, account Admin, periodo, quote, campi).'
Write-Host 'Per la produzione (IIS/Apache/Nginx, HTTPS) vedi docs\INSTALLATION.md.'
