<#
.SYNOPSIS
    Deploys a VMS release ZIP to the yard server, or rolls back to the previous release.

.DESCRIPTION
    Hybrid decision OPS-5 (a-f) and parity spec sections 2.6-2.7. One deploy:

      1. Extract the ZIP into a NEW folder (releases\new-<stamp>) and copy the current .env and
         public\branding\ (the yard logo, PE-7) into it (e).
      2. php artisan down on the running app.
      3. pg_dump of the database as the admin role; it MUST succeed with a non-empty file, otherwise the
         deploy stops and the app comes back up unchanged (b). The dump is this deploy's own.
      4. Pause the VMS-Cloud-Sync task (its php process works inside the app folder every minute and
         would block the move), stop VMS-Apache, move storage\ from the old app into the new one, then
         swap folders: app -> releases\previous-<stamp>, new -> app (e). The task resumes at the end,
         on success and on rollback alike.
      5. php artisan migrate --force. A failure rolls everything back: folders swapped back, this deploy's
         dump restored, Apache started, artisan up (a).
      6. config:cache, route:cache, view:cache.
      7. Start VMS-Apache and require it to be Running (d).
      8. Probe GET /api/health-db up to 6 times, 5 seconds apart (c); /api/health-db answers during
         maintenance mode. Failure = full rollback as in step 5.
      9. php artisan up. Old previous-* folders beyond -KeepReleases are removed.

    The app itself connects as the non-superuser role in .env (vms_app). Only pg_dump/pg_restore use the
    admin role (-DbAdminUser, default postgres), always with -w: its password comes from -PgPassFile
    (C:\VMS\secrets\pgpass.conf, written by setup-pgpass.ps1 and readable by administrators and SYSTEM only)
    and is never written in the script, the release or .env (f). The restore keeps the dump's owners, so
    every table is vms_app's again after a rollback.

    Run it from OUTSIDE C:\VMS\app (for example C:\VMS\tools\deploy.ps1): the app folder is moved.

    -Rollback swaps back to the newest releases\previous-* folder. It restores a database dump only when
    one is named explicitly with -RestoreDump - never "the latest dump in the folder", which may belong to
    another deploy (b).

.EXAMPLE
    .\deploy.ps1 -ReleaseZip C:\VMS\releases\vms-release-20261001_020000.zip
    .\deploy.ps1 -Rollback
    .\deploy.ps1 -Rollback -RestoreDump C:\VMS\backups\pre-deploy-20261001_020000.dump
#>
[CmdletBinding()]
param(
    [string] $ReleaseZip = '',
    [switch] $Rollback,
    [string] $RestoreDump = '',
    [string] $AppDir = 'C:\VMS\app',
    [string] $BackupDir = 'C:\VMS\backups',
    [string] $ReleasesDir = 'C:\VMS\releases',
    [string] $HealthUrl = 'https://127.0.0.1/api/health-db',
    [string] $Database = 'db_vms',
    [string] $DbAdminUser = 'postgres',
    [string] $DbHost = '127.0.0.1',
    [string] $PhpExe = 'php',
    [string] $ServiceName = 'VMS-Apache',
    [string] $SyncTaskName = 'VMS-Cloud-Sync',
    [string] $PgPassFile = 'C:\VMS\secrets\pgpass.conf',
    [int] $ProbeAttempts = 6,
    [int] $ProbeIntervalSeconds = 5,
    [int] $KeepReleases = 3
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'

# The app folder is moved below; neither this script nor the shell may sit inside it.
$appFull = [System.IO.Path]::GetFullPath($AppDir).TrimEnd('\') + '\'
if ($PSScriptRoot -and ([System.IO.Path]::GetFullPath($PSScriptRoot).TrimEnd('\') + '\').StartsWith($appFull, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Run a copy of deploy.ps1 from outside $AppDir, e.g. Copy-Item $PSCommandPath C:\VMS\tools\deploy.ps1 (INSTALL-GUIDE section 7)."
}
Set-Location -LiteralPath (Split-Path -Parent $appFull.TrimEnd('\'))

# pg_dump/pg_restore run with -w (never prompt); without this file they fall back to %APPDATA%\postgresql\pgpass.conf.
if (Test-Path -LiteralPath $PgPassFile) { $env:PGPASSFILE = $PgPassFile }

# The sync task's php process runs inside the app folder every minute; a folder swap during a cycle fails
# with "in use". The task is disabled while the folders move. An account that may not change the task
# (a non-admin runner) only gets a warning: `vms:sync-cloud-hub` skips itself in maintenance mode, and
# Move-WithRetry rides out the second it still spends starting up.
$script:syncTaskPaused = $false
function Suspend-SyncTask {
    $task = Get-ScheduledTask -TaskName $SyncTaskName -ErrorAction SilentlyContinue
    if (-not $task -or $task.State -eq 'Disabled') { return }
    try {
        Disable-ScheduledTask -TaskName $SyncTaskName -ErrorAction Stop | Out-Null
    } catch {
        Write-Warning "Could not disable ${SyncTaskName}: $($_.Exception.Message) - relying on maintenance mode."
        return
    }
    $script:syncTaskPaused = $true
    for ($i = 0; $i -lt 60 -and (Get-ScheduledTask -TaskName $SyncTaskName).State -eq 'Running'; $i++) { Start-Sleep -Seconds 1 }
    if ((Get-ScheduledTask -TaskName $SyncTaskName).State -eq 'Running') { Stop-ScheduledTask -TaskName $SyncTaskName }
}
function Resume-SyncTask {
    if ($script:syncTaskPaused) {
        Enable-ScheduledTask -TaskName $SyncTaskName | Out-Null
        $script:syncTaskPaused = $false
    }
}

function Move-WithRetry([string] $From, [string] $To) {
    for ($attempt = 1; ; $attempt++) {
        try {
            Move-Item -LiteralPath $From -Destination $To -ErrorAction Stop
            return
        } catch {
            if ($attempt -ge 5) { throw }
            Write-Host "   move $From busy (attempt $attempt): $($_.Exception.Message)" -ForegroundColor DarkYellow
            Start-Sleep -Seconds 3
        }
    }
}

function Write-Step([string] $Text) { Write-Host "== $Text" -ForegroundColor Cyan }

# php, pg_dump and pg_restore may write to stderr. Under Windows PowerShell 5.1 with
# ErrorActionPreference=Stop that would abort the script mid-deploy, so native tools run with Continue,
# their output goes to the console (not the pipeline), and only the exit code decides.
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

function Invoke-Artisan([string] $Dir, [string[]] $Arguments) {
    return Invoke-Native $PhpExe (@((Join-Path $Dir 'artisan')) + $Arguments)
}

function Start-Apache {
    Start-Service -Name $ServiceName
    (Get-Service -Name $ServiceName).WaitForStatus('Running', (New-TimeSpan -Seconds 30))
    if ((Get-Service -Name $ServiceName).Status -ne 'Running') { throw "$ServiceName did not reach Running." }
}

function Test-Health {
    for ($attempt = 1; $attempt -le $ProbeAttempts; $attempt++) {
        try {
            if ($PSVersionTable.PSVersion.Major -ge 6) {
                $response = Invoke-RestMethod -Uri $HealthUrl -TimeoutSec 10 -SkipCertificateCheck
            } else {
                # The yard CA is trusted on the counters, not necessarily by this PowerShell session.
                [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
                $response = Invoke-RestMethod -Uri $HealthUrl -TimeoutSec 10
            }
            if ($response.status -eq 'ok') {
                Write-Host "   health-db ok (attempt $attempt)" -ForegroundColor Green
                return $true
            }
        } catch {
            Write-Host "   attempt ${attempt}: $($_.Exception.Message)" -ForegroundColor DarkYellow
        }
        Start-Sleep -Seconds $ProbeIntervalSeconds
    }
    return $false
}

function Restore-Database([string] $Dump) {
    Write-Step "Restoring database $Database from $Dump"
    # No --no-owner: the dump's ALTER ... OWNER TO vms_app must run, or the app loses its own tables.
    $exit = Invoke-Native 'pg_restore' @('--clean', '--if-exists', '-w', '-U', $DbAdminUser, '-h', $DbHost, '-d', $Database, $Dump)
    if ($exit -ne 0) { Write-Warning "pg_restore exited $exit - check the database by hand." }
}

# Puts $PreviousDir back as the app. storage\ always travels with the live app folder.
function Restore-PreviousRelease([string] $PreviousDir, [string] $Dump) {
    Write-Host '====================================================' -ForegroundColor Red
    Write-Host "ROLLBACK to $PreviousDir" -ForegroundColor Red
    Write-Host '====================================================' -ForegroundColor Red

    Suspend-SyncTask
    Stop-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $AppDir) {
        $prevStorage = Join-Path $PreviousDir 'storage'
        if (Test-Path -LiteralPath $prevStorage) { Remove-Item -LiteralPath $prevStorage -Recurse -Force }
        Move-WithRetry (Join-Path $AppDir 'storage') $prevStorage
        Move-WithRetry $AppDir (Join-Path $ReleasesDir "failed-$stamp")
    }
    Move-WithRetry $PreviousDir $AppDir

    if ($Dump) { Restore-Database $Dump }

    Start-Apache
    [void] (Invoke-Artisan $AppDir @('up'))
    Resume-SyncTask
    Write-Host 'Rollback finished.' -ForegroundColor Green
}

foreach ($dir in @($BackupDir, $ReleasesDir)) {
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
}

# ---- manual rollback ------------------------------------------------------------------------------
if ($Rollback) {
    $previous = Get-ChildItem -LiteralPath $ReleasesDir -Directory -Filter 'previous-*' | Sort-Object Name -Descending | Select-Object -First 1
    if (-not $previous) { throw "No releases\previous-* folder to roll back to." }
    if ($RestoreDump -and -not (Test-Path -LiteralPath $RestoreDump)) { throw "Dump not found: $RestoreDump" }
    [void] (Invoke-Artisan $AppDir @('down', '--retry=60'))
    Restore-PreviousRelease $previous.FullName $RestoreDump
    exit 0
}

# ---- deploy ---------------------------------------------------------------------------------------
if (-not $ReleaseZip -or -not (Test-Path -LiteralPath $ReleaseZip)) { throw "Release ZIP not found: [$ReleaseZip]" }
if (-not (Test-Path -LiteralPath (Join-Path $AppDir '.env'))) { throw "No .env in $AppDir - install the app first (INSTALL-GUIDE)." }
foreach ($tool in @('pg_dump', 'pg_restore', $PhpExe)) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "$tool is not on PATH." }
}

# 1. New folder (e)
$newDir = Join-Path $ReleasesDir "new-$stamp"
Write-Step "Extracting $ReleaseZip to $newDir"
Expand-Archive -LiteralPath $ReleaseZip -DestinationPath $newDir -Force
if (-not (Test-Path -LiteralPath (Join-Path $newDir 'artisan'))) { throw "The ZIP holds no artisan at its root - not a VMS release." }
Copy-Item -LiteralPath (Join-Path $AppDir '.env') -Destination (Join-Path $newDir '.env') -Force
# The yard logo (decision PE-7) lives outside the release, like .env. Copied, not moved, so a rollback
# to the previous folder still finds its own copy.
$branding = Join-Path $AppDir 'public\branding'
if (Test-Path -LiteralPath $branding) {
    Copy-Item -LiteralPath $branding -Destination (Join-Path $newDir 'public\branding') -Recurse -Force
}

# 2. Maintenance mode
Write-Step 'php artisan down'
[void] (Invoke-Artisan $AppDir @('down', '--retry=60'))

# 3. Dump, which must succeed (b)
$dump = Join-Path $BackupDir "pre-deploy-$stamp.dump"
Write-Step "pg_dump $Database -> $dump"
$dumpExit = Invoke-Native 'pg_dump' @('-Fc', '-w', '-U', $DbAdminUser, '-h', $DbHost, '-f', $dump, $Database)
if ($dumpExit -ne 0 -or -not (Test-Path -LiteralPath $dump) -or (Get-Item -LiteralPath $dump).Length -eq 0) {
    Write-Host "pg_dump failed (exit $dumpExit) - deploy stopped, nothing changed." -ForegroundColor Red
    [void] (Invoke-Artisan $AppDir @('up'))
    Remove-Item -LiteralPath $newDir -Recurse -Force
    exit 1
}

# 4. Swap folders (e). A failure half-way puts every folder back where it was and brings the old
# release up again, so the gate never stays in maintenance mode or without its storage\.
Write-Step "Pausing $SyncTaskName, stopping $ServiceName and swapping $AppDir"
$previousDir = Join-Path $ReleasesDir "previous-$stamp"
try {
    Suspend-SyncTask
    Stop-Service -Name $ServiceName
    Remove-Item -LiteralPath (Join-Path $newDir 'storage') -Recurse -Force -ErrorAction SilentlyContinue
    Move-WithRetry (Join-Path $AppDir 'storage') (Join-Path $newDir 'storage')
    Move-WithRetry $AppDir $previousDir
    Move-WithRetry $newDir $AppDir
} catch {
    Write-Host "Folder swap failed: $($_.Exception.Message) - putting the old release back." -ForegroundColor Red
    if (-not (Test-Path -LiteralPath $AppDir) -and (Test-Path -LiteralPath $previousDir)) { Move-Item -LiteralPath $previousDir -Destination $AppDir }
    if (-not (Test-Path -LiteralPath (Join-Path $AppDir 'storage')) -and (Test-Path -LiteralPath (Join-Path $newDir 'storage'))) {
        Move-Item -LiteralPath (Join-Path $newDir 'storage') -Destination (Join-Path $AppDir 'storage')
    }
    Start-Apache
    [void] (Invoke-Artisan $AppDir @('up'))
    Resume-SyncTask
    Remove-Item -LiteralPath $newDir -Recurse -Force -ErrorAction SilentlyContinue
    exit 1
}

try {
    # 5. Migrations (a)
    Write-Step 'php artisan migrate --force'
    if ((Invoke-Artisan $AppDir @('migrate', '--force')) -ne 0) { throw 'Migrations failed.' }

    # 6. Caches
    Write-Step 'Caching config, routes and views'
    foreach ($cache in @('config:cache', 'route:cache', 'view:cache')) {
        if ((Invoke-Artisan $AppDir @($cache)) -ne 0) { throw "artisan $cache failed." }
    }

    # 7. Apache (d)
    Write-Step "Starting $ServiceName"
    Start-Apache

    # 8. Health probe (c)
    Write-Step "Probing $HealthUrl"
    if (-not (Test-Health)) { throw "health-db did not answer ok after $ProbeAttempts attempts." }
} catch {
    Write-Host "Deploy failed: $($_.Exception.Message)" -ForegroundColor Red
    Restore-PreviousRelease $previousDir $dump
    exit 1
}

# 9. Back in service
Write-Step 'php artisan up'
[void] (Invoke-Artisan $AppDir @('up'))
Resume-SyncTask

Get-ChildItem -LiteralPath $ReleasesDir -Directory -Filter 'previous-*' | Sort-Object Name -Descending |
    Select-Object -Skip $KeepReleases | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Recurse -Force }

Write-Host '====================================================' -ForegroundColor Green
Write-Host "VMS deployed. Previous release kept in $previousDir; database dump $dump." -ForegroundColor Green
Write-Host '====================================================' -ForegroundColor Green
exit 0
