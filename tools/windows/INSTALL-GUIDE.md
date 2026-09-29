# Panduan Instalasi dan Deployment Server Lokal VMS Windows 11 PC

Dokumen ini merupakan panduan resmi instalasi langkah demi langkah (*runbook*) untuk menyiapkan server produksi lokal VMS pada satu PC Windows 11 Pro 64-bit yang terhubung ke Local Area Network (LAN) Shipyard.

**Referensi Desain:** `D:\INIT-ASL\docs\superpowers\specs\2026-09-13-vms-local-server-parity-design.md` (§2.1–§2.10, §6.3).

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
   - Berikan IP statis pada adapter Ethernet LAN (misal: `192.168.10.200/24`, Gateway: `192.168.10.1`, DNS: `192.168.10.1`).
   - Tetapkan nama host komputer menjadi `vms-server` (atau nama domain lokal `vms.asl.local`).
2. **Pengaturan Daya (Power Plan):**
   - Buka Control Panel -> Power Options -> Pilih **High Performance**.
   - Klik *Change plan settings*:
     - *Turn off the display*: **15 minutes** (atau Never).
     - *Put the computer to sleep*: **Never** (Wajib!).
3. **Windows Update Active Hours:**
   - Masuk ke Settings -> Windows Update -> Advanced options -> *Active hours*: Setel **05:00 s/d 23:00**.
   - Nonaktifkan restart otomatis di luar jam kerja; restart pemeliharaan hanya dilakukan secara manual oleh tim IT.
4. **Struktur Direktori Standar:**
   - Buat struktur folder utama di drive `C:`:
     ```powershell
     New-Item -ItemType Directory -Path "C:\VMS\app", "C:\VMS\php", "C:\VMS\Apache24", "C:\VMS\certs", "C:\VMS\backups\daily", "C:\VMS\releases\previous", "C:\VMS\tools" -Force
     ```

---

## 2. Instalasi PostgreSQL 18

1. **Unduh & Jalankan Installer:**
   - Unduh installer resmi PostgreSQL 18 x64 dari EnterpriseDB (EDB).
   - Jalankan installer dengan hak akses Administrator.
   - Direktori instalasi: `C:\Program Files\PostgreSQL\18`.
   - Tetapkan port: `5432`.
   - Tetapkan password akun superuser `postgres`.
2. **Konfigurasi `postgresql.conf`:**
   - Buka berkas `C:\Program Files\PostgreSQL\18\data\postgresql.conf`.
   - Pastikan binding hanya ke localhost:
     ```ini
     listen_addresses = 'localhost'
     port = 5432
     ```
   - **PENTING (Temuan Rencana 2A):** Setel pesan lokal ke bahasa Inggris agar deteksi bentrok nomor tiket / nomor visitor mengenali format error bahasa Inggris:
     ```ini
     lc_messages = 'en_US.UTF-8'
     ```
3. **Inisialisasi Database & Role Pengguna:**
   - Buka SQL Shell (`psql`) atau Command Prompt:
     ```sql
     CREATE ROLE vms_app WITH LOGIN PASSWORD 'vms_rahasia_aman_2026';
     CREATE DATABASE db_vms OWNER vms_app;
     GRANT ALL PRIVILEGES ON DATABASE db_vms TO vms_app;
     \c db_vms
     GRANT ALL ON SCHEMA public TO vms_app;
     ```
4. **Verifikasi Service:**
   - Pastikan Windows Service `postgresql-x64-18` berjalan dengan Startup Type **Automatic**.

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
   - Ekstrak seluruh isi ke `C:\VMS\php`.
2. **Konfigurasi `php.ini`:**
   - Salin berkas `tools/windows/php/php-production.ini` ke `C:\VMS\php\php.ini`.
   - Pastikan folder sementara `C:\VMS\php\temp` dan `C:\VMS\php\logs` telah dibuat.
3. **Uji Modul PHP:**
   - Buka PowerShell:
     ```powershell
     C:\VMS\php\php.exe -v
     C:\VMS\php\php.exe -m
     ```
   - Pastikan ekstensi `pdo_pgsql`, `pgsql`, `intl`, `gd`, `openssl`, `mbstring`, `zip`, dan `Zend OPcache` muncul dalam daftar.

---

## 5. Pembuatan Sertifikat SSL & Otoritas CA Internal

1. **Jalankan Generator Sertifikat:**
   - Buka PowerShell di server lokal:
     ```powershell
     cd C:\VMS\tools\windows
     .\setup-ca.ps1 -ServerIp "192.168.10.200" -Hostname "vms.asl.local" -OutputDir "C:\VMS\certs" -OpenSslPath "C:\VMS\Apache24\bin\openssl.exe"
     ```
2. **Hasil Berkas:**
   - `C:\VMS\certs\vms-root-ca.crt` (Root CA publik).
   - `C:\VMS\certs\vms-root-ca.key` (Kunci privat Root CA — pindahkan ke flashdisk IT offline setelah selesai!).
   - `C:\VMS\certs\vms-server.crt` & `vms-server.key` (Sertifikat & kunci server Apache).
   - `C:\VMS\certs\import-root-ca.bat` (Skrip installer untuk komputer konter).
3. **Pemasangan di PC Konter Security (Wajib):**
   - Salin `vms-root-ca.crt` dan `import-root-ca.bat` ke setiap PC konter gerbang security.
   - Klik kanan `import-root-ca.bat` -> **Run as Administrator** (menjalankan `certutil -addstore -f Root vms-root-ca.crt`).
   - Buka browser Chrome/Edge di PC konter: pastikan mengakses `https://192.168.10.200` tidak memunculkan peringatan sertifikat merah.

---

## 6. Konfigurasi Lingkungan Produksi `.env`

Buat berkas `C:\VMS\app\.env` dengan konfigurasi berikut:

```ini
APP_NAME="VMS Shipyard"
APP_ENV=production
APP_KEY=base64:GENERATE_KEY_DENGAN_ARTISAN_KEY_GENERATE
APP_DEBUG=false
APP_URL=https://192.168.10.200

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=db_vms
DB_USERNAME=vms_app
DB_PASSWORD=vms_rahasia_aman_2026

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync

# Sesi & Cache di Database (Menjamin stabilitas tanpa Redis)
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

# Keamanan Jaringan LAN (Wajib Dikosongkan di Server Lokal)
TRUSTED_PROXIES=
```

---

## 7. Eksekusi Deployment Perdana

1. Di komputer pengembang/laptop, buat paket rilis:
   ```powershell
   cd D:\INIT-ASL\VMS
   powershell .\tools\windows\build-release.ps1 -OutputDir "D:\INIT-ASL\VMS\release"
   ```
2. Salin berkas ZIP rilis (misal `vms-release-20260925_010000.zip`) ke server `C:\VMS\releases\`.
3. Di server produksi, jalankan skrip deploy:
   ```powershell
   cd C:\VMS\tools\windows
   .\deploy.ps1 -ReleaseZip "C:\VMS\releases\vms-release-20260925_010000.zip"
   ```
4. Skrip akan memverifikasi probe kesehatan `GET https://127.0.0.1/api/health-db`. Jika respon 200, aplikasi aktif.

---

## 8. Penjadwalan Backup Otomatis Database

Jalankan skrip pendaftaran Windows Task Scheduler:
```powershell
cd C:\VMS\tools\windows
.\setup-backup-task.ps1 -DailyTime "02:00" -ScriptPath "C:\VMS\tools\windows\backup-db.ps1" -SecondaryDir "D:\VMS-Backups"
```
Backup akan berjalan otomatis setiap hari pukul 02:00 WIB sebagai akun `SYSTEM` tanpa perlu login pengguna.

---

## 9. Firewall & Sinkronisasi Waktu

1. **Windows Defender Firewall:**
   - Izinkan koneksi port HTTP (80) dan HTTPS (443) hanya dari subnet LAN shipyard:
     ```powershell
     New-NetFirewallRule -DisplayName "VMS Web HTTP" -Direction Inbound -LocalPort 80 -Protocol TCP -Action Allow -RemoteAddress 192.168.10.0/24
     New-NetFirewallRule -DisplayName "VMS Web HTTPS" -Direction Inbound -LocalPort 443 -Protocol TCP -Action Allow -RemoteAddress 192.168.10.0/24
     ```
   - Pastikan port 5432 (PostgreSQL) diblokir dari akses luar (hanya localhost).
2. **Sinkronisasi Waktu NTP LAN Lokal:**
   - Server beroperasi tanpa internet. Arahkan Windows Time Service ke Domain Controller atau Router NTP LAN lokal:
     ```powershell
     w32tm /config /manualpeerlist:"192.168.10.1" /syncfromflags:manual /reliable:yes /update
     net stop w32time ; net start w32time
     w32tm /resync
     ```

---

## 10. Checklist Penerimaan Server Lokal (Spec §6.3)

Sebelum konter gerbang beralih menggunakan server baru, 8 checklist penerimaan berikut wajib diuji dan diverifikasi:

- [ ] **1. Sertifikat HTTPS:** URL `https://192.168.10.200` dibuka di PC konter tanpa peringatan sertifikat merah (gembok hijau / aman).
- [ ] **2. Login Username:** Petugas berhasil login menggunakan username (misal `ADMIN` atau akun pos) tanpa peka huruf besar-kecil.
- [ ] **3. Siklus Kunjungan Lengkap:** Alur Check-in -> cetak pass fisik ke printer thermal/laser -> Check-out berhasil tanpa kendala.
- [ ] **4. Cetak Ulang & Laporan:** Fitur Reprint, Edit Kunjungan, Visitor Report (Print, Excel, PDF), Vessel Report, dan Crew Arrival berfungsi normal.
- [ ] **5. Simulasi Internet / WAN Putus:** Lepas kabel uplink internet dari switch LAN. Seluruh operasional check-in, cetak pass, dan laporan tetap berjalan lancar 100%.
- [ ] **6. Reboot Tanpa Login:** Restart komputer server PC Windows. Tanpa ada operator yang login ke Windows desktop, service `postgresql-x64-18` dan `VMS-Apache` otomatis menyala, dan PC konter langsung bisa mengakses kembali.
- [ ] **7. Uji Restore Database:** Berkas dump dari `C:\VMS\backups\daily\` berhasil di-restore ke database sementara (`vms_test`) tanpa data corrupt.
- [ ] **8. Sinkronisasi Jam:** Waktu sistem server cocok dengan sumber waktu LAN lokal dengan deviasi < 1 detik.
