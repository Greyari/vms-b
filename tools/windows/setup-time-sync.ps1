<#
.SYNOPSIS
    Points the yard server's clock at internet NTP and keeps it there.

.DESCRIPTION
    Hybrid multi-yard decision OPS-8 (follow-up): the yards have no domain controller or LAN time
    server, so the VMS server syncs straight to internet NTP. The cloud hub accepts a signed request
    only within 300 seconds of its own clock (SEC-1), and a Windows PC outside a domain otherwise
    syncs about once a week. The counter PCs need nothing: visit times come from the server.

    - w32time starts automatically;
    - manual peers time.windows.com and id.pool.ntp.org;
    - polls every hour (SpecialPollInterval = 3600);
    - may correct a large offset after the server was off for a long time.

    Run from an elevated PowerShell. Acceptance: `w32tm /query /status` shows an NTP source, not
    "Local CMOS Clock".
#>
[CmdletBinding()]
param(
    [string] $Peers = 'time.windows.com,0x9 id.pool.ntp.org,0x9',
    [int] $PollSeconds = 3600
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$principal = [Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated PowerShell (Run as Administrator).'
}

Set-Service -Name w32time -StartupType Automatic
Start-Service -Name w32time

& w32tm.exe /config "/manualpeerlist:$Peers" /syncfromflags:manual /reliable:NO /update | Out-Null
if ($LASTEXITCODE -ne 0) { throw "w32tm /config failed (exit $LASTEXITCODE)" }

$ntpClient = 'HKLM:\SYSTEM\CurrentControlSet\Services\W32Time\TimeProviders\NtpClient'
$config = 'HKLM:\SYSTEM\CurrentControlSet\Services\W32Time\Config'
Set-ItemProperty -Path $ntpClient -Name SpecialPollInterval -Value $PollSeconds
Set-ItemProperty -Path $config -Name MaxPosPhaseCorrection -Value 0xFFFFFFFF
Set-ItemProperty -Path $config -Name MaxNegPhaseCorrection -Value 0xFFFFFFFF

Restart-Service -Name w32time
& w32tm.exe /resync /force | Out-Null

Write-Host 'w32time now syncs from internet NTP every hour:' -ForegroundColor Green
& w32tm.exe /query /status
