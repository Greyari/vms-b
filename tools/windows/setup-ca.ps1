<#
.SYNOPSIS
    Generator Root CA Internal dan Sertifikat SSL Server VMS untuk Jaringan LAN.
.DESCRIPTION
    Menghasilkan:
    1. Root CA "VMS Internal CA" (10 tahun) dengan Name Constraints khusus LAN.
    2. Server SSL Certificate (2 tahun) dengan Subject Alternative Name (SAN).
    3. Script instalasi certutil untuk PC konter gerbang (import-root-ca.bat).
    Spec Reference: 2026-09-13-vms-local-server-parity-design.md (§2.4).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$ServerIp = "127.0.0.1",

    [Parameter(Mandatory = $false)]
    [string]$Hostname = "vms.asl.local",

    [Parameter(Mandatory = $false)]
    [string]$OutputDir = "C:\VMS\certs",

    [Parameter(Mandatory = $false)]
    [string]$OpenSslPath = "openssl",

    [Parameter(Mandatory = $false)]
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

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

Write-Host "Menyiapkan sertifikat SSL di: $OutputDir" -ForegroundColor Cyan
Write-Host "Server IP: $ServerIp | Hostname: $Hostname" -ForegroundColor Cyan

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
O = PT. ASL Shipyard Indonesia
OU = Security & IT Infrastructure
CN = VMS Internal Root CA

[v3_ca]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true
keyUsage = critical, digitalSignature, cRLSign, keyCertSign
nameConstraints = critical, permitted;IP:192.168.0.0/255.255.0.0, permitted;IP:10.0.0.0/255.0.0.0, permitted;IP:127.0.0.0/255.0.0.0, permitted;DNS:.asl.local, permitted;DNS:localhost
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
O = PT. ASL Shipyard Indonesia
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
if ($Force -or (-not (Test-Path $caCertPath))) {
    Write-Host "Membuat Root CA baru (10 tahun)..." -ForegroundColor Yellow
    & $resolvedOpenSsl req -x509 -new -nodes -newkey rsa:4096 -keyout $caKeyPath -out $caCertPath -days 3650 -config $caCnfPath
} else {
    Write-Host "Root CA sudah ada di $caCertPath (gunakan -Force untuk membuat ulang)." -ForegroundColor Gray
}

# 4. Generate Server Key and CSR
Write-Host "Membuat Server Private Key dan CSR..." -ForegroundColor Yellow
& $resolvedOpenSsl req -new -nodes -newkey rsa:2048 -keyout $serverKeyPath -out $serverCsrPath -config $serverCnfPath

# 5. Sign Server Certificate with Root CA
Write-Host "Menandatangani Server Certificate dengan Root CA (2 tahun)..." -ForegroundColor Yellow
& $resolvedOpenSsl x509 -req -in $serverCsrPath -CA $caCertPath -CAkey $caKeyPath -CAcreateserial -out $serverCertPath -days 730 -extfile $serverCnfPath -extensions v3_req

# 6. Generate Batch Script for Counter PCs
$batContent = @"
@echo off
echo Mengimpor VMS Root CA ke Trusted Root Certification Authorities...
certutil -addstore -f Root "%~dp0vms-root-ca.crt"
if %ERRORLEVEL% equ 0 (
    echo Sukses! VMS Root CA berhasil dipercaya pada komputer ini.
) else (
    echo Gagal mengimpor sertifikat. Pastikan dijalankan sebagai Administrator!
)
pause
"@
$batContent | Set-Content -Path $importBatPath -Encoding ASCII

Write-Host "Selesai! Berkas sertifikat tersimpan di ${OutputDir}:" -ForegroundColor Green
Write-Host "  - Root CA Public: $caCertPath" -ForegroundColor Green
Write-Host "  - Server Cert:    $serverCertPath" -ForegroundColor Green
Write-Host "  - Server Key:     $serverKeyPath" -ForegroundColor Green
Write-Host "  - Client Import:  $importBatPath" -ForegroundColor Green
Write-Host "Perhatian: Simpan private key Root CA ($caKeyPath) di tempat aman offline!" -ForegroundColor Red
