#Requires -Version 5.1
<#
.SYNOPSIS
  Socly one-click installer for Windows.
.DESCRIPTION
  Installs PHP 8.3/8.4 (preferred) or 8.2 if needed, Composer, MariaDB,
  creates the database, writes .env, runs migrations and starts a local
  server. Complete the wizard at http://127.0.0.1:8080/install and then the
  first-run setup at /setup.

  Safety defaults:
    * MariaDB is installed with a generated root password and bound to
      127.0.0.1 (no empty-root access).
    * The database client always talks to -DbHost/-DbPort over TCP.
    * Composer and PHP downloads are checked against their SHA-256.
    * php.ini is based on php.ini-production with errors logged to storage/logs.
    * A machine-readable manifest is written to storage/cache/install-manifest.json
      so -Uninstall can remove what this script created.
.PARAMETER Plan
  Show what would be done without changing anything.
.PARAMETER Check
  Verify the environment and exit. Combine with -Json for machine-readable output.
.PARAMETER Uninstall
  Remove the PHP runtime/shim and local server created by this script.
  Add -PurgeDatabase to also drop the Socly database/user.
  Add -PurgeMariaDb to also uninstall MariaDB when this script installed it.
.PARAMETER WaitOnExit
  Internal: keep this window open at the end. The script adds it only when it
  re-launches itself elevated, so the final URLs and log paths stay readable.
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
    [switch]$Ocr,
    [switch]$NoOcr,
    [switch]$NoBrowser,
    [switch]$NoServe,
    [switch]$Check,
    [switch]$Json,
    [string]$ReportPath = '',
    [switch]$Plan,
    [switch]$Uninstall,
    [switch]$PurgeDatabase,
    [switch]$PurgeMariaDb,
    [switch]$WaitOnExit
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$ProgressPreference = 'SilentlyContinue'
$global:LASTEXITCODE = 0

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ManifestPath = Join-Path $Root 'storage\cache\install-manifest.json'
$RootPasswordFile = Join-Path $Root 'storage\cache\db-root.pass'
$LogDir = Join-Path $Root 'storage\logs'
$ServerLog = Join-Path $LogDir 'install-server.log'
$ServerErrLog = Join-Path $LogDir 'install-server.err.log'
$ServerPidFile = Join-Path $Root 'storage\cache\install-server.pid'
$InstallerLog = Join-Path $LogDir ('install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
$script:PortExplicit = $PSBoundParameters.ContainsKey('Port')
$script:Manifest = [ordered]@{}
$script:AppUrl = ''
$script:DbPass = ''
$script:DbRootPass = ''
$script:RootPassword = ''
$script:PhpPath = ''
$script:PhpInstalledByScript = $false
$script:MariaDbInstalledByScript = $false
$script:MariaDbWasAbsentBefore = $false
$script:MariaDbService = ''

function Write-Ok([string]$Message) { Write-Host "[OK] $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { Write-Host "[!] $Message" -ForegroundColor Yellow }
function Fail([string]$Message, [int]$Code = 1) {
    Write-Host "[X] $Message" -ForegroundColor Red
    exit $Code
}
function Has-Command([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}
function Invoke-WebRequestCompat {
    param(
        [Parameter(Mandatory = $true)][string]$Uri,
        [string]$OutFile = '',
        [int]$TimeoutSec = 30,
        [switch]$AsString
    )
    $params = @{ Uri = $Uri; TimeoutSec = $TimeoutSec; ErrorAction = 'Stop' }
    if ($PSVersionTable.PSVersion.Major -lt 7) { $params['UseBasicParsing'] = $true }
    if ($OutFile -ne '') { $params['OutFile'] = $OutFile }
    $resp = Invoke-WebRequest @params
    if ($AsString) { return [string]$resp.Content }
    return $resp
}
function Test-TcpPort([string]$TargetHost, [int]$TargetPort, [int]$TimeoutMs = 800) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect($TargetHost, $TargetPort, $null, $null)
        if (-not $iar.AsyncWaitHandle.WaitOne($TimeoutMs, $false)) { return $false }
        $client.EndConnect($iar)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}
function Get-PortOwner([int]$TargetPort) {
    try {
        $conn = Get-NetTCPConnection -State Listen -LocalPort $TargetPort -ErrorAction Stop | Select-Object -First 1
        if ($conn) {
            $proc = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
            if ($proc) { return "$($proc.ProcessName) (PID $($proc.Id))" }
            return "PID $($conn.OwningProcess)"
        }
    } catch {
    }
    return 'processo sconosciuto'
}
function New-RandomPassword([int]$Length = 24) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#+-_'
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $sb = New-Object System.Text.StringBuilder
    foreach ($b in $bytes) { [void]$sb.Append($chars[$b % $chars.Length]) }
    return $sb.ToString()
}
function Escape-SqlString([string]$Value) {
    return $Value.Replace('\', '\\').Replace("'", "''")
}
function Get-EnvValue([string]$Path, [string]$Key) {
    if (-not (Test-Path $Path)) { return '' }
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*=\s*(.*)$'
    foreach ($line in Get-Content -Path $Path) {
        if ($line -match $pattern) {
            $value = $Matches[1].Trim()
            if ($value.Length -ge 2) {
                $first = $value.Substring(0, 1)
                $last = $value.Substring($value.Length - 1, 1)
                if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) {
                    $value = $value.Substring(1, $value.Length - 2)
                    if ($first -eq '"') {
                        $value = $value.Replace('\$', '$').Replace('\"', '"').Replace('\\', '\')
                    }
                }
            }
            return $value
        }
    }
    return ''
}
function Protect-PathAcl([string]$Path) {
    if (-not (Test-Path $Path)) { return }
    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    try {
        & icacls $Path /inheritance:r /grant:r "${identity}:(R,W)" '*S-1-5-18:(F)' '*S-1-5-32-544:(F)' 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warn "Impossibile applicare le ACL a $Path" }
    } catch {
        Write-Warn "Impossibile applicare le ACL a $Path : $($_.Exception.Message)"
    }
}
function Resolve-AppPort {
    if (-not (Test-TcpPort '127.0.0.1' $Port 500)) { return $Port }
    if ($script:PortExplicit) {
        Fail "La porta $Port e' occupata da $(Get-PortOwner $Port). Scegli un'altra porta con -Port."
    }
    for ($candidate = $Port + 1; $candidate -le $Port + 25; $candidate++) {
        if (-not (Test-TcpPort '127.0.0.1' $candidate 300)) {
            Write-Warn "La porta $Port e' occupata da $(Get-PortOwner $Port): uso $candidate."
            return $candidate
        }
    }
    Fail "Nessuna porta libera tra $Port e $($Port + 25)."
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
function Invoke-Mysql {
    param(
        [Parameter(Mandatory = $true)][string]$MysqlExe,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [string]$Sql = ''
    )
    $previous = $env:MYSQL_PWD
    try {
        if ($script:RootPassword -ne '') { $env:MYSQL_PWD = $script:RootPassword }
        else { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
        if ($Sql -ne '') {
            $output = & $MysqlExe @Arguments -e $Sql 2>&1
        } else {
            $output = & $MysqlExe @Arguments 2>&1
        }
        $code = $LASTEXITCODE
        $output | ForEach-Object { Write-Host $_ }
        return $code
    } catch {
        Write-Host $_.Exception.Message
        return 1
    } finally {
        if ($null -eq $previous) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
        else { $env:MYSQL_PWD = $previous }
    }
}
function Save-Manifest {
    $dir = Split-Path $ManifestPath
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $script:Manifest['updated_at'] = (Get-Date).ToString('o')
    $script:Manifest['version'] = 1
    $script:Manifest['root'] = $Root
    $script:Manifest['app_url'] = $script:AppUrl
    $script:Manifest['app_port'] = $Port
    $script:Manifest['db'] = [ordered]@{
        host = $DbHost; port = $DbPort; name = $DbName; user = $DbUser
    }
    $script:Manifest['php'] = [ordered]@{
        path = $script:PhpPath; installed_by_script = $script:PhpInstalledByScript
    }
    $script:Manifest['mariadb'] = [ordered]@{
        installed_by_script = $script:MariaDbInstalledByScript; service = $script:MariaDbService
    }
    $script:Manifest | ConvertTo-Json -Depth 6 | Set-Content -Path $ManifestPath -Encoding UTF8
}
function Get-ManifestValue([string]$Key, [string]$Default = '') {
    if (-not (Test-Path $ManifestPath)) { return $Default }
    try {
        $manifest = Get-Content -Path $ManifestPath -Raw | ConvertFrom-Json
        $value = $manifest.$Key
        if ($null -eq $value) { return $Default }
        return [string]$value
    } catch {
        return $Default
    }
}
function Test-SoclyResponse([string]$TargetUrl) {
    try {
        $resp = Invoke-WebRequestCompat -Uri $TargetUrl -TimeoutSec 5
        $marker = $resp.Headers['X-Socly-Installer']
        if ($marker -is [array]) { $marker = $marker[0] }
        $hasMarker = ([string]$marker).Trim() -eq '1'
        $hasBody = ([string]$resp.Content).Length -gt 0
        return ($hasMarker -and $hasBody)
    } catch {
        return $false
    }
}
function Get-CheckReport {
    $admin = $false
    try {
        $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
        $admin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch {
    }

    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    $phpVersion = ''
    $phpPath = ''
    $phpMissing = ''
    if ($phpCmd) {
        $phpPath = $phpCmd.Source
        try { $phpVersion = (& $phpCmd.Source -r 'echo PHP_VERSION;' 2>$null) } catch { $phpVersion = '' }
        $phpMissing = Get-MissingExtensions $phpCmd.Source
    }

    $composerVersion = ''
    if (Has-Command composer) {
        try { $composerVersion = (& composer --version 2>$null | Select-Object -First 1) } catch { $composerVersion = '' }
    }

    $mysqlPath = ''
    $mysqlCmd = Get-Command mysql -ErrorAction SilentlyContinue
    if ($mysqlCmd) { $mysqlPath = $mysqlCmd.Source }
    $mariaDirs = @(Get-ChildItem -Path "$env:ProgramFiles\MariaDB*" -Directory -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName })
    $mysqlDirs = @(Get-ChildItem -Path "$env:ProgramFiles\MySQL*" -Directory -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName })
    $dbServices = @(Get-Service -Name 'MariaDB*', 'MySQL*' -ErrorAction SilentlyContinue | ForEach-Object { "$($_.Name)=$($_.Status)" })

    $driveRoot = [System.IO.Path]::GetPathRoot($Root)
    $diskFreeGb = $null
    try {
        $drive = Get-PSDrive -Name $driveRoot.Substring(0, 1) -ErrorAction Stop
        if ($null -ne $drive.Free) { $diskFreeGb = [math]::Round($drive.Free / 1GB, 1) }
    } catch {
    }

    $storageWritable = $false
    try {
        $probe = Join-Path $Root 'storage\.writable-probe'
        Set-Content -Path $probe -Value 'ok' -Encoding ASCII -ErrorAction Stop
        Remove-Item $probe -Force -ErrorAction SilentlyContinue
        $storageWritable = $true
    } catch {
    }

    $firewall = ''
    try {
        $firewall = (@(Get-NetFirewallProfile -ErrorAction Stop | ForEach-Object { "$($_.Name)=$($_.Enabled)" }) -join ';')
    } catch {
        $firewall = 'non disponibile'
    }
    $defender = ''
    try {
        $mp = Get-MpComputerStatus -ErrorAction Stop
        $defender = "antivirus=$($mp.AntivirusEnabled); realtime=$($mp.RealTimeProtectionEnabled)"
    } catch {
        $defender = 'non disponibile'
    }

    return [ordered]@{
        generated_at = (Get-Date).ToString('o')
        os = [System.Environment]::OSVersion.VersionString
        powershell = $PSVersionTable.PSVersion.ToString()
        administrator = $admin
        elevation_required = $true
        root = $Root
        disk_free_gb = $diskFreeGb
        ports = [ordered]@{
            app = [ordered]@{ port = $Port; free = (-not (Test-TcpPort '127.0.0.1' $Port 400)); owner = (Get-PortOwner $Port) }
            database = [ordered]@{ host = $DbHost; port = $DbPort; reachable = (Test-TcpPort $DbHost $DbPort 600); owner = (Get-PortOwner $DbPort) }
        }
        network = [ordered]@{
            windows_php_net = (Test-TcpPort 'windows.php.net' 443 1500)
            getcomposer_org = (Test-TcpPort 'getcomposer.org' 443 1500)
            mariadb_org = (Test-TcpPort 'downloads.mariadb.org' 443 1500)
        }
        package_managers = [ordered]@{ winget = (Has-Command winget); choco = (Has-Command choco) }
        vcredist = (Test-Path (Join-Path $env:WINDIR 'System32\vcruntime140.dll'))
        php = [ordered]@{ present = [bool]$phpCmd; path = $phpPath; version = $phpVersion; missing_extensions = $phpMissing }
        composer = [ordered]@{ present = (Has-Command composer); version = $composerVersion }
        mariadb = [ordered]@{
            client = $mysqlPath
            install_dirs = $mariaDirs
            mysql_dirs = $mysqlDirs
            services = $dbServices
        }
        project = [ordered]@{
            env_example = (Test-Path (Join-Path $Root '.env.example'))
            env = (Test-Path (Join-Path $Root '.env'))
            composer_json = (Test-Path (Join-Path $Root 'composer.json'))
            vendor = (Test-Path (Join-Path $Root 'vendor'))
            storage_writable = $storageWritable
        }
        security = [ordered]@{ firewall = $firewall; defender = $defender }
    }
}

function Show-CheckReport($Report) {
    Write-Host 'Socly - verifica ambiente Windows'
    Write-Host "  Sistema:        $($Report.os) (PowerShell $($Report.powershell))"
    if ($Report.administrator) { Write-Host '  Privilegi:      amministratore' }
    else { Write-Host '  Privilegi:      utente standard (serve UAC per PHP in Program Files, servizio MariaDB e ACL)' }
    Write-Host "  Spazio disco:   $($Report.disk_free_gb) GB liberi (consigliati >= 2 GB)"
    if ($null -ne $Report.disk_free_gb -and [double]$Report.disk_free_gb -lt 2) {
        Write-Host '  [!] Spazio disco insufficiente: libera almeno 2 GB prima di installare.' -ForegroundColor Yellow
    }
    if ($Report.ports.app.free) { Write-Host "  Porta app:      $($Report.ports.app.port) libera" }
    else { Write-Host "  Porta app:      $($Report.ports.app.port) OCCUPATA da $($Report.ports.app.owner)" -ForegroundColor Yellow }
    if ($Report.ports.database.reachable) { Write-Host "  Porta DB:       $($Report.ports.database.host):$($Report.ports.database.port) in ascolto" }
    else { Write-Host "  Porta DB:       $($Report.ports.database.host):$($Report.ports.database.port) libera" }
    Write-Host "  Rete:           php=$($Report.network.windows_php_net) composer=$($Report.network.getcomposer_org) mariadb=$($Report.network.mariadb_org)"
    Write-Host "  winget/choco:   $($Report.package_managers.winget) / $($Report.package_managers.choco)"
    Write-Host "  VC++ runtime:   $($Report.vcredist)"
    if ($Report.php.present) {
        Write-Host "  PHP:            $($Report.php.version) ($($Report.php.path))"
        if ([string]::IsNullOrWhiteSpace($Report.php.missing_extensions)) { Write-Host '  Estensioni:     pdo_mysql, mbstring, openssl, json, fileinfo OK' }
        else { Write-Host "  Estensioni:     MANCANTI ($($Report.php.missing_extensions)) - verra installato PHP dedicato" -ForegroundColor Yellow }
    } else {
        Write-Host '  PHP:            assente (verra installato)'
    }
    if ($Report.composer.present) { Write-Host "  Composer:       $($Report.composer.version)" }
    else { Write-Host '  Composer:       assente (verra scaricato composer.phar verificato)' }
    if ($Report.mariadb.client -ne '') { Write-Host "  MariaDB client: $($Report.mariadb.client)" }
    else { Write-Host '  MariaDB client: assente (verra installato se possibile)' }
    if ($Report.mariadb.services.Count -gt 0) { Write-Host "  Servizi DB:     $($Report.mariadb.services -join ', ')" }
    Write-Host "  Progetto:       $($Report.root)"
    Write-Host "  File progetto:  .env.example=$($Report.project.env_example) .env=$($Report.project.env) vendor=$($Report.project.vendor) storage-scrivibile=$($Report.project.storage_writable)"
    Write-Host "  Firewall:       $($Report.security.firewall)"
    Write-Host "  Defender:       $($Report.security.defender)"
}

function Show-Plan {
    Write-Host 'Socly - piano di installazione (nessuna modifica)'
    Write-Host "  Progetto:       $Root"
    $portState = 'libera'
    if (Test-TcpPort '127.0.0.1' $Port 300) {
        $owner = Get-PortOwner $Port
        if ($script:PortExplicit) {
            $portState = "occupata da $owner (con -Port esplicito l'installazione si ferma)"
        } else {
            $planPort = $Port
            for ($candidate = $Port + 1; $candidate -le $Port + 25; $candidate++) {
                if (-not (Test-TcpPort '127.0.0.1' $candidate 200)) { $planPort = $candidate; break }
            }
            $portState = "occupata da $owner -> usera $planPort"
        }
    }
    Write-Host "  Porta app:      $Port (bind $Bind) - $portState"
    Write-Host "  Database:       $DbName su $DbHost`:$DbPort (utente $DbUser)"
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCmd -and (Test-PhpVersion $phpCmd.Source) -and [string]::IsNullOrWhiteSpace((Get-MissingExtensions $phpCmd.Source))) {
        Write-Host "  PHP:            riuso $($phpCmd.Source) (nessuna installazione)"
    } else {
        Write-Host '  PHP:            installazione dedicata in %ProgramFiles%\Socly\php (8.4/8.3/8.2)'
    }
    if (Has-Command composer) { Write-Host '  Composer:       riuso eseguibile di sistema' }
    else { Write-Host '  Composer:       download composer.phar in storage\cache + verifica SHA-256' }
    if (Has-Command mysql) { Write-Host '  MariaDB:        riuso client esistente' }
    else { Write-Host '  MariaDB:        installazione via winget/choco con root password generata e ascolto su 127.0.0.1' }
    Write-Host '  .env:           creato/aggiornato; ACL ristrette su Windows'
    Write-Host '  Composer:       install --no-dev --optimize-autoloader --no-progress --prefer-dist'
    Write-Host '  Migrazioni:     php bin/install/bootstrap.php'
    if ($NoServe) { Write-Host '  Server locale:  non avviato (-NoServe)' }
    else { Write-Host "  Server locale:  php -S $Bind`:$Port -t public public/router.php" }
    Write-Host '  Manifest:       storage\cache\install-manifest.json (per -Uninstall)'
}
function Invoke-RestMethodCompat([string]$Uri) {
    $params = @{ Uri = $Uri; TimeoutSec = 30; ErrorAction = 'Stop' }
    if ($PSVersionTable.PSVersion.Major -lt 7) { $params['UseBasicParsing'] = $true }
    return Invoke-RestMethod @params
}

function Ensure-StorageDirectories {
    foreach ($dir in @('logs', 'cache', 'sessions', 'uploads', 'documents', 'runts')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $Root "storage\$dir") | Out-Null
    }
}

function Ensure-Php {
    $phpDir = Join-Path $env:ProgramFiles 'Socly\php'
    $localPhp = Join-Path $phpDir 'php.exe'
    if (Test-Path $localPhp) {
        $env:Path = "$phpDir;$env:Path"
        if ((Test-PhpVersion $localPhp) -and [string]::IsNullOrWhiteSpace((Get-MissingExtensions $localPhp))) {
            $version = & $localPhp -r 'echo PHP_VERSION;' 2>$null
            Write-Ok "PHP dedicato gia installato: $version ($localPhp)"
            $script:PhpPath = $localPhp
            $script:PhpInstalledByScript = $true
            return $localPhp
        }
    }

    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd -and (Test-PhpVersion $cmd.Source)) {
        $missing = Get-MissingExtensions $cmd.Source
        if ([string]::IsNullOrWhiteSpace($missing)) {
            $version = & $cmd.Source -r 'echo PHP_VERSION;' 2>$null
            Write-Ok "PHP gia disponibile: $version"
            Write-Warn 'PHP esistente riutilizzato: il suo php.ini non viene modificato; verifica display_errors = Off e log_errors = On.'
            $script:PhpPath = $cmd.Source
            $script:PhpInstalledByScript = $false
            return $cmd.Source
        }
        Write-Warn "PHP presente ma estensioni mancanti ($missing): installo PHP dedicato."
    }

    New-Item -ItemType Directory -Force -Path $phpDir | Out-Null
    $zip = Join-Path $env:TEMP 'socly-php.zip'
    Write-Host 'Download PHP per Windows...'
    $releases = Invoke-RestMethodCompat 'https://windows.php.net/downloads/releases/releases.json'
    $branch = ''
    $zipPath = ''
    $sha256 = ''
    foreach ($candidate in @('8.4', '8.3', '8.2')) {
        if ($releases.PSObject.Properties.Name -notcontains $candidate) { continue }
        $entry = $releases.$candidate
        foreach ($key in @('nts-vs17-x64', 'nts-vs16-x64')) {
            if ($entry.PSObject.Properties.Name -contains $key) {
                $zipPath = [string]$entry.$key.zip.path
                $sha256 = [string]$entry.$key.zip.sha256
                $branch = $candidate
                break
            }
        }
        if ($zipPath -ne '') { break }
    }
    if ($zipPath -eq '') { Fail 'Nessuna build PHP 8.2/8.3/8.4 NTS x64 trovata.' }
    if ([string]::IsNullOrWhiteSpace($sha256)) { Fail "La release PHP $branch non espone un checksum SHA-256." }
    Invoke-WebRequestCompat ("https://windows.php.net/downloads/releases/" + $zipPath) $zip -TimeoutSec 900
    $actual = (Get-FileHash -Path $zip -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $sha256.ToLowerInvariant()) {
        Remove-Item $zip -Force -ErrorAction SilentlyContinue
        Fail 'Checksum PHP non valido (download corrotto o manomesso).'
    }

    $extracted = $false
    $tar = Join-Path $env:WINDIR 'System32\tar.exe'
    if (Test-Path $tar) {
        & $tar -xf $zip -C $phpDir
        if ($LASTEXITCODE -eq 0) { $extracted = $true }
    }
    if (-not $extracted) {
        Expand-Archive -Path $zip -DestinationPath $phpDir -Force
    }
    Remove-Item $zip -Force -ErrorAction SilentlyContinue

    $ini = Join-Path $phpDir 'php.ini'
    $template = Join-Path $phpDir 'php.ini-production'
    if (-not (Test-Path $template)) { Fail 'php.ini-production non trovato nella build PHP.' }
    Copy-Item $template $ini -Force
    $lines = Get-Content $ini
    $lines = $lines -replace '^;?\s*extension_dir\s*=.*$', 'extension_dir = "ext"'
    foreach ($ext in @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'zip')) {
        $lines = $lines -replace "^;?\s*extension\s*=\s*$ext\s*$", "extension=$ext"
    }
    $joined = $lines -join "`n"
    foreach ($ext in @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'zip')) {
        if ($joined -notmatch "(?m)^\s*extension\s*=\s*$ext\s*$") {
            $lines += "extension=$ext"
        }
    }
    $phpErrorLog = (Join-Path $Root 'storage\logs\php-error.log').Replace('\', '/')
    $sessionDir = (Join-Path $Root 'storage\sessions').Replace('\', '/')
    $lines = $lines -replace '^expose_php\s*=.*$', 'expose_php = Off'
    $lines = $lines -replace '^display_errors\s*=.*$', 'display_errors = Off'
    $lines = $lines -replace '^log_errors\s*=.*$', 'log_errors = On'
    $lines = $lines -replace '^;\s*error_log\s*=.*$', ''
    $lines += 'expose_php = Off'
    $lines += 'display_errors = Off'
    $lines += 'log_errors = On'
    $lines += "error_log = `"$phpErrorLog`""
    $lines += "session.save_path = `"$sessionDir`""
    $lines += 'opcache.enable = 1'
    $lines += 'opcache.enable_cli = 0'
    $lines += 'opcache.validate_timestamps = 0'
    $lines += 'opcache.memory_consumption = 128'
    $lines += 'opcache.max_accelerated_files = 20000'
    $lines += 'upload_max_filesize = 25M'
    $lines += 'post_max_size = 26M'
    $lines += 'memory_limit = 256M'
    Set-Content -Path $ini -Value $lines -Encoding ASCII

    $phpExe = Join-Path $phpDir 'php.exe'
    $env:Path = "$phpDir;$env:Path"
    $missing = Get-MissingExtensions $phpExe
    if (-not [string]::IsNullOrWhiteSpace($missing)) {
        Fail "PHP installato ma estensioni ancora mancanti: $missing"
    }

    $shimDir = Join-Path $env:ProgramData 'Socly\bin'
    New-Item -ItemType Directory -Force -Path $shimDir | Out-Null
    Set-Content -Path (Join-Path $shimDir 'php.cmd') -Value "@`"$phpExe`" %*" -Encoding ASCII

    $script:PhpPath = $phpExe
    $script:PhpInstalledByScript = $true
    Write-Ok "PHP $branch installato in $phpDir (php.ini production, opcache, log in storage\logs)"
    Write-Host "  php non e' stato aggiunto al PATH di sistema: usa $shimDir\php.cmd"
    return $phpExe
}

function Ensure-Composer([string]$PhpExe) {
    $cmd = Get-Command composer -ErrorAction SilentlyContinue
    if ($cmd) {
        Write-Ok "Composer gia disponibile: $($cmd.Source)"
        return @{ Kind = 'exe'; Path = $cmd.Source }
    }
    $phar = Join-Path $Root 'storage\cache\composer.phar'
    New-Item -ItemType Directory -Force -Path (Split-Path $phar) | Out-Null
    Write-Host 'Download Composer (composer.phar) con verifica SHA-256...'
    $base = 'https://getcomposer.org/download/latest-stable'
    Invoke-WebRequestCompat "$base/composer.phar" $phar -TimeoutSec 300
    $shaText = Invoke-WebRequestCompat "$base/composer.phar.sha256sum" -AsString -TimeoutSec 60
    $expected = (($shaText -split '\s+') | Where-Object { $_ -ne '' } | Select-Object -First 1)
    if ([string]::IsNullOrWhiteSpace($expected)) {
        Remove-Item $phar -Force -ErrorAction SilentlyContinue
        Fail 'Checksum SHA-256 di Composer non disponibile.'
    }
    $actual = (Get-FileHash -Path $phar -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $expected.Trim().ToLowerInvariant()) {
        Remove-Item $phar -Force -ErrorAction SilentlyContinue
        Fail 'Checksum Composer non valido (download corrotto o manomesso).'
    }
    Write-Ok 'Composer scaricato e verificato'
    return @{ Kind = 'phar'; Path = $phar }
}
function Find-MysqlClient {
    $cmd = Get-Command mysql -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($base in @("$env:ProgramFiles\MariaDB*", "$env:ProgramFiles\MySQL*")) {
        $dir = Get-ChildItem -Path $base -Directory -ErrorAction SilentlyContinue | Select-Object -First 1
        if (-not $dir) { continue }
        $exe = Get-ChildItem -Path $dir.FullName -Recurse -Filter mysql.exe -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($exe) { return $exe.FullName }
    }
    return ''
}

function Ensure-MariaDb {
    $client = Find-MysqlClient
    if ($client -ne '') {
        $env:Path = "$(Split-Path $client);$env:Path"
        Write-Ok "Client MariaDB/MySQL gia disponibile: $client"
        return $client
    }

    if ($DbHost -notin @('127.0.0.1', 'localhost', '::1')) {
        Fail "Client mysql assente e -DbHost=$DbHost non e' locale: installa il client MariaDB/MySQL e riprova."
    }
    $script:MariaDbWasAbsentBefore = $true
    if (Test-TcpPort '127.0.0.1' $DbPort 500) {
        Fail "La porta $DbPort e' gia' in ascolto ($(Get-PortOwner $DbPort)) ma mysql.exe non e' disponibile. Installa il client o libera la porta."
    }

    if ($script:DbRootPass -eq '' -and (Test-Path $RootPasswordFile)) {
        $savedRoot = (Get-Content -Path $RootPasswordFile -Raw).Trim()
        if ($savedRoot -ne '') { $script:DbRootPass = $savedRoot }
    }
    if ($script:DbRootPass -eq '') {
        $script:DbRootPass = New-RandomPassword 24
        New-Item -ItemType Directory -Force -Path (Split-Path $RootPasswordFile) | Out-Null
        Set-Content -Path $RootPasswordFile -Value $script:DbRootPass -Encoding ASCII
        Protect-PathAcl $RootPasswordFile
        Write-Ok "Password root MariaDB generata e salvata in $RootPasswordFile (ACL ristrette)."
    }
    if ($script:DbRootPass -match '["\x00-\x1F]') {
        Fail 'La password root non puo'' contenere virgolette o caratteri di controllo.'
    }

    if (Has-Command winget) {
        Write-Host 'Installazione MariaDB con winget (root password, servizio dedicato)...'
        # Do NOT use SKIPNETWORKING=1: PDO mysql connects over TCP to 127.0.0.1.
        $override = "/qn SERVICENAME=MariaDBSocly PORT=$DbPort SKIPNETWORKING=0 PASSWORD=`"$($script:DbRootPass)`""
        & winget install --id MariaDB.Server -e --silent --accept-package-agreements --accept-source-agreements --override $override
        if ($LASTEXITCODE -ne 0) {
            Write-Warn "winget ha restituito ${LASTEXITCODE}: verifico comunque l'installazione."
        } else {
            $script:MariaDbInstalledByScript = $true
        }
    } elseif (Has-Command choco) {
        Write-Host 'Installazione MariaDB con Chocolatey...'
        & choco install mariadb -y --params "/Password:`"$($script:DbRootPass)`" /Port:$DbPort /ServiceName:MariaDBSocly"
        if ($LASTEXITCODE -ne 0) {
            Write-Warn "choco ha restituito ${LASTEXITCODE}: verifico comunque l'installazione."
        } else {
            $script:MariaDbInstalledByScript = $true
        }
    } else {
        Fail 'MariaDB non trovato e nessun package manager disponibile. Installa MariaDB Server da https://mariadb.org/download/ e riprova.'
    }

    $deadline = (Get-Date).AddSeconds(90)
    do {
        $client = Find-MysqlClient
        $service = Get-Service -Name 'MariaDB*', 'MySQL*' -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -eq 'MariaDBSocly' } | Select-Object -First 1
        if (-not $service) {
            $service = Get-Service -Name 'MariaDB*', 'MySQL*' -ErrorAction SilentlyContinue | Select-Object -First 1
        }
        if ($client -ne '' -and $service) { break }
        Start-Sleep -Seconds 2
    } while ((Get-Date) -lt $deadline)
    if ($client -eq '') { Fail 'mysql.exe non trovato dopo 90 secondi: controlla il log di winget/choco.' }
    if (-not $service -and ($script:MariaDbInstalledByScript -or $script:MariaDbWasAbsentBefore)) {
        Fail 'Il servizio MariaDB non risulta registrato dopo 90 secondi: controlla il log di winget/choco.'
    }

    $env:Path = "$(Split-Path $client);$env:Path"
    Write-Ok "Client MariaDB installato: $client"
    return $client
}

function Start-MariaDbService {
    $service = Get-Service -Name 'MariaDB*', 'MySQL*' -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -eq 'MariaDBSocly' } | Select-Object -First 1
    if (-not $service) {
        $service = Get-Service -Name 'MariaDB*', 'MySQL*' -ErrorAction SilentlyContinue | Select-Object -First 1
    }
    if (-not $service) {
        Write-Warn 'Nessun servizio MariaDB/MySQL trovato: presuppongo un server gia avviato.'
        return
    }
    $script:MariaDbService = $service.Name
    if ($service.Status -eq 'Running') {
        Write-Ok "Servizio $($service.Name) gia attivo"
        return
    }
    try {
        Start-Service -Name $service.Name -ErrorAction Stop
        $service.WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
        Write-Ok "Servizio $($service.Name) avviato"
    } catch {
        Fail "Impossibile avviare il servizio $($service.Name): $($_.Exception.Message)"
    }
}

function Set-MariaDbLocalBinding {
    $candidates = @()
    foreach ($base in @("$env:ProgramData\MariaDB*", "$env:ProgramFiles\MariaDB*", "$env:ProgramData\MySQL*", "$env:ProgramFiles\MySQL*")) {
        $dirs = Get-ChildItem -Path $base -Directory -ErrorAction SilentlyContinue
        foreach ($dir in $dirs) {
            $candidates += @(Get-ChildItem -Path $dir.FullName -Recurse -Include 'my.ini', 'my.cnf' -File -ErrorAction SilentlyContinue)
        }
    }
    $ini = $candidates | Select-Object -First 1
    if (-not $ini) {
        Write-Warn 'my.ini non trovato: verifica che MariaDB ascolti solo su 127.0.0.1 (bind-address/firewall).'
        return
    }
    try {
        $content = Get-Content -Path $ini.FullName -Raw
        if ($content -match '(?im)^\s*bind-address\s*=\s*127\.0\.0\.1\s*$') {
            Write-Ok 'MariaDB gia limitato a 127.0.0.1'
            return
        }
        if ($content -match '(?im)^\s*bind-address\s*=') {
            $content = [regex]::Replace($content, '(?im)^\s*bind-address\s*=.*$', 'bind-address=127.0.0.1')
        } elseif ($content -match '(?im)^\[mysqld\]') {
            $rx = [regex]::new('(?im)^\[mysqld\]')
            $content = $rx.Replace($content, "[mysqld]`r`nbind-address=127.0.0.1", 1)
        } else {
            $content += "`r`n[mysqld]`r`nbind-address=127.0.0.1`r`n"
        }
        # A previous install may have disabled TCP; PDO needs it.
        $content = [regex]::Replace($content, '(?im)^\s*skip[-_]networking\s*=?.*$', '')
        Copy-Item $ini.FullName ($ini.FullName + '.socly.bak') -Force
        Set-Content -Path $ini.FullName -Value $content -Encoding ASCII
        if ($script:MariaDbService -ne '') {
            Restart-Service -Name $script:MariaDbService -Force -ErrorAction Stop
            $svc = Get-Service -Name $script:MariaDbService
            $svc.WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
        }
        Write-Ok 'MariaDB limitato a 127.0.0.1 (backup: *.socly.bak)'
    } catch {
        Write-Warn "Impossibile limitare MariaDB a 127.0.0.1: $($_.Exception.Message)"
    }
}

function Resolve-RootPassword {
    if ($script:DbRootPass -ne '') { return }
    if (Test-Path $RootPasswordFile) {
        $saved = (Get-Content -Path $RootPasswordFile -Raw).Trim()
        if ($saved -ne '') { $script:DbRootPass = $saved; return }
    }
    $secure = Read-Host 'Password root MariaDB (serve per creare database e utente)' -AsSecureString
    $plain = [System.Net.NetworkCredential]::new('', $secure).Password
    if ($plain -eq '') {
        Fail 'Password root vuota non accettata. Imposta una password root MariaDB e rilancia con -DbRootPass "...".'
    }
    $script:DbRootPass = $plain
}

function Test-RootAccess([string]$MysqlExe) {
    $baseArgs = @('-u', 'root', '-h', $DbHost, '-P', "$DbPort", '--protocol=TCP', '--connect-timeout=5')
    $script:RootPassword = $script:DbRootPass
    $code = Invoke-Mysql -MysqlExe $MysqlExe -Arguments $baseArgs -Sql 'SELECT 1;'
    if ($code -eq 0) {
        if ($script:DbRootPass -eq '') {
            Fail 'MariaDB accetta root senza password: impostane una e rilancia con -DbRootPass "...".'
        }
        Write-Ok 'Accesso root MariaDB verificato'
        return
    }

    if ($script:DbRootPass -eq '') {
        Resolve-RootPassword
        $script:RootPassword = $script:DbRootPass
        $code = Invoke-Mysql -MysqlExe $MysqlExe -Arguments $baseArgs -Sql 'SELECT 1;'
        if ($code -eq 0) { Write-Ok 'Accesso root MariaDB verificato'; return }
        Fail 'Accesso root a MariaDB non riuscito. Rilancia con -DbRootPass "...".'
    }

    if ($script:MariaDbInstalledByScript -or $script:MariaDbWasAbsentBefore) {
        Write-Warn 'MariaDB non accetta ancora la password root generata: provo a impostarla.'
        $script:RootPassword = ''
        $emptyCode = Invoke-Mysql -MysqlExe $MysqlExe -Arguments $baseArgs -Sql 'SELECT 1;'
        if ($emptyCode -eq 0) {
            $escaped = Escape-SqlString $script:DbRootPass
            $sql = "CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '$escaped'; " +
                "ALTER USER 'root'@'localhost' IDENTIFIED BY '$escaped'; " +
                "ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '$escaped'; FLUSH PRIVILEGES;"
            $setCode = Invoke-Mysql -MysqlExe $MysqlExe -Arguments $baseArgs -Sql $sql
            $script:RootPassword = $script:DbRootPass
            if ($setCode -eq 0) {
                $verifyCode = Invoke-Mysql -MysqlExe $MysqlExe -Arguments $baseArgs -Sql 'SELECT 1;'
                if ($verifyCode -eq 0) {
                    Write-Ok 'Password root MariaDB impostata'
                    return
                }
            }
        }
        $script:RootPassword = $script:DbRootPass
    }
    Fail 'Accesso root a MariaDB non riuscito. Rilancia con -DbRootPass "...".'
}

function Create-Database([string]$MysqlExe) {
    if ($DbName -notmatch '^[A-Za-z0-9_]+$') { Fail 'Nome database non valido: usa lettere, numeri e _' }
    if ($DbUser -notmatch '^[A-Za-z0-9_]+$') { Fail 'Nome utente database non valido: usa lettere, numeri e _' }
    if ($script:DbPass -eq '') { $script:DbPass = Get-EnvValue (Join-Path $Root '.env') 'DB_PASSWORD' }
    if ($script:DbPass -eq '') { $script:DbPass = New-RandomPassword 24 }
    if ($script:DbPass.Length -lt 8 -or $script:DbPass -match '[\x00-\x1F\x7F]') {
        Fail 'Password database non valida: usa almeno 8 caratteri senza caratteri di controllo.'
    }

    $user = Escape-SqlString $DbUser
    $pass = Escape-SqlString $script:DbPass
    $sql = 'CREATE DATABASE IF NOT EXISTS `' + $DbName + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; ' +
        "CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$pass'; " +
        "CREATE USER IF NOT EXISTS '$user'@'127.0.0.1' IDENTIFIED BY '$pass'; " +
        "ALTER USER '$user'@'localhost' IDENTIFIED BY '$pass'; " +
        "ALTER USER '$user'@'127.0.0.1' IDENTIFIED BY '$pass'; " +
        'GRANT ALL PRIVILEGES ON `' + $DbName + '`.* TO ' + "'$user'@'localhost'; " +
        'GRANT ALL PRIVILEGES ON `' + $DbName + '`.* TO ' + "'$user'@'127.0.0.1'; FLUSH PRIVILEGES;"

    $args = @('-u', 'root', '-h', $DbHost, '-P', "$DbPort", '--protocol=TCP', '--connect-timeout=5')
    $script:RootPassword = $script:DbRootPass
    $code = Invoke-Mysql -MysqlExe $MysqlExe -Arguments $args -Sql $sql
    if ($code -ne 0) {
        $redacted = $sql.Replace($pass, '***').Replace($script:DbPass, '***')
        Fail "Creazione database non riuscita. SQL (password oscurata):$([Environment]::NewLine)$redacted"
    }
    Write-Ok "Database $DbName e utente $DbUser pronti"
}
function Install-PhpDependencies([string]$PhpExe, $Composer) {
    Push-Location $Root
    try {
        Write-Ok 'Dipendenze PHP (Composer)'
        if ($Composer.Kind -eq 'exe') {
            & $Composer.Path install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
        } else {
            & $PhpExe $Composer.Path install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
        }
        if ($LASTEXITCODE -ne 0) {
            Write-Warn 'composer install non riuscito: provo composer update'
            if ($Composer.Kind -eq 'exe') {
                & $Composer.Path update --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
            } else {
                & $PhpExe $Composer.Path update --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
            }
        }
        if ($LASTEXITCODE -ne 0) { Fail "composer install/update non riuscito (exit $LASTEXITCODE)." }
    } finally {
        Pop-Location
    }
}

function Write-AppEnv([string]$PhpExe) {
    $envFile = Join-Path $Root '.env'
    if (-not (Test-Path $envFile)) {
        Copy-Item (Join-Path $Root '.env.example') $envFile -Force
    }
    Write-Ok 'Configurazione applicazione'
    $previous = $env:SOCLY_DB_PASS
    try {
        $env:SOCLY_DB_PASS = $script:DbPass
        & $PhpExe (Join-Path $Root 'bin\install\bootstrap.php') `
            "--db-host=$DbHost" "--db-port=$DbPort" "--db-name=$DbName" "--db-user=$DbUser" `
            "--url=$($script:AppUrl)" "--locale=$Locale"
        $bootstrapCode = $LASTEXITCODE
    } finally {
        if ($null -eq $previous) { Remove-Item Env:SOCLY_DB_PASS -ErrorAction SilentlyContinue }
        else { $env:SOCLY_DB_PASS = $previous }
    }
    if ($bootstrapCode -ne 0) { Fail "Configurazione applicazione non riuscita (exit $bootstrapCode)." }
    Protect-PathAcl $envFile
    Write-Ok '.env aggiornato (ACL ristrette)'
}

function Install-Ocr {
    if ($NoOcr) { Write-Host 'OCR saltato (-NoOcr).'; return }
    if (-not $Ocr) { Write-Host 'OCR non richiesto: usa -Ocr per installare Tesseract (poppler resta manuale).'; return }
    if (-not (Has-Command winget)) {
        Write-Warn 'winget assente: installa Tesseract e Poppler manualmente per l''OCR.'
        return
    }
    Write-Host 'Installazione OCR (Tesseract) con winget...'
    & winget install --id UB-Mannheim.TesseractOCR -e --silent --accept-package-agreements --accept-source-agreements
    if ($LASTEXITCODE -eq 0) { Write-Ok 'Tesseract installato' }
    else { Write-Warn "Tesseract non installato (winget exit $LASTEXITCODE)." }
    Write-Warn 'Poppler non e disponibile in modo affidabile via winget: installalo manualmente se serve la resa PDF completa.'
}

function Start-LocalServer([string]$PhpExe) {
    if (Test-TcpPort '127.0.0.1' $Port 400) {
        Fail "La porta $Port e' occupata da $(Get-PortOwner $Port)."
    }
    New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
    $proc = Start-Process -FilePath $PhpExe `
        -ArgumentList @('-S', "${Bind}:${Port}", '-t', 'public', 'public/router.php') `
        -WorkingDirectory $Root -RedirectStandardOutput $ServerLog -RedirectStandardError $ServerErrLog `
        -PassThru -WindowStyle Hidden
    Set-Content -Path $ServerPidFile -Value $proc.Id -Encoding ASCII

    $connectHost = $Bind
    if ($Bind -in @('0.0.0.0', '::', '[::]')) { $connectHost = '127.0.0.1' }
    $localUrl = "http://${connectHost}:${Port}/install"
    $deadline = (Get-Date).AddSeconds(90)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        if ($proc.HasExited) { break }
        if (Test-SoclyResponse $localUrl) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) {
        Get-Content $ServerLog -Tail 30 -ErrorAction SilentlyContinue | Write-Host
        Get-Content $ServerErrLog -Tail 30 -ErrorAction SilentlyContinue | Write-Host
        Fail "Il server locale non risponde su $localUrl (marcatore X-Socly-Installer assente)."
    }
    return $localUrl
}

function Invoke-Uninstall {
    Write-Host 'Socly - disinstallazione (solo componenti creati da questo script)'
    if (Test-Path $ServerPidFile) {
        $serverPid = (Get-Content -Path $ServerPidFile -Raw).Trim()
        if ($serverPid -match '^\d+$') {
            $serverProc = Get-Process -Id ([int]$serverPid) -ErrorAction SilentlyContinue
            if ($serverProc -and $serverProc.ProcessName -like 'php*') {
                Stop-Process -Id ([int]$serverPid) -Force -ErrorAction SilentlyContinue
                Write-Ok "Server locale fermato (PID $serverPid)"
            } elseif ($serverProc) {
                Write-Warn "Il PID $serverPid non e' php: non lo fermo."
            }
        }
        Remove-Item $ServerPidFile -Force -ErrorAction SilentlyContinue
    }

    $manifest = $null
    if (Test-Path $ManifestPath) {
        try { $manifest = Get-Content -Path $ManifestPath -Raw | ConvertFrom-Json } catch { $manifest = $null }
    }

    $targetDb = $DbName
    $targetUser = $DbUser
    $targetHost = $DbHost
    $targetPort = $DbPort
    if ($manifest -and $manifest.db) {
        if ($manifest.db.name) { $targetDb = [string]$manifest.db.name }
        if ($manifest.db.user) { $targetUser = [string]$manifest.db.user }
        if ($manifest.db.host) { $targetHost = [string]$manifest.db.host }
        if ($manifest.db.port) { $targetPort = [int]$manifest.db.port }
    }

    if ($PurgeDatabase) {
        $client = Find-MysqlClient
        if ($client -eq '') {
            Write-Warn 'Client mysql non trovato: database non rimosso.'
        } else {
            Resolve-RootPassword
            $script:RootPassword = $script:DbRootPass
            $user = Escape-SqlString $targetUser
            $sql = 'DROP DATABASE IF EXISTS `' + $targetDb + '`; ' +
                "DROP USER IF EXISTS '$user'@'localhost'; DROP USER IF EXISTS '$user'@'127.0.0.1'; FLUSH PRIVILEGES;"
            $args = @('-u', 'root', '-h', $targetHost, '-P', "$targetPort", '--protocol=TCP', '--connect-timeout=5')
            if ((Invoke-Mysql -MysqlExe $client -Arguments $args -Sql $sql) -eq 0) {
                Write-Ok "Database $targetDb e utente $targetUser rimossi"
            } else {
                Write-Warn 'Rimozione database non riuscita: controlla la password root.'
            }
        }
    }

    if ($PurgeMariaDb) {
        $installedHere = $false
        if ($manifest -and $manifest.mariadb -and $manifest.mariadb.installed_by_script) { $installedHere = $true }
        if ($installedHere -and (Has-Command winget)) {
            & winget uninstall --id MariaDB.Server -e --silent --accept-source-agreements
            if ($LASTEXITCODE -eq 0) { Write-Ok 'MariaDB disinstallato' }
            else { Write-Warn "Disinstallazione MariaDB non riuscita (winget exit $LASTEXITCODE)." }
        } elseif ($installedHere -and (Has-Command choco)) {
            & choco uninstall mariadb -y
            if ($LASTEXITCODE -eq 0) { Write-Ok 'MariaDB disinstallato' }
            else { Write-Warn "Disinstallazione MariaDB non riuscita (choco exit $LASTEXITCODE)." }
        } else {
            Write-Warn 'MariaDB non risulta installato da questo script: non lo rimuovo.'
        }
    }

    $phpRemoved = $false
    if ($manifest -and $manifest.php -and $manifest.php.installed_by_script) {
        $phpPath = [string]$manifest.php.path
        if ($phpPath -ne '' -and (Test-Path $phpPath)) {
            $phpDir = Split-Path $phpPath
            try {
                Remove-Item $phpDir -Recurse -Force -ErrorAction Stop
                Write-Ok "Rimosso PHP: $phpDir"
                $phpRemoved = $true
            } catch {
                Write-Warn "Impossibile rimuovere $phpDir (file in uso?): $($_.Exception.Message)"
            }
        }
    }
    if (-not $phpRemoved) { Write-Warn 'PHP non risulta installato da questo script: non lo rimuovo.' }

    $shimDir = Join-Path $env:ProgramData 'Socly\bin'
    $shim = Join-Path $shimDir 'php.cmd'
    if (Test-Path $shim) { Remove-Item $shim -Force; Write-Ok 'Rimosso shim php.cmd' }
    if ((Test-Path $shimDir) -and -not (Get-ChildItem $shimDir -Force -ErrorAction SilentlyContinue)) {
        Remove-Item $shimDir -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path $RootPasswordFile) { Remove-Item $RootPasswordFile -Force }
    $composerPhar = Join-Path $Root 'storage\cache\composer.phar'
    if (Test-Path $composerPhar) {
        Remove-Item $composerPhar -Force
        Write-Ok 'Rimosso composer.phar locale'
    }
    if (Test-Path $ManifestPath) { Remove-Item $ManifestPath -Force }
    Write-Ok 'Disinstallazione completata. File applicazione e storage/ non sono stati toccati.'
}
if ($Check) {
    $report = Get-CheckReport
    if ($ReportPath -ne '') {
        try {
            $report | ConvertTo-Json -Depth 8 | Set-Content -Path $ReportPath -Encoding UTF8
            if (-not $Json) { Write-Host "Report scritto in $ReportPath" }
        } catch {
            if (-not $Json) { Write-Warn "Impossibile scrivere il report in $ReportPath" }
        }
    }
    if ($Json) {
        $report | ConvertTo-Json -Depth 8
        exit 0
    }
    Show-CheckReport $report
    exit 0
}

if ($Plan) {
    Show-Plan
    exit 0
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Socly deve installare PHP in Program Files, registrare il servizio MariaDB e applicare ACL: richiedo i privilegi di amministratore.'
    $argList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $PSCommandPath + '"'))
    foreach ($key in $PSBoundParameters.Keys) {
        $value = $PSBoundParameters[$key]
        if ($value -is [switch]) {
            if ($value.IsPresent) { $argList += "-$key" }
        } elseif ($null -ne $value -and "$value" -ne '') {
            $escaped = "$value".Replace("'", "''")
            $argList += "-$key"
            $argList += "'$escaped'"
        }
    }
    if (-not $PSBoundParameters.ContainsKey('WaitOnExit')) { $argList += '-WaitOnExit' }
    try {
        $elevated = Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $argList -Wait -PassThru
    } catch {
        Fail 'Elevazione annullata: l''installazione richiede i privilegi di amministratore.'
    }
    if ($elevated.ExitCode -ne 0) {
        Write-Host "L'installazione non e' riuscita (exit $($elevated.ExitCode))." -ForegroundColor Red
        $latestLog = Get-ChildItem -Path $LogDir -Filter 'install-*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        if ($latestLog) {
            Write-Host "Ultime righe del log ($($latestLog.FullName)):" -ForegroundColor Yellow
            Get-Content $latestLog.FullName -Tail 40 -ErrorAction SilentlyContinue | Write-Host
        }
        Read-Host 'Premi Invio per chiudere'
    }
    exit $elevated.ExitCode
}

Ensure-StorageDirectories
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
try { Start-Transcript -Path $InstallerLog -Append | Out-Null } catch { Write-Warn "Transcript non disponibile: $($_.Exception.Message)" }

Write-Host 'Socly - installazione one-click (Windows)'
$script:RootPassword = ''
try {
    if ($Uninstall) {
        Invoke-Uninstall
        if ($WaitOnExit) { Read-Host 'Premi Invio per chiudere' | Out-Null }
        try { Stop-Transcript | Out-Null } catch { }
        exit 0
    }

    $Port = Resolve-AppPort
    $script:AppUrl = $Url
    if ([string]::IsNullOrWhiteSpace($script:AppUrl)) {
        $appHost = $Bind
        if ($Bind -in @('0.0.0.0', '::', '[::]')) { $appHost = '127.0.0.1' }
        $script:AppUrl = "http://${appHost}:${Port}"
    } else {
        $script:AppUrl = $script:AppUrl.TrimEnd('/')
    }
    $script:DbPass = $DbPass
    $script:DbRootPass = $DbRootPass

    $PhpExe = Ensure-Php
    Save-Manifest
    $Composer = Ensure-Composer $PhpExe
    $Mysql = Ensure-MariaDb
    Save-Manifest
    if ($DbHost -in @('127.0.0.1', 'localhost', '::1')) {
        Start-MariaDbService
        if ($script:MariaDbInstalledByScript -or $script:MariaDbWasAbsentBefore) { Set-MariaDbLocalBinding }
    } else {
        Write-Host "Database remoto ($DbHost`:$DbPort): salto la gestione del servizio locale."
    }
    Resolve-RootPassword
    Test-RootAccess $Mysql
    Create-Database $Mysql
    Install-PhpDependencies $PhpExe $Composer
    Write-AppEnv $PhpExe
    Install-Ocr
    Save-Manifest

    if ($NoServe) {
        Write-Ok 'Installazione completata (server non avviato).'
        Write-Host "Completa il wizard in $($script:AppUrl)/install e poi il setup in $($script:AppUrl)/setup dopo il login."
        if ($WaitOnExit) { Read-Host 'Premi Invio per chiudere' | Out-Null }
        try { Stop-Transcript | Out-Null } catch { }
        exit 0
    }

    $localUrl = Start-LocalServer $PhpExe
    if (-not $NoBrowser) { Start-Process $localUrl | Out-Null }

    Write-Host ''
    Write-Ok 'Socly e installato e il server locale e attivo.'
    Write-Host "  Wizard:   $localUrl"
    Write-Host "  Login:    http://$($localUrl.Split('/install')[0])/login (account di manutenzione: admin@socly.it)"
    Write-Host "  Setup:    http://$($localUrl.Split('/install')[0])/setup (dopo il wizard e il login)"
    Write-Host "  Log:      $ServerLog"
    Write-Host "  Install:  $InstallerLog"
    Write-Host "  Stop:     Stop-Process -Id (Get-Content '$ServerPidFile')"
    Write-Host ''
    Write-Host 'Completa il wizard (associazione, periodo, quote, campi); poi accedi con admin@socly.it e completa /setup.'
    Write-Host 'La password di manutenzione non viene stampata ne salvata dallo script.'
    Write-Host 'Per la produzione (IIS/Apache/Nginx, HTTPS) vedi docs\INSTALLATION.md.'
    if ($WaitOnExit) { Read-Host 'Premi Invio per chiudere' | Out-Null }
} catch {
    Fail $_.Exception.Message
} finally {
    try { Stop-Transcript | Out-Null } catch { }
}
