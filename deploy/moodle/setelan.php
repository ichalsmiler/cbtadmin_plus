<?php
/**
 * Setelan Moodle untuk ujian CBT. Dijalankan oleh deploy/moodle/pasang.sh sebagai www-data:
 *   php setelan.php /var/www/moodle/config.php
 * Aman dijalankan berulang kali: hanya nilai yang berbeda yang diubah, dan setiap perubahan dicetak.
 * Untuk membatalkan satu setelan, ubah lewat Site administration seperti biasa
 * (menjalankan skrip ini lagi akan memasangnya kembali).
 */
define('CLI_SCRIPT', true);

$config = $argv[1] ?? '/var/www/moodle/config.php';
require $config;
require_once $CFG->libdir . '/adminlib.php';
require_once $CFG->libdir . '/accesslib.php';

global $DB;
$dir     = __DIR__;
$ubah    = 0;
$catatan = static function (string $teks): void { echo "  - $teks\n"; };

/** Pasang satu setelan bila nilainya berbeda. */
$setel = static function (string $name, $value, ?string $plugin = null) use (&$ubah, $catatan): void {
    $lama = get_config($plugin ?? 'core', $name);
    if ((string) $lama !== (string) $value) {
        set_config($name, $value, $plugin);
        $catatan(($plugin ? "$plugin/" : '') . "$name: " . var_export($lama, true) . ' -> ' . var_export((string) $value, true));
        $ubah++;
    }
};

\core\session\manager::set_user(get_admin());

// ---------------------------------------------------------------------- Bahasa Indonesia
echo "== Bahasa\n";
$sm = get_string_manager();
if (! $sm->translation_exists('id', false)) {
    try {
        $c = new \tool_langimport\controller();
        $c->install_languagepacks(['id']);
        $sm->reset_caches();
        $catatan('paket bahasa Indonesia dipasang');
    } catch (\Throwable $e) {
        echo "  [PERINGATAN] Paket bahasa Indonesia gagal diunduh (perlu internet): {$e->getMessage()}\n";
    }
}
if ($sm->translation_exists('id', false)) {
    $setel('lang', 'id');
    // Akun yang masih berbahasa Inggris ikut Bahasa Indonesia (kecuali admin situs).
    $admins = array_filter(array_map('intval', explode(',', (string) $CFG->siteadmins)));
    [$notin, $params] = $DB->get_in_or_equal($admins ?: [0], SQL_PARAMS_NAMED, 'a', false);
    $n = $DB->count_records_select('user', "deleted = 0 AND lang = 'en' AND id $notin", $params);
    if ($n > 0) {
        $DB->execute("UPDATE {user} SET lang = 'id' WHERE deleted = 0 AND lang = 'en' AND id $notin", $params);
        $catatan("$n akun diubah ke Bahasa Indonesia");
    }
}
$setel('langmenu', 0);          // siswa tidak bisa mengganti bahasa
$setel('autolang', 0);          // bahasa tidak mengikuti browser: halaman login selalu bahasa Indonesia
$setel('country', 'ID');
// Zona waktu Moodle = zona waktu server (diatur install-moodle.sh, bawaan Asia/Makassar).
$zona = trim((string) @file_get_contents('/etc/timezone')) ?: date_default_timezone_get();
$setel('timezone', $zona);
$setel('forcetimezone', $zona);

// Teks kustom: Daftar Ujian, sapaan nama - kelas, pesan sesi berakhir (deploy/moodle/lang/*.php).
// Bahasa Indonesia: kata "kuis" di teks quiz (quiz, quiz_*, quizaccess_*, moodle) diganti "ujian";
// teks kustom ditambahkan sesudahnya sehingga tetap menang.
$kuisJadiUjian = static function (string $file): string {
    $string = [];
    include $file;
    $isi = '';
    foreach ($string as $id => $teks) {
        if (stripos((string) $teks, 'kuis') === false) {
            continue;
        }
        $baru = preg_replace_callback('/kuis/i', static fn ($m) => match ($m[0]) {
            'KUIS'  => 'UJIAN',
            'Kuis'  => 'Ujian',
            default => 'ujian',
        }, (string) $teks);
        $isi .= '$string[' . var_export($id, true) . '] = ' . var_export($baru, true) . ";\n";
    }

    return $isi;
};
$kustom = [];
foreach (glob($dir . '/lang/*.php') as $sumber) {
    $kustom[basename($sumber)] = preg_replace('/^<\?php\s*/', '', file_get_contents($sumber));
}
foreach (['id_local', 'en_local'] as $lokal) {
    $tujuan = $CFG->dataroot . '/lang/' . $lokal;
    if (! is_dir($tujuan)) {
        mkdir($tujuan, $CFG->directorypermissions, true);
    }
    $isiBaru = [];
    if ($lokal === 'id_local') {
        $paket = $CFG->dataroot . '/lang/id/';
        foreach (array_merge([$paket . 'quiz.php', $paket . 'moodle.php'], glob($paket . 'quiz_*.php'), glob($paket . 'quizaccess_*.php')) as $f) {
            if (is_file($f) && ($isi = $kuisJadiUjian($f)) !== '') {
                $isiBaru[basename($f)] = "// Dibuat otomatis oleh setelan.php: kata kuis diganti ujian.\n" . $isi;
            }
        }
    }
    foreach ($kustom as $nama => $isi) {
        $isiBaru[$nama] = ($isiBaru[$nama] ?? '') . $isi;
    }
    foreach ($isiBaru as $nama => $isi) {
        $isi  = "<?php\n" . $isi;
        $file = $tujuan . '/' . $nama;
        if (! is_file($file) || file_get_contents($file) !== $isi) {
            file_put_contents($file, $isi);
            chmod($file, $CFG->filepermissions);
            $catatan("teks kustom lang/$lokal/$nama");
            $ubah++;
        }
    }
}

// ---------------------------------------------------------------------- Login dan keamanan
echo "== Login dan keamanan\n";
$setel('forcelogin', 1);                 // wajib login
$setel('guestloginbutton', 0);           // tanpa tombol masuk sebagai tamu
$setel('auth', implode(',', array_diff(explode(',', (string) $CFG->auth), ['email', ''])));  // tanpa daftar mandiri
$setel('registerauth', '');
// Satu akun satu perangkat lewat Lock login plugin local_cbtujian (selalu aktif): login dari perangkat LAIN
// ditolak sampai pengawas me-Reset Login; perangkat yang sama (keluar aplikasi lalu masuk lagi) diterima.
// Batas bawaan Moodle (menendang sesi lama) dimatikan.
$setel('locklogin', 1, 'local_cbtujian');
$setel('limitconcurrentlogins', 0);
$setel('passwordpolicy', 0);             // Upload users tidak memaksa ganti kata sandi
$setel('debug', 0);
$setel('debugdisplay', 0);

// Siswa (Authenticated user): tidak bisa mengubah profil/kata sandi dan tidak bisa mengirim pesan.
$sys  = context_system::instance();
$user = $DB->get_record('role', ['shortname' => 'user'], '*', MUST_EXIST);
foreach (['moodle/user:editownprofile', 'moodle/user:changeownpassword', 'moodle/user:editownmessageprofile',
          'moodle/user:manageownfiles', 'moodle/site:sendmessage'] as $cap) {
    if ((int) $DB->get_field('role_capabilities', 'permission', ['roleid' => $user->id, 'capability' => $cap, 'contextid' => $sys->id]) !== CAP_PREVENT) {
        assign_capability($cap, CAP_PREVENT, $user->id, $sys->id, true);
        $catatan("Authenticated user: $cap -> Prevent");
        $ubah++;
    }
}

// ---------------------------------------------------------------------- Tampilan siswa
echo "== Tampilan\n";
$setel('defaulthomepage', 3);            // setelah login langsung ke Daftar Ujian (Kursusku)
$setel('allowguestmymoodle', 0);
$setel('enabledashboard', 0);            // tanpa Dasbor
$setel('messaging', 0);                  // tanpa pesan
$setel('enableblogs', 0);
$setel('usecomments', 0);
$setel('enablenotes', 0);
$setel('enablebadges', 0);
$setel('customusermenuitems', 'mycourses,moodle|/my/courses.php');   // menu profil: Daftar Ujian + Keluar
foreach (['inprogress', 'future', 'past', 'favourites'] as $g) {
    $setel('displaygrouping' . $g, 0, 'block_myoverview');           // Daftar Ujian: semua mapel tanpa filter
}
$setel('displaygroupingall', 1, 'block_myoverview');

foreach (['online_users', 'calendar_month', 'calendar_upcoming', 'timeline'] as $blok) {
    if ($DB->get_field('block', 'visible', ['name' => $blok])) {
        $DB->set_field('block', 'visible', 0, ['name' => $blok]);
        $catatan("blok $blok disembunyikan");
        $ubah++;
    }
}
foreach ($DB->get_records('tool_usertours_tours', ['enabled' => 1]) as $t) {
    $tour = \tool_usertours\tour::load_from_record($t);
    $tour->set_enabled(false);
    $tour->persist();
    $catatan("tour dimatikan: {$t->name}");
    $ubah++;
}

// CSS tema Boost: blok CBT diganti dari deploy/moodle/boost.scss; SCSS lain di luar blok dibiarkan.
$mulai = '/* === CBT MULAI (diatur deploy/setelan-moodle.sh) === */';
$akhir = '/* === CBT SELESAI === */';
$scss  = (string) get_config('theme_boost', 'scss');
$blokCss = $mulai . "\n" . trim((string) file_get_contents($dir . '/boost.scss')) . "\n" . $akhir;
if (str_contains($scss, $mulai)) {
    $baru = preg_replace('/' . preg_quote($mulai, '/') . '.*?' . preg_quote($akhir, '/') . '/s', addcslashes($blokCss, '\\$'), $scss);
} elseif (str_contains($scss, '/* CBT:')) {
    $baru = $blokCss;   // CSS CBT lama tanpa penanda: diganti seluruhnya
} else {
    $baru = trim($scss . "\n\n" . $blokCss);
}
if (trim($baru) !== trim($scss)) {
    set_config('scss', $baru, 'theme_boost');
    $catatan('CSS tema Boost diperbarui');
    $ubah++;
}

// Alamat situs di soal (mis. sumber berita "https://…") tidak boleh jadi tautan yang bisa diklik: siswa bisa keluar
// dari ujian (Anti Newtab). Parser soal RTF sudah membuang tautan, jadi cukup matikan filter "Convert URLs into links".
require_once($CFG->libdir . '/filterlib.php');
$filterurl = $DB->get_field('filter_active', 'active', ['filter' => 'urltolink', 'contextid' => context_system::instance()->id]);
if ($filterurl === false || (int) $filterurl !== TEXTFILTER_DISABLED) {
    filter_set_global_state('urltolink', TEXTFILTER_DISABLED);
    $catatan('filter urltolink dimatikan (alamat situs di soal tidak bisa diklik)');
    $ubah++;
}

// ---------------------------------------------------------------------- Course dan quiz
echo "== Course dan quiz\n";
// Kategori "SESI NONAKTIF" (tanpa ujian): tes aktif saat semua sesi nonaktif, tidak bisa dihapus dari panel.
// Dibuat sejak awal supaya selalu terlihat di Kategori Mapel (sama dengan cbt_kategori_nonaktif() di jembatan panel).
$adaNonaktif = false;
foreach ($DB->get_records('course_categories', ['parent' => 0], 'id', 'id, name') as $kat) {
    if (core_text::strtoupper(trim((string) $kat->name)) === 'SESI NONAKTIF') {
        $adaNonaktif = true;
        break;
    }
}
if (! $adaNonaktif) {
    // Moodle baru selalu punya satu kategori bawaan kosong (id 1, "Category 1"). Kategori itu dijadikan SESI NONAKTIF
    // supaya tidak tersisa kategori ekstra; hari ujian (H1, H2, …) yang dibuat kemudian mendapat id berikutnya.
    $atas = $DB->get_records('course_categories', ['parent' => 0], 'id', 'id, name, coursecount');
    $bawaan = count($atas) === 1 ? reset($atas) : null;
    if ($bawaan && (int) $bawaan->coursecount === 0
        && ! $DB->record_exists('course_categories', ['parent' => $bawaan->id])
        && ! $DB->record_exists('course', ['category' => $bawaan->id])) {
        core_course_category::get($bawaan->id, MUST_EXIST, true)->update(['name' => 'SESI NONAKTIF']);
        $catatan('kategori bawaan (id ' . $bawaan->id . ') diganti nama jadi SESI NONAKTIF');
    } else {
        core_course_category::create(['name' => 'SESI NONAKTIF', 'parent' => 0]);
        $catatan('kategori SESI NONAKTIF dibuat');
    }
    $ubah++;
}
$setel('format', 'singleactivity', 'moodlecourse');      // course baru: Single activity
$setel('activitytype', 'quiz', 'format_singleactivity'); // ... berisi Quiz
$setel('courseenddateenabled', 0, 'moodlecourse');       // tanpa tanggal selesai course
$setel('attempts', 1, 'quiz');                           // 1 kali percobaan,
$setel('attempts_locked', 1, 'quiz');                    // dikunci di form quiz
$setel('timelimit', 5400, 'quiz');
$setel('overduehandling', 'autosubmit', 'quiz');         // tersubmit otomatis saat waktu habis
$setel('autosaveperiod', 60, 'quiz');                    // jawaban tersimpan tiap 60 detik
$setel('questionsperpage', 1, 'quiz');
$setel('navmethod', 'free', 'quiz');
$setel('shuffleanswers', 1, 'quiz');
$setel('preferredbehaviour', 'deferredfeedback', 'quiz');
// Tinjauan: tanpa kunci jawaban dan benar/salah; nilai baru tampil setelah quiz ditutup.
$setel('reviewattempt', 0x10000, 'quiz');
$setel('reviewcorrectness', 0, 'quiz');
$setel('reviewmarks', 0x10, 'quiz');
$setel('reviewspecificfeedback', 0, 'quiz');
$setel('reviewgeneralfeedback', 0, 'quiz');
$setel('reviewrightanswer', 0, 'quiz');
$setel('reviewoverallfeedback', 0, 'quiz');
// Quiz yang sudah ada: tinjauan soal hanya saat mengerjakan (0x10000), jadi setelah submit siswa langsung kembali ke
// halaman awal ujian tanpa pratinjau soal (review.php mengalihkan ke view.php).
foreach ($DB->get_records_select('quiz', 'reviewattempt <> ?', [0x10000], '', 'id, name') as $q) {
    $DB->set_field('quiz', 'reviewattempt', 0x10000, ['id' => $q->id]);
    $catatan("quiz '{$q->name}': tanpa pratinjau soal setelah selesai");
    $ubah++;
}

// Nilai quiz skala 0–100 (Maximum grade = 100), apa pun Total of marks soalnya; quiz baru juga bawaan 100.
// Quiz lama diubah lewat API Moodle supaya nilai attempt dan gradebook ikut diskalakan ulang.
$setel('maximumgrade', 100, 'quiz');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
foreach ($DB->get_records_select('quiz', 'grade <> 100', null, '', 'id, name') as $q) {
    \mod_quiz\quiz_settings::create($q->id)->get_grade_calculator()->update_quiz_maximum_grade(100);
    $catatan("quiz '{$q->name}': nilai maksimum -> 100");
    $ubah++;
}

// Guru/manager tidak bisa memberi kesempatan kedua (override percobaan, hapus attempt); admin tetap bisa.
foreach (['editingteacher', 'manager'] as $short) {
    $role = $DB->get_record('role', ['shortname' => $short]);
    foreach (['mod/quiz:manageoverrides', 'mod/quiz:deleteattempts'] as $cap) {
        if ($role && $DB->record_exists('role_capabilities', ['roleid' => $role->id, 'capability' => $cap, 'contextid' => $sys->id])) {
            unassign_capability($cap, $role->id, $sys->id);
            $catatan("$short: $cap dicabut");
            $ubah++;
        }
    }
}

// ---------------------------------------------------------------------- Cache aplikasi di Redis
// Sesi login sudah di Redis (config.php). Cache aplikasi Moodle (MUC, mode application) juga dipindah ke Redis
// lokal supaya halaman quiz tidak membaca cache dari disk saat ujian serentak. Dilewati bila Redis tidak berjalan.
echo "== Cache Redis\n";
$redisHidup = false;
if (class_exists('Redis')) {
    try {
        $redis      = new Redis();
        $redisHidup = $redis->connect('127.0.0.1', 6379, 2) && $redis->ping();
    } catch (Throwable $e) {
        $redisHidup = false;
    }
}
if (! $redisHidup) {
    $catatan('Redis tidak berjalan di 127.0.0.1:6379; cache tetap di berkas');
} else {
    $writer = \core_cache\config_writer::instance();
    $konfig = ['server' => '127.0.0.1:6379', 'prefix' => 'mdlc_', 'password' => '', 'serializer' => Redis::SERIALIZER_PHP,
        'compressor' => 0, 'connectiontimeout' => 3, 'encryption' => false, 'cafile' => '', 'clustermode' => false];
    if (! array_key_exists('cbt_redis', $writer->get_all_stores())) {
        $writer->add_store_instance('cbt_redis', 'redis', $konfig);
        $catatan('penyimpanan cache cbt_redis dibuat');
        $ubah++;
    }
    $petaApp = array_values(array_map(static fn ($m) => $m['store'], array_filter($writer->get_mode_mappings(), static fn ($m) => (int) $m['mode'] === \core_cache\store::MODE_APPLICATION)));
    if ($petaApp !== ['cbt_redis']) {
        $peta = [];
        foreach ($writer->get_mode_mappings() as $m) {
            $peta[(int) $m['mode']][] = $m['store'];
        }
        $peta[\core_cache\store::MODE_APPLICATION] = ['cbt_redis'];
        $writer->set_mode_mappings($peta);
        $catatan('cache aplikasi (bawaan) memakai Redis');
        $ubah++;
    }
}

// ---------------------------------------------------------------------- Selesai
if ($ubah > 0) {
    theme_reset_all_caches();
    purge_all_caches();
}
echo $ubah > 0 ? "Selesai: $ubah setelan diubah.\n" : "Selesai: semua setelan sudah sesuai, tidak ada yang diubah.\n";
