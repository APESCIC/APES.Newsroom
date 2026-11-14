<#
.SYNOPSIS
    Put Herd's PHP, Composer and Node for APES Newsroom first on PATH for this
    PowerShell session.

.DESCRIPTION
    Reads docs/herd/profile.json and picks Herd's PHP build for the profile's
    version (8.4) and the newest Node of the profile's major version (22) from
    Herd's nvm, so the command line uses the same PHP and php.ini as the site.
    Also sets XDEBUG_MODE=off. Changes nothing on disk.

    Dot-source it, so the settings stay in your terminal:

        . .\scripts\herd-env.ps1

.PARAMETER HerdRoot
    Herd's folder. Defaults to %USERPROFILE%\.config\herd.
#>
[CmdletBinding()]
param(
    [string]$HerdRoot = (Join-Path $env:USERPROFILE '.config\herd')
)

$herdProfile = Get-Content (Join-Path (Split-Path $PSScriptRoot -Parent) 'docs\herd\profile.json') -Raw | ConvertFrom-Json
$phpDir = Join-Path $HerdRoot "bin\php$($herdProfile.php.version -replace '\.', '')"
$nodeDir = Get-ChildItem (Join-Path $HerdRoot 'bin\nvm') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match "^v$($herdProfile.node.version)\.\d+\.\d+$" } |
    Sort-Object { [version]($_.Name.TrimStart('v')) } -Descending |
    Select-Object -First 1

if (-not (Test-Path (Join-Path $phpDir 'php.exe'))) {
    Write-Warning "Herd has no PHP $($herdProfile.php.version) in $phpDir. Install it with: herd php:install $($herdProfile.php.version)"
    return
}
if (-not $nodeDir) {
    Write-Warning "Herd's nvm has no Node $($herdProfile.node.version). Turn on Node.js in Herd > Settings, then run: herd node:install $($herdProfile.node.version)"
    return
}

$env:Path = "$phpDir;$(Join-Path $HerdRoot 'bin');$($nodeDir.FullName);" + $env:Path
$env:XDEBUG_MODE = 'off'
Write-Host "Herd PHP $($herdProfile.php.version) ($phpDir), Node $($nodeDir.Name) and Composer are first on PATH; XDEBUG_MODE=off."
