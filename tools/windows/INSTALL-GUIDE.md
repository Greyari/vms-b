# Panduan Instalasi dan Deployment Server Lokal VMS Windows 11 PC

Dokumen ini merupakan panduan instalasi langkah demi langkah (*runbook*) untuk menyiapkan server produksi lokal
VMS pada satu PC Windows 11 Pro 64-bit di LAN sebuah yard. Setiap yard (CIS, ASL) punya servernya sendiri dan
mengikuti panduan yang sama; yang berbeda hanya isian bertanda `<…>`.

- **Referensi desain:** `D:\INIT-ASL\docs\superpowers\specs\2026-09-13-vms-local-server-parity-design.md`
  (§2.1–§2.10, §6.3). **Keputusan hybrid multi-yard yang mengikat:** `D:\INIT-ASL\docs\decisions\vms-hybrid-decisions.md`.
- **Menghubungkan server ke Cloud Hub:** `D:\INIT-ASL\docs\vms-multi-yard-setup-runbook.md` (setelah panduan ini selesai).
- **Status:** belum pernah dijalankan utuh di PC target. Direvisi 2026-09-29 (OPS-5f, OPS-8, PE-7, BE-6): contoh
  password dihapus, prosedur instalasi pertama dibetulkan, sumber waktu diganti ke NTP internet.

**Aturan kredensial:** tidak ada password yang ditulis ke dokumen ini, tiket, atau chat. Setiap `<…>` diisi di
server saja, dan password PostgreSQL **unik per server**.

---

## 1. Spesifikasi Mesin & Persiapan Windows

### A. Persyaratan Perangkat Keras
- **Sistem Operasi:** Windows 11 Pro 64-bit (versi 22H2 / 23H2 / 24H2).
- **Prosesor:** Minimal 4 Core (disarankan 8 Core / Intel Core i5/i7 Gen 10+ atau setara AMD Ryzen).
- **RAM:** Minimal 8 GB (disarankan 16 GB).
- **Penyimpanan:** Minimal SSD 256 GB NVMe / SATA (rekomendasi: disk sekunder terpasang untuk backup harian).
- **UPS (Uninterruptible Power Supply):** Wajib terpasang untuk menjaga daya saat listrik galangan padam.

### B. Konfigurasi Sistem Operasi & Jaringan
1. **IP Statis LAN:**
   - Berikan IP statis pada adapter Ethernet LAN (`<IP-server>/24`, Gateway dan DNS sesuai jaringan yard).
   - Tetapkan nama host komputer, misalnya `vms-server`.
2. **Pengaturan Daya (Power Plan):**
   - Buka Control Panel -> Power Options -> Pilih **High Performance**.
   - Klik *Change plan settings*:
     - *Turn off the display*: **15 minutes** (atau Never).
     - *Put the computer to sleep*: **Never** (Wajib!).
3. **Windows Update Active Hours:**
   - Masuk ke Settings -> Windows Update -> Advanced options -> *Active hours*: Setel **05:00 s/d 23:00**.
   - Nonaktifkan restart otomatis di luar jam kerja; restart pemeliharaan hanya dilakukan secara manual oleh tim IT.
4. **Struktur Direktori Standar:**
   ```powershell
   New-Item -ItemType Directory -Path "C:\VMS\app", "C:\VMS\php", "C:\VMS\Apache24", "C:\VMS\certs", "C:\VMS\backups\daily", "C:\VMS\releases", "C:\VMS\tools" -Force
   ```
   `C:\VMS\app` berisi rilis yang sedang jalan (ditukar oleh `deploy.ps1`), `C:\VMS\releases` menyimpan ZIP dan
   rilis sebelumnya, `C:\VMS\tools` tempat salinan `deploy.ps1` dijalankan (§7), dan `C:\VMS\secrets` dibuat
   oleh `setup-pgpass.ps1` (§2.4).

---

## 2. Instalasi PostgreSQL 18

1. **Unduh & Jalankan Installer:**
   - Unduh installer resmi PostgreSQL 18 x64 dari EnterpriseDB (EDB).
   - Jalankan installer dengan hak akses Administrator.
   - Direktori instalasi: `C:\Program Files\PostgreSQL\18`. Port: `5432`.
   - Tetapkan password superuser `postgres` (unik untuk server ini).
   - Tambahkan `C:\Program Files\PostgreSQL\18\bin` ke `PATH` sistem (`deploy.ps1` memanggil `pg_dump` dan
     `pg_restore` dari PATH).
2. **Konfigurasi `postgresql.conf`** (`C:\Program Files\PostgreSQL\18\data\postgresql.conf`):
   ```ini
   listen_addresses = 'localhost'
   port = 5432
   lc_messages = 'C'
   ```
   `lc_messages = 'C'` membuat pesan galat PostgreSQL berbahasa Inggris tanpa terjemahan — deteksi bentrok nomor
   tiket / nomor visitor (Plan 2A) membaca pesan itu. Nilai `'C'` selalu sah di Windows, berbeda dengan nama
   locale gaya Linux seperti `en_US.UTF-8` yang bisa membuat service gagal start. Restart service, lalu
   `psql -U postgres -c "SHOW lc_messages;"` harus menjawab `C`.
3. **Database & role aplikasi (OPS-5f):** aplikasi memakai `vms_app`, **bukan** `postgres`. Di `psql -U postgres`:
   ```sql
   CREATE ROLE vms_app WITH LOGIN PASSWORD '<password-vms_app-unik-server-ini>';
   CREATE DATABASE db_vms OWNER vms_app;
   ```
   `vms_app` memiliki database-nya (migrasi butuh DDL) tetapi bukan superuser. `postgres` hanya dipakai untuk
   dump/restore saat deploy.
4. **Berkas password untuk dump/restore:** setelah paket rilis pertama diekstrak (§7 langkah 2), jalankan dari
   PowerShell Administrator:
   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-pgpass.ps1
   ```
   Skrip menanyakan password `postgres` dan `vms_app` di konsol (tidak masuk riwayat PowerShell), menulis
   `C:\VMS\secrets\pgpass.conf`, dan mengunci foldernya hanya untuk Administrators dan SYSTEM. `deploy.ps1` dan
   backup harian membaca berkas itu lewat `PGPASSFILE` dan selalu memakai `-w`, jadi tidak pernah menunggu
   password. Setelah runner GitHub dipasang (roadmap langkah 9), jalankan ulang dengan
   `-GrantUser svc-vms-runner`. Mengganti password = jalankan ulang skrip ini.
5. **Verifikasi Service:** Windows Service `postgresql-x64-18` berjalan dengan Startup Type **Automatic**.

---

## 3. Instalasi Visual C++ Redistributable & Apache 2.4 Lounge

1. **Visual C++ Redistributable:**
   - Unduh dan instal `vc_redist.x64.exe` (Visual C++ 2015–2022 x64) dari Microsoft.
2. **Ekstrak Apache 2.4 Lounge:**
   - Unduh paket Apache 2.4 Lounge (VS17 x64) `httpd-2.4.*-win64-VS17.zip`.
   - Ekstrak seluruh isi folder `Apache24` ke direktori `C:\VMS\Apache24`.
3. **Salin Konfigurasi VMS:**
   - Salin template konfigurasi dari `tools/windows/apache/httpd-vms.conf` ke `C:\VMS\Apache24\conf\extra\httpd-vms.conf`.
   - Buka `C:\VMS\Apache24\conf\httpd.conf`:
     - Ubah `Define SRVROOT "C:/VMS/Apache24"`.
     - Aktifkan modul: `mod_ssl`, `mod_rewrite`, `mod_headers`, `mod_socache_shmcb`.
     - Tambahkan baris berikut di baris paling bawah:
       ```apache
       Include conf/extra/httpd-vms.conf
       ```
4. **Daftarkan Service Apache:**
   - Buka PowerShell sebagai Administrator:
     ```powershell
     cd C:\VMS\Apache24\bin
     .\httpd.exe -k install -n "VMS-Apache"
     ```
   - Atur pemulihan otomatis service bila crash:
     ```powershell
     sc.exe failure "VMS-Apache" reset= 86400 actions= restart/60000/restart/60000/restart/60000
     ```

---

## 4. Instalasi PHP 8.4 Thread Safe

1. **Ekstrak PHP:**
   - Unduh PHP 8.4 x64 Thread Safe ZIP dari `windows.php.net`.
   - Ekstrak seluruh isi ke `C:\VMS\php`, lalu tambahkan `C:\VMS\php` ke `PATH` sistem.
2. **Konfigurasi `php.ini`:**
   - Salin berkas `tools/windows/php/php-production.ini` ke `C:\VMS\php\php.ini`.
   - Pastikan folder sementara `C:\VMS\php\temp` dan `C:\VMS\php\logs` telah dibuat.
3. **Uji Modul PHP:**
   ```powershell
   php -v
   php -m
   ```
   Pastikan ekstensi `pdo_pgsql`, `pgsql`, `intl`, `gd`, `openssl`, `mbstring`, `zip`, `curl`, dan
   `Zend OPcache` muncul. (`pdo_mysql` sengaja tidak dimuat; hanya server CIS memakainya sekali, saat ekspor
   pilot di malam cutover, lewat `php -d extension=pdo_mysql` — lihat `docs/cutover-runbook.md`.)

---

## 5. Pembuatan Sertifikat SSL & Otoritas CA Internal

1. **Jalankan Generator Sertifikat** (setelah §7 langkah 2, karena skrip ikut di paket rilis):
   ```powershell
   cd C:\VMS\app\tools\windows
   .\setup-ca.ps1 -ServerIp "<IP-server>" -Hostname "<nama-host>.local" -OutputDir "C:\VMS\certs" -OpenSslPath "C:\VMS\Apache24\bin\openssl.exe"
   ```
2. **Hasil Berkas:**
   - `C:\VMS\certs\vms-root-ca.crt` (Root CA publik).
   - `C:\VMS\certs\vms-root-ca.key` (Kunci privat Root CA — pindahkan ke flashdisk IT offline setelah selesai!).
   - `C:\VMS\certs\vms-server.crt` & `vms-server.key` (Sertifikat & kunci server Apache).
   - `C:\VMS\certs\import-root-ca.bat` (Skrip installer untuk komputer konter).
3. **Pemasangan di PC Konter Security (Wajib):**
   - Salin `vms-root-ca.crt` dan `import-root-ca.bat` ke setiap PC konter gerbang security.
   - Klik kanan `import-root-ca.bat` -> **Run as Administrator** (menjalankan `certutil -addstore -f Root vms-root-ca.crt`).
   - Buka browser Chrome/Edge di PC konter: mengakses `https://<IP-server>` tidak boleh memunculkan peringatan
     sertifikat.

---

## 6. Konfigurasi Lingkungan Produksi `.env`

Buat berkas `C:\VMS\app\.env` (setelah §7 langkah 2). Nilai bertanda `<…>` diisi di server; bagian Cloud Hub
dijelaskan di runbook setup multi-yard §4–§5.

```ini
APP_NAME="VMS"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://<IP-server>

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=db_vms
DB_USERNAME=vms_app
DB_PASSWORD="<password vms_app>"

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
MAIL_MAILER=log

# Sesi & Cache di Database (tanpa Redis)
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

# Keamanan Jaringan LAN (wajib dikosongkan di server lokal: Apache melihat PC konter langsung)
TRUSTED_PROXIES=

# Akun awal, hanya dibaca saat db:seed pertama (§7). Hapus kedua password setelah seed berhasil.
VMS_ADMIN_EMAIL=admin@<yard>.local
VMS_ADMIN_NAME="VMS Admin"
VMS_ADMIN_PASSWORD="<password-awal-admin>"
VMS_SECURITY_EMAIL=security@<yard>.local
VMS_SECURITY_NAME="VMS Security"
VMS_SECURITY_PASSWORD="<password-awal-security>"

# Branding yard ini di pass dan PDF laporan (PE-7). Logo = NAMA berkas di C:\VMS\app\public\branding\
# (dibawa otomatis oleh deploy.ps1 ke setiap rilis berikutnya); kosongkan bila tanpa logo.
VMS_COMPANY_NAME="<nama PT yard ini>"
VMS_COMPANY_ADDRESS="<alamat singkat>"
VMS_COMPANY_LOGO=

# Kode yard: CIS atau ASL. vms:import menolak data yard lain (PE-8) dan sync memakai kode ini (BE-6).
VMS_YARD_CODE=<CIS|ASL>

# Sync ke Cloud Hub — mati sampai checklist runbook setup multi-yard §8 lulus
VMS_SYNC_ENABLED=false
VMS_CLOUD_HUB_URL=
VMS_CLOUD_HMAC_KEY_ID=
VMS_CLOUD_HMAC_SECRET=
```

---

## 7. Instalasi Pertama & Deployment Berikutnya

### Membuat paket rilis (di laptop pengembang)
```powershell
powershell -ExecutionPolicy Bypass -File D:\INIT-ASL\VMS\VMS-backend-laravel\tools\windows\build-release.ps1
```
ZIP (`vms-release-<versi>.zip`) dan manifest SHA-256-nya muncul di `D:\INIT-ASL\VMS\release\`. Salin ZIP ke
`C:\VMS\releases\` di server.

### Instalasi pertama (server kosong)
`deploy.ps1` hanya untuk server yang sudah punya aplikasi jalan (ia membutuhkan `.env` dan `storage\` rilis
lama). Server baru dipasang begini, dari PowerShell Administrator:

1. `Stop-Service VMS-Apache -ErrorAction SilentlyContinue`
2. `Expand-Archive C:\VMS\releases\vms-release-<versi>.zip -DestinationPath C:\VMS\app`
3. Buat `C:\VMS\app\.env` (§6), lalu jalankan `setup-pgpass.ps1` (§2.4) dan `setup-ca.ps1` (§5).
4. Di `C:\VMS\app`:
   ```powershell
   php artisan key:generate --force
   php artisan migrate --force
   php artisan db:seed --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
   Urutan ini penting: seeder membaca `VMS_ADMIN_*`/`VMS_SECURITY_*` langsung dari `.env`, yang tidak terbaca
   lagi setelah `config:cache`. Setelah seed berhasil, hapus kedua password awal dari `.env` lalu jalankan
   `php artisan config:cache` sekali lagi.
5. Logo yard (opsional): salin berkas logo ke `C:\VMS\app\public\branding\` dan isi `VMS_COMPANY_LOGO` dengan
   nama berkasnya.
6. `Start-Service VMS-Apache`, lalu `curl.exe -k https://127.0.0.1/api/health-db` harus menjawab `{"status":"ok"}`.

### Deployment berikutnya
Folder `C:\VMS\app` dipindah saat deploy, jadi `deploy.ps1` dijalankan dari salinannya di luar folder itu
(skrip menolak jalan dari dalam `C:\VMS\app`):
```powershell
Copy-Item C:\VMS\app\tools\windows\deploy.ps1 C:\VMS\tools\deploy.ps1 -Force
C:\VMS\tools\deploy.ps1 -ReleaseZip "C:\VMS\releases\vms-release-<versi>.zip"
```
Urutannya (OPS-5): ekstrak ke folder baru + bawa `.env` dan `public\branding\` → `artisan down` → `pg_dump`
wajib sukses → jeda task `VMS-Cloud-Sync` → tukar folder → migrate → cache → start Apache → probe
`GET https://127.0.0.1/api/health-db` 6 kali tiap 5 detik → `artisan up`. Gagal di langkah mana pun setelah
tukar folder = rilis lama kembali + dump deploy itu di-restore otomatis. Rollback manual:
`C:\VMS\tools\deploy.ps1 -Rollback` (tambah `-RestoreDump <berkas>` hanya bila database juga harus kembali).

---

## 8. Penjadwalan Backup Otomatis Database

```powershell
powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-backup-task.ps1 -DailyTime "02:00" -SecondaryDir "D:\VMS-Backups"
```
Backup berjalan setiap hari pukul 02:00 WIB sebagai akun `SYSTEM` tanpa perlu login. `backup-db.ps1` memakai
`vms_app` dan `-w`, dengan password dari `C:\VMS\secrets\pgpass.conf` (§2.4) — tanpa berkas itu backup
**gagal** (tercatat di `C:\VMS\backups\daily\backup.log`), tidak menggantung. Uji sekali setelah didaftarkan:
`schtasks /Run /TN VMS-Daily-Backup`, lalu pastikan berkas `db_vms-*.dump` baru muncul dan tidak kosong.

---

## 9. Firewall & Sinkronisasi Waktu

1. **Windows Defender Firewall:**
   - Izinkan HTTP (80) dan HTTPS (443) hanya dari subnet LAN yard:
     ```powershell
     New-NetFirewallRule -DisplayName "VMS Web HTTP" -Direction Inbound -LocalPort 80 -Protocol TCP -Action Allow -RemoteAddress <subnet-LAN-yard>
     New-NetFirewallRule -DisplayName "VMS Web HTTPS" -Direction Inbound -LocalPort 443 -Protocol TCP -Action Allow -RemoteAddress <subnet-LAN-yard>
     ```
   - Port 5432 (PostgreSQL) tidak dibuka (hanya localhost). Tidak ada port masuk dari internet.
   - Keluar ke internet hanya dibutuhkan untuk HTTPS 443 (Cloud Hub, GitHub) dan UDP 123 (NTP).
2. **Sinkronisasi Waktu — NTP internet (OPS-8):** yard tidak punya server waktu di LAN; server VMS sync langsung
   ke NTP internet tiap jam (tanda tangan sync ke hub hanya menerima selisih ±300 detik). Dari PowerShell
   Administrator:
   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\VMS\app\tools\windows\setup-time-sync.ps1
   ```
   Syarat lulus: `w32tm /query /status` menyebut sumber NTP (bukan `Local CMOS Clock`).

---

## 10. Checklist Penerimaan Server Lokal (Spec §6.3)

Sebelum konter gerbang beralih menggunakan server baru, checklist penerimaan berikut wajib diuji:

- [ ] **1. Sertifikat HTTPS:** `https://<IP-server>` dibuka di PC konter tanpa peringatan sertifikat.
- [ ] **2. Login Username:** Petugas berhasil login menggunakan username tanpa peka huruf besar-kecil.
- [ ] **3. Siklus Kunjungan Lengkap:** Check-in -> cetak pass ke printer konter -> Check-out berhasil; pass
      mencetak nama PT **yard ini** (PE-7).
- [ ] **4. Cetak Ulang & Laporan:** Reprint, Edit Kunjungan, Visitor Report (Print, Excel, PDF), Vessel Report,
      dan Crew Arrival berfungsi normal.
- [ ] **5. Simulasi Internet / WAN Putus:** Lepas kabel uplink internet. Check-in, cetak pass, dan laporan tetap
      berjalan 100%.
- [ ] **6. Reboot Tanpa Login:** Restart PC server tanpa login desktop; `postgresql-x64-18` dan `VMS-Apache`
      menyala sendiri dan PC konter bisa mengakses kembali.
- [ ] **7. Backup & Restore:** task `VMS-Daily-Backup` menghasilkan dump tidak kosong, dan dump itu berhasil
      di-restore ke database sementara (`vms_restore_test`) tanpa galat.
- [ ] **8. Sinkronisasi Jam:** `w32tm /query /status` bersumber NTP dan selisih ke `time.windows.com` < 2 detik.
- [ ] **9. Deploy & Rollback:** satu deploy rilis uji lewat `C:\VMS\tools\deploy.ps1` sukses, lalu
      `-Rollback` mengembalikan rilis sebelumnya dengan `health-db` tetap `ok`.
