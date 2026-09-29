# VMS — Backend Gerbang

API visitor management galangan (ASL dan CIS): registrasi dan check-in/check-out visitor, kartu visitor dan
kendaraan, blacklist lokal, cetak pass, crew arrival, laporan. Laravel 13 · PHP 8.4 · PostgreSQL 18 ·
Sanctum + spatie-permission.

Gerbang berjalan **offline di satu PC Windows per yard**; SPA (`VMS-web-react`) dibangun ke `public/` dan
dilayani dari origin yang sama. Arah program (hub cloud untuk pra-registrasi dan blacklist global) ada di
`D:\INIT-ASL\docs\decisions\vms-hybrid-decisions.md` (otoritatif) dan roadmap
`D:\INIT-ASL\docs\superpowers\plans\2026-09-25-vms-hybrid-azure-multiyard-plan.md`.

## Setup lokal

Prasyarat: PHP 8.4 (ekstensi `pdo_pgsql`, `intl`, `zip`, `gd`, `mbstring`), Composer, PostgreSQL 18
(di laptop dev: container `postgres-lokal`).

```bash
composer install
cp .env.example .env        # isi DB_PASSWORD dan VMS_ADMIN_PASSWORD
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8003
```

Akun awal: `VMS_ADMIN_EMAIL` (default `admin@vms.local`) dengan password dari `.env`.

## Test

```bash
php artisan test                                                        # SQLite in-memory
DB_CONNECTION=pgsql DB_DATABASE=vms_test DB_PASSWORD=<lokal> php artisan test   # PostgreSQL 18
./vendor/bin/pint --test
```

CI (`.github/workflows/quality.yml`) menjalankan `composer audit`, Pint, suite SQLite, dan suite PostgreSQL 18.

## Paket server Windows

`tools/windows/` berisi semua yang dipasang di PC server yard: konfigurasi Apache/PHP, CA internal
(`setup-ca.ps1`), paket rilis (`build-release.ps1`), deploy + rollback (`deploy.ps1`), backup harian, ekspor
VMS-old, latihan cutover, audit SLA, dan `INSTALL-GUIDE.md`. Runbook malam cutover: `docs/cutover-runbook.md`.
Status: kode ada, **belum diverifikasi di PC target maupun data asli**; `deploy.ps1` dan runbook cutover masih
direvisi (roadmap hybrid langkah 4).

```powershell
powershell .\tools\windows\build-release.ps1 -OutputDir release   # default: VMS-web-react di sebelah repo ini
```

## Deploy

- **On-prem (target):** paket ZIP dari `build-release.ps1`, dipasang `deploy.ps1` di PC server; nanti lewat
  workflow `deploy-onprem.yml` yang dipicu manual (roadmap hybrid langkah 9).
- **Azure (dipensiunkan 2026-09-28):** `.github/workflows/deploy.yml` sudah dimatikan; push ke `production` tidak
  men-deploy apa pun. Jangan diaktifkan lagi — App Service-nya kini milik Cloud Hub.

## Git

Commit, push, dan perubahan index dikerjakan owner.
