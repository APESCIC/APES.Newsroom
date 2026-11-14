<#
.SYNOPSIS
    Live preview gate, steps 1 and 2: load the latest code, then check the
    Herd preview serves it. Run before any development work.

.DESCRIPTION
    1. Stops if tracked files have uncommitted changes. Fetches origin, fast-
       forwards the branch, and merges origin/main into it (a merge commit, never
       a rebase; a conflict is aborted and reported). Nothing is pushed.
    2. Runs composer install or npm ci when a lock file is newer than the
       installed dependencies, and reports migrations that have not run (it
       never runs them).
    3. Checks Herd, nginx and MySQL are running and the site serves the
       checked-out code: /health shows the revision, the profile's PHP version
       and FastCGI.
    4. Starts Vite in its own window if nothing listens on its port.

    Opening the page in Cursor's browser is the agent's step 3.

    Exit codes: 0 ready, 1 blocked (the message says why), 3 needs a decision.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1
#>
[CmdletBinding()]
param(
    [switch]$NoSync,
    [switch]$NoMergeMain,
    [switch]$NoDevServer,
    [string]$HerdRoot = (Join-Path $env:USERPROFILE '.config\herd')
)

$ErrorActionPreference = 'Stop'

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message) { Write-Host "    $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { Write-Host "    WARNING: $Message" -ForegroundColor Yellow }
function Stop-Preview([int]$Code, [string]$Message) { Write-Host "    STOP: $Message" -ForegroundColor Red; exit $Code }

function Invoke-Native([scriptblock]$Command) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = & $Command 2>&1 } finally { $ErrorActionPreference = $previous }
    return [pscustomobject]@{ Code = $LASTEXITCODE; Output = ($output | Out-String).Trim() }
}

function Get-Page([string]$Url) {
    try {
        $content = (Invoke-WebRequest $Url -UseBasicParsing -TimeoutSec 60).Content
        if ($content -is [byte[]]) { $content = [Text.Encoding]::UTF8.GetString($content) }
        return $content
    } catch {
        $stream = $_.Exception.Response.GetResponseStream()
        if ($null -eq $stream) { return $null }
        return (New-Object IO.StreamReader $stream).ReadToEnd()
    }
}

$repo = Split-Path $PSScriptRoot -Parent
$p = Get-Content (Join-Path $repo 'docs\herd\profile.json') -Raw | ConvertFrom-Json
$herdBin = Join-Path $HerdRoot 'bin'
$phpDir = Join-Path $herdBin ('php' + ($p.php.version -replace '\.', ''))
if (-not (Test-Path (Join-Path $phpDir 'php.exe'))) { Stop-Preview 1 "Herd has no PHP $($p.php.version) in $phpDir. Run scripts\herd-setup.ps1." }
$composerPhar = Join-Path $herdBin 'composer.phar'
$nodeDir = Get-ChildItem (Join-Path $herdBin 'nvm') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match "^v$($p.node.version)\.\d+\.\d+$" } |
    Sort-Object { [version]($_.Name.TrimStart('v')) } -Descending |
    Select-Object -First 1
if (-not $nodeDir) { Stop-Preview 1 "Herd's nvm has no Node $($p.node.version). Turn on Node.js in Herd > Settings, then run: herd node:install $($p.node.version)" }
$env:Path = "$phpDir;$herdBin;$($nodeDir.FullName);" + $env:Path
if (-not $env:XDEBUG_MODE) { $env:XDEBUG_MODE = 'off' }

Push-Location $repo
try {
    # 1. Latest code ------------------------------------------------------------
    Write-Step 'Latest code'
    $before = (git rev-parse HEAD).Trim()
    $branch = (git branch --show-current).Trim()
    if ($NoSync) {
        Write-Warn 'Skipped the sync (-NoSync)'
    } else {
        $dirty = @(git status --porcelain --untracked-files=no)
        if ($dirty.Count -gt 0) { Stop-Preview 3 "$($dirty.Count) tracked files have uncommitted changes. Commit them or decide what to do before syncing." }
        $fetch = Invoke-Native { git fetch origin --prune --tags }
        if ($fetch.Code -ne 0) { Stop-Preview 1 "git fetch failed: $($fetch.Output)" }
        $upstream = Invoke-Native { git rev-parse --abbrev-ref '@{u}' }
        if ($upstream.Code -eq 0) {
            $ff = Invoke-Native { git merge --ff-only '@{u}' }
            if ($ff.Code -ne 0) { Stop-Preview 3 "$branch and $($upstream.Output) have both moved on. Merge them (no rebase), then run this again." }
        }
        if ($branch -ne 'main' -and -not $NoMergeMain) {
            $behind = [int](git rev-list --count 'HEAD..origin/main').Trim()
            if ($behind -gt 0) {
                $merge = Invoke-Native { git merge --no-edit origin/main }
                if ($merge.Code -ne 0) {
                    Invoke-Native { git merge --abort } | Out-Null
                    Stop-Preview 3 "Merging origin/main into $branch conflicts. The merge was aborted; resolve it before starting work."
                }
                Write-Ok "Merged $behind commits from origin/main into $branch (not pushed)"
            }
        }
    }
    $head = (git rev-parse HEAD).Trim()
    $short = $head.Substring(0, 12)
    Write-Ok "$branch at $short"

    # 2. Dependencies and migrations --------------------------------------------
    Write-Step 'Dependencies'
    $installed = Join-Path $repo 'vendor\composer\installed.json'
    if (-not (Test-Path $installed) -or (Get-Item 'composer.lock').LastWriteTime -gt (Get-Item $installed).LastWriteTime) {
        $run = Invoke-Native { php $composerPhar install --no-interaction --no-progress }
        if ($run.Code -ne 0) { Stop-Preview 1 "composer install failed: $($run.Output)" }
        # Composer leaves installed.json alone when nothing changed, which would re-run this every time.
        (Get-Item $installed).LastWriteTime = Get-Date
        Write-Ok 'Ran composer install'
    } else { Write-Ok 'Composer dependencies are current' }
    $npmMarker = Join-Path $repo 'node_modules\.package-lock.json'
    if (-not (Test-Path $npmMarker) -or (Get-Item 'package-lock.json').LastWriteTime -gt (Get-Item $npmMarker).LastWriteTime) {
        $run = Invoke-Native { npm ci --no-audit --no-fund }
        if ($run.Code -ne 0) { Stop-Preview 1 "npm ci failed: $($run.Output)" }
        Write-Ok 'Ran npm ci (restart the dev server if it was running)'
    } else { Write-Ok 'npm dependencies are current' }
    $pending = Invoke-Native { php artisan migrate:status --pending --no-ansi }
    if ($pending.Code -eq 0 -and $pending.Output -match '\bPending\b' -and $pending.Output -notmatch 'No pending migrations') {
        Write-Warn 'There are migrations that have not run. Ask before running php artisan migrate.'
    } else { Write-Ok 'No pending migrations' }

    # 3. Herd and the preview ----------------------------------------------------
    Write-Step "Herd preview $($p.appUrl)"
    $missing = @('Herd', 'nginx' | Where-Object { -not (Get-Process $_ -ErrorAction SilentlyContinue) })
    if ($missing.Count -gt 0) { Stop-Preview 1 "Not running: $($missing -join ', '). Open Herd." }
    if (-not (Get-NetTCPConnection -State Listen -LocalPort $p.database.port -ErrorAction SilentlyContinue)) { Stop-Preview 1 "Nothing listens on MySQL's port $($p.database.port). Start Herd's MySQL service (Herd > Services)." }
    $health = Get-Page "$($p.appUrl)/health" | ConvertFrom-Json
    if ($null -eq $health -or $null -eq $health.local) { Stop-Preview 1 "$($p.appUrl)/health did not answer with the local block. Is APP_ENV=local in .env, and is the site linked ('herd links')? Run scripts\herd-setup.ps1." }
    if ($health.local.git_revision -ne $short) {
        Invoke-Native { php artisan optimize:clear } | Out-Null
        $health = Get-Page "$($p.appUrl)/health" | ConvertFrom-Json
    }
    if ($health.local.git_revision -ne $short) { Stop-Preview 1 "The preview serves $($health.local.git_revision), not $short. Check 'herd links' shows $($p.herdSite) pointing at this clone." }
    if ($health.local.php_sapi -ne $p.php.sapi) { Stop-Preview 1 "The preview runs PHP as $($health.local.php_sapi), not FastCGI ($($p.php.sapi)). Run 'herd restart'." }
    if ($health.local.php -notlike "$($p.php.version).*") { Stop-Preview 1 "The preview runs PHP $($health.local.php), not $($p.php.version). Pin the site with: herd isolate $($p.php.version) --site=$($p.herdSite)" }
    Write-Ok "Serves $short on PHP $($health.local.php) through FastCGI"
    if ($health.status -ne 'ok') { Write-Warn "/health reports $($health.status). The preview still works; start the Herd service it needs if the task needs it." }

    # 4. Vite --------------------------------------------------------------------
    Write-Step "Vite on port $($p.vite.port)"
    if (Get-NetTCPConnection -State Listen -LocalPort $p.vite.port -ErrorAction SilentlyContinue) {
        Write-Ok 'Already running'
    } elseif ($NoDevServer) {
        Write-Warn "Not running (-NoDevServer). Start it with npm run dev, or: herd processes:start vite --site=$($p.herdSite)"
    } else {
        Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', 'npm run dev' -WorkingDirectory $repo -WindowStyle Minimized
        Write-Ok 'Started npm run dev in its own window'
    }

    Write-Step 'Ready'
    Write-Ok "Open $($p.appUrl) in Cursor's browser (live reload on port $($p.vite.port))."
    if ($before -ne $head) { Write-Ok "Code moved from $($before.Substring(0, 12)) to $short during the sync." }
} finally {
    Pop-Location
}
