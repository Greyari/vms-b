# VMS Production Cutover Runbook (Panduan Operasional Migrasi)

- **Sistem:** Visitor Management System (VMS) — gerbang on-premise per yard
- **Target:** dua PC server Windows 11 lokal, satu per yard — **CIS lebih dulu, lalu ASL** (OPS-6)
- **Spec referensi:** `docs/superpowers/specs/2026-09-13-vms-local-server-parity-design.md` §5.5, §6.2–§6.4;
  keputusan hybrid `docs/decisions/vms-hybrid-decisions.md` (PE-8, OPS-5, OPS-6, PE-7)
- **Ditulis:** 2026-09-25. **Direvisi:** 2026-09-29 (PE-8)
- **Status:** Draft — target. Belum pernah dilatih di PC target maupun dengan data asli.

> **Revisi 2026-09-29 (PE-8).** Versi 2026-09-25 menganggap satu server untuk ASL, dengan data pilot "jika ada"
> ikut diimpor ke server yang sama. Keputusan PE-8 membaliknya: **VMS-old adalah data ASL, pilot IIS adalah data
> CIS**, dan masing-masing hanya masuk ke server yard pemiliknya. `vms:import` kini menolak sumber milik yard
> lain bila `VMS_YARD_CODE` terisi, dan menolak folder ekspor yang `manifest.json`-nya dari sumber berbeda.
> Semua pekerjaan cutover di bawah dikerjakan **per yard**, pada malam yang berbeda.

---

## 0. Dua target sekilas

| | **CIS** (malam pertama) | **ASL** (setelah CIS stabil) |
|---|---|---|
| Sistem lama | Pilot VMS di IIS, database MySQL `db_vms` | VMS-old (PHP + XAMPP) di SQL Server `ASL-ASL-HRMS` @ `BTHS01` (192.168.10.199) |
| Sumber impor | `--source=pilot` | `--source=vms-old` |
| Ekspor | `php -d extension=pdo_mysql artisan vms:export-pilot` di server CIS (selalu penuh, tanpa watermark) | `tools\windows\export-vms-old.ps1` (`-Since <watermark>` untuk delta) |
| `VMS_YARD_CODE` di `.env` | `CIS` | `ASL` |
| Akun petugas | Ikut terimpor dari pilot (hash password dibawa) — lihat §4.3 | 14 akun dibuat manual — lihat §4.3 |
| Freeze sistem lama | Stop application pool IIS pilot | Hentikan Apache XAMPP yang melayani VMS-old |
| Latihan | `rehearse-cutover.ps1 -Yard CIS` | `rehearse-cutover.ps1 -Yard ASL` |

Tanggal malam ASL ditetapkan owner setelah CIS lolos pemantauan H+7 (§7).

---

## 1. Ikhtisar & Prinsip Dasar Cutover

1. **Operasional 24 jam:** gerbang galangan beroperasi 24 jam. Cutover dijadwalkan pada jam paling sepi,
   **03:00–05:00 WIB** (data VMS-old 90 hari: hanya 10–30 check-in di rentang ini; angka untuk CIS diukur dari
   pilot saat H-7).
2. **Tanpa internet:** seluruh komponen produksi (PostgreSQL 18, Apache 2.4, PHP 8.4, sertifikat internal)
   berjalan lokal di LAN yard. Internet putus tidak boleh menghentikan konter. Sync ke Cloud Hub baru
   dinyalakan **setelah** go-live (§5, 05:00).
3. **Pencatatan manual transisi:** selama 03:00–04:00 WIB konter mencatat tamu di buku log kertas.
4. **Keputusan tegas Go / No-Go:** pukul **03:30 WIB** verifikasi integritas dijalankan. Gagal = cutover dibatalkan
   (**No-Go**) dan gerbang kembali ke sistem lama tanpa kehilangan data — ekspor hanya membaca sistem lama.

---

## 2. Struktur Tim & Pembagian Tugas

| Peran | Penanggung Jawab | Tugas Utama |
|---|---|---|
| **Cutover Commander** | IT Lead / Project Owner | Otoritas penuh keputusan Go / No-Go pukul 03:30 WIB |
| **Database Administrator** | DBA / Data Engineer | Ekspor sumber, impor, verifikasi (`vms:import-verify`) |
| **System Administrator** | DevOps / Sysadmin | Service Apache/PostgreSQL, freeze sistem lama, backup dump |
| **Gate Security Supervisor** | Komandan Regu Security | Buku log manual di pos dan verifikasi fisik di konter |
| **Counter Operator** | Staf Security Konter | Uji check-in nyata, cetak pass setengah-A4, input backlog kertas |

---

## 3. Fase H-7 (Instalasi Fisik, Jaringan, PKI, Latihan & Pelatihan Operator)

### 3.1. Instalasi Server Fisik
- [ ] Server dipasang mengikuti `tools/windows/INSTALL-GUIDE.md` §1–§9 (PostgreSQL 18 dengan `lc_messages = 'C'`,
      `VMS-Apache`, PHP 8.4 TS, `setup-pgpass.ps1`, backup harian, NTP internet), UPS aktif.
- [ ] `.env` yard terisi: `VMS_YARD_CODE` (CIS atau ASL), `VMS_COMPANY_NAME`/`VMS_COMPANY_ADDRESS` (dan logo di
      `public\branding\` bila ada), `VMS_SYNC_ENABLED=false`.
- [ ] Checklist penerimaan `INSTALL-GUIDE.md` §10 lulus (termasuk deploy + rollback uji).

### 3.2. Distribusi Root CA ke Seluruh PC Konter
- [ ] Sertifikat dibuat di server (`INSTALL-GUIDE.md` §5):
  ```powershell
  C:\VMS\app\tools\windows\setup-ca.ps1 -ServerIp "<IP-server>" -Hostname "vms.<yard>.local" -Organization "<nama PT yard>"
  ```
- [ ] `import-root-ca.bat` dijalankan sebagai Administrator di setiap PC konter.
- [ ] Browser konter membuka `https://<IP-server>/` tanpa peringatan sertifikat.

### 3.3. Latihan Cutover di PC Target
- [ ] Jalankan latihan penuh dengan data asli yard itu, di server itu:
  ```powershell
  # CIS — PILOT_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD diisi sementara di .env (akun MySQL read-only)
  C:\VMS\app\tools\windows\rehearse-cutover.ps1 -Yard CIS -OutputDir C:\VMS\migration\rehearsal
  # ASL
  C:\VMS\app\tools\windows\rehearse-cutover.ps1 -Yard ASL -Server "192.168.10.199" -Database "ASL-ASL-HRMS" -OutputDir C:\VMS\migration\rehearsal
  ```
- [ ] Catat durasi ekspor dan impor delta dari `rehearsal-summary.json`. **Syarat lanjut:** ekspor + impor delta
      < 20 menit (jendela 03:05–03:30). CIS mengekspor ulang seluruh pilot tiap kali, jadi angka ini yang
      menentukan apakah malam CIS muat di jendela.
- [ ] Latihan menjalankan `migrate:fresh`: sesudahnya database dikosongkan lagi di H-1 (§4.1).

### 3.4. Pelatihan Operator Konter
- [ ] Akun uji coba untuk operator gerbang mencoba login username/password.
- [ ] Latihan form check-in, pencarian KTP, modal konflik kunjungan aktif, dan cetak pass.

---

## 4. Fase H-1 (Baseline Data & Akun Petugas)

### 4.1. Persiapan Database Target
- [ ] Pastikan `VMS_YARD_CODE` di `.env` sudah benar (`php artisan tinker --execute="echo config('vms.sync.yard_code');"`).
- [ ] Kosongkan skema — **hanya** di server yang belum go-live:
  ```powershell
  php artisan migrate:fresh --seed --force
  ```

### 4.2A. Baseline CIS (pilot IIS)
- [ ] Isi sementara `PILOT_DB_HOST`, `PILOT_DB_PORT`, `PILOT_DB_DATABASE` (`db_vms`), `PILOT_DB_USERNAME`,
      `PILOT_DB_PASSWORD` di `.env` server CIS (akun MySQL read-only), lalu `php artisan config:clear`.
- [ ] Ekspor penuh pilot:
  ```powershell
  php -d extension=pdo_mysql artisan vms:export-pilot --path="C:\VMS\migration\baseline"
  ```
- [ ] Impor dan verifikasi:
  ```powershell
  php artisan vms:import --source=pilot --path="C:\VMS\migration\baseline"
  php artisan vms:import-verify --source=pilot --path="C:\VMS\migration\baseline"
  ```
  *(Wajib exit code 0.)* `users.csv` hasil ekspor memuat hash password petugas pilot: folder
  `C:\VMS\migration` hanya boleh terbaca Administrators, dan dihapus di H+7 (§7).

### 4.2B. Baseline ASL (VMS-old)
- [ ] Ekspor penuh VMS-old dari SQL Server produksi:
  ```powershell
  C:\VMS\app\tools\windows\export-vms-old.ps1 -Server "192.168.10.199" -Database "ASL-ASL-HRMS" -OutputDir "C:\VMS\migration\baseline"
  ```
- [ ] Catat watermark baseline dari `C:\VMS\migration\baseline\manifest.json` (`max(LastModifiedDate)`).
- [ ] Impor dan verifikasi:
  ```powershell
  php artisan vms:import --source=vms-old --path="C:\VMS\migration\baseline"
  php artisan vms:import-verify --source=vms-old --path="C:\VMS\migration\baseline"
  ```
  *(Wajib exit code 0 — 7 invarian data lulus.)*

Di kedua yard, `vms:import` yang dijalankan dengan sumber yard lain berhenti dengan
"… holds <yard>'s data, but this server is <yard> (VMS_YARD_CODE). Import refused (decision PE-8)." — itu
pengaman, bukan galat yang perlu dilewati.

### 4.3. Akun Petugas
**ASL — pembuatan 14 akun pengguna legacy (Spec §5.3).** Password plaintext VMS-old tidak diimpor.
Administrator membuat 14 akun petugas resmi VMS-old:
- [ ] Login ke VMS baru sebagai Administrator, menu **Pengaturan Pengguna** (`/users`).
- [ ] Daftarkan 14 akun: username huruf besar identik `EmployeeNo`, nama sesuai `EmployeeMaster`, role
      `security` untuk petugas gerbang dan `supervisor` untuk komandan regu, password sementara per petugas.

**CIS — akun ikut dari pilot.** `vms:import --source=pilot` membawa akun pilot beserta hash password-nya, jadi
petugas login dengan password lama:
- [ ] Periksa daftar di `/users`: nonaktifkan akun yang sudah tidak bertugas, cocokkan role
      (`security`/`supervisor`/`administrator`).
- [ ] Minta tiap petugas mencoba login sebelum malam cutover.

- [ ] Catat bahwa baseline siap; server dalam mode standby.

---

## 5. Fase Malam Cutover: Jadwal Menit-demi-Menit (03:00–05:00 WIB)

```
03:00 WIB ────> Freeze sistem lama (Pencatatan Buku Log Kertas)
03:05 WIB ────> Ekspor delta (CIS: ekspor penuh ulang pilot; ASL: -Since watermark)
03:15 WIB ────> Impor delta ke VMS baru (vms:import)
03:30 WIB ────> Verifikasi integritas & KEPUTUSAN GO / NO-GO GATE
                ├─ LULUS ──> Lanjut Go-Live (03:35 Uji Fisik Konter)
                └─ GAGAL ──> NO-GO (Rollback ke sistem lama, tanpa kehilangan data)
04:00 WIB ────> Input backlog kunjungan dari buku log kertas ke VMS baru
05:00 WIB ────> GO-LIVE RESMI, matikan sistem lama, nyalakan sync hub
```

### 03:00 WIB: Freeze sistem lama & pengalihan ke kertas
1. Komandan Regu Security menginstruksikan konter berhenti menginput di sistem lama.
2. Konter mencatat tamu di **buku log kertas** (Nama, No KTP, Perusahaan, Lokasi/Kapal, Jam Masuk, No Kartu Fisik).
3. Sysadmin menghentikan akses tulis ke sistem lama:
   - **CIS:** `Stop-WebAppPool -Name "<app pool pilot>"` di server IIS pilot.
   - **ASL:** hentikan Apache XAMPP yang melayani VMS-old (atau alihkan ke halaman maintenance).

### 03:05 WIB: Ekspor delta
- **CIS** (pilot tidak punya watermark; importer idempoten lewat `import_mappings`, jadi ekspor penuh ulang aman):
  ```powershell
  php -d extension=pdo_mysql artisan vms:export-pilot --path="C:\VMS\migration\delta"
  ```
- **ASL:**
  ```powershell
  C:\VMS\app\tools\windows\export-vms-old.ps1 -Server "192.168.10.199" -Database "ASL-ASL-HRMS" -OutputDir "C:\VMS\migration\delta" -Since "<watermark-H-1>"
  ```
- Pastikan berkas CSV dan `manifest.json` terbentuk lengkap.

### 03:15 WIB: Impor delta
```powershell
php artisan vms:import --source=<pilot|vms-old> --path="C:\VMS\migration\delta"
```
Amati log konsol: kunjungan yang sudah checkout terupdate, kunjungan baru terdaftar, status kartu tersinkron.

### 03:30 WIB: Verifikasi Integritas & Keputusan Go / No-Go
1. Jalankan verifikasi:
   ```powershell
   php artisan vms:import-verify --source=<pilot|vms-old> --path="C:\VMS\migration\delta"
   ```
2. **Kriteria:**
   - **GO:** exit code 0, tidak ada orphan record, status kartu konsisten. Cutover Commander mengumumkan **"GO!"**
   - **NO-GO:** exit code 1 atau anomali integritas. Cutover Commander mengumumkan **"NO-GO!"** (Bab 6).

### 03:35 WIB: Uji verifikasi fisik di konter
Jika **GO**:
1. Konter membuka `https://<IP-server>` dan login dengan akun masing-masing.
2. Satu transaksi uji: KTP baru, cetak pass (setengah-A4, nomor tiket `YY/MM/NNNNN`, lokasi, nama petugas,
   **nama PT yard ini** di kepala pass — PE-7), lalu check-out dan pastikan kartu kembali `available`.

### 04:00 WIB: Input backlog kertas
1. Konter menginput tamu dari buku log kertas (sejak 03:00) ke VMS baru secara berurutan.
2. Pass disesuaikan dengan kartu fisik yang sudah diserahkan.

### 05:00 WIB: Go-Live Resmi & Decommissioning
1. Cutover dinyatakan selesai.
2. Matikan sistem lama permanen:
   - **CIS:** `Stop-WebAppPool -Name "<app pool pilot>"` dan setel app pool itu ke Startup manual.
   - **ASL:** hentikan service Apache XAMPP VMS-old dan setel ke manual.
3. **CIS:** hapus `PILOT_DB_*` dari `.env`, lalu `php artisan config:cache`.
4. Nyalakan sync ke Cloud Hub mengikuti runbook setup multi-yard (`D:\INIT-ASL\docs\vms-multi-yard-setup-runbook.md`
   §5–§6 dan checklist §8): `VMS_SYNC_ENABLED=true`, `php artisan config:cache`, `setup-sync-task.ps1`.
5. VMS lokal resmi menjadi sistem utama gerbang yard itu.

---

## 6. Prosedur Kontingensi & Rollback (Jika Keputusan No-Go)

1. **Pemberitahuan:** Cutover Commander mengumumkan **NO-GO** ke seluruh tim dan pos security.
2. **Nyalakan kembali sistem lama:**
   - **CIS:** `Start-WebAppPool -Name "<app pool pilot>"`.
   - **ASL:** start ulang Apache XAMPP VMS-old.
   - Pastikan URL lama dapat diakses dari PC konter.
3. **Penyelarasan data:** konter menginput buku log kertas (sejak 03:00) ke sistem lama. Tidak ada data hilang
   di sistem lama: ekspor hanya membaca.
4. **Server baru:** biarkan apa adanya; baseline diulang di H-1 berikutnya (`migrate:fresh --seed` + §4.2).
5. **Evaluasi:** audit `rehearsal-summary.json` / keluaran `vms:import-verify`, perbaiki, jadwalkan ulang.

---

## 7. Fase Pasca Go-Live (H+1 sampai H+7)

1. **Pemantauan harian (H+1 s.d. H+3):** tim teknis standby di jam sibuk (07:00–08:30 dan 17:00–18:30 WIB);
   tidak ada penumpukan galat di `storage\logs\laravel-*.log`; indikator sync di header hijau.
2. **Audit backup (H+1):** pukul 06:00 periksa task `VMS-Daily-Backup` pukul 02:00 menghasilkan
   `C:\VMS\backups\daily\db_vms-*.dump` tidak kosong (dan salinan di disk kedua bila diset).
3. **Audit kinerja SLA (§6.4):** jalankan `audit-performance.ps1` di produksi.
4. **Pembersihan (H+7):** hapus `C:\VMS\migration\` (berisi data pribadi pengunjung, dan untuk CIS hash
   password petugas) setelah sistem stabil 7 hari.
5. **Lampu hijau ASL:** CIS stabil 7 hari tanpa insiden terbuka = owner menetapkan malam cutover ASL.

---

## 8. Checklist Penerimaan Sistem Lengkap (Spec §6.3)

| No | Kriteria Penerimaan | Metode Verifikasi | Status |
|---|---|---|---|
| 1 | Sertifikat HTTPS internal dipercaya tanpa error browser di semua PC konter | Buka `https://<IP-server>/`, tanpa peringatan | [ ] |
| 2 | Login username huruf besar/kecil berhasil (case-insensitive) | Login dengan `admin`, `ADMIN`, dan `Admin` | [ ] |
| 3 | Alur lengkap check-in, cetak pass setengah-A4, dan check-out | 1 siklus check-in + cetak printer konter + check-out | [ ] |
| 4 | Kunjungan aktif menampilkan kolom `days_open` terhitung akurat | Periksa tabel Kunjungan Aktif | [ ] |
| 5 | Filter laporan visitor bulanan dan ekspor streaming Excel berfungsi | Menu Laporan, filter 1 bulan, unduh Excel | [ ] |
| 6 | Navigasi RBAC menyembunyikan Master Data dan User Management untuk role `security` | Login akun security, cek sidebar dan ketik URL langsung | [ ] |
| 7 | Internet putus tidak mengganggu operasional konter | Cabut kabel WAN, check-in/out di konter | [ ] |
| 8 | Reboot server: PG 18 dan Apache start otomatis tanpa login | Restart PC, tunggu 2 menit, uji dari konter | [ ] |
| 9 | Backup harian otomatis pukul 02:00 menghasilkan dump tidak kosong | `schtasks /Run /TN VMS-Daily-Backup`, periksa berkas | [ ] |
| 10 | Rollback restore dump teruji mengembalikan database dengan bersih | Restore dump ke database uji; tabel tetap milik `vms_app` | [ ] |
| 11 | Pass dan PDF laporan mencetak nama PT yard ini, bukan yard lain (PE-7) | Cetak pass uji + unduh PDF laporan | [ ] |
| 12 | Data yard lain tidak bisa diimpor ke server ini (PE-8) | `vms:import` dengan sumber yard lain berhenti "Import refused" | [ ] |

---
*Dokumen ini wajib dicetak dan dipegang Cutover Commander serta Komandan Regu Security selama malam cutover.*
