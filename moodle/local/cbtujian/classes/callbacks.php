<?php
namespace local_cbtujian;

use core\event\user_loggedin;
use core\hook\after_config;
use core\hook\output\before_standard_top_of_body_html_generation;

/**
 * Aturan ujian CBT. Setelan (plugin local_cbtujian) diatur dari CBT Admin → Tools:
 *  - antisubmit  : menit sebelum waktu habis saat tombol selesai mulai aktif (0 = mati)
 *  - locklogin   : tolak login bila akun masih login di perangkat lain (pengawas me-reset login)
 *  - resumetimer : waktu selama siswa keluar ditambahkan kembali saat ia login lagi
 *  - antinewtab  : tiap kali siswa meninggalkan halaman ujian (tab/aplikasi lain) dicatat dan diperingatkan;
 *                  lebih dari antinewtabmaks kali (bawaan 3) → ujian dikunci sampai pengawas membukanya di panel
 *  - loginmaks   : batas login per mapel (per attempt yang berjalan; login untuk memulai = 1). Lebih dari itu
 *                  login ditolak sampai pengawas membuka kunci di panel (0 = mati)
 */
class callbacks {
    /** Ambang jeda (detik) sebelum Resume timer menambah waktu; autosave quiz tiap 60 detik. */
    private const JEDA_MINIMAL = 120;

    // ------------------------------------------------------------------ Login

    /** Setelah login: Lock login dan Resume timer. */
    public static function login(user_loggedin $event): void {
        global $SESSION;

        $userid = (int) $event->objectid;
        if (is_siteadmin($userid)) {
            return;
        }
        if (get_config('local_cbtujian', 'locklogin')) {
            $lain = self::sesi_lain($userid);
            if ($lain !== [] && !self::perangkat_sama($userid, $lain)) {
                // Login dari perangkat LAIN: sesi baru ditolak pada permintaan berikutnya (lihat setelah_config),
                // supaya alur login Moodle selesai dulu dengan rapi. Sesi lama tetap berjalan; percobaan dicatat
                // untuk menu Reset Login di panel.
                $SESSION->local_cbtujian_tolak = 'masihlogin';
                set_user_preference('cbtujian_ditolak', json_encode(['w' => time(), 'ip' => getremoteaddr()]), $userid);

                return;
            }
            // Perangkat yang sama (keluar aplikasi lalu masuk lagi): sesi lama perangkat ini ditutup, login diterima.
            foreach ($lain as $sid) {
                \core\session\manager::kill_session($sid);
            }
            set_user_preference('cbtujian_perangkat', json_encode(self::cap_perangkat()), $userid);
            unset_user_preference('cbtujian_ditolak', $userid);
        }
        if (self::login_maks() > 0) {
            try {
                $n = self::hitung_login($userid);
                if ($n > self::login_maks()) {
                    $SESSION->local_cbtujian_tolak = 'terkuncilogin';

                    return;
                }
                if ($n > 0) {
                    // Peringatan "login n dari maks" di halaman berikutnya (notifikasi Moodle bertahan setelah login).
                    $a = (object) ['n' => $n, 'maks' => self::login_maks(), 'sisa' => self::login_maks() - $n];
                    \core\notification::warning(get_string($a->sisa > 0 ? 'peringatanlogin' : 'peringatanloginakhir', 'local_cbtujian', $a));
                }
            } catch (\Throwable $e) {
                debugging('local_cbtujian batas login: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        if (get_config('local_cbtujian', 'resumetimer')) {
            try {
                self::lanjutkan_waktu($userid);
            } catch (\Throwable $e) {
                debugging('local_cbtujian resume timer: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /** Sid sesi aktif lain milik user (selain sesi ini). */
    private static function sesi_lain(int $userid): array {
        global $CFG;

        $batas = time() - (int) $CFG->sessiontimeout;
        $sid   = [];
        foreach (\core\session\manager::get_sessions_by_userid($userid) as $s) {
            if ($s->sid !== session_id() && (int) $s->timemodified > $batas) {
                $sid[] = $s->sid;
            }
        }

        return $sid;
    }

    // ------------------------------------------------------------------ Identitas perangkat (Lock login)

    /** Cookie penanda perangkat (acak, bertahan 1 tahun; tidak ikut hilang saat sesi Moodle berakhir). */
    private const COOKIE_PERANGKAT = 'CBTPERANGKAT';

    /** Nilai cookie perangkat untuk permintaan ini (dibuat di setelah_config bila belum ada). */
    private static ?string $perangkat = null;

    /** Pasang cookie perangkat bila belum ada (dipanggil sebelum header terkirim). */
    private static function pasang_cookie_perangkat(): void {
        $nilai = (string) ($_COOKIE[self::COOKIE_PERANGKAT] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $nilai)) {
            self::$perangkat = $nilai;

            return;
        }
        self::$perangkat = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie(self::COOKIE_PERANGKAT, self::$perangkat, [
                'expires'  => time() + 365 * DAYSECS,
                'path'     => '/',
                'secure'   => is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /** Ciri perangkat saat ini: cookie perangkat, IP, dan sidik User-Agent. */
    private static function cap_perangkat(): array {
        return [
            'c'  => self::$perangkat ?? '',
            'ip' => (string) getremoteaddr(),
            'ua' => sha1((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ];
    }

    /**
     * Apakah login ini dari perangkat yang sama dengan sesi yang masih aktif.
     * Sama bila cookie perangkat cocok, atau (bila cookie ikut terhapus saat aplikasi ditutup) IP dan
     * User-Agent sama dengan login terakhir yang diterima. Belum ada catatan (sesi dari sebelum fitur ini):
     * sama bila IP semua sesi aktif lain sama dengan IP sekarang.
     */
    private static function perangkat_sama(int $userid, array $sids): bool {
        global $DB;

        $kini = self::cap_perangkat();
        $lalu = json_decode((string) get_user_preferences('cbtujian_perangkat', '', $userid), true);
        if (is_array($lalu)) {
            if ($kini['c'] !== '' && ($lalu['c'] ?? '') === $kini['c']) {
                return true;
            }

            return ($lalu['ip'] ?? '') === $kini['ip'] && ($lalu['ua'] ?? '') === $kini['ua'];
        }
        foreach ($DB->get_records_list('sessions', 'sid', $sids, '', 'id, lastip') as $s) {
            if ((string) $s->lastip !== $kini['ip']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resume timer: untuk setiap attempt yang masih berjalan dan belum habis waktunya, waktu sejak
     * penyimpanan terakhir ditambahkan ke batas waktu siswa itu (override quiz per siswa).
     */
    private static function lanjutkan_waktu(int $userid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attempts = $DB->get_records('quiz_attempts', ['userid' => $userid, 'state' => 'inprogress', 'preview' => 0]);
        foreach ($attempts as $attempt) {
            $jeda = time() - (int) $attempt->timemodified;
            if ($jeda < self::JEDA_MINIMAL) {
                continue;
            }
            $quizobj = \mod_quiz\quiz_settings::create((int) $attempt->quiz, $userid);
            $quiz    = $quizobj->get_quiz();      // sudah termasuk override siswa ini
            if (empty($quiz->timelimit)) {
                continue;
            }
            $batas = (int) $attempt->timestart + (int) $quiz->timelimit;
            if (!empty($quiz->timeclose)) {
                $batas = min($batas, (int) $quiz->timeclose);
            }
            if ($batas <= time()) {
                continue;  // waktu sudah habis; attempt diselesaikan seperti biasa
            }

            $data     = ['userid' => $userid, 'timelimit' => (int) $quiz->timelimit + $jeda];
            $existing = $DB->get_record('quiz_overrides', ['quiz' => $attempt->quiz, 'userid' => $userid]);
            if ($existing) {
                $data['id'] = (int) $existing->id;
                foreach (['timeopen', 'attempts', 'password'] as $tetap) {
                    if ($existing->$tetap !== null) {
                        $data[$tetap] = $existing->$tetap;
                    }
                }
            }
            if (!empty($quiz->timeclose)) {
                $data['timeclose'] = (int) $quiz->timeclose + $jeda;
            }
            $quizobj->get_override_manager()->save_override($data);
            // Jeda sudah diganti; login berikutnya menghitung dari sekarang, bukan menambah jeda yang sama lagi.
            $DB->set_field('quiz_attempts', 'timemodified', time(), ['id' => $attempt->id]);
        }
    }

    // ------------------------------------------------------------------ Awal setiap permintaan (sebelum halaman dibuat)

    public static function setelah_config(after_config $hook): void {
        global $SCRIPT, $SESSION;

        if (CLI_SCRIPT || during_initial_install() || empty($SESSION)) {
            return;
        }
        if (get_config('local_cbtujian', 'locklogin')) {
            self::pasang_cookie_perangkat();
        }

        // Lock login: sesi baru ditolak, kembali ke halaman login dengan pesan.
        if (!empty($SESSION->local_cbtujian_tolak)) {
            $tolak = $SESSION->local_cbtujian_tolak;
            unset($SESSION->local_cbtujian_tolak);
            if (isloggedin() && !isguestuser() && !is_siteadmin()) {
                $pesan = $tolak === 'terkuncilogin' ? 'terkuncilogin' : 'masihlogin';
                // require_logout() menutup sesi (write_close), jadi pesan dibawa lewat URL dan dipasang di
                // permintaan halaman login berikutnya (lihat di bawah).
                require_logout();
                redirect(new \moodle_url(get_login_url(), ['cbtpesan' => $pesan]));
            }
        }
        if ($SCRIPT === '/login/index.php' && !isloggedin()) {
            $pesan = optional_param('cbtpesan', '', PARAM_ALPHA);
            if (in_array($pesan, ['terkuncilogin', 'masihlogin'], true)) {
                $SESSION->loginerrormsg = get_string($pesan, 'local_cbtujian');
            }
        }

        if (in_array($SCRIPT, ['/mod/quiz/attempt.php', '/mod/quiz/summary.php'], true) && isloggedin() && !isguestuser()) {
            self::cek_kunci_keluar();
        }
        if ($SCRIPT === '/mod/quiz/attempt.php' && isloggedin() && !isguestuser() && !is_siteadmin() && self::login_maks() > 0) {
            self::info_login_pertama();
        }
        if ($SCRIPT === '/mod/quiz/summary.php' && isloggedin()) {
            self::anti_submit();
        }
        if ($SCRIPT === '/admin/user.php' && isloggedin() && isset($_GET['cbtcari'])) {
            self::cari_pengguna();
        }
    }

    // ------------------------------------------------------------------ Cari pengguna (Browse list of users)

    /** Kolom yang bisa dicari dari kotak cari di admin/user.php. */
    private const KOLOM_CARI = ['fullname', 'username', 'email'];

    /** Id laporan sistem "Browse list of users" (filter disimpan per laporan per pengguna). */
    private static function id_laporan_pengguna(): int {
        $report = \core_reportbuilder\system_report_factory::create(
            \core_admin\reportbuilder\local\systemreports\users::class, \context_system::instance());

        return (int) $report->get_report_persistent()->get('id');
    }

    /** Kotak cari dikirim: isi filter "contains" laporan pengguna lalu kembali ke daftar. */
    private static function cari_pengguna(): void {
        $sys = \context_system::instance();
        if (!confirm_sesskey() || !has_any_capability(['moodle/user:update', 'moodle/user:delete'], $sys)) {
            return;
        }
        $q     = optional_param('cbtcari', '', PARAM_RAW_TRIMMED);
        $kolom = optional_param('cbtkolom', 'fullname', PARAM_ALPHA);
        $kolom = in_array($kolom, self::KOLOM_CARI, true) ? $kolom : 'fullname';

        $id     = self::id_laporan_pengguna();
        $values = \core_reportbuilder\local\helpers\user_filter_manager::get($id);
        foreach (self::KOLOM_CARI as $k) {
            unset($values["user:{$k}_operator"], $values["user:{$k}_value"]);
        }
        if ($q !== '') {
            $values["user:{$kolom}_operator"] = \core_reportbuilder\local\filters\text::CONTAINS;
            $values["user:{$kolom}_value"]    = $q;
        }
        \core_reportbuilder\local\helpers\user_filter_manager::set($id, $values);
        redirect(new \moodle_url('/admin/user.php'));
    }

    /** HTML kotak cari di atas daftar pengguna (dipindah ke atas tabel oleh skrip). */
    private static function kotak_cari_pengguna(): string {
        $q = '';
        $kolom = 'fullname';
        try {
            $values = \core_reportbuilder\local\helpers\user_filter_manager::get(self::id_laporan_pengguna());
            foreach (self::KOLOM_CARI as $k) {
                if ((int) ($values["user:{$k}_operator"] ?? 0) === \core_reportbuilder\local\filters\text::CONTAINS
                        && (string) ($values["user:{$k}_value"] ?? '') !== '') {
                    [$q, $kolom] = [(string) $values["user:{$k}_value"], $k];
                    break;
                }
            }
        } catch (\Throwable $e) {
            debugging('local_cbtujian cari pengguna: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $opsi = '';
        foreach (self::KOLOM_CARI as $k) {
            $opsi .= \html_writer::tag('option', get_string('cari_' . $k, 'local_cbtujian'),
                ['value' => $k] + ($k === $kolom ? ['selected' => 'selected'] : []));
        }
        $url  = new \moodle_url('/admin/user.php');
        $form = \html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false),
                'id' => 'cbt-cari-pengguna', 'class' => 'd-flex flex-wrap gap-2 align-items-center me-auto', 'hidden' => 'hidden'])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . \html_writer::empty_tag('input', ['type' => 'search', 'name' => 'cbtcari', 'value' => $q,
                'class' => 'form-control', 'style' => 'max-width:320px', 'autofocus' => 'autofocus',
                'placeholder' => get_string('cari_placeholder', 'local_cbtujian'),
                'aria-label' => get_string('cari_placeholder', 'local_cbtujian')])
            . \html_writer::tag('select', $opsi, ['name' => 'cbtkolom', 'class' => 'form-select w-auto',
                'aria-label' => get_string('cari_kolom', 'local_cbtujian')])
            . \html_writer::tag('button', get_string('cari', 'local_cbtujian'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        if ($q !== '') {
            $form .= \html_writer::link(new \moodle_url($url, ['cbtcari' => '', 'sesskey' => sesskey()]),
                get_string('cari_hapus', 'local_cbtujian'), ['class' => 'btn btn-secondary']);
        }
        $form .= \html_writer::end_tag('form');

        return $form . \html_writer::script(
            "document.addEventListener('DOMContentLoaded',function(){var f=document.getElementById('cbt-cari-pengguna'),"
            . "w=document.querySelector('[data-region=\"report-user-list-wrapper\"]');if(!f||!w)return;"
            // Sebaris dengan tombol "Add a new user" / "Filters"; bila baris itu tidak ada, di atas tabel.
            . "var b=w.querySelector('.dropdown.d-flex.justify-content-end');"
            . "if(b){b.insertBefore(f,b.firstChild);}else{f.classList.add('mb-3');w.parentNode.insertBefore(f,w);}"
            . "f.hidden=false;});"
        );
    }

    /**
     * Detik sampai siswa boleh menyelesaikan attempt (?attempt= di URL) menurut Anti Submit;
     * 0 bila sudah boleh, Anti Submit mati, quiz tanpa batas waktu, atau bukan attempt miliknya yang berjalan.
     */
    private static function detik_sebelum_boleh_submit(): int {
        global $CFG, $USER;

        $menit = (int) get_config('local_cbtujian', 'antisubmit');
        $id    = optional_param('attempt', 0, PARAM_INT);
        if ($menit <= 0 || !$id || !isloggedin()) {
            return 0;
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        try {
            $attempt = \mod_quiz\quiz_attempt::create($id);
        } catch (\Throwable $e) {
            return 0;
        }
        if ((int) $attempt->get_userid() !== (int) $USER->id || $attempt->get_state() !== \mod_quiz\quiz_attempt::IN_PROGRESS) {
            return 0;
        }
        $due = (int) $attempt->get_due_date();

        return $due ? max(0, $due - $menit * 60 - time()) : 0;
    }

    /** Anti Submit: halaman ringkasan (tombol "Submit all and finish") baru bisa dibuka menjelang waktu habis. */
    private static function anti_submit(): void {
        global $CFG, $USER;

        $menit = (int) get_config('local_cbtujian', 'antisubmit');
        $id    = optional_param('attempt', 0, PARAM_INT);
        if ($menit <= 0 || !$id) {
            return;
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $attempt = \mod_quiz\quiz_attempt::create($id);
        if ((int) $attempt->get_userid() !== (int) $USER->id || $attempt->get_state() !== \mod_quiz\quiz_attempt::IN_PROGRESS) {
            return;
        }
        $due = $attempt->get_due_date();
        if (!$due) {
            return;  // quiz tanpa batas waktu
        }
        $sisa = (int) $due - time();
        if ($sisa > $menit * 60) {
            redirect(
                $attempt->attempt_url(null, $attempt->get_currentpage()),
                get_string('belumbisasubmit', 'local_cbtujian', (object) ['menit' => $menit, 'lagi' => (int) ceil(($sisa - $menit * 60) / 60)]),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
    }

    // ------------------------------------------------------------------ Sebelum header

    /**
     * Siswa (tidak bisa mengubah course): tanpa menu course/quiz (Peserta, Nilai, Aktivitas, …) dan
     * body diberi kelas `cbt-siswa` (laci blok kanan disembunyikan oleh boost.scss).
     */
    public static function sebelum_header(\core\hook\output\before_http_headers $hook): void {
        global $PAGE;

        if (!isloggedin() || isguestuser()) {
            return;
        }
        $dicourse = !empty($PAGE->course->id) && (int) $PAGE->course->id !== SITEID;
        $konteks  = $dicourse ? \context_course::instance($PAGE->course->id) : \context_system::instance();
        if (has_capability('moodle/course:update', $konteks)) {
            return;
        }
        $PAGE->add_body_class('cbt-siswa');
        if ($dicourse) {
            $PAGE->set_secondary_navigation(false);
        }
    }

    // ------------------------------------------------------------------ Tombol Laporkan (Laporan Siswa panel)

    /**
     * Tombol "Laporkan" melayang di kiri bawah untuk siswa: ke form panel (laporurl) dengan tautan bertanda tangan
     * HMAC(username|waktu, laporkunci). Hanya bila diaktifkan panel (lapor = fitur aktif + ada sesi aktif), dan
     * DISEMBUNYIKAN selama siswa masih punya attempt yang berjalan (sebelum dan sesudah ujian saja).
     */
    private static function tombol_lapor(): string {
        global $DB, $PAGE, $USER;

        $url   = (string) get_config('local_cbtujian', 'laporurl');
        $kunci = (string) get_config('local_cbtujian', 'laporkunci');
        if (!get_config('local_cbtujian', 'lapor') || $url === '' || $kunci === '' || !isloggedin() || isguestuser()
                || is_siteadmin() || in_array($PAGE->pagetype, ['mod-quiz-attempt', 'mod-quiz-summary', 'login-index'], true)) {
            return '';
        }
        $dicourse = !empty($PAGE->course->id) && (int) $PAGE->course->id !== SITEID;
        if (has_capability('moodle/course:update', $dicourse ? \context_course::instance($PAGE->course->id) : \context_system::instance())) {
            return '';  // guru/manajer
        }
        if ($DB->record_exists('quiz_attempts', ['userid' => $USER->id, 'state' => 'inprogress', 'preview' => 0])) {
            return '';
        }
        $t    = time();
        $link = $url . '?' . http_build_query(['u' => $USER->username, 't' => $t,
            's' => hash_hmac('sha256', $USER->username . '|' . $t, $kunci)]);

        return \html_writer::tag('style', '.cbt-lapor{position:fixed;left:12px;bottom:12px;z-index:1030;border-radius:999px;'
                . 'box-shadow:0 2px 8px rgba(0,0,0,.25)}@media print{.cbt-lapor{display:none}}')
            . \html_writer::link($link, '&#9873; ' . get_string('laporkan', 'local_cbtujian'),
                ['class' => 'btn btn-danger btn-sm cbt-lapor', 'title' => get_string('laporkan_info', 'local_cbtujian')]);
    }

    // ------------------------------------------------------------------ Awal halaman

    public static function awal_halaman(before_standard_top_of_body_html_generation $hook): void {
        global $PAGE, $SCRIPT;

        // Kotak cari di Site administration → Users → Browse list of users.
        if ($SCRIPT === '/admin/user.php' && isloggedin()
                && has_any_capability(['moodle/user:update', 'moodle/user:delete'], \context_system::instance())) {
            $hook->add_html(self::kotak_cari_pengguna());

            return;
        }

        // Pesan setelah keluar otomatis oleh Anti Newtab.
        if ($PAGE->pagetype === 'login-index') {
            $pesan = json_encode(get_string('antinewtab', 'local_cbtujian'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $hook->add_html(\html_writer::script(
                "document.addEventListener('DOMContentLoaded',function(){try{if(!sessionStorage.getItem('cbtNewtab'))return;"
                . "sessionStorage.removeItem('cbtNewtab');}catch(e){return;}var f=document.querySelector('.login-form');if(!f)return;"
                . "var d=document.createElement('div');d.className='alert alert-danger';d.setAttribute('role','alert');d.textContent={$pesan};"
                . "f.parentNode.insertBefore(d,f);});"
            ));

            return;
        }

        // Laci blok kanan disembunyikan (boost.scss), jadi kotak nomor soal ("Daftar Soal") disalin ke bawah soal
        // (klik .qnbutton ditangani mod_quiz lewat delegasi di body); pewaktu "Sisa waktu" dipindah ke kotak itu
        // seperti LMS sekolah (tetap diperbarui mod_quiz lewat id-nya).
        // Halaman Selesaikan ujian (summary) juga mendapat kotak Daftar Soal di kanan (seperti LMS sekolah);
        // pewaktu di sana tetap di tengah halaman.
        if ($PAGE->pagetype === 'mod-quiz-attempt' || $PAGE->pagetype === 'mod-quiz-summary') {
            $judul = json_encode(get_string('quiznavigation', 'quiz'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $hook->add_html(\html_writer::script(
                "document.addEventListener('DOMContentLoaded',function(){var b=document.querySelector('#mod_quiz_navblock .qn_buttons'),"
                . "f=document.getElementById('responseform'),m=document.querySelector('[role=main]');if(!b||!(f||m))return;"
                . "var d=document.createElement('div');d.id='cbt-nav-soal';"
                . "var h=document.createElement('div');h.className='cbt-nav-judul';h.textContent={$judul};d.appendChild(h);"
                . "var c=b.cloneNode(true);c.querySelectorAll('[id]').forEach(function(e){e.removeAttribute('id');});d.appendChild(c);"
                . "if(!f){m.appendChild(d);return;}"
                . "var tm=document.getElementById('quiz-timer-wrapper'),inf=f.querySelector('.que .info');"
                . "if(tm){(inf||d).appendChild(tm);}"
                . "f.parentNode.insertBefore(d,f.nextSibling);});"
            ));
        }

        // Navigasi soal macet karena jaringan: setelah kiriman pertama, core_question/question_engine
        // (preventRepeatSubmission) menolak kiriman berikutnya + men-disable semua tombol submit, dan nav_to_page
        // mod_quiz mengosongkan name tombol "next". Bila permintaan macet/gagal, halaman tidak berpindah dan semua
        // nomor soal "tidak bisa diklik" sampai dimuat ulang. Di sini: keterangan "Menyimpan jawaban…" saat dikirim,
        // dan bila 10 detik belum berpindah, kunci itu dilepas supaya siswa bisa menekan lagi (27 Nov 2026).
        if ($PAGE->pagetype === 'mod-quiz-attempt') {
            $simpan = json_encode('Menyimpan jawaban…', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $lambat = json_encode('Koneksi lambat. Bila soal belum berpindah, tekan lagi nomor soal atau tombol soal berikutnya.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $hook->add_html(\html_writer::script(
                "document.addEventListener('DOMContentLoaded',function(){var f=document.getElementById('responseform');if(!f)return;"
                . "var nx=document.getElementById('mod_quiz-next-nav'),t=null,k=document.createElement('div');k.id='cbt-kirim';"
                . "k.setAttribute('role','status');k.style.cssText='position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:2000;"
                . "max-width:92vw;padding:8px 14px;border-radius:6px;background:#333;color:#fff;font-size:14px;box-shadow:0 2px 8px rgba(0,0,0,.3);display:none';"
                . "document.body.appendChild(k);var tampil=function(s,warna){k.textContent=s;k.style.background=warna||'#333';k.style.display='block';};"
                . "var jalan=false,lepas=function(){jalan=false;f.dataset.formSubmitted='0';f.querySelectorAll('input[type=submit]').forEach(function(i){i.removeAttribute('disabled');});"
                . "if(nx&&!nx.name)nx.name='next';};"
                . "f.addEventListener('submit',function(e){if(jalan||e.defaultPrevented)return;jalan=true;tampil({$simpan});clearTimeout(t);"
                . "t=setTimeout(function(){lepas();tampil({$lambat},'#b45309');},10000);});"
                // Tombol next yang namanya sudah dikosongkan nav_to_page membuat klik nomor berikutnya error: pulihkan
                // namanya sebelum handler mod_quiz (delegasi di body) berjalan, selama tidak ada kiriman yang berjalan.
                . "document.addEventListener('click',function(e){var q=e.target.closest&&e.target.closest('.qnbutton');"
                . "if(q&&nx&&!nx.name&&f.dataset.formSubmitted!=='1')nx.name='next';},true);"
                . "window.addEventListener('pageshow',function(e){if(e.persisted){clearTimeout(t);lepas();k.style.display='none';}});});"
            ));
        }

        // Anti Submit: tombol "Selesaikan ujian" (halaman soal terakhir) tidak bisa diklik sampai waktunya,
        // lalu aktif sendiri. Penjaga sebenarnya tetap anti_submit() di summary.php.
        if ($PAGE->pagetype === 'mod-quiz-attempt') {
            $sisa = self::detik_sebelum_boleh_submit();
            if ($sisa > 0) {
                $j     = fn(string $s) => json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $menit = (int) get_config('local_cbtujian', 'antisubmit');
                $label = $j(get_string('endtest', 'quiz'));
                $info  = $j(get_string('tombolselesaiaktif', 'local_cbtujian', $menit));
                $hook->add_html(\html_writer::script(
                    // Tombol TIDAK di-disabled: navigasi Daftar Soal (mod_quiz module.js nav_to_page) mengklik tombol
                    // "next" ini lewat kode; tombol disabled membuat nomor soal tidak bisa diklik di halaman terakhir
                    // (26 Nov 2026). Hanya klik siswa (isTrusted) yang ditahan; klik dari kode tetap jalan.
                    "document.addEventListener('DOMContentLoaded',function(){var b=document.getElementById('mod_quiz-next-nav');"
                    . "if(!b||b.value!=={$label})return;var kunci=true;b.title={$info};b.setAttribute('aria-disabled','true');"
                    . "b.style.opacity='.5';b.style.cursor='not-allowed';"
                    . "b.addEventListener('click',function(e){if(kunci&&e.isTrusted){e.preventDefault();e.stopImmediatePropagation();}},true);"
                    . "var p=document.createElement('div');p.className='cbt-antisubmit small text-muted mt-2';p.textContent={$info};"
                    . "b.parentNode.appendChild(p);"
                    . "setTimeout(function(){kunci=false;b.removeAttribute('aria-disabled');b.style.opacity='';b.style.cursor='';"
                    . "b.removeAttribute('title');p.remove();}," . ($sisa * 1000) . ");});"
                ));
            }
        }

        // Halaman awal ujian: bila kesempatan mengerjakan sudah habis (semua percobaan selesai), kotak tombol di bawah
        // tabel diganti keterangan + tombol Daftar Ujian (pesan bawaan "tidak ada lagi kesempatan" ikut diganti).
        // Hanya view.php: startattempt.php (halaman isian token, dipakai bila jendela token JS gagal, mis. Safe Exam
        // Browser iOS) juga ber-pagetype mod-quiz-view karena set_url(view_url) lebih dulu; CSS di bawah menyembunyikan
        // form token sehingga siswa hanya melihat tombol Kembali (26 Nov 2026).
        $halamanawal = $PAGE->pagetype === 'mod-quiz-view' && $SCRIPT === '/mod/quiz/view.php';
        if ($halamanawal && isloggedin() && ! isguestuser() && ! empty($PAGE->cm->instance)) {
            global $DB, $USER;
            $maks    = (int) $DB->get_field('quiz', 'attempts', ['id' => (int) $PAGE->cm->instance]);
            $selesai = $DB->count_records_select('quiz_attempts', "quiz = ? AND userid = ? AND state IN ('finished', 'abandoned')",
                [(int) $PAGE->cm->instance, (int) $USER->id]);
            if ($maks > 0 && $selesai >= $maks) {
                // CSS langsung di awal halaman: info token/waktu dan tombol bawaan tidak sempat tampil sebelum JS
                // (DOMContentLoaded) menggantinya; tanpa ini halaman "Masukkan token" terlihat sekejap.
                $hook->add_html(\html_writer::tag('style',
                    '#page-mod-quiz-view [role=main] > .quizinfo{display:none!important}'
                    . '#page-mod-quiz-view [role=main] > .quizattempt > :not(.cbt-selesai){display:none!important}'));
                $teks  = json_encode(get_string('sudahmengerjakan', 'local_cbtujian'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $label = json_encode(get_string('mycourses'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $url   = json_encode((new \moodle_url('/my/courses.php'))->out(false), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $hook->add_html(\html_writer::script(
                    "document.addEventListener('DOMContentLoaded',function(){var b=document.querySelector('[role=main] > .quizattempt');"
                    // Info kesempatan/token/waktu sudah tidak relevan setelah selesai.
                    . "var i=document.querySelector('[role=main] > .quizinfo');if(i){i.style.display='none';}"
                    . "if(!b)return;b.innerHTML='';var d=document.createElement('div');d.className='cbt-selesai';"
                    . "var p=document.createElement('p');p.textContent={$teks};d.appendChild(p);"
                    . "var a=document.createElement('a');a.className='btn btn-primary';a.href={$url};a.textContent={$label};d.appendChild(a);"
                    . "b.appendChild(d);});"
                ));
            }
        }

        // Halaman awal ujian seperti LMS sekolah: kartu "Percobaan 1" (Moodle 4.4+) diganti tabel "Status | Review"
        // (urutan dan gaya di boost.scss).
        if ($halamanawal) {
            // Kartu percobaan bawaan disembunyikan sejak awal (diganti tabel oleh JS di bawah), supaya tidak berkedip.
            // Form token (preflight) dicetak di halaman lalu dipindah JS Moodle ke jendela modal; selama masih di
            // [role=main] disembunyikan, supaya isian "Masukkan token" tidak terlihat sekejap saat halaman dibuka.
            $hook->add_html(\html_writer::tag('style', '#page-mod-quiz-view [role=main] > h3 + ul.list-unstyled{display:none}'
                . '#page-mod-quiz-view [role=main] #mod_quiz_preflight_form{display:none}'));
            // Token di halaman sendiri (startattempt.php), bukan jendela pop-up (26 Nov 2026), seperti LMS sekolah:
            // klik tombol mulai ditangkap lebih dulu (capture) sebelum JS mod_quiz/preflightcheck membuka modal,
            // lalu form tombol (POST cmid + sesskey) dikirim biasa → Moodle menampilkan halaman isian token.
            $hook->add_html(\html_writer::script(
                "document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.quizstartbuttondiv [type=submit]');"
                . "if(!b||!b.form||!document.getElementById('mod_quiz_preflight_form'))return;"
                . "e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();b.disabled=true;b.form.submit();},true);"
            ));
            $j = fn(string $s) => json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $status = $j(get_string('attemptstate', 'quiz'));
            $review = $j(get_string('review', 'quiz'));
            $hook->add_html(\html_writer::script(
                "document.addEventListener('DOMContentLoaded',function(){var ul=document.querySelector('[role=main] > h3 + ul.list-unstyled');"
                . "if(!ul)return;var S={$status},t=document.createElement('table');t.className='cbt-percobaan';"
                . "var hr=t.createTHead().insertRow();[S,{$review}].forEach(function(x,i){var th=document.createElement('th');"
                . "th.className='c'+i;th.textContent=x;hr.appendChild(th);});var tb=t.createTBody();"
                . "ul.querySelectorAll('.card').forEach(function(c){var r=tb.insertRow(),a=r.insertCell(),b=r.insertCell();b.className='c1';"
                . "c.querySelectorAll('.quizreviewsummary tr').forEach(function(tr){var h=tr.querySelector('th'),d=tr.querySelector('td');"
                . "if(h&&d&&h.textContent.trim()===S){a.innerHTML=d.innerHTML;}});var rv=c.querySelector('.card-body');if(rv){b.innerHTML=rv.innerHTML;}});"
                . "ul.parentNode.replaceChild(t,ul);});"
            ));
        }

        $hook->add_html(self::tombol_lapor());

        // Anti Newtab: hanya saat mengerjakan quiz.
        if (!get_config('local_cbtujian', 'antinewtab') || !in_array($PAGE->pagetype, ['mod-quiz-attempt', 'mod-quiz-summary'], true)
                || !isloggedin() || is_siteadmin()) {
            return;
        }
        $attempt = optional_param('attempt', 0, PARAM_INT);
        if (!$attempt) {
            return;
        }
        // Tiap kali siswa meninggalkan halaman (pindah aplikasi/tab) dicatat di server (sendBeacon, tetap terkirim
        // saat aplikasi masuk latar belakang). Saat kembali: peringatan "Keluar n dari maks"; lebih dari maks →
        // halaman dimuat ulang dan setelah_config mengunci ujian sampai pengawas membuka kuncinya.
        $url = json_encode((new \moodle_url('/local/cbtujian/keluar.php', ['sesskey' => sesskey(), 'attempt' => $attempt]))->out(false));
        $hook->add_html(\html_writer::script(
            // Pindah halaman soal (Next, nomor soal, Selesai) juga menyembunyikan halaman: ditandai `pindah` lewat
            // klik yang benar-benar berpindah halaman (tautan/submit/nomor soal; bukan tombol peringatan ini),
            // submit/beforeunload/pagehide supaya tidak terhitung keluar. Bila halaman ternyata tidak berpindah
            // (`tunda`) lalu terlihat lagi, keluarnya tetap dihitung saat kembali (27 Nov 2026: celah 4 detik).
            "(function(){var url={$url},pergi=false,pindah=false,tunda=false;"
            . "function tandai(){pindah=true;setTimeout(function(){pindah=false;},3000);}"
            . "document.addEventListener('click',function(e){if(e.target.closest('#cbtKeluar'))return;"
            . "var a=e.target.closest('a[href]');if(a){var h=a.getAttribute('href')||'';if(h.charAt(0)!=='#'&&h.indexOf('javascript:')!==0&&a.target!=='_blank')tandai();return;}"
            . "if(e.target.closest('.qnbutton,input[type=submit],button[type=submit],form button:not([type])'))tandai();},true);"
            . "document.addEventListener('submit',tandai,true);window.addEventListener('beforeunload',tandai);"
            . "window.addEventListener('pagehide',function(){pindah=true;tunda=false;});"
            . "function kirim(){try{if(navigator.sendBeacon&&navigator.sendBeacon(url+'&catat=1'))return;}catch(e){}"
            . "try{fetch(url+'&catat=1',{method:'POST',keepalive:true,credentials:'same-origin'});}catch(e){}}"
            . "function keluar(){if(pergi)return;if(pindah){tunda=true;return;}pergi=true;kirim();}"
            . "function kembali(){if(tunda){tunda=false;pindah=false;pergi=true;kirim();}if(!pergi)return;pergi=false;setTimeout(function(){fetch(url,{credentials:'same-origin'}).then(function(r){return r.json();})"
            . ".then(function(d){if(d.kunci){location.reload();return;}if(d.n>0)peringatan(d);}).catch(function(){});},600);}"
            . "function peringatan(d){var w=document.getElementById('cbtKeluar');if(!w){w=document.createElement('div');w.id='cbtKeluar';"
            . "w.setAttribute('role','alert');w.style.cssText='position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.55);padding:16px';"
            . "document.body.appendChild(w);}var sisa=d.maks-d.n;w.innerHTML='<div style=\"background:#fff;color:#212529;max-width:420px;border-radius:12px;padding:20px;text-align:center;font-size:1.05rem\">'"
            . "+'<div style=\"font-size:2rem\">⚠️</div><strong>Anda keluar dari aplikasi ujian '+d.n+' dari '+d.maks+' kali.</strong><p style=\"margin:10px 0 16px\">'"
            . "+(sisa>0?'Sisa '+sisa+' kali. Bila keluar lebih dari '+d.maks+' kali, ujian Anda dikunci dan harus dibuka pengawas.':'Ini kesempatan terakhir. Keluar sekali lagi, ujian Anda dikunci dan harus dibuka pengawas.')"
            . "+'</p><button type=\"button\" class=\"btn btn-primary\" id=\"cbtKeluarOk\">Saya mengerti</button></div>';"
            . "document.getElementById('cbtKeluarOk').onclick=function(){w.remove();};}"
            . "document.addEventListener('visibilitychange',function(){if(document.hidden)keluar();else kembali();});"
            . "window.addEventListener('blur',function(){setTimeout(function(){if(!document.hasFocus()&&!document.hidden)keluar();},1500);});"
            . "window.addEventListener('focus',kembali);})();"
        ));
    }

    // ------------------------------------------------------------------ Batas login per mapel

    /** Nama preferensi user penghitung login per attempt (tidak ada = 1, yaitu login saat memulai). */
    public const PREF_LOGIN = 'cbtujian_login_';

    /** Batas login per mapel (setelan loginmaks; 0 = mati). */
    public static function login_maks(): int {
        return max(0, (int) get_config('local_cbtujian', 'loginmaks'));
    }

    /**
     * Login baru: tambah penghitung setiap attempt user ini yang masih berjalan (waktunya belum habis).
     * Mengembalikan jumlah login terbesar (0 = tidak ada ujian berjalan); lebih dari batas = login ditolak.
     */
    private static function hitung_login(int $userid): int {
        global $DB;

        $terbesar = 0;
        $rows  = $DB->get_records_sql(
            "SELECT a.id, a.timestart, q.timelimit
               FROM {quiz_attempts} a
               JOIN {quiz} q ON q.id = a.quiz
              WHERE a.userid = :u AND a.state = 'inprogress' AND a.preview = 0", ['u' => $userid]);
        foreach ($rows as $a) {
            if ((int) $a->timelimit > 0 && (int) $a->timestart + (int) $a->timelimit + 600 < time()) {
                continue; // waktunya sudah lama habis; attempt ditutup cron
            }
            $nama = self::PREF_LOGIN . $a->id;
            $n    = (int) get_user_preferences($nama, 1, $userid) + 1;
            set_user_preference($nama, $n, $userid);
            set_user_preference($nama . '_waktu', time(), $userid);
            $terbesar = max($terbesar, $n);
        }

        return $terbesar;
    }

    /** Halaman soal pertama kali dibuka (belum ada login ulang): beri tahu "login 1 dari maks", sekali per attempt. */
    private static function info_login_pertama(): void {
        global $SESSION;

        $id = optional_param('attempt', 0, PARAM_INT);
        if (!$id || !empty($SESSION->local_cbtujian_infologin[$id]) || get_user_preferences(self::PREF_LOGIN . $id, null) !== null) {
            return;
        }
        $SESSION->local_cbtujian_infologin[$id] = 1;
        $a = (object) ['n' => 1, 'maks' => self::login_maks(), 'sisa' => self::login_maks() - 1];
        \core\notification::info(get_string($a->sisa > 0 ? 'peringatanlogin' : 'peringatanloginakhir', 'local_cbtujian', $a));
    }

    // ------------------------------------------------------------------ Anti Newtab: hitung keluar, kunci

    /** Nama preferensi user penghitung keluar per attempt. */
    public const PREF_KELUAR = 'cbtujian_keluar_';

    /** Batas keluar yang dibolehkan (setelan antinewtabmaks, bawaan 3; 0 = keluar sekali langsung terkunci). */
    public static function keluar_maks(): int {
        $maks = get_config('local_cbtujian', 'antinewtabmaks');

        return $maks === false || $maks === '' ? 3 : max(0, (int) $maks);
    }

    /**
     * Catat satu kali keluar (bila $catat) untuk attempt milik user ini yang masih berjalan, lalu kembalikan
     * jumlahnya. kunci = jumlah melebihi batas.
     */
    public static function keluar(int $attemptid, bool $catat): array {
        global $DB, $USER;

        $maks = self::keluar_maks();
        $a    = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, userid, state');
        if (!$a || (int) $a->userid !== (int) $USER->id || $a->state !== 'inprogress' || !get_config('local_cbtujian', 'antinewtab')) {
            return ['n' => 0, 'maks' => $maks, 'kunci' => false];
        }
        $nama = self::PREF_KELUAR . $attemptid;
        $n    = (int) get_user_preferences($nama, 0);
        if ($catat) {
            $n++;
            set_user_preference($nama, $n);
            set_user_preference($nama . '_waktu', time());
        }

        return ['n' => $n, 'maks' => $maks, 'kunci' => $n > $maks];
    }

    /** Ujian terkunci: buka attempt/summary dialihkan ke halaman awal quiz dengan pesan. */
    private static function cek_kunci_keluar(): void {
        global $CFG, $USER;

        $id = optional_param('attempt', 0, PARAM_INT);
        if (!$id || !get_config('local_cbtujian', 'antinewtab') || is_siteadmin()) {
            return;
        }
        $n = (int) get_user_preferences(self::PREF_KELUAR . $id, 0);
        if ($n <= self::keluar_maks()) {
            return;
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $attempt = \mod_quiz\quiz_attempt::create($id);
        if ((int) $attempt->get_userid() !== (int) $USER->id || $attempt->get_state() !== \mod_quiz\quiz_attempt::IN_PROGRESS) {
            return;
        }
        redirect($attempt->view_url(), get_string('terkuncikeluar', 'local_cbtujian', $n), null, \core\output\notification::NOTIFY_ERROR);
    }
}
