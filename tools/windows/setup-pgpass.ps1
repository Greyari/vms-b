<#
.SYNOPSIS
    Writes the PostgreSQL password file that deploy.ps1 and backup-db.ps1 read (decision OPS-5f).

.DESCRIPTION
    Both scripts run pg_dump/pg_restore with -w, so they never prompt: a scheduled task running as SYSTEM
    has no terminal to prompt on. They read passwords from one file, C:\VMS\secrets\pgpass.conf, via the
    PGPASSFILE environment variable.

    This script asks for the passwords at the console (nothing is typed on the command line, so nothing
    lands in PowerShell history), writes the file in libpq's format without a byte-order mark, and locks
    the folder down to Administrators and SYSTEM - plus, optionally, one more account such as the future
    GitHub runner account svc-vms-runner (roadmap step 9).

      <host>:<port>:*:postgres:<password>   - deploy.ps1 dump/restore (superuser, OPS-5f)
      <host>:<port>:db_vms:vms_app:<password> - backup-db.ps1 daily dump (the database owner)

    Run it again to change a password; it rewrites the whole file.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-pgpass.ps1
    powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-pgpass.ps1 -GrantUser svc-vms-runner
#>
[CmdletBinding()]
param(
    [string] $Path = 'C:\VMS\secrets\pgpass.conf',
    [string] $DbHost = '127.0.0.1',
    [int] $Port = 5432,
    [string] $Database = 'db_vms',
    [string] $AdminUser = 'postgres',
    [string] $AppUser = 'vms_app',
    [string] $GrantUser = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated (Administrator) PowerShell.'
}

# libpq's pgpass format: a backslash or colon inside a field is escaped with a backslash.
function ConvertTo-PgPassField([string] $Value) {
    return $Value.Replace('\', '\\').Replace(':', '\:')
}

function Read-Password([string] $Account) {
    $secure = Read-Host -AsSecureString "Password for PostgreSQL role $Account"
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
    if (-not $plain) { throw "Empty password for $Account - nothing written." }
    return $plain
}

$adminPassword = Read-Password $AdminUser
$appPassword = Read-Password $AppUser

$dir = Split-Path -Parent $Path
if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }

# Folder first: no inherited rights, full control for Administrators (S-1-5-32-544) and SYSTEM (S-1-5-18).
$grants = @('*S-1-5-32-544:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F')
if ($GrantUser) { $grants += "${GrantUser}:(OI)(CI)R" }
& icacls.exe $dir /inheritance:r /grant:r @grants | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls failed on $dir (exit $LASTEXITCODE) - nothing written." }

$lines = @(
    ('{0}:{1}:*:{2}:{3}' -f $DbHost, $Port, (ConvertTo-PgPassField $AdminUser), (ConvertTo-PgPassField $adminPassword)),
    ('{0}:{1}:{2}:{3}:{4}' -f $DbHost, $Port, (ConvertTo-PgPassField $Database), (ConvertTo-PgPassField $AppUser), (ConvertTo-PgPassField $appPassword))
)
# No BOM: libpq would read it as part of the first host name and never match.
[System.IO.File]::WriteAllText($Path, ($lines -join "`n") + "`n", (New-Object System.Text.UTF8Encoding $false))
$adminPassword = $null
$appPassword = $null

Write-Host "Written $Path (readable by Administrators and SYSTEM$(if ($GrantUser) { " and $GrantUser" }))." -ForegroundColor Green
Write-Host ("Check: `$env:PGPASSFILE = '$Path'; pg_dump -w -U $AdminUser -h $DbHost --schema-only -f NUL $Database") -ForegroundColor Gray
