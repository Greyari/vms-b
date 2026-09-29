<#
.SYNOPSIS
    Skrip Audit Kinerja SLA Produksi VMS (Spec §6.4).
    Target:
    1. Pencarian identitas/nama saat check-in: < 1.000 ms
    2. Kueri laporan visitor rentang satu bulan: < 3.000 ms
    3. Ekspor streaming Excel satu bulan: < 30.000 ms

.DESCRIPTION
    Menjalankan pengujian latensi nyata terhadap endpoint REST API Laravel:
    - Mengautentikasi akun jika -ApiToken tidak disediakan.
    - Melakukan kueri warm-up untuk meniadakan efek disk cache cold-start.
    - Mengukur waktu respons milidetik pada 3 skenario SLA Spec §6.4.
    - Membandingkan hasil aktual terhadap batas ambang dan mencetak tabel hasil.

.PARAMETER BaseUrl
    Alamat root server web VMS (default: "http://127.0.0.1:8000" atau "https://127.0.0.1").
.PARAMETER Username
    Username akun penguji (default: "admin").
.PARAMETER Password
    Password akun penguji (default: "password").
.PARAMETER ApiToken
    Sanctum Bearer token jika sudah login sebelumnya.
.PARAMETER TestIdNumber
    Nomor identitas KTP/Paspor untuk benchmark pencarian (default: "1234567890123456").
.PARAMETER Month
    Bulan pelaporan yang diuji (format: "YYYY-MM", default bulan berjalan).
.PARAMETER OutputDir
    Direktori untuk menyimpan ringkasan hasil audit (opsional).
.PARAMETER SkipTlsVerify
    Abaikan verifikasi SSL jika menggunakan HTTPS dengan CA internal.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$BaseUrl = "http://127.0.0.1:8000",

    [Parameter(Mandatory = $false)]
    [string]$Username = "admin",

    [Parameter(Mandatory = $false)]
    [string]$Password = "password",

    [Parameter(Mandatory = $false)]
    [string]$ApiToken = "",

    [Parameter(Mandatory = $false)]
    [string]$TestIdNumber = "1234567890123456",

    [Parameter(Mandatory = $false)]
    [string]$Month = "",

    [Parameter(Mandatory = $false)]
    [string]$OutputDir = "",

    [Parameter(Mandatory = $false)]
    [switch]$SkipTlsVerify
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if ($SkipTlsVerify) {
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
}

if (-not $Month) {
    $Month = (Get-Date).ToString("yyyy-MM")
}

$startDate = "$Month-01"
$dtMonth = [DateTime]::ParseExact("$Month-01", "yyyy-MM-dd", [System.Globalization.CultureInfo]::InvariantCulture)
$daysInMonth = [DateTime]::DaysInMonth($dtMonth.Year, $dtMonth.Month)
$endDate = "$Month-$($daysInMonth.ToString('D2'))"

Write-Host "===================================================================" -ForegroundColor Cyan
Write-Host " VMS PERFORMANCE AUDIT (SLA SPEC §6.4 BENCHMARK)" -ForegroundColor Cyan
Write-Host " Base URL       : $BaseUrl" -ForegroundColor Cyan
Write-Host " Periode Bulan  : $Month ($startDate s.d. $endDate)" -ForegroundColor Cyan
Write-Host " ID Uji         : $TestIdNumber" -ForegroundColor Cyan
Write-Host "===================================================================" -ForegroundColor Cyan

# -------------------------------------------------------------
# 1. Autentikasi Pengguna
# -------------------------------------------------------------
if (-not $ApiToken) {
    Write-Host "`n[Autentikasi] Melakukan login sebagai [$Username]..." -ForegroundColor Yellow
    $loginUrl = "$BaseUrl/api/login"
    $loginBody = @{
        username = $Username
        password = $Password
    } | ConvertTo-Json

    try {
        $loginRes = Invoke-RestMethod -Uri $loginUrl -Method Post -Body $loginBody -ContentType "application/json" -TimeoutSec 10
        if ($loginRes.token) {
            $ApiToken = $loginRes.token
            Write-Host "  -> Berhasil login! Token diterima." -ForegroundColor Green
        } elseif ($loginRes.data -and $loginRes.data.token) {
            $ApiToken = $loginRes.data.token
            Write-Host "  -> Berhasil login! Token diterima." -ForegroundColor Green
        } else {
            throw "Token tidak ditemukan pada respons login: $($loginRes | ConvertTo-Json -Compress)"
        }
    } catch {
        Write-Warning "Autentikasi gagal pada ${loginUrl}: $($_.Exception.Message)"
        throw $_
    }
}

$headers = @{
    "Authorization" = "Bearer $ApiToken"
    "Accept" = "application/json"
}

# -------------------------------------------------------------
# 2. Warm-Up Request
# -------------------------------------------------------------
Write-Host "`n[Warm-Up] Mengirim request pemanasan untuk mengeliminasi cold-start..." -ForegroundColor Yellow
try {
    $null = Invoke-RestMethod -Uri "$BaseUrl/api/health-db" -Method Get -TimeoutSec 5 -ErrorAction SilentlyContinue
    $null = Invoke-RestMethod -Uri "$BaseUrl/api/visitors/check-ktp/$TestIdNumber" -Headers $headers -Method Get -TimeoutSec 5 -ErrorAction SilentlyContinue
    Write-Host "  -> Warm-up selesai." -ForegroundColor Green
} catch {
    Write-Host "  -> Warm-up dilewati: $($_.Exception.Message)" -ForegroundColor Gray
}

# -------------------------------------------------------------
# 3. Benchmark Pengujian 3 SLA Spec §6.4
# -------------------------------------------------------------
$auditResults = @()

# SLA 1: Pencarian KTP / Pengunjung (< 1.000 ms)
Write-Host "`n[SLA 1/3] Menguji latensi pencarian identitas pengunjung (Spec §6.4: < 1.000 ms)..." -ForegroundColor Yellow
$searchUrl = "$BaseUrl/api/visitors/check-ktp/$TestIdNumber"
$swSearch = [System.Diagnostics.Stopwatch]::StartNew()
try {
    $searchRes = Invoke-RestMethod -Uri $searchUrl -Headers $headers -Method Get -TimeoutSec 10
    $swSearch.Stop()
    $searchMs = [Math]::Round($swSearch.Elapsed.TotalMilliseconds, 2)
    $searchStatus = if ($searchMs -le 1000) { "PASS" } else { "FAIL" }
    Write-Host "  -> Waktu Respons: $searchMs ms | SLA: 1000 ms | Status: $searchStatus" -ForegroundColor (if ($searchStatus -eq "PASS") { "Green" } else { "Red" })

    $auditResults += [PSCustomObject]@{
        SLA_Metric = "1. Pencarian Identitas Pengunjung (check-ktp)"
        Threshold_ms = 1000
        Actual_ms = $searchMs
        Status = $searchStatus
    }
} catch {
    $swSearch.Stop()
    Write-Warning "Request SLA 1 gagal: $($_.Exception.Message)"
    $auditResults += [PSCustomObject]@{
        SLA_Metric = "1. Pencarian Identitas Pengunjung (check-ktp)"
        Threshold_ms = 1000
        Actual_ms = [Math]::Round($swSearch.Elapsed.TotalMilliseconds, 2)
        Status = "FAIL (Error)"
    }
}

# SLA 2: Kueri Laporan Bulanan (< 3.000 ms)
Write-Host "`n[SLA 2/3] Menguji latensi kueri laporan kunjungan 1 bulan (Spec §6.4: < 3.000 ms)..." -ForegroundColor Yellow
$reportUrl = "$BaseUrl/api/reports/visits?date_from=$startDate&date_to=$endDate&per_page=100"
$swReport = [System.Diagnostics.Stopwatch]::StartNew()
try {
    $reportRes = Invoke-RestMethod -Uri $reportUrl -Headers $headers -Method Get -TimeoutSec 15
    $swReport.Stop()
    $reportMs = [Math]::Round($swReport.Elapsed.TotalMilliseconds, 2)
    $reportStatus = if ($reportMs -le 3000) { "PASS" } else { "FAIL" }
    Write-Host "  -> Waktu Respons: $reportMs ms | SLA: 3000 ms | Status: $reportStatus" -ForegroundColor (if ($reportStatus -eq "PASS") { "Green" } else { "Red" })

    $auditResults += [PSCustomObject]@{
        SLA_Metric = "2. Laporan Kunjungan Bulanan (API Report)"
        Threshold_ms = 3000
        Actual_ms = $reportMs
        Status = $reportStatus
    }
} catch {
    $swReport.Stop()
    Write-Warning "Request SLA 2 gagal: $($_.Exception.Message)"
    $auditResults += [PSCustomObject]@{
        SLA_Metric = "2. Laporan Kunjungan Bulanan (API Report)"
        Threshold_ms = 3000
        Actual_ms = [Math]::Round($swReport.Elapsed.TotalMilliseconds, 2)
        Status = "FAIL (Error)"
    }
}

# SLA 3: Ekspor Streaming Excel Satu Bulan (< 30.000 ms)
Write-Host "`n[SLA 3/3] Menguji streaming unduhan Excel 1 bulan (Spec §6.4: < 30.000 ms)..." -ForegroundColor Yellow
$excelUrl = "$BaseUrl/api/reports/visits/export?date_from=$startDate&date_to=$endDate"
$tempExcel = [System.IO.Path]::GetTempFileName()
$swExcel = [System.Diagnostics.Stopwatch]::StartNew()
try {
    $excelHeaders = @{
        "Authorization" = "Bearer $ApiToken"
        "Accept" = "*/*"
    }
    Invoke-WebRequest -Uri $excelUrl -Headers $excelHeaders -OutFile $tempExcel -TimeoutSec 60
    $swExcel.Stop()
    $excelMs = [Math]::Round($swExcel.Elapsed.TotalMilliseconds, 2)
    $excelStatus = if ($excelMs -le 30000) { "PASS" } else { "FAIL" }
    Write-Host "  -> Waktu Respons: $excelMs ms | SLA: 30000 ms | Status: $excelStatus" -ForegroundColor (if ($excelStatus -eq "PASS") { "Green" } else { "Red" })

    $auditResults += [PSCustomObject]@{
        SLA_Metric = "3. Unduhan Streaming Excel Kunjungan (Export XLSX)"
        Threshold_ms = 30000
        Actual_ms = $excelMs
        Status = $excelStatus
    }
} catch {
    $swExcel.Stop()
    Write-Warning "Request SLA 3 gagal: $($_.Exception.Message)"
    $auditResults += [PSCustomObject]@{
        SLA_Metric = "3. Unduhan Streaming Excel Kunjungan (Export XLSX)"
        Threshold_ms = 30000
        Actual_ms = [Math]::Round($swExcel.Elapsed.TotalMilliseconds, 2)
        Status = "FAIL (Error)"
    }
} finally {
    if (Test-Path $tempExcel) {
        Remove-Item -Path $tempExcel -Force -ErrorAction SilentlyContinue
    }
}

# -------------------------------------------------------------
# 4. Ringkasan & Tabel Hasil
# -------------------------------------------------------------
Write-Host "`n===================================================================" -ForegroundColor Cyan
Write-Host " HASIL AUDIT KINERJA SLA PRODUKSI (Spec §6.4)" -ForegroundColor Cyan
Write-Host "===================================================================" -ForegroundColor Cyan
$auditResults | Format-Table -AutoSize

$allPassed = ($auditResults | Where-Object { $_.Status -ne "PASS" }).Count -eq 0

if ($OutputDir) {
    if (-not (Test-Path $OutputDir)) {
        New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
    }
    $summaryPath = Join-Path $OutputDir "audit-performance-summary.json"
    $auditResults | ConvertTo-Json -Depth 5 | Set-Content -Path $summaryPath -Encoding UTF8
    Write-Host "Ringkasan tersimpan di: $summaryPath" -ForegroundColor Green
}

if ($allPassed) {
    Write-Host "`nSELURUH TARGET KINERJA SLA SPEC §6.4 TERPENUHI (PASS)!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`nPERINGATAN: TERDAPAT METRIK YANG MELEBIHI BATAS SLA SPEC §6.4!" -ForegroundColor Red
    exit 1
}
