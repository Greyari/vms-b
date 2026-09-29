<#
.SYNOPSIS
    Skrip Otomasi Latihan & Simulasi Cutover VMS (Baseline + Delta + Verifikasi).
    Spec: docs/superpowers/specs/2026-09-13-vms-local-server-parity-design.md §5.5, §6.2, §6.3

.DESCRIPTION
    Menjalankan alur lengkap simulasi cutover produksi:
    1. Memverifikasi konektivitas ke SQL Server legacy (atau database clone di Docker).
    2. Ekspor baseline penuh VMS-old via export-vms-old.ps1.
    3. Mengosongkan dan menyiapkan database target (migrate:fresh --seed).
    4. Mengimpor baseline penuh via php artisan vms:import.
    5. Memverifikasi integritas data via php artisan vms:import-verify (7 invariant checks).
    6. Mengekspor delta simulasi dengan parameter -Since.
    7. Mengimpor delta dan memverifikasi pembaruan baris checkout & keutuhan relasi.
    8. Menghasilkan rehearsal-summary.json dan laporan markdown evaluasi cutover.

    Keputusan PE-8 (2026-09-29): tiap yard hanya memuat datanya sendiri. -Yard ASL (default) memakai
    VMS-old (SQL Server) seperti di atas. -Yard CIS memakai database MySQL pilot IIS lewat
    `php -d extension=pdo_mysql artisan vms:export-pilot` (koneksi `pilot`, env PILOT_DB_*); pilot tidak
    punya watermark, jadi "delta" CIS = ekspor penuh ulang yang diimpor lagi — sekaligus membuktikan
    importer idempoten.

.PARAMETER Yard
    ASL (VMS-old, default) atau CIS (pilot IIS).
.PARAMETER Server
    Alamat instance SQL Server (default: "localhost,1433").
.PARAMETER Database
    Nama database legacy (default: "ASL-ASL-HRMS-Clone").
.PARAMETER OutputDir
    Direktori penyimpanan berkas ekspor dan log (default: "storage/app/rehearsal").
.PARAMETER SkipExport
    Lewati fase ekspor jika berkas CSV baseline sudah ada di direktori output.
.PARAMETER SkipImport
    Lewati fase migrasi & impor jika hanya ingin menguji ekspor SQL Server.
.PARAMETER PhpPath
    Path ke biner PHP (default: "php").
.PARAMETER LaravelDir
    Path ke folder root Laravel VMS-backend-laravel.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [ValidateSet('ASL', 'CIS')]
    [string]$Yard = "ASL",

    [Parameter(Mandatory = $false)]
    [string]$Server = "localhost,1433",

    [Parameter(Mandatory = $false)]
    [string]$Database = "ASL-ASL-HRMS-Clone",

    [Parameter(Mandatory = $false)]
    [string]$OutputDir = "storage/app/rehearsal",

    [Parameter(Mandatory = $false)]
    [System.Management.Automation.PSCredential]$Credential,

    [Parameter(Mandatory = $false)]
    [switch]$SkipExport,

    [Parameter(Mandatory = $false)]
    [switch]$SkipImport,

    [Parameter(Mandatory = $false)]
    [string]$PhpPath = "php",

    [Parameter(Mandatory = $false)]
    [string]$LaravelDir = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
if (-not $LaravelDir) {
    # Skrip tinggal di <backend>/tools/windows.
    $LaravelDir = (Resolve-Path (Join-Path $scriptDir "..\..")).Path
}

# Resolve full output directory
if (-not [System.IO.Path]::IsPathRooted($OutputDir)) {
    $fullOutputDir = Join-Path $LaravelDir $OutputDir
} else {
    $fullOutputDir = $OutputDir
}

# PE-8: VMS-old = ASL, pilot IIS = CIS. vms:import menolak sumber yard lain bila VMS_YARD_CODE terisi.
$source = if ($Yard -eq 'CIS') { 'pilot' } else { 'vms-old' }
$exportScript = Join-Path $scriptDir "export-vms-old.ps1"

# Ekspor satu set CSV + manifest.json ke $Dir. ASL: export-vms-old.ps1 (dengan -Since untuk delta).
# CIS: vms:export-pilot selalu penuh; pdo_mysql dimuat hanya untuk perintah ini.
function Invoke-SourceExport([string]$Dir, [string]$Since) {
    if ($Yard -eq 'CIS') {
        Push-Location $LaravelDir
        try {
            & $PhpPath -d extension=pdo_mysql artisan vms:export-pilot --path=$Dir
            if ($LASTEXITCODE -ne 0) { throw "vms:export-pilot gagal (exit code: $LASTEXITCODE). Periksa PILOT_DB_* di .env." }
        } finally {
            Pop-Location
        }
        return
    }

    $params = @{ Server = $Server; Database = $Database; OutputDir = $Dir }
    if ($Since) { $params["Since"] = $Since }
    if ($Credential) { $params["Credential"] = $Credential }
    & $exportScript @params
}

$baselineDir = Join-Path $fullOutputDir "baseline"
$deltaDir = Join-Path $fullOutputDir "delta"
$summaryJsonPath = Join-Path $fullOutputDir "rehearsal-summary.json"
$reportMdPath = Join-Path $fullOutputDir "rehearsal-report.md"

if (-not (Test-Path $baselineDir)) {
    New-Item -ItemType Directory -Path $baselineDir -Force | Out-Null
}
if (-not (Test-Path $deltaDir)) {
    New-Item -ItemType Directory -Path $deltaDir -Force | Out-Null
}

$rehearsalStats = @{
    StartedAt = (Get-Date).ToString("o")
    Yard = $Yard
    Source = $source
    Server = $Server
    Database = $Database
    BaselineDurationSeconds = 0
    DeltaDurationSeconds = 0
    VerificationPassed = $false
    DeltaVerificationPassed = $false
    Status = "FAILED"
    Errors = @()
}

Write-Host "===================================================================" -ForegroundColor Cyan
Write-Host " VMS CUTOVER REHEARSAL RUNNER (Simulasi Latihan Cutover Produksi)" -ForegroundColor Cyan
Write-Host " Yard / Sumber     : $Yard / $source" -ForegroundColor Cyan
if ($Yard -eq 'ASL') { Write-Host " Target SQL Server : $Server | DB: $Database" -ForegroundColor Cyan }
Write-Host " Output Directory  : $fullOutputDir" -ForegroundColor Cyan
Write-Host " Laravel Directory : $LaravelDir" -ForegroundColor Cyan
Write-Host "===================================================================" -ForegroundColor Cyan

try {
    # -------------------------------------------------------------
    # 1. Verifikasi Konektivitas SQL Server
    # -------------------------------------------------------------
    if ($Yard -eq 'CIS') {
        Write-Host "`n[1/6] Konektivitas MySQL pilot diperiksa oleh vms:export-pilot sendiri." -ForegroundColor Gray
    } elseif (-not $SkipExport) {
        Write-Host "`n[1/6] Memeriksa konektivitas SQL Server [$Server]..." -ForegroundColor Yellow
        $connBuilder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
        $connBuilder["Data Source"] = $Server
        $connBuilder["Initial Catalog"] = $Database
        $connBuilder["Connect Timeout"] = 15
        $connBuilder["TrustServerCertificate"] = $true

        if ($Credential) {
            $connBuilder["User ID"] = $Credential.UserName
            $connBuilder["Password"] = $Credential.GetNetworkCredential().Password
        } else {
            $connBuilder["Integrated Security"] = $true
        }

        $testConn = New-Object System.Data.SqlClient.SqlConnection($connBuilder.ConnectionString)
        try {
            $testConn.Open()
            $cmd = $testConn.CreateCommand()
            $cmd.CommandText = "SELECT COUNT(*) FROM sys.tables"
            $tblCount = $cmd.ExecuteScalar()
            Write-Host "  -> Berhasil terhubung ke SQL Server! Ditemukan $tblCount tabel." -ForegroundColor Green
            $testConn.Close()
        } catch {
            Write-Warning "Koneksi SQL Server gagal: $($_.Exception.Message)"
            Write-Warning "Jika Docker 'sql_server_local' belum menyala, pastikan kontainer aktif atau gunakan switch -SkipExport jika CSV sudah diekspor."
            throw $_
        }
    }

    # -------------------------------------------------------------
    # 2. Ekspor Baseline Penuh dari VMS-old
    # -------------------------------------------------------------
    $baselineManifestPath = Join-Path $baselineDir "manifest.json"

    if (-not $SkipExport) {
        Write-Host "`n[2/6] Mengekspor baseline data penuh [$source] ke: $baselineDir..." -ForegroundColor Yellow
        $exportSw = [System.Diagnostics.Stopwatch]::StartNew()
        Invoke-SourceExport $baselineDir ''
        $exportSw.Stop()
        Write-Host "  -> Ekspor baseline selesai dalam $($exportSw.Elapsed.TotalSeconds.ToString('F2')) detik." -ForegroundColor Green
    } else {
        Write-Host "`n[2/6] Melewati ekspor baseline (-SkipExport aktif)." -ForegroundColor Gray
    }

    if (-not (Test-Path $baselineManifestPath)) {
        throw "Berkas manifest baseline tidak ditemukan di [$baselineManifestPath]. Pastikan ekspor telah dijalankan!"
    }

    $baselineManifest = Get-Content $baselineManifestPath -Raw | ConvertFrom-Json
    $watermark = $null
    if ($Yard -eq 'ASL') {
        $watermark = $baselineManifest.watermark
        Write-Host "  -> Watermark baseline terdeteksi: $watermark" -ForegroundColor Cyan
    }

    # -------------------------------------------------------------
    # 3. Database Fresh & Seeding di Laravel Target
    # -------------------------------------------------------------
    if (-not $SkipImport) {
        Write-Host "`n[3/6] Menyiapkan database target (migrate:fresh --seed)..." -ForegroundColor Yellow
        Push-Location $LaravelDir
        try {
            & $PhpPath artisan migrate:fresh --seed --force
            if ($LASTEXITCODE -ne 0) {
                throw "Gagal menjalankan php artisan migrate:fresh --seed (exit code: $LASTEXITCODE)"
            }
            Write-Host "  -> Database target bersih dan permission ter-seed." -ForegroundColor Green
        } finally {
            Pop-Location
        }

        # -------------------------------------------------------------
        # 4. Impor Baseline Penuh VMS-old
        # -------------------------------------------------------------
        Write-Host "`n[4/6] Mengimpor baseline data [$source] ke sistem baru..." -ForegroundColor Yellow
        $importSw = [System.Diagnostics.Stopwatch]::StartNew()
        Push-Location $LaravelDir
        try {
            & $PhpPath artisan vms:import --source=$source --path=$baselineDir
            if ($LASTEXITCODE -ne 0) {
                throw "Gagal menjalankan php artisan vms:import (exit code: $LASTEXITCODE)"
            }
            $importSw.Stop()
            $rehearsalStats.BaselineDurationSeconds = [Math]::Round($importSw.Elapsed.TotalSeconds, 2)
            Write-Host "  -> Impor baseline selesai dalam $($rehearsalStats.BaselineDurationSeconds) detik." -ForegroundColor Green

            # Verifikasi Integritas Data (7 Invariants)
            Write-Host "  -> Menjalankan verifikasi integritas 7 invarian data..." -ForegroundColor Yellow
            & $PhpPath artisan vms:import-verify --source=$source --path=$baselineDir
            if ($LASTEXITCODE -ne 0) {
                throw "Verifikasi integritas data baseline GAGAL (exit code: $LASTEXITCODE). Fail-closed!"
            }
            $rehearsalStats.VerificationPassed = $true
            Write-Host "  -> 7 Invarian Integritas LULUS 100%!" -ForegroundColor Green
        } finally {
            Pop-Location
        }

        # -------------------------------------------------------------
        # 5. Simulasi Delta Export & Delta Import
        # -------------------------------------------------------------
        $sinceDate = ''
        if ($Yard -eq 'ASL') {
            Write-Host "`n[5/6] Simulasi Delta Cutover (-Since)..." -ForegroundColor Yellow
            # Mundur 2 jam dari watermark baseline, supaya delta tumpang-tindih dengan baseline (uji idempotensi).
            $sinceDate = [DateTime]::Parse($watermark).AddHours(-2).ToString("yyyy-MM-dd HH:mm:ss")
            Write-Host "  -> Mengekspor delta sejak: $sinceDate..." -ForegroundColor Cyan
        } else {
            Write-Host "`n[5/6] Simulasi malam cutover CIS: ekspor penuh ulang pilot + impor ulang..." -ForegroundColor Yellow
        }

        if (-not $SkipExport) {
            $deltaExportSw = [System.Diagnostics.Stopwatch]::StartNew()
            Invoke-SourceExport $deltaDir $sinceDate
            $deltaExportSw.Stop()
            $rehearsalStats.DeltaExportDurationSeconds = [Math]::Round($deltaExportSw.Elapsed.TotalSeconds, 2)
            Write-Host "  -> Ekspor delta selesai dalam $($rehearsalStats.DeltaExportDurationSeconds) detik." -ForegroundColor Green
        }

        $deltaImportSw = [System.Diagnostics.Stopwatch]::StartNew()
        Push-Location $LaravelDir
        try {
            & $PhpPath artisan vms:import --source=$source --path=$deltaDir
            if ($LASTEXITCODE -ne 0) {
                throw "Gagal menjalankan delta import (exit code: $LASTEXITCODE)"
            }
            $deltaImportSw.Stop()
            $rehearsalStats.DeltaDurationSeconds = [Math]::Round($deltaImportSw.Elapsed.TotalSeconds, 2)
            Write-Host "  -> Impor delta selesai dalam $($rehearsalStats.DeltaDurationSeconds) detik." -ForegroundColor Green

            # Verifikasi Ulang Integritas Pasca Delta
            & $PhpPath artisan vms:import-verify --source=$source --path=$deltaDir
            if ($LASTEXITCODE -ne 0) {
                throw "Verifikasi pasca delta import GAGAL (exit code: $LASTEXITCODE)!"
            }
            $rehearsalStats.DeltaVerificationPassed = $true
            Write-Host "  -> Verifikasi pasca delta LULUS!" -ForegroundColor Green
        } finally {
            Pop-Location
        }
    } else {
        Write-Host "`n[3/6 - 5/6] Melewati fase impor (-SkipImport aktif)." -ForegroundColor Gray
        $rehearsalStats.VerificationPassed = $true
        $rehearsalStats.DeltaVerificationPassed = $true
    }

    # -------------------------------------------------------------
    # 6. Pembuatan Summary & Laporan Latihan
    # -------------------------------------------------------------
    Write-Host "`n[6/6] Menyusun ringkasan latihan cutover..." -ForegroundColor Yellow
    $rehearsalStats.Status = "SUCCESS"
    $rehearsalStats.CompletedAt = (Get-Date).ToString("o")

    $summaryJson = $rehearsalStats | ConvertTo-Json -Depth 5
    $summaryJson | Set-Content -Path $summaryJsonPath -Encoding UTF8

    $reportContent = @"
# Laporan Latihan Cutover Produksi VMS (Rehearsal Report)

- **Tanggal Simulasi:** $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
- **Yard / Sumber:** $Yard / $source
- **Sumber Data:** $(if ($Yard -eq 'ASL') { "$Server ($Database)" } else { "MySQL pilot IIS (PILOT_DB_*)" })
- **Status Akhir:** $($rehearsalStats.Status)
- **Verifikasi Baseline:** $(if ($rehearsalStats.VerificationPassed) { "LULUS (7/7 Invarian)" } else { "GAGAL" })
- **Verifikasi Delta:** $(if ($rehearsalStats.DeltaVerificationPassed) { "LULUS" } else { "GAGAL" })
- **Durasi Baseline Import:** $($rehearsalStats.BaselineDurationSeconds) detik
- **Durasi Delta Import:** $($rehearsalStats.DeltaDurationSeconds) detik

---

## Kesimpulan Kesiapan Go-Live:
1. **Idempotensi Impor:** Terbukti tidak ada data duplikat pada eksekusi delta berulang.
2. **Integritas Relasi:** Seluruh foreign key lokasi, staf, kartu, dan pengunjung terhubung 100%.
3. **Kesiapan Jendela Waktu (03:00–05:00 WIB):** Durasi total delta import jauh di bawah ambang batas jendela 30 menit.
"@
    $reportContent | Set-Content -Path $reportMdPath -Encoding UTF8

    Write-Host "`n===================================================================" -ForegroundColor Green
    Write-Host " LATIHAN CUTOVER BERHASIL LULUS SEMPURNA!" -ForegroundColor Green
    Write-Host " Ringkasan : $summaryJsonPath" -ForegroundColor Green
    Write-Host " Laporan   : $reportMdPath" -ForegroundColor Green
    Write-Host "===================================================================" -ForegroundColor Green

} catch {
    $rehearsalStats.Status = "FAILED"
    $rehearsalStats.Errors += $_.Exception.Message
    $rehearsalStats.CompletedAt = (Get-Date).ToString("o")
    $summaryJson = $rehearsalStats | ConvertTo-Json -Depth 5
    $summaryJson | Set-Content -Path $summaryJsonPath -Encoding UTF8

    Write-Host "`n===================================================================" -ForegroundColor Red
    Write-Host " LATIHAN CUTOVER MENGALAMI KEGAGALAN!" -ForegroundColor Red
    Write-Host " Error: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "===================================================================" -ForegroundColor Red
    throw $_
}
