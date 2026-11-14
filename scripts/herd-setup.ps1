<#
.SYNOPSIS
    Sets up APES Newsroom as its own Laravel Herd site (docs/local-dev.md).

.DESCRIPTION
    Reads docs/herd/profile.json, then:
      1. checks Herd is running and has the profile's PHP, Composer and Node,
         and puts them first on PATH for this PowerShell session only;
      2. checks the PHP extensions and that public/.htaccess stays safe for
         Cloudron's Apache;
      3. checks Herd's MySQL service answers on the profile's port;
      4. links the site (herd link) and pins it to the profile's PHP version
         (herd isolate), unless that is already done;
      5. creates .env from .env.example if missing and checks it matches the
         profile;
      6. installs Composer and npm dependencies and generates APP_KEY if blank;
      7. creates the apes_newsroom_local database if missing, links
         public/storage and runs the migrations;
      8. offers to load the fake demo data.

    Safe to run more than once. It only touches this repository, its Herd site
    link and the apes_newsroom_local database.

.PARAMETER Demo
    Load the demo data without asking.

.PARAMETER NoDemo
    Skip the demo data without asking.

.PARAMETER UpdateEnv
    If .env exists but its APP_URL, DB_* or MAIL_* values differ from the
    profile, back it up to .env.backup-<timestamp> and set the profile values.

.PARAMETER HerdRoot
    Herd's folder. Defaults to %USERPROFILE%\.config\herd.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\herd-setup.ps1

.EXAMPLE
    composer setup
#>
[CmdletBinding()]
param(
    [switch]$Demo,
    [switch]$NoDemo,
    [switch]$UpdateEnv,
    [string]$HerdRoot = (Join-Path $env:USERPROFILE '.config\herd')
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Config = Get-Content -Raw -Path (Join-Path $RepoRoot 'docs\herd\profile.json') | ConvertFrom-Json
$Warnings = New-Object System.Collections.Generic.List[string]

function Write-Step([string]$Text) { Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text) { Write-Host "    ok: $Text" -ForegroundColor Green }
function Write-Note([string]$Text) { Write-Host "    $Text" }
function Add-Warning([string]$Text) {
    $Warnings.Add($Text)
    Write-Host "    WARNING: $Text" -ForegroundColor Yellow
}
function Stop-Setup([string]$Text) {
    Write-Host "    ERROR: $Text" -ForegroundColor Red
    exit 1
}

# Native tools write progress to stderr; Windows PowerShell 5.1 would treat
# that as an error under 'Stop', so these rely on exit codes instead.
function Invoke-Checked([string]$Exe, [string[]]$Arguments, [string]$What) {
    $ErrorActionPreference = 'Continue'
    & $Exe @Arguments 2>&1 | ForEach-Object { Write-Host "    $_" }
    if ($LASTEXITCODE -ne 0) { Stop-Setup "$What failed (exit code $LASTEXITCODE)." }
}

function Get-NativeOutput([string]$Exe, [string[]]$Arguments) {
    $ErrorActionPreference = 'Continue'
    $output = @(& $Exe @Arguments 2>$null | ForEach-Object { "$_" })
    return , $output
}

function Get-EnvValue([string[]]$Lines, [string]$Key) {
    foreach ($line in $Lines) {
        if ($line -match "^\s*$([regex]::Escape($Key))=(.*)$") { return $Matches[1].Trim().Trim('"') }
    }
    return $null
}

function Test-Port([string]$HostName, [int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect($HostName, $Port, $null, $null)
        return ($async.AsyncWaitHandle.WaitOne(1500) -and $client.Connected)
    } catch { return $false } finally { $client.Close() }
}

Write-Host "APES Newsroom Herd setup ($RepoRoot)" -ForegroundColor White

# --- Herd and its tools ----------------------------------------------------
Write-Step 'Checking Herd'
$herdBin = Join-Path $HerdRoot 'bin'
$Herd = Join-Path $herdBin 'herd.bat'
if (-not (Test-Path $Herd)) { Stop-Setup "Laravel Herd was not found at $HerdRoot. Install Herd for Windows, or pass -HerdRoot." }
$notRunning = @('Herd', 'nginx' | Where-Object { -not (Get-Process $_ -ErrorAction SilentlyContinue) })
if ($notRunning.Count -gt 0) { Stop-Setup "Not running: $($notRunning -join ', '). Open Herd, then run this again." }
Write-Ok "Herd at $HerdRoot"

$phpWanted = [string]$Config.php.version
$phpDir = Join-Path $herdBin ('php' + ($phpWanted -replace '\.', ''))
$Php = Join-Path $phpDir 'php.exe'
if (-not (Test-Path $Php)) { Stop-Setup "Herd has no PHP $phpWanted. Install it with: herd php:install $phpWanted" }
$phpVersion = (Get-NativeOutput $Php @('-r', 'echo PHP_VERSION;'))[0]
Write-Ok "PHP CLI $phpVersion ($phpDir)"

$phpModules = Get-NativeOutput $Php @('-m') | ForEach-Object { $_.Trim().ToLowerInvariant() }
$missingExt = @($Config.php.extensions | Where-Object { $phpModules -notcontains $_ })
if ($missingExt.Count -gt 0) {
    Add-Warning ("PHP extensions not enabled: " + ($missingExt -join ', ') + ". Open php.ini with 'herd ini $phpWanted', remove the ; before extension=<name>, then run 'herd restart php'.")
} else {
    Write-Ok 'PHP extensions'
}

$composerPhar = Join-Path $herdBin 'composer.phar'
if (-not (Test-Path $composerPhar)) { Stop-Setup "Composer was not found at $composerPhar." }
$composerVersion = (Get-NativeOutput $Php @($composerPhar, '--version', '--no-ansi'))[0]
Write-Ok ('Composer ' + ($composerVersion -replace '^Composer version (\S+).*$', '$1'))

$nodeWanted = [string]$Config.node.version
$nodeDir = Get-ChildItem (Join-Path $herdBin 'nvm') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match "^v$([regex]::Escape($nodeWanted))\.\d+\.\d+$" } |
    Sort-Object { [version]($_.Name.TrimStart('v')) } -Descending |
    Select-Object -First 1
if (-not $nodeDir) { Stop-Setup "Herd's nvm has no Node.js $nodeWanted. Turn on Node.js in Herd > Settings, then run: herd node:install $nodeWanted" }
$env:Path = "$phpDir;$herdBin;$($nodeDir.FullName);" + $env:Path
if (-not $env:XDEBUG_MODE) { $env:XDEBUG_MODE = 'off' }
Write-Ok "Node.js $($nodeDir.Name) ($($nodeDir.FullName))"

$htaccess = Join-Path $RepoRoot 'public\.htaccess'
$depth = 0
$problems = @()
foreach ($line in Get-Content $htaccess) {
    if ($line -match '^\s*<IfModule\s+(php_module|mod_php\d*\.c)\s*>') { $depth++ }
    elseif ($depth -gt 0 -and $line -match '^\s*</IfModule>') { $depth-- }
    elseif ($depth -eq 0 -and $line -match '^\s*php_(value|flag|admin_value|admin_flag)\b') { $problems += $line.Trim() }
}
if ($problems.Count -gt 0) { Add-Warning "public\.htaccess has PHP settings outside <IfModule php_module>, which breaks Cloudron's Apache: $($problems -join '; ')." }
elseif (-not (Select-String -Path $htaccess -Pattern 'HTTP_AUTHORIZATION' -Quiet)) { Add-Warning 'public\.htaccess no longer passes the Authorization header to PHP, which the API needs on Cloudron.' }
else { Write-Ok 'public\.htaccess is safe for Cloudron (nginx ignores it locally)' }

$dbHost = [string]$Config.database.host
$dbPort = [int]$Config.database.port
if (-not (Test-Port $dbHost $dbPort)) { Stop-Setup "MySQL is not answering on ${dbHost}:$dbPort. Start Herd's MySQL service (Herd > Services), then run this again." }
$mysqlBin = Get-ChildItem (Join-Path $herdBin 'services\mysql') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like "$($Config.database.version).*" } |
    Sort-Object { [version]$_.Name } -Descending |
    Select-Object -First 1
if (-not $mysqlBin) { Stop-Setup "Herd's MySQL $($Config.database.version) was not found in $herdBin\services\mysql. Create it in Herd > Services." }
$Mysql = Join-Path $mysqlBin.FullName 'bin\mysql.exe'
Write-Ok "MySQL $($mysqlBin.Name) running on ${dbHost}:$dbPort"

# --- Herd site ---------------------------------------------------------------
Write-Step "Herd site $($Config.hostname)"
Set-Location $RepoRoot
$site = [string]$Config.herdSite
$repoFull = (Resolve-Path $RepoRoot).Path.TrimEnd('\')
$siteLink = Join-Path $HerdRoot "config\valet\Sites\$site"
if (Test-Path $siteLink) {
    $target = "$((Get-Item $siteLink -Force).Target)".TrimEnd('\')
    if ($target -and $target -ine $repoFull) { Stop-Setup "The Herd site $site already points at $target, not this clone ($repoFull). Run 'herd unlink $site' there first, then run this again." }
    Write-Ok "Already linked to this clone"
} else {
    Invoke-Checked $Herd @('link', $site) 'herd link'
    Write-Ok "Linked $($Config.hostname) to $repoFull"
}
if ($Config.php.isolated) {
    $sitePhp = (Get-NativeOutput $Herd @('which-php', $site)) -join ' '
    if ($sitePhp -match ('php' + ($phpWanted -replace '\.', '') + '[\\/]')) {
        Write-Ok "Pinned to PHP $phpWanted"
    } else {
        Invoke-Checked $Herd @('isolate', $phpWanted, "--site=$site") 'herd isolate'
        Write-Ok "Pinned $($Config.hostname) to PHP $phpWanted"
    }
}

$hostname = [string]$Config.hostname
try {
    $addresses = [System.Net.Dns]::GetHostAddresses($hostname) | ForEach-Object { $_.IPAddressToString }
    if ($addresses -contains '127.0.0.1') { Write-Ok "$hostname resolves to 127.0.0.1" }
    else { Add-Warning "$hostname resolves to $($addresses -join ', '), not 127.0.0.1." }
} catch {
    Add-Warning "$hostname does not resolve. Restart Herd so its helper service writes the hosts entry."
}

# --- .env ------------------------------------------------------------------
Write-Step 'Checking .env'
$envFile = Join-Path $RepoRoot '.env'
if (-not (Test-Path $envFile)) {
    Copy-Item (Join-Path $RepoRoot '.env.example') $envFile
    Write-Ok 'Created .env from .env.example'
}

$wanted = [ordered]@{
    APP_URL = [string]$Config.appUrl
    DB_CONNECTION = [string]$Config.database.engine
    DB_HOST = $dbHost
    DB_PORT = [string]$dbPort
    DB_DATABASE = [string]$Config.database.name
    DB_USERNAME = [string]$Config.database.username
    DB_PASSWORD = [string]$Config.database.password
    MAIL_MAILER = [string]$Config.mail.mailer
    MAIL_HOST = [string]$Config.mail.host
    MAIL_PORT = [string]$Config.mail.port
}
$lines = @(Get-Content $envFile)
$diffs = @($wanted.Keys | Where-Object { [string](Get-EnvValue $lines $_) -ne $wanted[$_] })
if ($diffs.Count -eq 0) {
    Write-Ok '.env matches the Herd profile'
} elseif ($UpdateEnv) {
    $backup = "$envFile.backup-" + (Get-Date -Format 'yyyyMMddHHmmss')
    Copy-Item $envFile $backup
    foreach ($key in $diffs) {
        $found = $false
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match "^\s*$([regex]::Escape($key))=") {
                $lines[$i] = "$key=$($wanted[$key])"
                $found = $true
            }
        }
        if (-not $found) { $lines += "$key=$($wanted[$key])" }
    }
    [IO.File]::WriteAllText($envFile, (($lines -join "`n") + "`n"), (New-Object System.Text.UTF8Encoding($false)))
    Write-Ok ("Updated .env (" + ($diffs -join ', ') + "); old copy at $backup")
} else {
    Add-Warning ('.env differs from the Herd profile for: ' + ($diffs -join ', ') + '. Rerun with -UpdateEnv to fix it (a backup is kept), or edit .env by hand.')
}

$lines = @(Get-Content $envFile)
if ((Get-EnvValue $lines 'APP_ENV') -ne 'local') {
    Add-Warning 'APP_ENV in .env is not local. The role preview and demo data only work with APP_ENV=local.'
}

# --- Dependencies ------------------------------------------------------------
Write-Step 'Installing dependencies'
$vendorStamp = Join-Path $RepoRoot 'vendor\composer\installed.json'
if (-not (Test-Path $vendorStamp) -or (Get-Item (Join-Path $RepoRoot 'composer.lock')).LastWriteTime -gt (Get-Item $vendorStamp).LastWriteTime) {
    Invoke-Checked $Php @($composerPhar, 'install', '--no-interaction', '--no-progress') 'composer install'
    # Composer leaves installed.json alone when nothing changed, which would re-run this every time.
    (Get-Item $vendorStamp).LastWriteTime = Get-Date
} else {
    Write-Ok 'vendor/ is up to date'
}

$nodeStamp = Join-Path $RepoRoot 'node_modules\.package-lock.json'
if (-not (Test-Path $nodeStamp) -or (Get-Item (Join-Path $RepoRoot 'package-lock.json')).LastWriteTime -gt (Get-Item $nodeStamp).LastWriteTime) {
    Invoke-Checked (Join-Path $nodeDir.FullName 'npm.cmd') @('ci', '--no-audit', '--no-fund') 'npm ci'
} else {
    Write-Ok 'node_modules/ is up to date'
}

if (-not (Get-EnvValue @(Get-Content $envFile) 'APP_KEY')) {
    Invoke-Checked $Php @('artisan', 'key:generate', '--ansi') 'php artisan key:generate'
} else {
    Write-Ok 'APP_KEY is set'
}

# --- Database ------------------------------------------------------------------
Write-Step "Preparing database $($Config.database.name)"
$dbName = [string]$Config.database.name
if ($dbName -notmatch '^[a-z0-9_]+_local$') { Stop-Setup "Refusing to use database name '$dbName' (it must end in _local)." }
$mysqlArgs = @('-h', $dbHost, '-P', [string]$dbPort, '-u', [string]$Config.database.username)
if ([string]$Config.database.password) { $mysqlArgs += ('--password=' + [string]$Config.database.password) }
Invoke-Checked $Mysql ($mysqlArgs + @('-e', "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET $($Config.database.charset) COLLATE $($Config.database.collation)")) 'Creating the database'
Write-Ok "Database $dbName exists"

if (-not (Test-Path (Join-Path $RepoRoot 'public\storage'))) {
    Invoke-Checked $Php @('artisan', 'storage:link') 'php artisan storage:link'
}

Invoke-Checked $Php @('artisan', 'config:clear') 'php artisan config:clear'
Invoke-Checked $Php @('artisan', 'migrate', '--force', '--no-interaction') 'php artisan migrate'

# --- Demo data ---------------------------------------------------------------
Write-Step 'Demo data'
$loadDemo = $false
if ($Demo) { $loadDemo = $true }
elseif ($NoDemo) { $loadDemo = $false }
elseif ([Console]::IsInputRedirected) {
    Write-Note 'No interactive console, so the demo data was not loaded (pass -Demo to load it).'
}
else {
    $answer = Read-Host '    Load the demo data now? It is fake and safe to load again (y/N)'
    $loadDemo = $answer -match '^(y|yes)$'
}
if ($loadDemo) {
    Invoke-Checked $Php @('artisan', 'newsroom:demo-data') 'Loading demo data'
} else {
    Write-Note 'Skipped. Load it later with: composer demo:seed'
}

# --- Summary -------------------------------------------------------------------
Write-Host ''
if ($Warnings.Count -gt 0) {
    Write-Host "Setup finished with $($Warnings.Count) warning(s):" -ForegroundColor Yellow
    $Warnings | ForEach-Object { Write-Host " - $_" -ForegroundColor Yellow }
} else {
    Write-Host 'Setup finished.' -ForegroundColor Green
}
Write-Host "Site:        $($Config.appUrl)"
Write-Host "Live reload: npm run dev (Vite on http://$($Config.vite.host):$($Config.vite.port)), or composer dev for Vite plus the queue worker"
Write-Host 'Demo logins: docs/herd/demo-data.md'
