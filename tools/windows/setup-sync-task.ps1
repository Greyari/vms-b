<#
.SYNOPSIS
    Registers the Windows Scheduled Task that runs one cloud hub sync cycle every minute.

.DESCRIPTION
    Hybrid multi-yard decision OPS-7 (docs/vms-hybrid-architecture-spec.md section 8.1):
    - one trigger per day at 00:00 repeating every minute for one day - a valid repetition
      duration on every Windows version, instead of an "infinite" maximum timespan, which Task
      Scheduler rejects on some builds;
    - MultipleInstances = IgnoreNew: a slow cycle is never started twice (the command also holds
      its own lock, and exits 0 whatever the hub does);
    - runs as NT AUTHORITY\LOCAL SERVICE, a built-in account without interactive logon or stored
      password; it gets write access to storage\ and bootstrap\cache\ only;
    - the task does nothing until VMS_SYNC_ENABLED=true in the app's .env.

    Run once from an elevated PowerShell on the yard server. Running it again replaces the task.

.PARAMETER AppDir
    Laravel application folder. Default C:\VMS\app.

.PARAMETER PhpExe
    PHP CLI. Default C:\VMS\php\php.exe.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-sync-task.ps1
#>
[CmdletBinding()]
param(
    [string] $TaskName = 'VMS-Cloud-Sync',
    [string] $AppDir = 'C:\VMS\app',
    [string] $PhpExe = 'C:\VMS\php\php.exe'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$principal = [Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated PowerShell (Run as Administrator).'
}
foreach ($path in @($PhpExe, (Join-Path $AppDir 'artisan'))) {
    if (-not (Test-Path -LiteralPath $path)) { throw "Not found: $path" }
}

# LOCAL SERVICE (SID S-1-5-19) writes Laravel's log and compiled files; nothing else under the app.
foreach ($dir in @('storage', 'bootstrap\cache')) {
    $full = Join-Path $AppDir $dir
    & icacls.exe $full /grant '*S-1-5-19:(OI)(CI)M' /T /C /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls failed on $full (exit $LASTEXITCODE)" }
}

$action = New-ScheduledTaskAction -Execute $PhpExe -Argument 'artisan vms:sync-cloud-hub' -WorkingDirectory $AppDir

$trigger = New-ScheduledTaskTrigger -Daily -At '00:00'
$repeat = New-ScheduledTaskTrigger -Once -At '00:00' -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 1)
$trigger.Repetition = $repeat.Repetition

$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

$taskPrincipal = New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\LOCAL SERVICE' -LogonType ServiceAccount -RunLevel Limited

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $taskPrincipal `
    -Description 'VMS: push the global blacklist outbox and pull blacklist changes and pre-registrations from the cloud hub (OPS-7).' `
    -Force | Out-Null

# Start the first cycle now instead of waiting for midnight.
Start-ScheduledTask -TaskName $TaskName

$task = Get-ScheduledTask -TaskName $TaskName
Write-Host "Task $TaskName registered: every minute, IgnoreNew, as LOCAL SERVICE (state: $($task.State))." -ForegroundColor Green
Write-Host 'Check: Get-ScheduledTaskInfo VMS-Cloud-Sync; then the sync indicator in the SPA header.'
