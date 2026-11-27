# CBT Admin Plus — peta proyek (untuk Claude; baca ini dulu, jangan menjelajah ulang)

Panel admin ujian CBT (CodeIgniter 4.7, PHP 8.4) di atas **Moodle 5.1.8+** (kode web di `public/`).
Meniru AIOCBT CBTAdmin. Semua teks UI berbahasa Indonesia. Repo `ichalsmiler/cbtadmin_plus` (lokal `F:/apps/cbtadmin_plus`)
= panel + Konvert User (`konvert/`, TypeScript+Vite).

**Rincian per menu, aturan bisnis, kustomisasi Moodle, HA/cadangan, alamat/domain: `docs/PETA-RINCI.md`.**
Baca bagian yang relevan saja (Grep judul menu/nama fitur di sana), bukan seluruh berkas.

## Server dan alur kerja
- Server kerja TIDAK tetap: semua server masih percobaan dan bisa berganti. Pakai server terakhir yang dipakai
  (alias di `~/.ssh/config`: `cbt162` root@172.16.32.162, `cbtplus` smiler@.157, `cbt159` root@.159; kunci `id_ed25519_moodle`).
  Bila SSH gagal/timeout: JANGAN mencari-cari server lain, cukup minta pengguna memasukkan IP server utama yang baru,
  lalu coba user root/smiler dan tambahkan alias. Kunci publik untuk dipasang: `~/.ssh/id_ed25519_moodle.pub`.
- Server standar (pasang-cbt.sh): Debian 13, MariaDB, Redis, WITA. Kredensial `/root/moodle-credentials.txt`, `/root/cbtadmin-credentials.txt`.
- Server lama .154/.155: abaikan. Lainnya (detail di PETA-RINCI): `vps` (cadangan), `konver-soal` .150,
  `cbtsim` 172.16.16.101 (proxy Dashboard Simulasi + panel pengawas, `deploy/pasang-simulasi.sh cbtsim <alias-utama>`).
- Panel `/var/www/cbtadmin` (URL `/cbtadmin/`), Moodle `/var/www/moodle` (web `public/`), data `/var/moodledata`.
- PHP tidak ada di PC: lint `ssh <alias> 'php -l /var/www/cbtadmin/<file>'`. Node.js hanya di PC.
- Deploy panel `bash deploy/deploy.sh <alias>`; setelan/plugin/CSS Moodle `bash deploy/setelan-moodle.sh <alias>`;
  Konvert `bash deploy/build-konvert.sh` → `app/Konvert/konvert.html` (di-commit); tes Konvert `npm test` di `konvert/`.
- Commit: `git -c user.name=ichalsmiler -c user.email=ichalsmiler@users.noreply.github.com commit`.
  Naikkan `PanelController::VERSION` bila CSS/JS berubah.
- Uji Moodle lewat CLI di server (`sudo -u www-data php /tmp/x.php`); data uji dibuat lalu dihapus.

## Aturan kerja dari pengguna
- Admin IT sekolah; jawab dan tulis UI dalam bahasa Indonesia, ringkas. Hemat token.
- Setiap menambah fitur: perbarui `docs/PETA-RINCI.md` (rincian) dan CLAUDE.md hanya bila struktur/aturan inti berubah.
- Commit hanya bila diminta; push hanya setelah ditanya dan disetujui. Cek poin = tag `cekpoin-N` berurutan, bila diminta.
- Deploy hanya ke server kerja saat ini (yang terakhir diberikan pengguna). Jangan ubah keadaan server lain (HA, peran, data) tanpa disuruh jelas.
- Jangan mengetik password di halaman login; bila sesi browser habis, minta pengguna login.
- Jangan uji dengan tindakan yang menghapus data asli (reset peserta, hapus attempt); pakai data sementara / kueri baca.
- Jangan ubah repo lain di `F:\apps` kecuali diminta.
- Moodle: ubah lewat `deploy/moodle/setelan.php` (idempoten), `deploy/moodle/boost.scss` (antara `CBT MULAI/SELESAI`),
  `deploy/moodle/lang/`, plugin `moodle/local/` (`cbtblokir`, `cbtnama`, `cbtujian`); jangan lewat Site administration.

## Struktur kode panel (`app/`)
- `Controllers/PanelController.php` — basis: `render()`, `myRuang()`, `ruangList()`, `VERSION`; saring pengawas ke
  siswa ruangnya di sesi aktif (`siswaSesi()`, `saringSesi()`, `moodleIdsSesi()`).
- `Filters/Guard.php` — login, CSRF, timeout, role; `PROKTOR_PATHS` = menu pengawas (role `proktor`).
  Role `operator` = **Proktor** (tampil "Proktor", dibuat di Tools → Panel → Akun): `OPERATOR_PATHS` (akhiran `*` = turunan), melihat semua ruang (`Guard::lihatSemua`), tombol admin di view diberi `data-hanya-admin` (disembunyikan CSS `.peran-operator`). Role `proktor` di DB = PENGAWAS ruang.
- `Views/layout/main.php` — array `$menu` sidebar. `Config/Routes.php` — semua rute. `Config/Registrar.php` — baseURL per permintaan.
- JS `public/assets/js/panel.js` (data-post, data-fill-modal, datatable, data-pdf, filter Tingkatan→Kelas→Ruang),
  CSS `public/assets/css/panel.css` (token terang/gelap; arah desain `DESIGN.md`).
- Partial `Views/partials/filter_tingkat.php` + `Libraries/Tingkat.php` (`dariRequest()`, `jam()`).

Menu → controller (rincian tiap baris di PETA-RINCI):
| Menu | Controller |
|---|---|
| Dashboard | Dashboard (+ `Libraries/PantauRuang`) |
| Daftar Peserta | Peserta |
| Status Ujian: Setting Ujian / Token / Hapus Attempt | StatusTes / HapusAttempt (+ `ActiveTest`) |
| Status Peserta (+ Login ganda) | StatusPeserta |
| Reset Login / Lock Peserta (batas login per mapel) | ResetLogin / LockPeserta (+ `KeluarAplikasi`) |
| Laporan Kehadiran | Kehadiran (+ `Absensi`) |
| Blokir Siswa | BlokirSiswa (+ `Blokir`) |
| Daftar Ruang | Ruang (+ `Pengawas`) |
| Dokumen (+ Rekap Simulasi) | Dokumen / RekapSimulasi |
| Hasil Ujian | HasilUjian (+ `AnalisisSoal`) |
| Soal (grup): Masukkan Soal `soal` / Review & Edit Soal `soal/edit` / Acak Soal `acak_soal` / Cek Soal `cek_soal` | Soal (+ `SoalRtf`) / AcakSoal / CekSoalPanel + CekGuru (+ `CekSoal`, `Telegram`) |
| Admin Tools: Sesi / Jadwal / Kategori Mapel / Konvert User / Server Cadangan | SesiTingkat / Konvert / Tools::testManage / Konvert / ServerCadanganPanel (+ `ServerCadangan`) |
| Tools: Moodle Tools / Info Sekolah / Panel | Tools (+ `LogUjian`) |

Library penting: `Moodle` (baca DB Moodle), `MoodleBridge::call(action, payload)` (tulis lewat API Moodle, CLI),
`QuizStatus`, `Settings` (skey/svalue), `School::info()`, `Pengawas`, `ActivityLog::add()`, `Pdf::respond()` (dompdf), `Excel`.

Jembatan `Bridge/moodle_bridge.php` (aksi): create_users, reset_passwords, finish_attempts, delete_attempts,
add_time, list_sessions, kill_sessions, quiz_password, course_visibility, category_visibility, suspend_users,
cbtujian_config, purge_caches, create_category, create_categories, rename_categories, update_category, delete_category,
delete_course, move_courses, kategori_nonaktif, login_logo, favicon, sort_courses, reset_moodle, enrol_users, import_soal, soal_daftar, soal_baca, soal_ubah, quiz_timelimit, quiz_shuffle, antinewtab_buka,
create_course, create_courses, unenrol_users.

Migrasi `Database/Migrations/`: admins (role admin/operator/proktor/demo, + sesi_token: pengawas maks. 2 perangkat, sesi 30 menit), peserta, ruang, sesi, settings, activity_log, absensi, blokir, laporan_siswa, cek_guru + cek_tugas (Cek Soal), pengawas_hadir (nama pengawas wajib diisi setelah login, `PengawasHadir`), server_sampel (sampel server tiap menit untuk ringkasan server per sesi, `ServerSampel` + `LaporanSesi`).
README.md = panduan untuk manusia; `docs/INSTALASI-SERVER.md` = tutorial pasang server.
