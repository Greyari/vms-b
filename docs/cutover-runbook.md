# VMS Production Cutover Runbook (Panduan Operasional Migrasi)

- **Sistem:** Visitor Management System (VMS) — PT. ASL Shipyard Indonesia
- **Target Lingkungan:** PC Server Windows 11 Lokal (LAN Shipyard, Non-Cloud)
- **Spec Referensi:** `docs/superpowers/specs/2026-09-13-vms-local-server-parity-design.md` §5.5, §6.2, §6.3, §6.4
- **Tanggal Rilis:** 2026-09-25

---

## 1. Ikhtisar & Prinsip Dasar Cutover

1. **Operasional 24 Jam Tanpa Kegagalan:** Gerbang galangan beroperasi 24 jam sehari melayani rata-rata 300 kunjungan/hari. Cutover dijadwalkan pada jam paling sepi: **03:00–05:00 WIB** (data historis 90 hari membuktikan hanya terjadi 10–30 check-in pada rentang ini).
2. **Kemandirian Tanpa Internet:** Seluruh komponen produksi (PostgreSQL 18, Apache 2.4, PHP 8.4, sertifikat SSL internal) berjalan lokal pada jaringan LAN shipyard. Terputusnya kabel fiber optic internet tidak boleh menghentikan konter security.
3. **Pencatatan Manual Transisi:** Pada rentang 03:00–04:00 WIB saat migrasi delta berjalan, konter mencatat tamu menggunakan buku log kertas manual.
4. **Keputusan Tegas Go / No-Go:** Tepat pada pukul **03:30 WIB**, verifikasi integritas matematis dijalankan. Jika gagal, cutover dibatalkan (**No-Go**) dan gerbang langsung kembali ke sistem lama tanpa kehilangan data.

---

## 2. Struktur Tim & Pembagian Tugas

| Peran | Penanggung Jawab | Tugas Utama |
|---|---|---|
| **Cutover Commander** | IT Lead / Project Owner | Otoritas penuh keputusan Go / No-Go pada pukul 03:30 WIB |
| **Database Administrator** | DBA / Data Engineer | Eksekusi ekspor SQL Server, impor delta, verifikasi data (`vms:import-verify`) |
| **System Administrator** | DevOps / Sysadmin | Pengendalian service Apache, PostgreSQL, switch IIS legacy, backup dump |
| **Gate Security Supervisor** | Komandan Regu Security | Koordinasi pencatatan buku log manual di pos dan verifikasi fisik di konter |
| **Counter Operator** | Staf Security Konter | Pengujian alur check-in nyata, pencetakan pass setengah-A4, dan input backlog kertas |

---

## 3. Fase H-7 (Instalasi Fisik, Jaringan, PKI & Pelatihan Operator)

### 3.1. Instalasi Server Fisik
- [ ] PC Server terpasang di ruang server IT/Security dengan UPS aktif.
- [ ] Static IP dikonfigurasi: `192.168.1.10/24`, Gateway `192.168.1.1`, DNS lokal.
- [ ] PostgreSQL 18 EDB Windows Service terpasang, port 5432, parameter `lc_messages = 'en_US.UTF-8'`.
- [ ] Apache 2.4 Lounge x64 terpasang sebagai service `VMS-Apache`, port 80 (redirect) dan 443 (SSL).
- [ ] PHP 8.4 Thread Safe terpasang (`memory_limit = 512M`, `opcache.enable = 1`).

### 3.2. Distribusi Root CA ke Seluruh PC Konter
- [ ] Jalankan generator sertifikat di server:
  ```powershell
  .\tools\windows\setup-ca.ps1 -ServerIp "192.168.1.10" -Hostname "vms.asl.local"
  ```
- [ ] Bagikan berkas `import-root-ca.bat` dan `vms-root-ca.crt` ke seluruh PC konter gerbang.
- [ ] Jalankan `import-root-ca.bat` sebagai Administrator pada setiap PC konter.
- [ ] Buka browser (Chrome/Edge) ke `https://192.168.1.10/` dan pastikan ikon gembok hijau valid tanpa peringatan sertifikat.

### 3.3. Pelatihan Operator Konter
- [ ] Berikan akun uji coba untuk operator gerbang mencoba login username/password.
- [ ] Latihan pengisian form check-in, pencarian KTP, modal konflik kunjungan aktif, dan pencetakan pass pengunjung.

---

## 4. Fase H-1 (Baseline Data Importer & Pembuatan 14 Akun Legacy)

### 4.1. Persiapan Database Target
- [ ] Jalankan pembersihan skema database:
  ```powershell
  php artisan migrate:fresh --seed --force
  ```

### 4.2. Ekspor dan Impor Baseline Penuh
- [ ] Ekspor penuh data VMS-old dari SQL Server produksi:
  ```powershell
  .\tools\windows\export-vms-old.ps1 -Server "192.168.10.199" -Database "ASL-ASL-HRMS" -OutputDir "C:\VMS\migration\baseline"
  ```
- [ ] Catat nilai watermark baseline dari `C:\VMS\migration\baseline\manifest.json` (`max(LastModifiedDate)`).
- [ ] Eksekusi impor baseline data VMS-old:
  ```powershell
  php artisan vms:import --source=vms-old --path="C:\VMS\migration\baseline"
  ```
- [ ] Eksekusi impor data pilot (jika ada):
  ```powershell
  php artisan vms:import --source=pilot --path="C:\VMS\migration\pilot"
  ```
- [ ] Verifikasi baseline:
  ```powershell
  php artisan vms:import-verify --source=vms-old --path="C:\VMS\migration\baseline"
  ```
  *(Wajib exit code 0 — 7 invarian data lulus).*

### 4.3. Pembuatan 14 Akun Pengguna Legacy (Spec §5.3)
Sesuai aturan keamanan, password plaintext tidak diimpor. Administrator membuat 14 akun pengguna resmi VMS-old:
- [ ] Login ke sistem VMS baru sebagai Administrator.
- [ ] Masuk ke menu **Pengaturan Pengguna** (`/users`).
- [ ] Daftarkan 14 akun petugas sesuai daftar pegawai:
  1. Username: Huruf besar identik `EmployeeNo` (contoh: `@SEC001`, `@SEC002`).
  2. Nama: Sesuai data `EmployeeMaster.EmployeeName`.
  3. Role: Tetapkan role `security` untuk petugas gerbang, `supervisor` untuk komandan regu.
  4. Berikan password sementara yang aman kepada masing-masing petugas.
- [ ] Catat bahwa baseline siap, server berada dalam mode standby.

---

## 5. Fase Malam Cutover: Jadwal Menit-demi-Menit (03:00–05:00 WIB)

```
03:00 WIB ────> Stop VMS-old (Pencatatan Buku Log Kertas)
03:05 WIB ────> Ekspor Delta SQL Server (-Since Watermark)
03:15 WIB ────> Impor Delta ke VMS Baru (vms:import)
03:30 WIB ────> Verifikasi Integritas & KEPUTUSAN GO / NO-GO GATE
                ├─ LULUS ──> Lanjut Go-Live (03:35 Uji Fisik Konter)
                └─ GAGAL ──> NO-GO (Rollback ke VMS-old, Tanpa Kehilangan Data)
04:00 WIB ────> Input Backlog Kunjungan dari Buku Log Kertas ke VMS Baru
05:00 WIB ────> GO-LIVE RESMI & Nonaktifkan IIS VMS-old
```

### 03:00 WIB: Freeze VMS-old & Pengalihan ke Kertas
1. Komandan Regu Security menginstruksikan seluruh petugas konter berhenti menginput di VMS-old.
2. Petugas konter mulai mencatat tamu yang masuk/keluar di **Buku Log Kertas Manual** (Nama, No KTP, Perusahaan, Lokasi/Kapal, Jam Masuk, No Kartu Fisik).
3. Sysadmin menghentikan akses tulis ke aplikasi IIS VMS-old (stop Application Pool / redirect maintenance).

### 03:05 WIB: Ekspor Delta SQL Server
1. DBA mengeksekusi ekspor delta sejak watermark H-1:
   ```powershell
   .\tools\windows\export-vms-old.ps1 -Server "192.168.10.199" -Database "ASL-ASL-HRMS" -OutputDir "C:\VMS\migration\delta" -Since "<watermark-H-1>"
   ```
2. Pastikan file CSV delta (`ESSVisitorRegistration.csv`, dll.) dan `manifest.json` terbentuk lengkap.

### 03:15 WIB: Impor Delta ke Database Target
1. Jalankan proses impor delta:
   ```powershell
   php artisan vms:import --source=vms-old --path="C:\VMS\migration\delta"
   ```
2. Amati log konsol: kunjungan yang sudah checkout terupdate, kunjungan baru terdaftar, status kartu tersinkronisasi.

### 03:30 WIB: Verifikasi Integritas & Keputusan Go / No-Go
1. Jalankan verifikasi otomatis 7 invarian:
   ```powershell
   php artisan vms:import-verify --source=vms-old --path="C:\VMS\migration\delta"
   ```
2. **Kriteria Go / No-Go Decision:**
   - **GO DECISION (Lulus):** Exit code 0, 7 invarian data valid, tidak ada orphan records, status kartu konsisten. Cutover Commander mengumumkan: **"GO!"**
   - **NO-GO DECISION (Batal):** Exit code 1 atau terdapat anomali integritas data. Cutover Commander mengumumkan: **"NO-GO!"** (Lompat ke Bab 6: Prosedur Rollback).

### 03:35 WIB: Uji Verifikasi Fisik di Konter
Jika keputusan **GO**:
1. Petugas konter membuka `https://192.168.1.10` dari browser PC konter.
2. Login menggunakan username masing-masing (`@SEC...`).
3. Lakukan 1 uji transaksi check-in:
   - Masukkan nomor KTP baru.
   - Cetak pass pengunjung ke printer thermal/laser konter (pastikan ukuran setengah-A4, nomor tiket `YY/MM/NNNNN`, lokasi, nama petugas pencetak).
   - Lakukan check-out pada kunjungan uji coba tersebut dan pastikan kartu kembali `available`.

### 04:00 WIB: Input Backlog Kertas ke Sistem Baru
1. Petugas konter menginput data tamu yang tercatat di buku log kertas manual (sejak 03:00 WIB) ke dalam sistem VMS baru secara berurutan.
2. Pass pengunjung disesuaikan dengan kartu fisik yang telah diserahkan sebelumnya.

### 05:00 WIB: Go-Live Resmi & Decommissioning
1. Cutover dinyatakan selesai 100%.
2. Hentikan IIS VMS-old secara permanen:
   ```powershell
   Stop-WebAppPool -Name "VMSOldAppPool"
   ```
3. Sistem VMS lokal resmi menjadi sistem utama gerbang galangan.

---

## 6. Prosedur Kontingensi & Rollback (Jika Keputusan No-Go)

Jika terjadi kendala fatal pada pukul 03:30 WIB:
1. **Pemberitahuan Segera:** Cutover Commander mengumumkan status **NO-GO** ke seluruh tim dan pos security.
2. **Pengaktifan Kembali VMS-old:**
   - Sysadmin menyalakan kembali Application Pool IIS VMS-old.
   - Pastikan URL lama VMS-old dapat diakses dari PC konter.
3. **Penyelarasan Data:**
   - Petugas konter menginput data dari buku log kertas manual (sejak 03:00 WIB) ke VMS-old.
   - Tidak ada data yang hilang di SQL Server karena selama proses migrasi tidak ada penghapusan data di server lama.
4. **Pembersihan Server Target:**
   - Jika diperlukan, restore dump PostgreSQL pre-deploy via `pg_restore`.
5. **Evaluasi Pasca Insiden:** Tim IT melakukan audit log `rehearsal-summary.json` dan menjadwalkan ulang cutover setelah bug diperbaiki.

---

## 7. Fase Pasca Go-Live (H+1 sampai H+7)

1. **Pemantauan Harian (H+1 s.d. H+3):**
   - Tim teknis standby memantau antrean konter pada jam sibuk (07:00–08:30 WIB dan 17:00–18:30 WIB).
   - Memastikan tidak ada penumpukan log error di `storage/logs/laravel.log`.
2. **Audit Backup Otomatis (H+1):**
   - Pukul 06:00 WIB: Periksa apakah scheduled task `backup-db.ps1` telah berhasil berjalan pada 02:00 WIB dan menghasilkan file `vms_backup_*.dump` di `C:\VMS\backups\db\` serta secondary disk/SMB share.
3. **Audit Kinerja SLA (§6.4):**
   - Jalankan `audit-performance.ps1` untuk memvalidasi performa di lingkungan produksi nyata.
4. **Pembersihan Berkas Sementara (H+7):**
   - Hapus direktori staging ekspor CSV `C:\VMS\migration\` setelah memastikan sistem stabil selama 7 hari penuh.

---

## 8. Checklist Penerimaan Sistem Lengkap (Spec §6.3)

| No | Kriteria Penerimaan | Metode Verifikasi | Status |
|---|---|---|---|
| 1 | Sertifikat HTTPS internal dipercaya tanpa error browser di semua PC konter | Buka `https://192.168.1.10/`, ikon gembok hijau valid | [ ] |
| 2 | Login username huruf besar/kecil berhasil (case-insensitive) | Login dengan `admin`, `ADMIN`, dan `Admin` | [ ] |
| 3 | Alur lengkap check-in, cetak pass setengah-A4, dan check-out | 1 siklus check-in pengunjung + cetak printer konter + check-out | [ ] |
| 4 | Kunjungan aktif menampilkan kolom `days_open` terhitung akurat | Periksa tabel Kunjungan Aktif di SPA | [ ] |
| 5 | Filter laporan visitor bulanan dan ekspor streaming Excel berfungsi | Buka menu Laporan, filter 1 bulan, unduh Excel | [ ] |
| 6 | Navigasi RBAC menyembunyikan Master Data dan User Management untuk role `security` | Login dengan akun role security, cek menu sidebar | [ ] |
| 7 | Simulasi internet putus tidak mengganggu operasional sistem konter | Cabut kabel WAN/modem eksternal, lakukan check-in/out di konter | [ ] |
| 8 | Server Windows PC reboot dan seluruh service (PG 18, Apache) otomatis start tanpa login | Restart Windows 11 PC, tunggu 2 menit, uji akses browser konter | [ ] |
| 9 | Scheduled Task backup harian berjalan otomatis pukul 02:00 WIB | Jalankan task di Task Scheduler, verifikasi file dump tercipta | [ ] |
| 10 | Rollback restore dump `pg_restore` teruji mengembalikan database dengan bersih | Uji coba restore dump pre-deploy di database simulasi | [ ] |

---
*Dokumen ini wajib dicetak fisik dan dipegang oleh Cutover Commander serta Komandan Regu Security selama malam cutover.*
