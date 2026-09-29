<#
.SYNOPSIS
    Builds the VMS release ZIP (Laravel API + React SPA) on a developer laptop or CI runner.

.DESCRIPTION
    Hybrid decision OPS-4 and parity spec sections 2.3, 2.7:
      1. Copies the backend source into a clean STAGING folder (never touches the working checkout).
      2. Builds the SPA once with VITE_API_BASE_URL=/api and copies dist\ into staging\public\.
      3. Runs `composer install --no-dev --optimize-autoloader` inside staging, so the checkout keeps its
         dev dependencies.
      4. Includes tools\windows\ - every release carries its own deploy.ps1 and server scripts. The server
         runs a copy of deploy.ps1 from outside C:\VMS\app (INSTALL-GUIDE section 7), because the app
         folder is moved during a deploy.
      5. Zips staging, writes a manifest with the SHA-256 and the backend and frontend commits.

    No .env, no tests, no node_modules and no storage contents ever enter the ZIP.

.EXAMPLE
    .\tools\windows\build-release.ps1
    .\tools\windows\build-release.ps1 -BackendDir D:\a\backend -FrontendDir D:\a\frontend -OutputDir D:\a\release
#>
[CmdletBinding()]
param(
    [string] $BackendDir = '',
    [string] $FrontendDir = '',
    [string] $OutputDir = 'release',
    [string] $Version = '',
    [switch] $SkipSpaBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not $Version) { $Version = Get-Date -Format 'yyyyMMdd_HHmmss' }

# The script lives in <backend>\tools\windows. Defaults follow the laptop layout (VMS-web-react next to
# VMS-backend-laravel); CI passes both explicitly.
if (-not $BackendDir) { $BackendDir = (Resolve-Path "$PSScriptRoot\..\..").Path }
if (-not $FrontendDir) { $FrontendDir = Join-Path (Split-Path -Parent $BackendDir) 'VMS-web-react' }
$backendDir = (Resolve-Path $BackendDir).Path

# A relative output folder sits next to the backend repo, so a ZIP never lands inside git.
$outDir = $OutputDir
if (-not [System.IO.Path]::IsPathRooted($outDir)) { $outDir = Join-Path (Split-Path -Parent $backendDir) $OutputDir }
if (-not (Test-Path -LiteralPath $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }

$zipName = "vms-release-$Version.zip"
$zipPath = Join-Path $outDir $zipName
$staging = Join-Path $outDir "staging_$Version"

# npm, composer and git write progress to stderr. Under Windows PowerShell 5.1 with
# ErrorActionPreference=Stop that would abort the script, so native tools run with Continue and are
# judged by their exit code only.
function Invoke-Native([string] $Exe, [string[]] $Arguments) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $Exe @Arguments 2>&1 | ForEach-Object { "$_" } | Out-Host
        return $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
}

function Get-Commit([string] $Dir) {
    if (-not (Test-Path -LiteralPath (Join-Path $Dir '.git'))) { return $null }
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $sha = & git -C $Dir rev-parse HEAD 2>$null
        if ($LASTEXITCODE -eq 0) { return "$sha".Trim() } else { return $null }
    } finally {
        $ErrorActionPreference = $previous
    }
}

Write-Host "== VMS release $Version" -ForegroundColor Cyan
Write-Host "   backend : $backendDir"
Write-Host "   frontend: $FrontendDir"
Write-Host "   output  : $zipPath"

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { throw 'composer is not on PATH: a release without vendor\ cannot run.' }

# 1. Staging copy of the backend
if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force }
New-Item -ItemType Directory -Path $staging -Force | Out-Null
foreach ($dir in @('app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tools')) {
    $src = Join-Path $backendDir $dir
    if (Test-Path -LiteralPath $src) { Copy-Item -LiteralPath $src -Destination (Join-Path $staging $dir) -Recurse -Force }
}
foreach ($file in @('artisan', 'composer.json', 'composer.lock')) {
    Copy-Item -LiteralPath (Join-Path $backendDir $file) -Destination (Join-Path $staging $file) -Force
}
# Compiled caches from the laptop must not travel (they hold laptop paths).
Get-ChildItem -LiteralPath (Join-Path $staging 'bootstrap\cache') -Filter '*.php' -ErrorAction SilentlyContinue |
    ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
# Each yard's logo stays on its own server (decision PE-7); a laptop copy must not ship to every yard.
Remove-Item -LiteralPath (Join-Path $staging 'public\branding') -Recurse -Force -ErrorAction SilentlyContinue

# 2. SPA, built once, into staging\public
if (-not $SkipSpaBuild) {
    if (-not (Test-Path -LiteralPath $FrontendDir)) { throw "Frontend folder not found: $FrontendDir" }
    Write-Host '== Building the SPA (VITE_API_BASE_URL=/api)' -ForegroundColor Yellow
    Push-Location $FrontendDir
    try {
        $env:VITE_API_BASE_URL = '/api'
        if ((Invoke-Native 'npm.cmd' @('run', 'build')) -ne 0) { throw 'SPA build failed (tsc -b / vite build).' }
    } finally {
        Pop-Location
    }
    Copy-Item -Path (Join-Path $FrontendDir 'dist\*') -Destination (Join-Path $staging 'public') -Recurse -Force
}

# 3. Production dependencies inside staging only
Write-Host '== composer install --no-dev (staging)' -ForegroundColor Yellow
Push-Location $staging
try {
    $composer = (Get-Command composer).Source
    if ((Invoke-Native $composer @('install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress')) -ne 0) {
        throw 'composer install failed in staging.'
    }
} finally {
    Pop-Location
}

# 4. Empty runtime folders
foreach ($dir in @('storage\app\public', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs')) {
    $target = Join-Path $staging $dir
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $target '.gitignore') -Value "*`n!.gitignore" -Encoding ASCII
}

# 5. ZIP + manifest
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $zipPath -Force
Remove-Item -LiteralPath $staging -Recurse -Force

$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash
$manifest = [ordered]@{
    version = $Version
    file = $zipName
    sha256 = $hash
    size_mb = [math]::Round((Get-Item -LiteralPath $zipPath).Length / 1MB, 2)
    backend_commit = Get-Commit $backendDir
    frontend_commit = if ($SkipSpaBuild) { $null } else { Get-Commit $FrontendDir }
    created_at = Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK'
}
$manifestPath = Join-Path $outDir "release-manifest-$Version.json"
$manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8

Write-Host "== Release ready: $zipPath" -ForegroundColor Green
Write-Host "   SHA256  : $hash"
Write-Host "   Manifest: $manifestPath"
Write-Host "   Deploy: copy tools\windows\deploy.ps1 to C:\VMS\tools\ and run it there with -ReleaseZip <zip> (INSTALL-GUIDE section 7)"
