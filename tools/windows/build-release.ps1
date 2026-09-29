<#
.SYNOPSIS
    Skrip Pembuat Paket Rilis Produksi VMS (Gabungan React SPA + Laravel API).
.DESCRIPTION
    Menjalankan proses build rilis mandiri tanpa ketergantungan internet:
    1. Build React SPA (VMS-web-react) dengan VITE_API_BASE_URL=/api.
    2. Menyalin berkas dist/ ke direktori public/ VMS-backend-laravel.
    3. Mengoptimasi autoloader Composer (--no-dev --optimize-autoloader).
    4. Mengemas seluruh artefak produksi ke dalam berkas ZIP rilis bersih.
    Spec Reference: 2026-09-13-vms-local-server-parity-design.md (§2.3, §2.7).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$BackendDir = "",

    [Parameter(Mandatory = $false)]
    [string]$FrontendDir = "",

    [Parameter(Mandatory = $false)]
    [string]$OutputDir = "release",

    [Parameter(Mandatory = $false)]
    [string]$Version = "",

    [Parameter(Mandatory = $false)]
    [switch]$SkipSpaBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not $Version) {
    $Version = (Get-Date -Format "yyyyMMdd_HHmmss")
}

# Skrip ini tinggal di <backend>/tools/windows. Default mengikuti layout laptop
# (VMS-web-react bersebelahan dengan VMS-backend-laravel); CI memberi keduanya eksplisit.
if (-not $BackendDir) { $BackendDir = (Resolve-Path "$PSScriptRoot\..\..").Path }
if (-not $FrontendDir) { $FrontendDir = Join-Path (Split-Path -Parent $BackendDir) "VMS-web-react" }
$backendDir = (Resolve-Path $BackendDir).Path
$frontendDir = $FrontendDir

# Keluaran relatif ditaruh di luar repo backend supaya ZIP tidak pernah ikut ke git.
$resolvedOutputDir = $OutputDir
if (-not [System.IO.Path]::IsPathRooted($resolvedOutputDir)) {
    $resolvedOutputDir = Join-Path (Split-Path -Parent $backendDir) $OutputDir
}

if (-not (Test-Path -Path $resolvedOutputDir)) {
    New-Item -ItemType Directory -Path $resolvedOutputDir -Force | Out-Null
}

$zipFileName = "vms-release-$Version.zip"
$zipFilePath = Join-Path $resolvedOutputDir $zipFileName
$stagingDir = Join-Path $resolvedOutputDir "staging_$Version"

Write-Host "====================================================" -ForegroundColor Cyan
Write-Host "Memulai Pembuatan Paket Rilis VMS Versi: $Version" -ForegroundColor Cyan
Write-Host "Backend : $backendDir" -ForegroundColor Gray
Write-Host "Frontend: $frontendDir" -ForegroundColor Gray
Write-Host "Output  : $zipFilePath" -ForegroundColor Gray
Write-Host "====================================================" -ForegroundColor Cyan

# ------------------------------------------------------------------------------
# 1. Build React SPA & Copy to Laravel Public
# ------------------------------------------------------------------------------
if (-not $SkipSpaBuild) {
    if (Test-Path -Path $frontendDir) {
        Write-Host "1. Membangun React SPA di [$frontendDir]..." -ForegroundColor Yellow
        Push-Location $frontendDir
        try {
            $env:VITE_API_BASE_URL = "/api"
            cmd /c "npm run build"
            if ($LASTEXITCODE -ne 0) {
                throw "Gagal membangun frontend SPA. Periksa log Vite / TypeScript."
            }
        } finally {
            Pop-Location
        }

        $spaDist = Join-Path $frontendDir "dist"
        $backendPublic = Join-Path $backendDir "public"
        Write-Host "Menyalin aset SPA dari [$spaDist] ke [$backendPublic]..." -ForegroundColor Yellow
        Copy-Item -Path (Join-Path $spaDist "*") -Destination $backendPublic -Recurse -Force
    } else {
        Write-Warning "Direktori frontend [$frontendDir] tidak ditemukan. Melewati langkah build SPA."
    }
} else {
    Write-Host "Melewati build SPA (flag -SkipSpaBuild aktif)." -ForegroundColor Gray
}

# ------------------------------------------------------------------------------
# 2. Optimize Composer Autoloader
# ------------------------------------------------------------------------------
Write-Host "2. Mengoptimasi dependensi PHP Composer (no-dev)..." -ForegroundColor Yellow
Push-Location $backendDir
try {
    # Check composer command
    if (Get-Command composer -ErrorAction SilentlyContinue) {
        cmd /c "composer install --no-dev --optimize-autoloader --no-interaction"
    } else {
        Write-Warning "Perintah 'composer' tidak ditemukan di PATH. Menggunakan vendor/ yang ada."
    }
} finally {
    Pop-Location
}

# ------------------------------------------------------------------------------
# 3. Assemble Clean Staging Directory
# ------------------------------------------------------------------------------
Write-Host "3. Menyiapkan direktori staging bersih di [$stagingDir]..." -ForegroundColor Yellow
if (Test-Path -Path $stagingDir) {
    Remove-Item -Path $stagingDir -Recurse -Force
}
New-Item -ItemType Directory -Path $stagingDir -Force | Out-Null

$directoriesToInclude = @("app", "bootstrap", "config", "database", "public", "resources", "routes", "vendor")
foreach ($dir in $directoriesToInclude) {
    $src = Join-Path $backendDir $dir
    if (Test-Path -Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $stagingDir $dir) -Recurse -Force
    }
}

$filesToInclude = @("artisan", "composer.json", "composer.lock")
foreach ($file in $filesToInclude) {
    $src = Join-Path $backendDir $file
    if (Test-Path -Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $stagingDir $file) -Force
    }
}

# Ensure empty runtime storage folders with .gitignore
$storageDirs = @(
    "storage\app\public",
    "storage\framework\cache\data",
    "storage\framework\sessions",
    "storage\framework\views",
    "storage\logs"
)
foreach ($sDir in $storageDirs) {
    $targetDir = Join-Path $stagingDir $sDir
    if (-not (Test-Path -Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    }
    Set-Content -Path (Join-Path $targetDir ".gitignore") -Value "*`n!.gitignore" -Encoding ASCII
}

# ------------------------------------------------------------------------------
# 4. Create Final Release ZIP Archive
# ------------------------------------------------------------------------------
Write-Host "4. Mengompresi paket rilis ke [$zipFilePath]..." -ForegroundColor Yellow
if (Test-Path -Path $zipFilePath) {
    Remove-Item -Path $zipFilePath -Force
}

Compress-Archive -Path "$stagingDir\*" -DestinationPath $zipFilePath -Force

# Clean up staging folder
Remove-Item -Path $stagingDir -Recurse -Force

# Calculate Checksum
$fileHash = Get-FileHash -Path $zipFilePath -Algorithm SHA256
$sizeMb = [math]::Round(((Get-Item $zipFilePath).Length / 1MB), 2)

$manifestData = @{
    version = $Version
    file = $zipFileName
    sha256 = $fileHash.Hash
    size_mb = $sizeMb
    created_at = (Get-Date -Format "yyyy-MM-ddTHH:mm:ssK")
}
$manifestPath = Join-Path $resolvedOutputDir "release-manifest-$Version.json"
$manifestData | ConvertTo-Json -Depth 3 | Set-Content -Path $manifestPath -Encoding UTF8

Write-Host "====================================================" -ForegroundColor Green
Write-Host "Paket rilis berhasil dibuat!" -ForegroundColor Green
Write-Host "Berkas   : $zipFilePath ($sizeMb MB)" -ForegroundColor Green
Write-Host "SHA256   : $($fileHash.Hash)" -ForegroundColor Green
Write-Host "Manifest : $manifestPath" -ForegroundColor Green
Write-Host "====================================================" -ForegroundColor Green
