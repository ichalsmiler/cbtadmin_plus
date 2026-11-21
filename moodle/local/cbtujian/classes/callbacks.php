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
 *  - antinewtab  : siswa otomatis keluar bila membuka tab/aplikasi lain saat mengerjakan quiz
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
        if (get_config('local_cbtujian', 'locklogin') && self::sesi_lain($userid)) {
            // Sesi baru ditolak pada permintaan berikutnya (lihat sebelum_header), supaya alur login Moodle
            // selesai dulu dengan rapi. Sesi lama tetap berjalan.
            $SESSION->local_cbtujian_tolak = true;

            return;
        }
        if (get_config('local_cbtujian', 'resumetimer')) {
            try {
                self::lanjutkan_waktu($userid);
            } catch (\Throwable $e) {
                debugging('local_cbtujian resume timer: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /** Apakah user masih punya sesi aktif lain (selain sesi ini). */
    private static function sesi_lain(int $userid): bool {
        global $CFG;

        $batas = time() - (int) $CFG->sessiontimeout;
        foreach (\core\session\manager::get_sessions_by_userid($userid) as $s) {
            if ($s->sid !== session_id() && (int) $s->timemodified > $batas) {
                return true;
            }
        }

        return false;
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

        // Lock login: sesi baru ditolak, kembali ke halaman login dengan pesan.
        if (!empty($SESSION->local_cbtujian_tolak)) {
            unset($SESSION->local_cbtujian_tolak);
            if (isloggedin() && !isguestuser() && !is_siteadmin()) {
                require_logout();
                $SESSION->loginerrormsg = get_string('masihlogin', 'local_cbtujian');
                redirect(get_login_url());
            }
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
                    "document.addEventListener('DOMContentLoaded',function(){var b=document.getElementById('mod_quiz-next-nav');"
                    . "if(!b||b.value!=={$label})return;b.disabled=true;b.title={$info};"
                    . "var p=document.createElement('div');p.className='cbt-antisubmit small text-muted mt-2';p.textContent={$info};"
                    . "b.parentNode.appendChild(p);"
                    . "setTimeout(function(){b.disabled=false;b.removeAttribute('title');p.remove();}," . ($sisa * 1000) . ");});"
                ));
            }
        }

        // Halaman awal ujian: bila kesempatan mengerjakan sudah habis (semua percobaan selesai), kotak tombol di bawah
        // tabel diganti keterangan + tombol Daftar Ujian (pesan bawaan "tidak ada lagi kesempatan" ikut diganti).
        if ($PAGE->pagetype === 'mod-quiz-view' && isloggedin() && ! isguestuser() && ! empty($PAGE->cm->instance)) {
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
        if ($PAGE->pagetype === 'mod-quiz-view') {
            // Kartu percobaan bawaan disembunyikan sejak awal (diganti tabel oleh JS di bawah), supaya tidak berkedip.
            $hook->add_html(\html_writer::tag('style', '#page-mod-quiz-view [role=main] > h3 + ul.list-unstyled{display:none}'));
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

        // Anti Newtab: hanya saat mengerjakan quiz.
        if (!get_config('local_cbtujian', 'antinewtab') || !in_array($PAGE->pagetype, ['mod-quiz-attempt', 'mod-quiz-summary'], true)
                || !isloggedin() || is_siteadmin()) {
            return;
        }
        $logout = json_encode((new \moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false));
        $hook->add_html(\html_writer::script(
            "(function(){var keluar=false;function out(){if(keluar)return;keluar=true;try{sessionStorage.setItem('cbtNewtab','1');}catch(e){}"
            . "window.location.href={$logout};}"
            . "document.addEventListener('visibilitychange',function(){if(document.hidden)out();});"
            . "window.addEventListener('blur',function(){setTimeout(function(){if(!document.hasFocus())out();},1500);});})();"
        ));
    }
}
