<#
.SYNOPSIS
    Skrip Pencadangan Database Harian PostgreSQL 18 VMS.
.DESCRIPTION
    Mengeksekusi pg_dump -Fc ke direktori lokal C:\VMS\backups\daily,
    membersihkan arsip dump yang melewati masa retensi (default: 30 hari),
    dan menyalin berkas dump ke secondary disk / LAN network share.
    Spec Reference: 2026-09-13-vms-local-server-parity-design.md (§2.8).

    Task Scheduler menjalankannya sebagai SYSTEM tanpa terminal, jadi pg_dump memakai -w (tidak pernah
    bertanya password) dan membaca password dari -PgPassFile (C:\VMS\secrets\pgpass.conf, ditulis oleh
    setup-pgpass.ps1). Tanpa berkas itu backup gagal dengan pesan jelas, bukan menggantung (OPS-5f).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$Database = "db_vms",

    [Parameter(Mandatory = $false)]
    [string]$OutputDir = "C:\VMS\backups\daily",

    [Parameter(Mandatory = $false)]
    [string]$SecondaryDir = "",

    [Parameter(Mandatory = $false)]
    [int]$RetentionDays = 30,

    [Parameter(Mandatory = $false)]
    [string]$PgDumpPath = "pg_dump",

    [Parameter(Mandatory = $false)]
    [string]$DbUser = "vms_app",

    [Parameter(Mandatory = $false)]
    [string]$DbHost = "127.0.0.1",

    [Parameter(Mandatory = $false)]
    [int]$DbPort = 5432,

    [Parameter(Mandatory = $false)]
    [string]$PgPassFile = "C:\VMS\secrets\pgpass.conf"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not (Test-Path -Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$dumpFileName = "$Database-$timestamp.dump"
$dumpFilePath = Join-Path $OutputDir $dumpFileName
$logFile = Join-Path $OutputDir "backup.log"

$logMessage = "[$((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))] Memulai backup database [$Database] ke [$dumpFilePath]"
Write-Host $logMessage -ForegroundColor Cyan
Add-Content -Path $logFile -Value $logMessage

# Resolve pg_dump binary
$resolvedPgDump = $null
if (Get-Command $PgDumpPath -ErrorAction SilentlyContinue) {
    $resolvedPgDump = $PgDumpPath
} elseif (Test-Path "C:\Program Files\PostgreSQL\18\bin\pg_dump.exe") {
    $resolvedPgDump = "C:\Program Files\PostgreSQL\18\bin\pg_dump.exe"
} else {
    $resolvedPgDump = "pg_dump"
}

if (Test-Path -LiteralPath $PgPassFile) {
    $env:PGPASSFILE = $PgPassFile
} else {
    Add-Content -Path $logFile -Value "[$((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))] PERINGATAN: $PgPassFile tidak ada - jalankan setup-pgpass.ps1"
}

# Run pg_dump. Called directly (not through cmd.exe) so a path with spaces works; stderr is not fatal under
# PowerShell 5.1, only the exit code and the file decide.
$previousPreference = $ErrorActionPreference
$ErrorActionPreference = "Continue"
try {
    & $resolvedPgDump -Fc -w -U $DbUser -h $DbHost -p $DbPort -f $dumpFilePath $Database 2>&1 |
        ForEach-Object { Add-Content -Path $logFile -Value "  pg_dump: $_" }
    $dumpExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousPreference
}

if ($dumpExit -ne 0 -or (-not (Test-Path -Path $dumpFilePath)) -or (Get-Item $dumpFilePath).Length -eq 0) {
    $errMsg = "[$((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))] GAGAL: Eksekusi pg_dump menghasilkan kode error $dumpExit"
    Write-Error $errMsg
    Add-Content -Path $logFile -Value $errMsg
    exit 1
}

$dumpSizeMb = [math]::Round(((Get-Item $dumpFilePath).Length / 1MB), 2)
$successMsg = "[$((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))] SUKSES: Backup selesai. Ukuran: $dumpSizeMb MB"
Write-Host $successMsg -ForegroundColor Green
Add-Content -Path $logFile -Value $successMsg

# Secondary Copy (Disk 2 / Share LAN)
if ($SecondaryDir -and (Test-Path -Path $SecondaryDir)) {
    try {
        $secondaryTarget = Join-Path $SecondaryDir $dumpFileName
        Copy-Item -Path $dumpFilePath -Destination $secondaryTarget -Force
        $secMsg = "[$((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))] SUKSES: Salinan sekunder tersimpan di [$secondaryTarget]"
        Write-Host $secMsg -ForegroundColor Green
        Add-Content -Path $logFile -Value $secMsg
    } catch {
        $secWarn = "[$((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))] PERINGATAN: Gagal menyalin ke secondary dir: $($_.Exception.Message)"
        Write-Warning $secWarn
        Add-Content -Path $logFile -Value $secWarn
    }
}

# Retention Cleanup
$cutoffDate = (Get-Date).AddDays(-$RetentionDays)
$oldFiles = Get-ChildItem -Path $OutputDir -Filter "$Database-*.dump" | Where-Object { $_.LastWriteTime -lt $cutoffDate }

foreach ($oldFile in $oldFiles) {
    try {
        Remove-Item -Path $oldFile.FullName -Force
        $purgeMsg = "[$((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))] RETENSI: Berkas lawas [$($oldFile.Name)] dihapus (> $RetentionDays hari)"
        Write-Host $purgeMsg -ForegroundColor Gray
        Add-Content -Path $logFile -Value $purgeMsg
    } catch {
        Write-Warning "Gagal menghapus berkas lawas: $($oldFile.FullName)"
    }
}
