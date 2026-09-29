<#
.SYNOPSIS
    Generator Root CA Internal dan Sertifikat SSL Server VMS untuk Jaringan LAN satu yard.
.DESCRIPTION
    Menghasilkan:
    1. Root CA "VMS Internal Root CA (<Hostname>)" (10 tahun) dengan Name Constraints: CA ini hanya sah
       untuk -Hostname (dan sub-domainnya), localhost, dan alamat IP privat. Sertifikat untuk nama lain
       ditolak browser, jadi kunci CA yang bocor tidak bisa dipakai memalsukan situs lain.
    2. Server SSL Certificate (2 tahun) dengan Subject Alternative Name (IP server, 127.0.0.1, -Hostname,
       localhost).
    3. import-root-ca.bat untuk PC konter: menghapus root VMS lama dengan nama yang sama lalu mengimpor
       root ini.
    Spec Reference: 2026-09-13-vms-local-server-parity-design.md (§2.4).

    Riwayat: sampai 2026-09-30 batasan DNS di-hard-code `.asl.local` dan PT-nya "PT. ASL Shipyard
    Indonesia". Di server CIS (`-Hostname vms.cis.local`) sertifikat server melanggar batasan root-nya
    sendiri ("permitted subtree violation"), dan Edge/Chrome menolaknya sebagai NET::ERR_CERT_INVALID.

    Setiap kali Root CA dibuat ulang (-Force pada CA baru), root BARU harus diimpor ulang di SEMUA PC
    konter dan di server itu sendiri; sampai itu browser menjawab NET::ERR_CERT_AUTHORITY_INVALID.
.EXAMPLE
    .\setup-ca.ps1 -ServerIp 192.168.50.100 -Hostname vms.cis.local -Organization "PT Cemara Intan Shipyard" -Force
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$ServerIp = "127.0.0.1",

    [Parameter(Mandatory = $false)]
    [string]$Hostname = "vms.asl.local",

    [Parameter(Mandatory = $false)]
    [string]$Organization = "PT. ASL Shipyard Indonesia",

    [Parameter(Mandatory = $false)]
    [string]$OutputDir = "C:\VMS\certs",

    [Parameter(Mandatory = $false)]
    [string]$OpenSslPath = "openssl",

    [Parameter(Mandatory = $false)]
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# A host name, not an IP and not a wildcard: it becomes both the certificate's DNS name and the only DNS
# subtree the root CA may ever sign for.
if ($Hostname -notmatch '^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$' -or $Hostname -match '^[0-9.]+$') {
    throw "-Hostname [$Hostname] must be a plain host name such as vms.cis.local (no '*', no IP address)."
}
$parsedIp = $null
if (-not [System.Net.IPAddress]::TryParse($ServerIp, [ref]$parsedIp)) { throw "-ServerIp [$ServerIp] is not an IP address." }
if ($Organization -match '[/=\\]') { throw "-Organization must not contain '/', '=' or '\'." }

# openssl writes progress to stderr. Under Windows PowerShell 5.1 with ErrorActionPreference=Stop that is fatal
# as soon as output is redirected, so it runs with Continue and only the exit code decides.
function Invoke-OpenSsl([string[]] $Arguments) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $resolvedOpenSsl @Arguments 2>&1 | ForEach-Object { "$_" } | Where-Object { $_ -notmatch '^[.+*]+$' } | Out-Host
        $exit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($exit -ne 0) { throw "openssl $($Arguments[0]) failed (exit $exit)." }
}

# Ensure output directory exists
if (-not (Test-Path -Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

# Resolve OpenSSL executable
$resolvedOpenSsl = $null
if (Get-Command $OpenSslPath -ErrorAction SilentlyContinue) {
    $resolvedOpenSsl = $OpenSslPath
} elseif (Test-Path "C:\VMS\Apache24\bin\openssl.exe") {
    $resolvedOpenSsl = "C:\VMS\Apache24\bin\openssl.exe"
} else {
    Write-Warning "OpenSSL binary not found at [$OpenSslPath]. Using 'openssl' command directly."
    $resolvedOpenSsl = "openssl"
}

$caKeyPath = Join-Path $OutputDir "vms-root-ca.key"
$caCertPath = Join-Path $OutputDir "vms-root-ca.crt"
$serverKeyPath = Join-Path $OutputDir "vms-server.key"
$serverCsrPath = Join-Path $OutputDir "vms-server.csr"
$serverCertPath = Join-Path $OutputDir "vms-server.crt"
$importBatPath = Join-Path $OutputDir "import-root-ca.bat"
$caCommonName = "VMS Internal Root CA ($Hostname)"

Write-Host "Menyiapkan sertifikat SSL di: $OutputDir" -ForegroundColor Cyan
Write-Host "Server IP: $ServerIp | Hostname: $Hostname | PT: $Organization" -ForegroundColor Cyan

# 1. OpenSSL Configuration for Root CA
$caCnfContent = @"
[req]
distinguished_name = req_distinguished_name
x509_extensions = v3_ca
prompt = no

[req_distinguished_name]
C = ID
ST = Kepulauan Riau
L = Batam
O = $Organization
OU = Security & IT Infrastructure
CN = $caCommonName

[v3_ca]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true
keyUsage = critical, digitalSignature, cRLSign, keyCertSign
nameConstraints = critical, permitted;IP:192.168.0.0/255.255.0.0, permitted;IP:172.16.0.0/255.240.0.0, permitted;IP:10.0.0.0/255.0.0.0, permitted;IP:127.0.0.0/255.0.0.0, permitted;DNS:$Hostname, permitted;DNS:localhost
"@

$caCnfPath = Join-Path $OutputDir "ca.cnf"
$caCnfContent | Set-Content -Path $caCnfPath -Encoding ASCII

# 2. OpenSSL Configuration for Server Certificate
$serverCnfContent = @"
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
C = ID
ST = Kepulauan Riau
L = Batam
O = $Organization
OU = Visitor Management System
CN = $Hostname

[v3_req]
basicConstraints = CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
IP.1 = $ServerIp
IP.2 = 127.0.0.1
DNS.1 = $Hostname
DNS.2 = localhost
"@

$serverCnfPath = Join-Path $OutputDir "server.cnf"
$serverCnfContent | Set-Content -Path $serverCnfPath -Encoding ASCII

# 3. Generate Root CA if not exists or if -Force
$newRoot = $false
if ($Force -or (-not (Test-Path $caCertPath))) {
    Write-Host "Membuat Root CA baru (10 tahun): $caCommonName" -ForegroundColor Yellow
    Invoke-OpenSsl @('req', '-x509', '-new', '-nodes', '-newkey', 'rsa:4096', '-keyout', $caKeyPath, '-out', $caCertPath, '-days', '3650', '-config', $caCnfPath)
    $newRoot = $true
} else {
    Write-Host "Root CA sudah ada di $caCertPath (gunakan -Force untuk membuat ulang)." -ForegroundColor Gray
}

# 4. Generate Server Key and CSR
Write-Host "Membuat Server Private Key dan CSR..." -ForegroundColor Yellow
Invoke-OpenSsl @('req', '-new', '-nodes', '-newkey', 'rsa:2048', '-keyout', $serverKeyPath, '-out', $serverCsrPath, '-config', $serverCnfPath)

# 5. Sign Server Certificate with Root CA
Write-Host "Menandatangani Server Certificate dengan Root CA (2 tahun)..." -ForegroundColor Yellow
Invoke-OpenSsl @('x509', '-req', '-in', $serverCsrPath, '-CA', $caCertPath, '-CAkey', $caKeyPath, '-CAcreateserial', '-out', $serverCertPath, '-days', '730', '-extfile', $serverCnfPath, '-extensions', 'v3_req')

# 6. Prove the chain before anyone installs it: a server name outside the root's constraints fails here
#    ("permitted subtree violation"), not later in every browser as NET::ERR_CERT_INVALID.
Write-Host "Memverifikasi rantai sertifikat..." -ForegroundColor Yellow
Invoke-OpenSsl @('verify', '-CAfile', $caCertPath, $serverCertPath)

# 7. Batch script for counter PCs: remove older VMS roots (the pre-2026-09-30 name and this one), then import.
$batContent = @"
@echo off
echo Menghapus root VMS lama (bila ada)...
certutil -delstore Root "VMS Internal Root CA" >nul 2>&1
certutil -delstore Root "$caCommonName" >nul 2>&1
echo Mengimpor $caCommonName ke Trusted Root Certification Authorities...
certutil -addstore -f Root "%~dp0vms-root-ca.crt"
if %ERRORLEVEL% equ 0 (
    echo Sukses! Tutup SEMUA jendela browser lalu buka lagi https://$ServerIp/
) else (
    echo Gagal mengimpor sertifikat. Pastikan dijalankan sebagai Administrator!
)
pause
"@
$batContent | Set-Content -Path $importBatPath -Encoding ASCII

Write-Host "Selesai! Berkas sertifikat tersimpan di ${OutputDir}:" -ForegroundColor Green
Write-Host "  - Root CA Public: $caCertPath ($caCommonName)" -ForegroundColor Green
Write-Host "  - Server Cert:    $serverCertPath" -ForegroundColor Green
Write-Host "  - Server Key:     $serverKeyPath" -ForegroundColor Green
Write-Host "  - Client Import:  $importBatPath" -ForegroundColor Green
if ($newRoot) {
    Write-Host "Root CA BARU: jalankan import-root-ca.bat sebagai Administrator di server ini dan di SETIAP PC konter," -ForegroundColor Yellow
    Write-Host "lalu Restart-Service VMS-Apache. Sampai itu browser menjawab NET::ERR_CERT_AUTHORITY_INVALID." -ForegroundColor Yellow
}
Write-Host "Perhatian: Simpan private key Root CA ($caKeyPath) di tempat aman offline!" -ForegroundColor Red
