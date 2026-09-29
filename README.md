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
(`setup-ca.ps1`), berkas password PostgreSQL (`setup-pgpass.ps1`), paket rilis (`build-release.ps1`), deploy +
rollback (`deploy.ps1`), backup harian, task sync hub (`setup-sync-task.ps1`), NTP (`setup-time-sync.ps1`),
ekspor VMS-old, latihan cutover per yard (`rehearse-cutover.ps1 -Yard CIS|ASL`), audit SLA, dan
`INSTALL-GUIDE.md` (termasuk urutan instalasi pertama). Runbook malam cutover dua target (CIS lalu ASL):
`docs/cutover-runbook.md`. Status: kode ada, **belum diverifikasi di PC target maupun data asli**.

```powershell
powershell .\tools\windows\build-release.ps1 -OutputDir release   # default: VMS-web-react di sebelah repo ini
```

Branding per yard (nama PT, alamat, logo di pass dan PDF) diatur lewat `VMS_COMPANY_*` di `.env` server dan
dibaca SPA dari `GET /api/app-config` (keputusan PE-7). Impor data lama hanya menerima sumber milik yard server
itu (`VMS_YARD_CODE`: VMS-old = ASL, pilot IIS = CIS; keputusan PE-8).

## Deploy

- **On-prem:** paket ZIP dari `build-release.ps1`, dipasang dengan salinan `deploy.ps1` yang dijalankan dari
  luar `C:\VMS\app` (lihat `INSTALL-GUIDE.md` §7), atau lewat workflow `.github/workflows/deploy-onprem.yml`
  (dipicu manual, input yard `cis`/`asl`, hanya dari `production` dengan Quality hijau; job kedua berjalan di
  runner self-hosted berlabel `yard-<yard>`). SPA dibaca lewat deploy key SSH read-only (secret
  `VMS_WEB_REACT_DEPLOY_KEY`, dipasang 2026-09-29, rotasi tahunan — OPS-3-KEY); runner di PC yard dipasang owner.
- **Azure (dipensiunkan 2026-09-28):** workflow `deploy.yml` lama dimatikan 2026-09-28 dan berkasnya dihapus
  2026-09-29; push ke `production` hanya menjalankan Quality. App Service-nya kini milik Cloud Hub.

## Git

Commit, push, dan perubahan index dikerjakan owner.
