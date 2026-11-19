# CBT Admin Plus — peta proyek (untuk Claude; baca ini dulu, jangan menjelajah ulang)

Panel admin ujian CBT (CodeIgniter 4.7, PHP 8.4) di atas **Moodle 5.1.8+** (kode web di `public/`).
Meniru AIOCBT CBTAdmin (lms.sman2makassar.sch.id/cbtadmin). Semua teks UI berbahasa Indonesia.
Repo `ichalsmiler/cbtadmin_plus` (lokal `F:/apps/cbtadmin_plus`) = cbtadmin + Konvert User (dulu repo `web-ujian`) di folder `konvert/`.
Repo lama `cbtadmin` dan `web-ujian` tidak dipakai lagi untuk fitur baru.

## Server dan alur kerja
- **SATU-SATUNYA server kerja: `cbtplus` = 172.16.32.157** (sejak 19 Nov 2026, perintah pengguna). Alias SSH, user smiler,
  sudo tanpa sandi, kunci `id_ed25519_moodle`; LXC Debian 13, 2 core, RAM 2 GB, MariaDB 11.8, Redis (sesi + cache Moodle,
  store `cbt_redis`, prefix `mdlc_`), WITA. Kredensial: `/root/moodle-credentials.txt`, `/root/cbtadmin-credentials.txt`.
- Server lama `moodle` (.154) dan `cadangan` (.155): ABAIKAN, jangan deploy/ubah ke sana kecuali pengguna meminta.
- Panel di `/var/www/cbtadmin` (URL `/cbtadmin/`), Moodle di `/var/www/moodle` (web `public/`), data `/var/moodledata`.
- PHP tidak ada di PC ini: lint di server (`ssh cbtplus 'php -l /var/www/cbtadmin/<file>'`).
- Deploy panel: `bash deploy/deploy.sh cbtplus` (Git Bash). Setelan + plugin + CSS Moodle: `bash deploy/setelan-moodle.sh cbtplus`.
- Commit: `git -c user.name=ichalsmiler -c user.email=ichalsmiler@users.noreply.github.com commit`; push hanya
  setelah ditanya ke pengguna. Naikkan `PanelController::VERSION` bila CSS/JS berubah (cache-buster).
- Uji Moodle lewat skrip CLI di server (`sudo -u www-data php /tmp/x.php`); jangan login web dengan akun uji.
  Data uji dibuat lalu dihapus. Login panel di browser dilakukan pengguna.

## Aturan kerja dari pengguna (berlaku di akun Claude mana pun)
- Pengguna admin IT sekolah, berbahasa Indonesia: jawab dan tulis teks UI dalam bahasa Indonesia, ringkas.
- Hemat token: baca CLAUDE.md ini dulu, jangan menjelajah ulang kode; perbarui berkas ini setiap menambah fitur.
- Jangan push sebelum ditanya dan disetujui; commit hanya bila diminta.
- Semua perubahan dipasang ke `cbtplus` (.157) saja; .154/.155 tidak disebut-sebut lagi.
- Cek poin = tag git `cekpoin-N` berurutan; buat bila pengguna minta.
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
  Sesi dicap `cred` = `Guard::cap(password_hash)` (diisi di Auth, Tukar Akses/kembali, Ganti Password, ubah akun sendiri);
  password/kode diganti → sesi lama ditolak. Proktor tanpa ruang → `admin_ruang`=0 (tanpa siswa, token terkunci).
  Simpan absensi pengawas hanya untuk siswa sesi aktif. Jembatan `create_users` menolak admin situs/guest Moodle.
- `Views/layout/main.php` — array `$menu` sidebar; admin menyembunyikan `token`,`kehadiran`; Reset Login muncul
  hanya bila setting `lock_login`=1. Tombol "Kembali ke Admin" saat Tukar Akses (`session tukar_dari`).
- `Config/Routes.php` — semua rute (grup terfilter Guard).
- JS `public/assets/js/panel.js`: `data-post`+`data-fields`+`data-confirm-text` (POST via `#postForm`),
  `data-confirm-button` (form massal), `data-fill-modal`+`data-fill` (isi modal), `table.datatable` (DataTables; isi tbody disembunyikan sampai siap: `html.js` dari `partials/tema_awal` + kelas `.dt-siap` dari panel.js,
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
| Daftar Peserta | Peserta | Daftar Peserta Moodle: tombol Buat user dari Data CBTAdmin + Sinkron dihapus, diganti "Tambah user" (`daftar_peserta/moodle/tambah` → `tambahMoodle`: satu akun langsung ke Moodle, username yang sudah ada ditolak, password kosong = acak, mapel opsional via enrol_users, centang simpan juga ke Data CBTAdmin); massal lewat Konvert User → Kirim ke Moodle; filter Tingkatan + Kelas (kelas = department atau lastname, bawaan Semua, kolom Kelas + Cohort); Data CBTAdmin dibuka: tingkatan/kelas/ruang bawaan Semua (`tingkatData()`, tidak memakai `filter_tingkat` tersimpan; Export Excel dihapus), urut No. Peserta naik (SQL + `data-order-col="1"`); template 7 kolom sederhana (`COLUMNS`, `Excel::build`); impor berkas dan JSON dari Konvert User (`daftar_peserta/cbtadmin/impor_json` → `imporJson`, inti bersama `simpanBaris`; tombol "Kirim N peserta ke Data CBTAdmin" di Pratinjau & unduh menggantikan unduh CSV saat di panel, meta `cbt-peserta-url`/`cbt-peserta-halaman`, `konvert/src/ui/panel.ts kirimPeserta`); impor template CBT Admin atau AIOCBT (`Excel::read`, header baris 9), No. Peserta = username bila kosong; fitur mapel per peserta + Sinkron/enrol DIHAPUS 2026-11-18 (migrasi `DropPesertaMapel`, aksi bridge `enrol_users` dipasang lagi untuk Kirim ke Moodle & Tambah user); pengawas: `peserta_ruang` (`Peserta::ruang`, baca saja, ruangnya); Reset Peserta (ketik HAPUS) / centang "Hapus data lama" saat impor → `hapusSemua()` (buka suspend Moodle siswa terblokir, kosongkan peserta+absensi+blokir; centang `ruang=1` ikut hapus ruang, sesi, akun pengawas; akun Moodle tetap) |
| Status Ujian (grup: Setting Ujian `status_tes`, Hapus Attempt `hapus_attempt`) | StatusTes / HapusAttempt | sesi wajib dipilih; daftar mapel disaring menurut tingkatan sesi terpilih (`data-tingkat` dari shortname/fullname; mapel tingkatan lain disabled → tidak dikirim → disembunyikan saat Simpan); tombol Aktifkan / Nonaktifkan sesi (`status_tes/sesi`, aksi=aktifkan/nonaktifkan; nonaktif = `sesi_aktif` kosong + tes aktif = kategori `SESI NONAKTIF` (`ActiveTest::NAMA_NONAKTIF`, bridge `kategori_nonaktif` membuatnya bila belum ada, category_visibility menyembunyikan kategori lain + course di dalamnya → siswa yang sedang mengerjakan terhenti, attempt dikirim cron saat waktu habis) → semua quiz dikunci password bawaan, `apply()` token dilewati; SESI NONAKTIF tidak bisa dipilih lewat Simpan, dihapus, atau diganti nama, tidak dipakai rencanaHari, dan jadi satu-satunya kategori yang disisakan Reset Moodle) (`sesi_id` → setting `sesi_aktif`, `ActiveTest::sesiList()/sesiAktif()`, sesi peserta = `COALESCE(p.sesi_id, r.sesi_id)`, tingkatan dari kelas pesertanya); kategori Moodle = tes; simpan → hanya mapel dicentang + kategori itu tampil, lainnya disembunyikan; token bersama (`ActiveTest`, cron `cbt:token`); quiz di luar kategori aktif (quiz baru, tes lama) dikunci password bawaan `ActiveTest::DEFAULT_PASSWORD`=123456 (`applyDefault`, dipanggil `sync()` tiap menit dan saat simpan tes) |
| Status Peserta | StatusPeserta | paksa submit, tambah waktu; kolom Waktu Pengerjaan = selesai − mulai (masih mengerjakan: berjalan, `data-mulai` di panel.js); "Login ganda" `status_peserta/login_ganda` (log user_loggedin per IP per tanggal) |
| Reset Login | ResetLogin | hanya dipakai saat Lock login aktif |
| Laporan Kehadiran | Kehadiran | absensi pengawas: hanya siswa sesi aktif (`Absensi::forRuang($ruang,$tgl,$sesi)`; tanpa sesi aktif pengawas lihat pesan, admin pakai filter tingkatan; simpan hanya yang tampil); kunci token per sesi aktif (`terkunciUntuk`); tidak hadir → suspend Moodle hari itu (`Absensi`); token pengawas tampil di Dashboard (`partials/token_card`, menu Token dihapus, `/token` → dashboard) dan terkunci sampai ada absensi ruangnya hari ini (`ActiveTest::terkunciUntuk`, `mapelAktif`) |
| Blokir Siswa | BlokirSiswa | `Libraries/Blokir.php`, tabel `blokir`; tidak dibuka oleh absensi |
| Daftar Ruang | Ruang | kode login pengawas `NPSN-<huruf><no><2 acak>` (`Pengawas::usernameFor`), password 4 huruf kapital (`Pengawas::randomPassword`), Tukar Akses, Generate Kode, Cetak ID Login (`pdf/id_login`), nama lab (`ruang.lokasi`) |
| Dokumen | Dokumen | kartu login peserta per ruang/kelas (`pdf/kartu`: logo sekolah kiri + logo kanan `logo2` dari Info Sekolah, sama besar via `School::gayaLogo`; catatan kiri + tanda tangan kepala sekolah kanan bila nama kepala diisi; jam ujian per tingkatan `jam_<X|XI|XII>_mulai/selesai`, `catatan_kartu`), daftar hadir; Laporan Kehadiran admin `dokumen/laporan_kehadiran` 2 tab: `?tab=pengawas` (rekap absensi pengawas, `Absensi::rekap($tgl,$tingkat)`, view `dokumen/absensi`; alamat lama `dokumen/absensi` dialihkan) dan `?tab=ujian` (hadir = punya attempt, berita acara) |
| Hasil Ujian / No Attempt | HasilUjian / NoAttempt | tab `?tab=cepat` "Selesai terlalu cepat" (pw/pm/pn: % batas waktu, % median, % nilai); tab `?tab=analisis` + `export_analisis` (P kesukaran, D daya beda 27%) dan `export_soal` (B/S/–/? per nomor) via `Libraries/AnalisisSoal` (langkah terakhir question_attempt_steps dari attempt selesai terakhir) |
| Admin Tools (grup sidebar) | Konvert / Tools::testManage / SesiTingkat | submenu Sesi (`sesi_tingkat` → `SesiTingkat`: nomor sesi per tingkatan X/XI/XII di setting `sesi_tingkat` JSON, bawaan XII=1 X=2 XI=3; centang "Terapkan" → peserta.sesi_id per tingkatan (sesi bernama angka dibuat); dipakai Konvert lewat meta `cbt-sesi-tingkat` (menang atas localStorage saat dibuka) dan perubahan di Konvert dikirim balik ke `sesi_tingkat/json` lewat `aturSimpanSesiJauh`); submenu Masukkan Soal (`soal` → `Soal`: unggah RTF (opsi = daftar berhuruf evelnfc3/4, kunci "ANS: X", gambar JPEG/PNG dari shppict; `Libraries/SoalRtf::urai` + `keXml` → Moodle XML), periksa (pratinjau + kunci, disimpan `writable/soal/<token>.json`), lalu bridge import_soal: quiz dibuat bila belum ada (create_module), impor qformat_xml ke bank soal konteks quiz, quiz_add_quiz_question, mode tambah/ganti (ganti ditolak bila ada attempt), recompute sumgrades, nilai maks 100) + Jadwal (berkas jadwal & file kelas moving disimpan di IndexedDB `konvert-berkas` (`konvert/src/ui/berkas.ts`) sampai tombol Reset di atas tab ditekan (`resetDataJadwal/resetDataUser` + `hapusBerkas`); main.ts hanya memasang tab yang tampil; `jadwal_mapel` → `Konvert::jadwal`, bingkai `konvert/app?bagian=jadwal`: tab Jadwal + Kamus Mapel (`konvert/src/ui/tab-kamus.ts`: ubah/tambah/hapus kode, nama lengkap, alias; Ekspor/Impor JSON; Kembalikan ke awal; inti `ubahMapel/hapusMapel/kamusDariJson/pecahAlias` di core/kamus.ts)) + Kategori Mapel (`tools/test_manage`, dulu "Kategori & Mapel") + Konvert User (`?bagian=user`: tab lain saja) (`tools/test_manage`: buat/ubah nama kategori = tes (`ujian/ubah`, bridge update_category; isian "Jumlah hari" `hari` → H1..Hn: kategori tingkat atas yang ada dipakai dulu urut id lalu diganti nama (`Tools::rencanaHari`, bridge rename_categories 2 tahap), kekurangan dibuat (create_categories); pratinjau + konfirmasi di form; Mapel dari jadwal HANYA lewat Konvert (tab Jadwal → Mapel, tombol "Buat N mapel di Moodle", `konvert/src/ui/panel.ts` → POST JSON `mapel_json` + header CSRF dari meta yang disisipkan `Konvert::app`; inti `Tools::buatMapel`): category = NOMOR HARI → kategori bernama awalan+N, bukan id; bridge create_courses, shortname ada dilewati, setiap mapel (baru & lama) dipastikan ber-format singleactivity + activitytype quiz (create_course tidak memakai bawaan situs; update_course) , forum Announcements (news, tanpa postingan) dihapus + newsitems 0, dan punya quiz lewat `cbt_pastikan_quiz` (create_module quiz: 1 percobaan, nilai 100; juga dipakai create_course & import_soal), lalu sort_courses menyusun urutan course per kategori sesuai kiriman (course baru Moodle masuk paling atas); penetapan sesi di tab Jadwal (Sesi 1..3 → tingkat, bawaan XII, X, XI, disimpan bersama Kel. Sesi tab User → CBTAdmin di localStorage `web-ujian.sesi-tingkat` (`muatUrutanSesi/simpanUrutanSesi` turunan `muatSesi/simpanSesi`, pendengar `saatSesiBerubah` + event storage antar-halaman), `urutGabung(tingkat, urutan)`) menentukan urutan mapel per hari; pratinjau Konvert menampilkan kolom Hari, shortname, fullname; Durasi pengerjaan (kartu bawah, `tools/test_manage/durasi` → `Tools::durasi`: setting `durasi_menit` (bawaan 90 menit), bridge quiz_timelimit untuk semua mapel atau ujian terbuka, quiz yang sedang dikerjakan dilewati; quiz baru memakai `Tools::durasiDetik()` lewat masukan `timelimit`); Reset Moodle (kartu bawah, `tools/test_manage/reset` + `reset/lanjut`, `Tools::resetMoodle/langkahReset`, view `tools/reset_proses` kirim ulang otomatis): centang siswa (simpan guru/manager), ujian (semua course lalu kategori, 1 kategori tingkat atas disisakan), log, data panel; ketik RESET MOODLE; ditolak bila sesi aktif atau ada attempt inprogress; backup mysqldump Moodle+panel ke `writable/backup/reset_*.sql.gz` (30 hari) dulu; bridge reset_moodle bertahap 20 dtk; selesai → tes_kategori=0, peserta.moodle_userid NULL, purge cache; Hapus mapel per baris `mapel/hapus` (`Tools::hapusMapel`, bridge delete_course; ketik shortname, mapel aktif di tes aktif ditolak, jumlah attempt ditampilkan); Hapus ujian `ujian/hapus` (bridge delete_category = delete_full; berisi mapel wajib ketik nama, tes aktif ditolak, minimal 1 kategori tersisa)), course = mapel lewat bridge create_category/create_course); Konvert User: admin saja; aplikasi TypeScript+Vite di `konvert/` (jadwal → mapel, data peserta → template CBTAdmin, kelas moving (pilih alur Kelas XI atau XII di atas; tiap alur punya upload/cocok/manual/rekap sendiri, file yang diunggah di alur X dianggap kelas X + peringatan bila nama file/kolom KELAS menyebut kelas lain; hasil kedua alur digabung ke User → Moodle), user Moodle (tombol "Kirim N siswa ke Moodle": `kirimKeMoodle` per 40 siswa → `daftar_peserta/moodle/kirim_json` `Peserta::kirimMoodleJson`: bridge create_users (yang ada: password + firstname/lastname/department/address disamakan) lalu enrol_users (manual, role student, shortname tak ada dilaporkan), isi peserta.moodle_userid; hasil konversi jadwal per tingkat disimpan di localStorage `web-ujian.hasil-mapel.v1` + event storage, karena menu Jadwal dan Konvert User halaman berbeda); diproses di browser, 166 tes `npm test`), di-build `bash deploy/build-konvert.sh` → `app/Konvert/konvert.html` (di-commit, ikut `deploy.sh`), disajikan `konvert/app` dalam iframe `konvert/index`; di dalam bingkai tema mengikuti panel (`cbt-tema`, event storage) dan kepala aplikasi disembunyikan (`:root.di-panel`), token warna sama dengan panel. Node.js hanya di PC, tidak di server |
| Tools | Tools | accordion: Moodle Tools (Kelola Test `tools/test_manage`, Cron, Purge/Delete cache, Anti Submit) + CBTAdmin Tools (Info Sekolah, Info Kegiatan, Lock/Resume/Anti Newtab, Ganti Password) + Lainnya (akun, panel, backup, log: tab Panel / Pengawas (login panel + jam absen per ruang dari activity_log + tabel tindakan pengawas) / Mulai ujian (`quiz_attempts.timestart`, telat vs `jam_<T>_mulai`, `?telat=`) / Login siswa (logstore, >1 IP kuning) / Belum mulai (hadir tanpa attempt) via `Libraries/LogUjian`, `?log_tgl=&log_tab=`; Excel `tools/log_export?jenis=`; jejak siswa `tools/jejak?u=` (event Moodle + absensi + log panel yang menyebut username; paksa submit/tambah waktu/reset login mencatat `; siswa: …` lewat `PanelController::daftarSiswaLog`)) |

Library penting: `Moodle` (koneksi DB Moodle langsung, baca; `quizzes()`, `categories()`, `coursesInCategory()`,
`allCourses()`), `MoodleBridge::call(action, payload)` (tulis lewat API Moodle, CLI) dan `MoodleBridge::cron()`,
`QuizStatus` (status peserta per quiz, `filter(rows, ruang, kelas, tingkat)`, `latestAttemptIds`),
`Settings` (tabel `settings` skey/svalue), `School::info()` (identitas + logo/ttd data-URI untuk PDF),
`Pengawas` (akun pengawas per ruang), `ActivityLog::add()`, `Pdf::respond()` (dompdf), `Excel`.

Jembatan `Bridge/moodle_bridge.php` (aksi): create_users, reset_passwords, quiz_times, finish_attempts,
delete_attempts, add_time, list_sessions, kill_sessions, quiz_password, course_visibility, category_visibility,
suspend_users, cbtujian_config, purge_caches, create_category, create_categories, rename_categories, update_category, delete_category, delete_course, kategori_nonaktif, sort_courses, reset_moodle, enrol_users, import_soal, quiz_timelimit, create_course, create_courses, session_gc.

Migrasi `Database/Migrations/`: tabel admins (role admin/proktor, ruang_id, password_plain), peserta, ruang
(+lokasi), sesi, settings, activity_log, absensi, blokir.

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
  kelas), tanpa Dasbor/Beranda/pesan/lonceng/tombol ?/filter; quiz 1 percobaan dikunci, tanpa pratinjau soal setelah submit (reviewattempt=0x10000 di setelan.php untuk semua quiz + bridge cbt_pastikan_quiz; siswa kembali ke view.php dengan pesan lang `noreview` "Jawaban Anda sudah terkirim").
- `cbtujian` juga: menu sekunder course/quiz disembunyikan untuk yang tak punya `moodle/course:update` (hook
  `before_http_headers` → `set_secondary_navigation(false)`); laci blok kanan disembunyikan untuk siswa (body `cbt-siswa`) dan di attempt/summary
  (boost.scss), navigasi nomor soal `.qn_buttons` disalin ke `#cbt-nav-soal` di bawah `#responseform` (awal_halaman) berjudul "Daftar Soal" (lang/quiz.php quiznavigation) dengan gaya LMS sekolah 3.9: tombol 30x40, merah #ff7f7f belum, hijau #bdda57 tersimpan, kuning ragu, nomor aktif garis 3px, `#quiz-timer-wrapper` dipindah ke ujung kanan bilah info soal pertama (cadangan: kotak itu).
- Halaman soal (`mod/quiz/attempt.php`) seperti LMS sekolah: di laptop (>=992px) lebar penuh, grid 2 kolom (soal kiri dalam
  kotak, `#cbt-nav-soal` kanan 360px), di ponsel 1 kolom; bilah info abu-abu "Soal nomor N | Belum dijawab | Ragu-ragu"
  (lang question.php questionx/clickflag), tombol "Soal sebelumnya" abu-abu / "Soal berikutnya" biru (lang quiz.php).
- Halaman Selesaikan ujian (`mod/quiz/summary.php`) seperti LMS: judul/tabel status/Kembali disembunyikan, tengah = Sisa waktu +
  "Ujian akan selesai secara otomatis pada …" + tombol abu-abu "Kirim Jawaban", Daftar Soal kanan (JS cbtujian juga di summary);
  tombol soal terakhir "Selesaikan ujian" (lang quiz.php endtest).
- Halaman awal ujian (`mod/quiz/view.php`) meniru LMS sekolah 3.9: teks "Kesempatan mengerjakan : 1 kali", "Masukkan token
  untuk memulai ujian", "Waktu mengerjakan : …" (lang quizaccess_numattempts/password/timelimit, `min(s)`=menit), kartu
  percobaan diganti tabel `.cbt-percobaan` Status | Review oleh JS di `cbtujian` awal_halaman, urutan/kotak/tombol abu-abu
  di boost.scss (`#page-mod-quiz-view`). Cek poin = tag git `cekpoin-N` (kembali: checkout tag lalu deploy + setelan-moodle).
- Bahasa Indonesia: kata "kuis" → "ujian" di paket `id` (quiz, quiz_*, quizaccess_*, moodle) dibuat otomatis oleh
  setelan.php ke `lang/id_local`, lalu teks kustom `deploy/moodle/lang/<berkas>` ditambahkan di belakangnya (menang).
- Dialog mulai quiz berwaktu (ajakan berdoa + peringatan kecurangan): `deploy/moodle/lang/quizaccess_timelimit.php`.
- Nilai quiz skala 0–100: `quiz/maximumgrade`=100 dan setiap quiz dengan grade ≠ 100 diubah ke 100 lewat
  `grade_calculator::update_quiz_maximum_grade` (setelan.php; jalankan ulang setelah guru membuat quiz).
## Lain-lain
- `deploy/install-moodle.sh` (server baru, `ZONA_WAKTU`), `deploy/install.sh` (panel; dilewati migrasi di cadangan HA),
  `deploy/ha/` (replikasi MariaDB + rsync; dua IP LAN → langsung, ada IP publik → WireGuard 10.77.0.x;
  mode campuran utama LAN + cadangan VPS: peer ber-IP lokal tanpa Endpoint (`ip_lokal`), skema http/https per server; `/etc/cbt-ha-peran`).
- README.md berisi panduan lengkap untuk manusia.
