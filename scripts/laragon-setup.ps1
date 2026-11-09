<#
.SYNOPSIS
    Sets up APES Newsroom as its own Laragon project (docs/local-dev.md).

.DESCRIPTION
    Reads docs/laragon/profile.json, checks Laragon's PHP, Composer, Node.js and
    MySQL against it, warns if the php or node on PATH is not Laragon's,
    installs the apes-newsroom Laragon profile if missing,
    creates .env from .env.example if missing, installs dependencies, creates
    this project's database, runs migrations and offers to load demo data.

    Safe to run more than once. It only touches this repository, the
    apes_newsroom_local database and C:\laragon\usr\profile\apes-newsroom.ini.
    It never edits virtual hosts, other databases or other projects.

.PARAMETER LaragonRoot
    Laragon folder. Defaults to laragonRoot in profile.json (C:\laragon).

.PARAMETER Demo
    Load the demo data without asking.

.PARAMETER NoDemo
    Skip the demo data without asking.

.PARAMETER UpdateEnv
    If .env exists but its APP_URL or DB_* values differ from the profile,
    back it up to .env.backup-<timestamp> and set them to the profile values.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\laragon-setup.ps1

.EXAMPLE
    composer setup
#>
[CmdletBinding()]
param(
    [string]$LaragonRoot,
    [switch]$Demo,
    [switch]$NoDemo,
    [switch]$UpdateEnv
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Config = Get-Content -Raw -Path (Join-Path $RepoRoot 'docs\laragon\profile.json') | ConvertFrom-Json
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

function Get-VersionFolder([string]$Parent, [string]$Pattern) {
    if (-not (Test-Path $Parent)) { return $null }
    Get-ChildItem -Path $Parent -Directory |
        Where-Object { $_.Name -match $Pattern } |
        Sort-Object { if ($_.Name -match '(\d+\.\d+(?:\.\d+)?)') { [version]$Matches[1] } else { [version]'0.0' } } -Descending |
        Select-Object -First 1
}

function Get-EnvValue([string[]]$Lines, [string]$Key) {
    foreach ($line in $Lines) {
        if ($line -match "^\s*$([regex]::Escape($Key))=(.*)$") { return $Matches[1].Trim().Trim('"') }
    }
    return $null
}

function Test-PathTool([string]$Name, [string]$Source, [string[]]$VersionArgs, [string]$LaragonDir, [string]$Uses) {
    if (-not $Source) {
        Add-Warning "$Name is not on PATH, so $Uses cannot find it outside this script. Run them from Laragon's Terminal (Menu > Laragon > Terminal)."
    } elseif (-not $Source.StartsWith($LaragonRoot.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
        $version = (Get-NativeOutput $Source $VersionArgs)[0]
        if (-not $version) { $version = 'unknown version' }
        Add-Warning "$Name on PATH is $Source ($($version.Trim())), not Laragon's, and $Uses use it. Run them from Laragon's Terminal (Menu > Laragon > Terminal), or put $LaragonDir first on PATH."
    } else {
        Write-Ok "$Name on PATH is Laragon's ($Source)"
    }
}

function Test-Port([string]$HostName, [int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect($HostName, $Port, $null, $null)
        return ($async.AsyncWaitHandle.WaitOne(1500) -and $client.Connected)
    } catch { return $false } finally { $client.Close() }
}

Write-Host "APES Newsroom Laragon setup ($RepoRoot)" -ForegroundColor White

# --- Laragon and its tools ------------------------------------------------
Write-Step 'Checking Laragon'
$PathPhp = (Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1).Source
$PathNode = (Get-Command node -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1).Source
if (-not $LaragonRoot) { $LaragonRoot = $Config.laragonRoot }
if (-not (Test-Path (Join-Path $LaragonRoot 'laragon.exe'))) {
    Stop-Setup "Laragon not found at $LaragonRoot. Install Laragon (Full) or pass -LaragonRoot."
}
Write-Ok "Laragon at $LaragonRoot"

$phpWanted = [string]$Config.php.version
$phpDir = Get-VersionFolder (Join-Path $LaragonRoot 'bin\php') ("^php-" + [regex]::Escape($phpWanted) + "\.\d+-Win32")
if (-not $phpDir) {
    Stop-Setup "Laragon has no thread-safe PHP $phpWanted in $LaragonRoot\bin\php. Add it with Menu > PHP > Version > Get more (choose a Thread Safe build)."
}
$Php = Join-Path $phpDir.FullName 'php.exe'
Write-Ok "PHP CLI $($phpDir.Name)"

$modPhp = Join-Path $LaragonRoot 'etc\apache2\mod_php.conf'
if (Test-Path $modPhp) {
    $loaded = Select-String -Path $modPhp -Pattern 'php-(\d+\.\d+)\.\d+' | Select-Object -First 1
    if ($loaded -and $loaded.Matches[0].Groups[1].Value -eq $phpWanted) {
        Write-Ok "Apache runs PHP $phpWanted"
    } elseif ($loaded) {
        Add-Warning ("Laragon's Apache is set to PHP " + $loaded.Matches[0].Groups[1].Value + ", but APES Newsroom needs PHP $phpWanted. In Laragon choose Menu > PHP > Version > $($phpDir.Name), or Menu > Profile > $($Config.laragonProfile), then Menu > Apache > Reload.")
    }
} else {
    Add-Warning "Could not read $modPhp to check Apache's PHP version. Start Laragon once, then rerun."
}

$phpModules = Get-NativeOutput $Php @('-m') | ForEach-Object { $_.Trim().ToLowerInvariant() }
$missingExt = @($Config.php.extensions | Where-Object { $phpModules -notcontains $_ })
if ($missingExt.Count -gt 0) {
    Add-Warning ("PHP extensions not enabled: " + ($missingExt -join ', ') + ". Enable them with Menu > PHP > Extensions.")
} else {
    Write-Ok 'PHP extensions'
}

$composerPhar = Join-Path $LaragonRoot 'bin\composer\composer.phar'
if (Test-Path $composerPhar) {
    $ComposerExe = $Php
    $ComposerArgs = @($composerPhar)
} elseif (Get-Command composer -ErrorAction SilentlyContinue) {
    $ComposerExe = (Get-Command composer).Source
    $ComposerArgs = @()
} else {
    Stop-Setup "Composer not found in $LaragonRoot\bin\composer or on PATH."
}
$composerVersion = (Get-NativeOutput $ComposerExe ($ComposerArgs + @('--version', '--no-ansi')))[0]
Write-Ok ('Composer ' + ($composerVersion -replace '^Composer version (\S+).*$', '$1'))

$nodeWanted = [string]$Config.node.version
$nodeDir = Get-VersionFolder (Join-Path $LaragonRoot 'bin\nodejs') ("^node-v" + [regex]::Escape($nodeWanted))
if ($nodeDir) {
    $env:Path = $nodeDir.FullName + ';' + $env:Path
}
$nodeExe = Get-Command node -ErrorAction SilentlyContinue
if (-not $nodeExe) { Stop-Setup "Node.js $nodeWanted not found. Add it with Menu > Tools > Quick add > NodeJS." }
$nodeVersion = (Get-NativeOutput 'node' @('-v'))[0].Trim()
if ($nodeVersion -notmatch "^v$([regex]::Escape($nodeWanted))\.") {
    Add-Warning "Node.js is $nodeVersion; APES Newsroom expects Node.js $nodeWanted (Laragon's node-v$nodeWanted, the same as CI)."
} else {
    Write-Ok "Node.js $nodeVersion"
}

$nodeHome = if ($nodeDir) { $nodeDir.FullName } else { Join-Path $LaragonRoot "bin\nodejs\node-v$nodeWanted" }
Test-PathTool 'php' $PathPhp @('-r', 'echo PHP_VERSION;') $phpDir.FullName 'composer and php artisan'
Test-PathTool 'node' $PathNode @('-v') $nodeHome 'npm run dev, npm run build and npm test'

$dbWanted = [string]$Config.database.version
$mysqlDir = Get-VersionFolder (Join-Path $LaragonRoot 'bin\mysql') ("^mysql-" + [regex]::Escape($dbWanted))
if (-not $mysqlDir) { Stop-Setup "Laragon has no MySQL $dbWanted in $LaragonRoot\bin\mysql." }
$Mysql = Join-Path $mysqlDir.FullName 'bin\mysql.exe'
Write-Ok "MySQL client $($mysqlDir.Name)"

$dbHost = [string]$Config.database.host
$dbPort = [int]$Config.database.port
if (-not (Test-Port $dbHost $dbPort)) {
    Stop-Setup "MySQL is not answering on ${dbHost}:$dbPort. Click Start All in Laragon, then rerun."
}
Write-Ok "MySQL running on ${dbHost}:$dbPort"

# --- Project folder, hostname and Laragon profile --------------------------
Write-Step 'Checking the Laragon project'
$folder = [string]$Config.folder
if (Test-Path $folder) {
    $item = Get-Item $folder
    $target = if ($item.LinkType) { @($item.Target)[0] } else { $item.FullName }
    if ((Resolve-Path $target).Path.TrimEnd('\') -ieq (Resolve-Path $RepoRoot).Path.TrimEnd('\')) {
        Write-Ok "$folder is this repository"
    } else {
        Add-Warning "$folder points at $target, not this repository ($RepoRoot)."
    }
} else {
    Add-Warning "$folder does not exist. Clone the repo there, or link this clone: cmd /c mklink /J `"$folder`" `"$RepoRoot`", then Menu > Apache > Reload."
}

$hostname = [string]$Config.hostname
try {
    $addresses = [System.Net.Dns]::GetHostAddresses($hostname) | ForEach-Object { $_.IPAddressToString }
    if ($addresses -contains '127.0.0.1') { Write-Ok "$hostname resolves to 127.0.0.1" }
    else { Add-Warning "$hostname resolves to $($addresses -join ', '), not 127.0.0.1." }
} catch {
    Add-Warning "$hostname does not resolve. In Laragon choose Menu > Apache > Reload (Auto Virtual Hosts adds the hosts entry)."
}

$profileDir = Join-Path $LaragonRoot 'usr\profile'
$profileFile = Join-Path $profileDir ($Config.laragonProfile + '.ini')
if (Test-Path $profileFile) {
    Write-Ok "Laragon profile $($Config.laragonProfile) already exists (left as it is)"
} else {
    $defaults = @{}
    $defaultIni = Join-Path $profileDir 'default.ini'
    if (Test-Path $defaultIni) {
        $section = $null
        foreach ($line in Get-Content $defaultIni) {
            if ($line -match '^\[(.+)\]') { $section = $Matches[1] }
            elseif ($section -and $line -match '^Version=(.*)$') { $defaults[$section] = $Matches[1].Trim() }
        }
    }
    $values = @{
        php = $phpDir.Name
        mysql = $mysqlDir.Name
        nodejs = $(if ($nodeDir) { $nodeDir.Name } else { $defaults['nodejs'] })
        apache = $defaults['apache']
        redis = $defaults['redis']
        mailpit = $defaults['mailpit']
    }
    $template = Get-Content (Join-Path $RepoRoot 'docs\laragon\apes-newsroom.ini') | Where-Object { $_ -notmatch '^\s*;' }
    $rendered = New-Object System.Collections.Generic.List[string]
    $skipSection = $false
    foreach ($line in $template) {
        if ($line -match '^\[(.+)\]') { $skipSection = -not $values[$Matches[1]] }
        if ($skipSection) { continue }
        $rendered.Add([regex]::Replace($line, '\{\{(\w+)\}\}', { param($m) [string]$values[$m.Groups[1].Value] }))
    }
    New-Item -ItemType Directory -Force -Path $profileDir | Out-Null
    [IO.File]::WriteAllText($profileFile, (($rendered -join "`r`n").Trim() + "`r`n"), (New-Object System.Text.UTF8Encoding($false)))
    Write-Ok "Installed Laragon profile $profileFile (switch with Menu > Profile > $($Config.laragonProfile))"
}

# --- .env ------------------------------------------------------------------
Write-Step 'Checking .env'
Set-Location $RepoRoot
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
}
$lines = @(Get-Content $envFile)
$diffs = @($wanted.Keys | Where-Object { (Get-EnvValue $lines $_) -ne $wanted[$_] })
if ($diffs.Count -eq 0) {
    Write-Ok '.env matches the Laragon profile'
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
    Add-Warning ('.env differs from the Laragon profile for: ' + ($diffs -join ', ') + '. Rerun with -UpdateEnv to fix it (a backup is kept), or edit .env by hand.')
}

$lines = @(Get-Content $envFile)
if ((Get-EnvValue $lines 'APP_ENV') -ne 'local') {
    Add-Warning 'APP_ENV in .env is not local. The role preview and demo data only work with APP_ENV=local.'
}

# --- Dependencies ------------------------------------------------------------
Write-Step 'Installing dependencies'
$vendorStamp = Join-Path $RepoRoot 'vendor\.laragon-setup'
if (-not (Test-Path $vendorStamp) -or (Get-Item (Join-Path $RepoRoot 'composer.lock')).LastWriteTime -gt (Get-Item $vendorStamp).LastWriteTime) {
    Invoke-Checked $ComposerExe ($ComposerArgs + @('install', '--no-interaction', '--no-progress')) 'composer install'
    Set-Content -Path $vendorStamp -Value (Get-Date -Format o)
} else {
    Write-Ok 'vendor/ is up to date'
}

$nodeStamp = Join-Path $RepoRoot 'node_modules\.package-lock.json'
if (-not (Test-Path $nodeStamp) -or (Get-Item (Join-Path $RepoRoot 'package-lock.json')).LastWriteTime -gt (Get-Item $nodeStamp).LastWriteTime) {
    Invoke-Checked 'npm.cmd' @('ci', '--no-audit', '--no-fund') 'npm ci'
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
Invoke-Checked $Mysql ($mysqlArgs + @('-e', "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) 'Creating the database'
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
Write-Host "Site:       $($Config.appUrl)"
Write-Host "Live reload: npm run dev (Vite on http://$($Config.vite.host):$($Config.vite.port)), or composer dev for Vite plus the queue worker"
Write-Host 'Demo logins: docs/laragon/demo-data.md'
