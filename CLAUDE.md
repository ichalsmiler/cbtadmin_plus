# CBT Admin Plus — peta proyek (untuk Claude; baca ini dulu, jangan menjelajah ulang)

Panel admin ujian CBT (CodeIgniter 4.7, PHP 8.4) di atas **Moodle 5.1.8+** (kode web di `public/`).
Meniru AIOCBT CBTAdmin (lms.sman2makassar.sch.id/cbtadmin). Semua teks UI berbahasa Indonesia.
Repo `ichalsmiler/cbtadmin_plus` (lokal `F:ppstadmin_plus`) = cbtadmin + Konvert User (dulu repo `web-ujian`) di folder `konvert/`.
Repo lama `cbtadmin` dan `web-ujian` tidak dipakai lagi untuk fitur baru.

## Server dan alur kerja
- Utama `moodle` = 172.16.32.154, cadangan `cadangan` = 172.16.32.155 (alias SSH, user smiler, sudo tanpa sandi;
  keduanya container LXC Proxmox, Debian 13, MariaDB 11.8, Redis untuk sesi, WITA).
- Panel di `/var/www/cbtadmin` (URL `/cbtadmin/`), Moodle di `/var/www/moodle`, data `/var/moodledata`.
- PHP tidak ada di PC ini: lint di server (`ssh moodle 'php -l /var/www/cbtadmin/<file>'`).
- Deploy panel: `bash deploy/deploy.sh moodle` (Git Bash). Perubahan kecil cukup ke `moodle`; `cadangan` disamakan
  sebelum ujian. Setelan + plugin Moodle: `bash deploy/setelan-moodle.sh <host>`.
- Commit: `git -c user.name=ichalsmiler -c user.email=ichalsmiler@users.noreply.github.com commit`; push hanya
  setelah ditanya ke pengguna. Naikkan `PanelController::VERSION` bila CSS/JS berubah (cache-buster).
- Uji Moodle lewat skrip CLI di server (`sudo -u www-data php /tmp/x.php`); jangan login web dengan akun uji.
  Data uji dibuat lalu dihapus. Login panel di browser dilakukan pengguna.

## Aturan kerja dari pengguna (berlaku di akun Claude mana pun)
- Pengguna admin IT sekolah, berbahasa Indonesia: jawab dan tulis teks UI dalam bahasa Indonesia, ringkas.
- Hemat token: baca CLAUDE.md ini dulu, jangan menjelajah ulang kode; perbarui berkas ini setiap menambah fitur.
- Jangan push sebelum ditanya dan disetujui; commit hanya bila diminta.
- Perubahan kecil cukup deploy ke `moodle`; sebutkan bahwa `cadangan` tertinggal. Samakan `cadangan`
  (`deploy.sh cadangan` + `setelan-moodle.sh cadangan`) sebelum ujian, latihan failover, atau bila diminta.
- Jangan mengetik password di halaman login (panel/Moodle); bila sesi browser habis, minta pengguna login.
- Jangan menjalankan tindakan yang menghapus data asli (reset peserta, hapus attempt) untuk uji coba;
  uji dengan data sementara atau kueri baca saja.
- Jangan masuk/mengubah repo lain di `F:\apps` (mis. `web-ujian`) kecuali diminta jelas.
- Server lain: `konver-soal` = 172.16.32.150. Acuan tampilan: AIOCBT CBTAdmin (perlu login pengguna).
- Saran yang belum dibuat: uji beban + tambah RAM utama (1 GB), backup di luar host, Safe Exam Browser,
  matikan rememberusername/autolang di halaman login Moodle, rsync HA berprioritas rendah.

## Struktur kode panel (`app/`)
- `Controllers/PanelController.php` — basis: `render()`, `myRuang()` (ruang pengawas), `ruangList()`, `VERSION`;
  pengawas hanya melihat/menindak siswa ruangnya di sesi aktif: `siswaSesi()`, `saringSesi($rows)`, `moodleIdsSesi()`
  (Peserta ruang, Status Peserta, Login ganda, Reset Login, Blokir Siswa); tanpa sesi aktif daftar kosong + peringatan
  di layout (`sesiBelumPengawas`); `Tingkat::dariRequest()` pengawas bawaan = tingkatan sesi aktif.
- `Filters/Guard.php` — login, CSRF, timeout, role. `PROKTOR_PATHS` = menu yang boleh untuk pengawas (role `proktor`).
- `Views/layout/main.php` — array `$menu` sidebar; admin menyembunyikan `token`,`kehadiran`; Reset Login muncul
  hanya bila setting `lock_login`=1. Tombol "Kembali ke Admin" saat Tukar Akses (`session tukar_dari`).
- `Config/Routes.php` — semua rute (grup terfilter Guard).
- JS `public/assets/js/panel.js`: `data-post`+`data-fields`+`data-confirm-text` (POST via `#postForm`),
  `data-confirm-button` (form massal), `data-fill-modal`+`data-fill` (isi modal), `table.datatable` (DataTables,
  `data-order-col`), `a[data-pdf]`/`form[data-pdf]` (pratinjau PDF di `#pdfModal`, Unduh = `?unduh=1` → attachment
  di `Pdf::respond`; `?cetak=1` = HTML siap print browser tanpa dompdf, gambar via `tools/gambar/*`, dipakai tombol Cetak
  Kartu Peserta (`data-no-pdf`) dan Cetak ID Login). CSS `public/assets/css/panel.css` (token warna terang/gelap di `:root` dan `[data-bs-theme=dark]`; arah desain di `DESIGN.md`: sejak 2.5.0 slate + aksen hijau, tema bawaan terang (saran ui-ux-pro-max), font Fira Sans/Fira Code lokal di `public/assets/fonts` (OFL), kaca hanya bilah atas + `.token-card`, kartu statistik `stat-netral|proses|ok|awas|redup`; tema disimpan `localStorage cbt-tema`, dipasang `partials/tema_awal`, tombol `[data-tema-toggle]`).
- Server utama RAM 1 GB: dompdf maks ±400 kartu per PDF (`Dokumen::MAKS_KARTU`, lebih → daftar tautan per
  ruang/kelas `dokumen/kartu_bagian`); gambar di PDF besar pakai path berkas (chroot `writable/uploads`), bukan data-URI.
- Partial `Views/partials/filter_tingkat.php` (select Tingkatan X/XI/XII lalu "Semua", `Libraries/Tingkat.php`);
  `Tingkat::dariRequest()` bawaan X, pilihan diingat di sesi `filter_tingkat` (dipakai juga ekspor tanpa ?tingkat=).
  Urutan filter di semua halaman: Tingkatan → Kelas → Ruang. panel.js menyaring `#fKelas` menurut `#fTingkat` dan
  `#fRuang` (opsi `data-kelas`, dari kolom `kelas` di `PanelController::ruangList()`) menurut kelas/tingkatan.

Menu → controller:
| Menu | Controller | Catatan |
|---|---|---|
| Dashboard | Dashboard | stat via `dashboard/stats` (JSON); pengawas: kartu Belum Login / Belum Mulai / Mengerjakan / Sudah Submit + kartu "Pantauan Siswa" berisi nama per kelompok (`Libraries/PantauRuang::get`, hari ini, quiz tes aktif; JSON `pantau`), lalu kartu "Sesi Aktif (Kelas X)" + siswa ruangnya di sesi aktif, Belum/Sudah Submit juga per sesi (`Pengawas::usernamesInRuang($ruang, $sesi)`); token pengawas diganti pesan "Sesi belum diaktifkan oleh proktor" selama `sesi_aktif` kosong (`ActiveTest::sesiBelumAktifUntuk`, JSON `status_tes/token` → `sesiBelum`, halaman reload otomatis) |
| Daftar Peserta | Peserta | template TUNGGAL (`template` dan `export` sama: `Excel::templatePeserta`, format AIOCBT judul kolom baris 9 + No. Peserta + Mapel 1..n, lembar "Daftar Mapel" dari Moodle; bisa diimpor ulang); kolom `mapel` (shortname course, dipisah koma; impor kolom `mapel`/`course1..14`, CSV upload user Moodle langsung bisa: firstname=nama, lastname=kelas); tombol **Sinkron ke Moodle** (`daftar_peserta/moodle/buat` → `createMoodle`, bertahap ±20 dtk/permintaan + `peserta/sinkron_proses` kirim ulang otomatis): buat akun yang belum ada lalu daftarkan mapel yang belum (`mapelBelumTerdaftar`, aksi bridge `enrol_users`, role student, enrol manual), shortname tak dikenal dilaporkan; pengawas: `peserta_ruang` (`Peserta::ruang`, baca saja, ruangnya; tautan kotak siswa di dashboard); impor AIOCBT (`Excel::read`, header baris 9), No. Peserta = username bila kosong; Reset Peserta (ketik HAPUS) / centang "Hapus data lama" saat impor → `hapusSemua()` (buka suspend Moodle siswa terblokir, kosongkan peserta+absensi+blokir; centang `ruang=1` di Reset ikut hapus ruang, sesi, akun pengawas; akun Moodle tetap) |
| Status Test / Token | StatusTes | sesi wajib dipilih; tombol Aktifkan / Nonaktifkan sesi (`status_tes/sesi`, aksi=aktifkan/nonaktifkan; nonaktif = `sesi_aktif` kosong → semua quiz dikunci password bawaan, `apply()` token dilewati) (`sesi_id` → setting `sesi_aktif`, `ActiveTest::sesiList()/sesiAktif()`, sesi peserta = `COALESCE(p.sesi_id, r.sesi_id)`, tingkatan dari kelas pesertanya); kategori Moodle = tes; simpan → hanya mapel dicentang + kategori itu tampil, lainnya disembunyikan; token bersama (`ActiveTest`, cron `cbt:token`); quiz di luar kategori aktif (quiz baru, tes lama) dikunci password bawaan `ActiveTest::DEFAULT_PASSWORD`=123456 (`applyDefault`, dipanggil `sync()` tiap menit dan saat simpan tes) |
| Status Peserta | StatusPeserta | paksa submit, tambah waktu; tombol "Hapus attempt…" (admin) → HapusAttempt; "Login ganda" `status_peserta/login_ganda` (log user_loggedin per IP per tanggal) |
| Reset Login | ResetLogin | hanya dipakai saat Lock login aktif |
| Laporan Kehadiran | Kehadiran | absensi pengawas: hanya siswa sesi aktif (`Absensi::forRuang($ruang,$tgl,$sesi)`; tanpa sesi aktif pengawas lihat pesan, admin pakai filter tingkatan; simpan hanya yang tampil); kunci token per sesi aktif (`terkunciUntuk`); tidak hadir → suspend Moodle hari itu (`Absensi`); token pengawas tampil di Dashboard (`partials/token_card`, menu Token dihapus, `/token` → dashboard) dan terkunci sampai ada absensi ruangnya hari ini (`ActiveTest::terkunciUntuk`, `mapelAktif`) |
| Blokir Siswa | BlokirSiswa | `Libraries/Blokir.php`, tabel `blokir`; tidak dibuka oleh absensi |
| Daftar Ruang | Ruang | kode login pengawas `NPSN-<huruf><no><2 acak>` (`Pengawas::usernameFor`), password 4 huruf kapital (`Pengawas::randomPassword`), Tukar Akses, Generate Kode, Cetak ID Login (`pdf/id_login`), nama lab (`ruang.lokasi`) |
| Dokumen | Dokumen | kartu login peserta per ruang/kelas (`pdf/kartu`: logo sekolah kiri + logo kanan `logo2` dari Info Sekolah, sama besar via `School::gayaLogo`; catatan kiri + tanda tangan kepala sekolah kanan bila nama kepala diisi; jam ujian per tingkatan `jam_<X|XI|XII>_mulai/selesai`, `catatan_kartu`), daftar hadir; Laporan Kehadiran admin `dokumen/laporan_kehadiran` 2 tab: `?tab=pengawas` (rekap absensi pengawas, `Absensi::rekap($tgl,$tingkat)`, view `dokumen/absensi`; alamat lama `dokumen/absensi` dialihkan) dan `?tab=ujian` (hadir = punya attempt, berita acara) |
| Hasil Ujian / No Attempt | HasilUjian / NoAttempt | tab `?tab=cepat` "Selesai terlalu cepat" (pw/pm/pn: % batas waktu, % median, % nilai); tab `?tab=analisis` + `export_analisis` (P kesukaran, D daya beda 27%) dan `export_soal` (B/S/–/? per nomor) via `Libraries/AnalisisSoal` (langkah terakhir question_attempt_steps dari attempt selesai terakhir) |
| Konvert User | Konvert | admin saja; aplikasi TypeScript+Vite di `konvert/` (jadwal → mapel, data peserta → template CBTAdmin, kelas moving, user Moodle; diproses di browser, 166 tes `npm test`), di-build `bash deploy/build-konvert.sh` → `app/Konvert/konvert.html` (di-commit, ikut `deploy.sh`), disajikan `konvert/app` dalam iframe `konvert/index`. Node.js hanya di PC, tidak di server |
| Tools | Tools | accordion: Moodle Tools (Kelola Test `tools/test_manage`, Cron, Purge/Delete cache, Anti Submit) + CBTAdmin Tools (Info Sekolah, Info Kegiatan, Lock/Resume/Anti Newtab, Ganti Password) + Lainnya (akun, panel, backup, log: tab Panel / Pengawas (login panel + jam absen per ruang dari activity_log + tabel tindakan pengawas) / Mulai ujian (`quiz_attempts.timestart`, telat vs `jam_<T>_mulai`, `?telat=`) / Login siswa (logstore, >1 IP kuning) / Belum mulai (hadir tanpa attempt) via `Libraries/LogUjian`, `?log_tgl=&log_tab=`; Excel `tools/log_export?jenis=`; jejak siswa `tools/jejak?u=` (event Moodle + absensi + log panel yang menyebut username; paksa submit/tambah waktu/reset login mencatat `; siswa: …` lewat `PanelController::daftarSiswaLog`)) |

Library penting: `Moodle` (koneksi DB Moodle langsung, baca; `quizzes()`, `categories()`, `coursesInCategory()`,
`allCourses()`), `MoodleBridge::call(action, payload)` (tulis lewat API Moodle, CLI) dan `MoodleBridge::cron()`,
`QuizStatus` (status peserta per quiz, `filter(rows, ruang, kelas, tingkat)`, `latestAttemptIds`),
`Settings` (tabel `settings` skey/svalue), `School::info()` (identitas + logo/ttd data-URI untuk PDF),
`Pengawas` (akun pengawas per ruang), `ActivityLog::add()`, `Pdf::respond()` (dompdf), `Excel`.

Jembatan `Bridge/moodle_bridge.php` (aksi): create_users, reset_passwords, quiz_times, finish_attempts,
delete_attempts, add_time, list_sessions, kill_sessions, quiz_password, course_visibility, category_visibility,
suspend_users, cbtujian_config, purge_caches, create_category, create_course, session_gc, enrol_users.

Migrasi `Database/Migrations/`: tabel admins (role admin/proktor, ruang_id, password_plain), peserta, ruang
(+lokasi), sesi, settings, activity_log, absensi, blokir; peserta.mapel (TEXT).

## Moodle (kustomisasi)
- Semua setelan ada di `deploy/moodle/setelan.php` (idempoten), CSS di `deploy/moodle/boost.scss` (tema Boost,
  antara penanda `CBT MULAI/SELESAI`), teks bahasa di `deploy/moodle/lang/` (→ `moodledata/lang/{id,en}_local`).
  Ubah di sana lalu jalankan `setelan-moodle.sh`; jangan ubah manual lewat Site administration.
- Plugin `moodle/local/`: `cbtblokir` (pesan "User Anda terblokir"), `cbtnama` (nama depan di samping ikon profil),
  `cbtujian` (Anti Submit / Lock login / Resume timer / Anti Newtab; setelan plugin `local_cbtujian` dikirim dari
  Tools via aksi `cbtujian_config`, Lock login mematikan `limitconcurrentlogins`; juga kotak cari di
  `admin/user.php` → `?cbtcari=&cbtkolom=fullname|username|email` mengisi filter "contains" laporan via
  `user_filter_manager` lalu redirect).
- Tampilan siswa: menu atas hanya "Daftar Ujian" (+ nama), sapaan "nama - kelas" (first name = nama, last name =
  kelas), tanpa Dasbor/Beranda/pesan/lonceng/tombol ?/filter; quiz 1 percobaan dikunci, tinjauan tanpa kunci jawaban.
- `cbtujian` juga: menu sekunder course/quiz disembunyikan untuk yang tak punya `moodle/course:update` (hook
  `before_http_headers` → `set_secondary_navigation(false)`); laci blok kanan disembunyikan untuk siswa (body `cbt-siswa`) dan di attempt/summary
  (boost.scss), navigasi nomor soal `.qn_buttons` disalin ke `#cbt-nav-soal` di bawah `#responseform` (awal_halaman).
- Bahasa Indonesia: kata "kuis" → "ujian" di paket `id` (quiz, quiz_*, quizaccess_*, moodle) dibuat otomatis oleh
  setelan.php ke `lang/id_local`, lalu teks kustom `deploy/moodle/lang/<berkas>` ditambahkan di belakangnya (menang).
- Dialog mulai quiz berwaktu (ajakan berdoa + peringatan kecurangan): `deploy/moodle/lang/quizaccess_timelimit.php`.
- Nilai quiz skala 0–100: `quiz/maximumgrade`=100 dan setiap quiz dengan grade ≠ 100 diubah ke 100 lewat
  `grade_calculator::update_quiz_maximum_grade` (setelan.php; jalankan ulang setelah guru membuat quiz).
## Lain-lain
- `deploy/install-moodle.sh` (server baru, `ZONA_WAKTU`), `deploy/install.sh` (panel; dilewati migrasi di cadangan HA),
  `deploy/ha/` (replikasi MariaDB + rsync, mode LAN tanpa WireGuard; `/etc/cbt-ha-peran`).
- README.md berisi panduan lengkap untuk manusia.
