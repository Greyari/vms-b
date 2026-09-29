<#
.SYNOPSIS
    Mendaftarkan Windows Scheduled Task untuk Backup Harian Otomatis VMS.
.DESCRIPTION
    Menjadwalkan eksekusi backup-db.ps1 setiap hari pukul 02:00 WIB
    berjalan di bawah akun SYSTEM tanpa memerlukan login pengguna di server.
    Spec Reference: 2026-09-13-vms-local-server-parity-design.md (§2.8).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$TaskName = "VMS-Daily-Backup",

    [Parameter(Mandatory = $false)]
    [string]$DailyTime = "02:00",

    [Parameter(Mandatory = $false)]
    # The release ships the scripts inside the app; deploy.ps1 keeps this path valid across releases.
    [string]$ScriptPath = "C:\VMS\app\tools\windows\backup-db.ps1",

    [Parameter(Mandatory = $false)]
    [string]$SecondaryDir = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Write-Host "Mendaftarkan Windows Scheduled Task: $TaskName" -ForegroundColor Cyan
Write-Host "Waktu Eksekusi: Setiap hari pukul $DailyTime WIB" -ForegroundColor Cyan
Write-Host "Skrip Target  : $ScriptPath" -ForegroundColor Cyan

$argString = "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`""
if ($SecondaryDir) {
    $argString += " -SecondaryDir `"$SecondaryDir`""
}

# Use schtasks.exe for high compatibility across Windows Server & Windows 11
$schtasksCmd = "schtasks /Create /TN `"$TaskName`" /TR `"powershell.exe $argString`" /SC DAILY /ST $DailyTime /RU SYSTEM /RL HIGHEST /F"

Write-Host "Mengeksekusi: $schtasksCmd" -ForegroundColor Gray
cmd /c $schtasksCmd

if ($LASTEXITCODE -eq 0) {
    Write-Host "Scheduled task [$TaskName] berhasil didaftarkan di Task Scheduler!" -ForegroundColor Green
} else {
    Write-Warning "Pendaftaran via schtasks menghasilkan kode: $LASTEXITCODE. Pastikan PowerShell dijalankan sebagai Administrator."
}
