<#
.SYNOPSIS
    Prepares the local account that runs the GitHub Actions runner service on a yard server (decision OPS-6).

.DESCRIPTION
    Run once from an elevated PowerShell, after INSTALL-GUIDE sections 1-8 (the first install) and after
    setup-pgpass.ps1, and BEFORE registering the runner. It gives the account exactly what
    .github/workflows/deploy-onprem.yml -> deploy.ps1 needs, and nothing more:

      1. Local user svc-vms-runner (password asked at the console, never on the command line), NOT a member
         of Administrators. It is the runner service's logon account, never SYSTEM or NETWORK SERVICE.
      2. Modify on C:\VMS (release ZIPs, the app folder swap, pre-deploy dumps). C:\VMS\secrets keeps its own
         locked-down ACL and only gets Read (deploy.ps1 reads pgpass.conf through PGPASSFILE).
      3. Query/start/stop rights on the VMS-Apache service, added to the service's security descriptor.

    The VMS-Cloud-Sync task is not changed: deploy.ps1 only warns when the account may not disable it, and
    vms:sync-cloud-hub skips itself while the app is in maintenance mode.

    Decision SEC-8/SEC-8-FREE: register the runner on the REPOSITORY INIT-ASL/VMS-backend-laravel only (never
    on the organization), with the label yard-<yard>. The commands are printed at the end.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-runner-account.ps1
    powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-runner-account.ps1 -Yard asl
#>
[CmdletBinding()]
param(
    [string] $Account = 'svc-vms-runner',
    [string] $VmsRoot = 'C:\VMS',
    [string] $ServiceName = 'VMS-Apache',
    [ValidateSet('cis', 'asl')]
    [string] $Yard = 'cis'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated (Administrator) PowerShell.'
}
if (-not (Test-Path -LiteralPath $VmsRoot)) { throw "$VmsRoot not found - do the first install (INSTALL-GUIDE) first." }
if (-not (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue)) { throw "Service $ServiceName not found - install Apache first (INSTALL-GUIDE section 3)." }

# 1. Account
if (Get-LocalUser -Name $Account -ErrorAction SilentlyContinue) {
    Write-Host "= local user $Account exists" -ForegroundColor Gray
} else {
    $password = Read-Host -AsSecureString "New password for $Account (the runner service logs on with it)"
    New-LocalUser -Name $Account -Password $password -PasswordNeverExpires -UserMayNotChangePassword `
        -Description 'GitHub Actions runner for VMS deploys (OPS-6)' | Out-Null
    $password = $null
    Write-Host "+ local user $Account" -ForegroundColor Green
}
$adminGroup = (New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')).Translate([Security.Principal.NTAccount]).Value.Split('\')[-1]
if (Get-LocalGroupMember -Group $adminGroup -Member $Account -ErrorAction SilentlyContinue) {
    throw "$Account is a member of $adminGroup - remove it; the runner must not be an administrator (OPS-6)."
}
$sid = (New-Object Security.Principal.NTAccount($Account)).Translate([Security.Principal.SecurityIdentifier]).Value

# 2. Folders. The inheritable ACE on C:\VMS reaches every child that inherits (app, releases, backups).
& icacls.exe $VmsRoot /grant "*${sid}:(OI)(CI)M" | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls on $VmsRoot failed (exit $LASTEXITCODE)." }
Write-Host "+ Modify on $VmsRoot" -ForegroundColor Green
$secrets = Join-Path $VmsRoot 'secrets'
if (Test-Path -LiteralPath $secrets) {
    & icacls.exe $secrets /grant "*${sid}:(OI)(CI)R" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls on $secrets failed (exit $LASTEXITCODE)." }
    Write-Host "+ Read on $secrets (re-running setup-pgpass.ps1 later needs -GrantUser $Account)" -ForegroundColor Green
} else {
    Write-Warning "$secrets does not exist yet - run setup-pgpass.ps1 -GrantUser $Account."
}

# 3. Service rights: query config/status, enumerate dependents, start, stop, pause, interrogate, user control, read.
$sddl = ((& sc.exe sdshow $ServiceName) | Where-Object { $_.Trim() }) -join ''
if ($LASTEXITCODE -ne 0 -or -not $sddl.StartsWith('D:')) { throw "Could not read the security descriptor of $ServiceName." }
if ($sddl -match [regex]::Escape($sid)) {
    Write-Host "= $ServiceName already grants $Account" -ForegroundColor Gray
} else {
    $ace = "(A;;CCLCSWRPWPDTLOCRRC;;;$sid)"
    $index = $sddl.IndexOf('S:')
    $newSddl = if ($index -ge 0) { $sddl.Substring(0, $index) + $ace + $sddl.Substring($index) } else { $sddl + $ace }
    & sc.exe sdset $ServiceName $newSddl | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "sc.exe sdset $ServiceName failed (exit $LASTEXITCODE)." }
    Write-Host "+ start/stop rights on $ServiceName" -ForegroundColor Green
}

Write-Host ''
Write-Host 'Next: register the runner on the REPOSITORY (not the organization), in C:\actions-runner:' -ForegroundColor Cyan
Write-Host '  1. GitHub > INIT-ASL/VMS-backend-laravel > Settings > Actions > Runners > New self-hosted runner (Windows)'
Write-Host '     shows a one-hour token. Type it only into the command below, never into chat or a document.'
Write-Host "  2. .\config.cmd --url https://github.com/INIT-ASL/VMS-backend-laravel --token <token> --name yard-$Yard --labels yard-$Yard"
Write-Host "     Run as service? Y.  User account for the service? .\$Account  (then its password)."
Write-Host '  An organization-level runner registered earlier must be removed first: .\config.cmd remove --token <removal token>'
Write-Host '  (GitHub > organization INIT-ASL > Settings > Actions > Runners > the runner > Remove shows that token).'
