<#
.SYNOPSIS
    Skrip Deployment dan Rollback Otomatis VMS di Windows Server Lokal.
.DESCRIPTION
    Menjalankan alur rilis berstandar produksi lokal offline:
    1. php artisan down
    2. pg_dump -Fc database snapshot pre-deploy
    3. Snapshot versi sebelumnya ke releases/previous
    4. Ekstrak release zip ke direktori aplikasi (preservasi .env & storage/)
    5. php artisan migrate --force
    6. php artisan config:cache, route:cache, view:cache
    7. Restart service VMS-Apache
    8. Probe kesehatan GET /api/health-db (rollback otomatis bila gagal)
    9. php artisan up
    Spec Reference: 2026-09-13-vms-local-server-parity-design.md (§2.6, §2.7).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$ReleaseZip = "",

    [Parameter(Mandatory = $false)]
    [switch]$Rollback,

    [Parameter(Mandatory = $false)]
    [string]$AppDir = "C:\VMS\app",

    [Parameter(Mandatory = $false)]
    [string]$BackupDir = "C:\VMS\backups",

    [Parameter(Mandatory = $false)]
    [string]$ReleasesDir = "C:\VMS\releases",

    [Parameter(Mandatory = $false)]
    [string]$HealthUrl = "https://127.0.0.1/api/health-db",

    [Parameter(Mandatory = $false)]
    [string]$Database = "db_vms",

    [Parameter(Mandatory = $false)]
    [string]$DbUser = "vms_app",

    [Parameter(Mandatory = $false)]
    [switch]$SkipDbBackup
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# Ensure directories exist
foreach ($dir in @($BackupDir, $ReleasesDir)) {
    if (-not (Test-Path -Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}

$previousAppDir = Join-Path $ReleasesDir "previous"
$timestamp = (Get-Date -Format "yyyyMMdd_HHmmss")
$preDeployDump = Join-Path $BackupDir "pre-deploy-$timestamp.dump"

function Invoke-RollbackRoutine {
    Write-Host "====================================================" -ForegroundColor Red
    Write-Host "MEMULAI PROSEDUR ROLLBACK VMS KE VERSI SEBELUMNYA..." -ForegroundColor Red
    Write-Host "====================================================" -ForegroundColor Red

    if (Test-Path "$AppDir\artisan") {
        Push-Location $AppDir
        try { cmd /c "php artisan down" } catch {}
        Pop-Location
    }

    if (Test-Path -Path $previousAppDir) {
        Write-Host "Memulihkan berkas aplikasi dari [$previousAppDir]..." -ForegroundColor Yellow
        Copy-Item -Path (Join-Path $previousAppDir "*") -Destination $AppDir -Recurse -Force
    } else {
        Write-Warning "Direktori cadangan sebelumnya [$previousAppDir] tidak ditemukan."
    }

    # Restore DB dump if exists
    $latestDump = Get-ChildItem -Path $BackupDir -Filter "pre-deploy-*.dump" | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($latestDump) {
        Write-Host "Memulihkan snapshot database dari [$($latestDump.FullName)]..." -ForegroundColor Yellow
        cmd /c "pg_restore -c -U $DbUser -h 127.0.0.1 -d $Database $($latestDump.FullName)"
    }

    if (Test-Path "$AppDir\artisan") {
        Push-Location $AppDir
        try {
            cmd /c "php artisan optimize:clear"
        } catch {}
        Pop-Location
    }

    Write-Host "Merestart service VMS-Apache..." -ForegroundColor Yellow
    Restart-Service -Name "VMS-Apache" -ErrorAction SilentlyContinue

    if (Test-Path "$AppDir\artisan") {
        Push-Location $AppDir
        try { cmd /c "php artisan up" } catch {}
        Pop-Location
    }

    Write-Host "Prosedur Rollback Selesai." -ForegroundColor Green
}

# Handle standalone -Rollback switch
if ($Rollback) {
    Invoke-RollbackRoutine
    exit 0
}

if (-not $ReleaseZip -or (-not (Test-Path -Path $ReleaseZip))) {
    throw "Berkas release ZIP tidak ditemukan di: [$ReleaseZip]. Tentukan berkas ZIP yang valid."
}

Write-Host "====================================================" -ForegroundColor Cyan
Write-Host "Memulai Deployment Paket Rilis: $ReleaseZip" -ForegroundColor Cyan
Write-Host "Target App Dir: $AppDir" -ForegroundColor Cyan
Write-Host "====================================================" -ForegroundColor Cyan

# 1. Maintenance Mode
if (Test-Path "$AppDir\artisan") {
    Write-Host "1. Mengaktifkan Maintenance Mode (php artisan down)..." -ForegroundColor Yellow
    Push-Location $AppDir
    try {
        cmd /c "php artisan down --retry=60"
    } finally {
        Pop-Location
    }
}

# 2. Database Pre-deploy Backup
if (-not $SkipDbBackup) {
    Write-Host "2. Mengambil dump database pre-deploy ke [$preDeployDump]..." -ForegroundColor Yellow
    if (Get-Command pg_dump -ErrorAction SilentlyContinue) {
        cmd /c "pg_dump -Fc -U $DbUser -h 127.0.0.1 -f `"$preDeployDump`" $Database"
    } else {
        Write-Warning "'pg_dump' tidak ditemukan di PATH. Melewati backup database otomatis."
    }
} else {
    Write-Host "2. Melewati backup database (flag -SkipDbBackup aktif)." -ForegroundColor Gray
}

# 3. Snapshot Current App to Releases/previous
if (Test-Path -Path $AppDir) {
    Write-Host "3. Mencadangkan direktori aplikasi saat ini ke [$previousAppDir]..." -ForegroundColor Yellow
    if (Test-Path -Path $previousAppDir) {
        Remove-Item -Path $previousAppDir -Recurse -Force
    }
    New-Item -ItemType Directory -Path $previousAppDir -Force | Out-Null
    
    # Copy app excluding storage runtime files
    Get-ChildItem -Path $AppDir -Exclude "storage" | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $previousAppDir -Recurse -Force
    }
}

# 4. Extract Release Zip Preserving .env & storage/
Write-Host "4. Mengekstrak paket rilis baru ke [$AppDir]..." -ForegroundColor Yellow
$tempExtract = Join-Path $ReleasesDir "extract_$timestamp"
if (Test-Path -Path $tempExtract) { Remove-Item -Path $tempExtract -Recurse -Force }
New-Item -ItemType Directory -Path $tempExtract -Force | Out-Null

Expand-Archive -Path $ReleaseZip -DestinationPath $tempExtract -Force

# Preserve existing .env and storage if present
$savedEnv = $null
if (Test-Path "$AppDir\.env") {
    $savedEnv = Get-Content "$AppDir\.env" -Raw
}

if (-not (Test-Path -Path $AppDir)) {
    New-Item -ItemType Directory -Path $AppDir -Force | Out-Null
}

Copy-Item -Path (Join-Path $tempExtract "*") -Destination $AppDir -Recurse -Force
Remove-Item -Path $tempExtract -Recurse -Force

if ($savedEnv) {
    Set-Content -Path "$AppDir\.env" -Value $savedEnv -Encoding ASCII
}

# 5. Run Database Migrations
Write-Host "5. Menjalankan migrasi database (php artisan migrate --force)..." -ForegroundColor Yellow
Push-Location $AppDir
try {
    cmd /c "php artisan migrate --force"
    if ($LASTEXITCODE -ne 0) {
        throw "Gagal menjalankan migrasi database."
    }

    # 6. Optimize Caches
    Write-Host "6. Mengoptimasi cache konfigurasi, route, dan view..." -ForegroundColor Yellow
    cmd /c "php artisan config:cache"
    cmd /c "php artisan route:cache"
    cmd /c "php artisan view:cache"
} finally {
    Pop-Location
}

# 7. Restart Apache Service
Write-Host "7. Merestart service VMS-Apache..." -ForegroundColor Yellow
Restart-Service -Name "VMS-Apache" -ErrorAction SilentlyContinue

# 8. Health Check Probe
Write-Host "8. Melakukan uji probe kesehatan ke [$HealthUrl]..." -ForegroundColor Yellow
$probeSuccess = $false
try {
    # In PowerShell 5.1/7, ignore cert errors for local probing if needed
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = {$true}
    $response = Invoke-RestMethod -Uri $HealthUrl -TimeoutSec 10 -ErrorAction Stop
    $probeSuccess = $true
    Write-Host "Probe kesehatan BERHASIL: $response" -ForegroundColor Green
} catch {
    Write-Warning "Uji probe kesehatan gagal: $($_.Exception.Message)"
    $probeSuccess = $false
}

if (-not $probeSuccess) {
    Write-Error "Deployment GAGAL memenuhi uji kesehatan. Memulai proses Rollback otomatis!"
    Invoke-RollbackRoutine
    exit 1
}

# 9. Leave Maintenance Mode
Write-Host "9. Menonaktifkan maintenance mode (php artisan up)..." -ForegroundColor Yellow
Push-Location $AppDir
try {
    cmd /c "php artisan up"
} finally {
    Pop-Location
}

Write-Host "====================================================" -ForegroundColor Green
Write-Host "DEPLOYMENT VMS BERHASIL 100%!" -ForegroundColor Green
Write-Host "====================================================" -ForegroundColor Green
exit 0
