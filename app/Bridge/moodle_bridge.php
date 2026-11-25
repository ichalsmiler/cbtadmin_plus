<?php
/**
 * Jembatan ke Moodle: dijalankan lewat CLI oleh panel (App\Libraries\MoodleBridge)
 * supaya perubahan data memakai fungsi Moodle sendiri, bukan SQL langsung.
 *
 * Masukan : JSON di STDIN  {"config": "/path/config.php", "action": "...", ...}
 * Keluaran: baris "@@CBTJSON@@" diikuti JSON hasil.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$in = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($in) || empty($in['action']) || empty($in['config']) || ! is_file($in['config'])) {
    echo "\n@@CBTJSON@@" . json_encode(['ok' => false, 'error' => 'Masukan jembatan tidak valid.']);
    exit(1);
}

define('CLI_SCRIPT', true);
require $in['config'];
require_once $CFG->dirroot . '/user/lib.php';
require_once $CFG->dirroot . '/cohort/lib.php';
require_once $CFG->dirroot . '/mod/quiz/lib.php';
require_once $CFG->dirroot . '/mod/quiz/locallib.php';

// Semua perubahan dicatat di log Moodle atas nama admin situs.
\core\session\manager::set_user(get_admin());

/** Cari atau buat cohort tingkat situs dengan nama tertentu, lalu masukkan user. */
function cbt_add_to_cohort(int $userid, string $name): void
{
    global $DB;
    $context = context_system::instance();
    $cohort  = $DB->get_record('cohort', ['name' => $name, 'contextid' => $context->id], '*', IGNORE_MULTIPLE);
    if ($cohort) {
        $cohortid = (int) $cohort->id;
    } else {
        $cohortid = cohort_add_cohort((object) [
            'contextid'         => $context->id,
            'name'              => $name,
            // ID number = nama (bila belum dipakai cohort lain) agar kolom cohort1 di upload CSV Moodle juga cocok.
            'idnumber'          => $DB->record_exists('cohort', ['idnumber' => $name]) ? '' : $name,
            'description'       => '',
            'descriptionformat' => FORMAT_HTML,
            'visible'           => 1,
            'component'         => '',
        ]);
    }
    if (! cohort_is_member($cohortid, $userid)) {
        cohort_add_member($cohortid, $userid);
    }
}

/** Tolak password yang melanggar kebijakan password situs Moodle. */
function cbt_check_password(string $password, ?stdClass $user = null): void
{
    $error = '';
    if (! check_password_policy($password, $error, $user)) {
        throw new RuntimeException($error);
    }
}

/** Pesan error Moodle berisi HTML; jadikan satu baris teks biasa. */
function cbt_clean_error(string $message): string
{
    $text = html_entity_decode(strip_tags(str_replace('</div>', ' ', $message)), ENT_QUOTES);

    return trim(preg_replace('/\s+/', ' ', preg_replace('#^error/#', '', trim($text))));
}

/**
 * Course mapel (format Single activity = Quiz) beserta quiz-nya: quiz dibuat bila belum ada, supaya mapel langsung
 * berbentuk quiz (tanpa guru membuka course dulu). Nama quiz = nama mapel, 1 percobaan, nilai maksimal 100,
 * durasi pengerjaan dari masukan `timelimit` (detik, pengaturan "Durasi pengerjaan" di Kategori Mapel; 0 = tanpa batas).
 * Mengembalikan [cm, dibuat].
 */
/**
 * Quiz mapel beserta konteks dan susunan soalnya (slot -> questionid versi terbaru). Dipakai Edit Soal.
 * Mengembalikan [quiz, cm, context, slots] atau [null, null, null, []] bila mapel belum punya quiz.
 */
function cbt_soal_quiz(int $courseid): array
{
    global $DB;
    global $CFG;
    require_once $CFG->dirroot . '/lib/questionlib.php';
    if ($courseid <= 1 || $courseid === (int) SITEID) {
        throw new RuntimeException('Mapel tidak valid.');
    }
    $quiz = $DB->get_record('quiz', ['course' => $courseid], '*', IGNORE_MULTIPLE);
    if (! $quiz) {
        return [null, null, null, []];
    }
    $cm      = get_coursemodule_from_instance('quiz', $quiz->id, $courseid, false, MUST_EXIST);
    $context = context_module::instance((int) $cm->id);

    return [$quiz, $cm, $context, \mod_quiz\question\bank\qbank_helper::get_question_structure((int) $quiz->id, $context)];
}

/** Gambar @@PLUGINFILE@@ di teks soal/opsi -> data URI (untuk editor panel); src asli disimpan di data-berkas. */
function cbt_soal_gambar_ke_data(string $html, int $contextid, string $filearea, int $itemid): string
{
    $fs    = get_file_storage();
    $files = [];
    foreach ($fs->get_area_files($contextid, 'question', $filearea, $itemid, 'filename', false) as $f) {
        $files[$f->get_filepath() . $f->get_filename()] = $f;
    }

    return (string) preg_replace_callback('/src="(@@PLUGINFILE@@(\/[^"]*))"/', static function ($m) use ($files) {
        $f = $files[rawurldecode(html_entity_decode($m[2]))] ?? null;
        if (! $f || $f->get_filesize() > 3 * 1024 * 1024) {
            return $m[0];
        }

        return 'src="data:' . $f->get_mimetype() . ';base64,' . base64_encode($f->get_content()) . '" data-berkas="' . $m[1] . '"';
    }, $html);
}

/** Nama kategori tetap "tanpa ujian": tes aktif saat semua sesi nonaktif, disisakan saat reset, tidak bisa dihapus/diganti nama. */
const CBT_KAT_NONAKTIF = 'SESI NONAKTIF';

function cbt_kategori_nonaktif_kah(string $name): bool
{
    return core_text::strtoupper(trim($name)) === CBT_KAT_NONAKTIF;
}

/** Id kategori SESI NONAKTIF (tingkat atas); dibuat bila belum ada. */
function cbt_kategori_nonaktif(): int
{
    global $DB;
    foreach ($DB->get_records('course_categories', ['parent' => 0], 'id', 'id, name') as $c) {
        if (cbt_kategori_nonaktif_kah((string) $c->name)) {
            return (int) $c->id;
        }
    }

    return (int) core_course_category::create(['name' => CBT_KAT_NONAKTIF, 'parent' => 0])->id;
}

function cbt_pastikan_quiz(stdClass $course): array
{
    global $CFG, $DB, $in;
    require_once $CFG->dirroot . '/course/lib.php';
    require_once $CFG->dirroot . '/course/modlib.php';
    // Format mapel: Single activity dengan jenis aktivitas Quiz (create_course tidak memakai bawaan situs,
    // jadi course baru/lama ber-format lain diubah di sini).
    $opsi = course_get_format($course)->get_format_options();
    if ($course->format !== 'singleactivity' || ($opsi['activitytype'] ?? '') !== 'quiz') {
        update_course((object) ['id' => (int) $course->id, 'format' => 'singleactivity', 'activitytype' => 'quiz']);
        $course = get_course((int) $course->id);
    }
    // Mapel hanya berisi quiz: forum Announcements (forum jenis news) dihapus selama belum ada postingan,
    // dan "newsitems" 0 supaya tidak dibuat lagi.
    if ((int) $course->newsitems !== 0) {
        $DB->set_field('course', 'newsitems', 0, ['id' => $course->id]);
    }
    foreach ($DB->get_records('forum', ['course' => (int) $course->id, 'type' => 'news'], '', 'id') as $forum) {
        if (! $DB->record_exists('forum_discussions', ['forum' => $forum->id])
            && ($cmforum = get_coursemodule_from_instance('forum', (int) $forum->id, (int) $course->id))) {
            course_delete_module((int) $cmforum->id);
        }
    }
    $cms = get_coursemodules_in_course('quiz', (int) $course->id);
    if ($cms) {
        return [reset($cms), false];
    }
    $info = (object) [
        'modulename' => 'quiz', 'module' => (int) $DB->get_field('modules', 'id', ['name' => 'quiz']),
        'course' => (int) $course->id, 'section' => 0, 'visible' => 1, 'visibleoncoursepage' => 1,
        'name' => $course->fullname, 'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
        'cmidnumber' => '', 'groupmode' => 0, 'groupingid' => 0,
        'quizpassword' => '', 'timeopen' => 0, 'timeclose' => 0, 'timelimit' => max(0, (int) ($in['timelimit'] ?? 0)), 'overduehandling' => 'autosubmit',
        'graceperiod' => 0, 'attempts' => 1, 'grademethod' => QUIZ_GRADEHIGHEST, 'grade' => 100, 'sumgrades' => 0,
        'questionsperpage' => 1, 'navmethod' => 'free', 'shuffleanswers' => 1, 'preferredbehaviour' => 'deferredfeedback',
        'canredoquestions' => 0, 'attemptonlast' => 0, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
        'showuserpicture' => 0, 'showblocks' => 0, 'browsersecurity' => '-', 'subnet' => '', 'delay1' => 0, 'delay2' => 0,
        'completionminattempts' => 0, 'allowofflineattempts' => 0,
    ];
    foreach (['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'] as $r) {
        foreach (['during', 'immediately', 'open', 'closed'] as $w) {
            $info->{$r . $w} = 0;
        }
    }
    // Tinjauan soal hanya saat mengerjakan: setelah submit siswa kembali ke halaman awal ujian, bukan pratinjau soal.
    $info->attemptduring = 1;
    $info->marksimmediately = $info->marksopen = $info->marksclosed = 1;
    $baru = create_module($info);

    return [get_coursemodule_from_id('quiz', (int) $baru->coursemodule, (int) $course->id, false, MUST_EXIST), true];
}

$result = ['ok' => true, 'items' => []];

try {
    switch ($in['action']) {
        case 'create_users':
            foreach ($in['users'] as $u) {
                $username = core_text::strtolower(trim((string) $u['username']));
                $item     = ['username' => $username];
                try {
                    $existing = $DB->get_record('user', [
                        'username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0,
                    ]);
                    if ($existing) {
                        // Sama seperti reset_passwords/kill_sessions/suspend_users: akun admin situs dan guest
                        // tidak boleh diubah dari panel (mis. baris impor ber-username "admin").
                        if (is_siteadmin($existing) || isguestuser($existing)) {
                            throw new RuntimeException('Akun admin tidak boleh diubah dari panel.');
                        }
                        if (! empty($u['password'])) {
                            cbt_check_password((string) $u['password'], $existing);
                            update_internal_user_password($existing, (string) $u['password']);
                        }
                        // Kiriman dari Konvert User membawa profil terbaru (nama, kelas, ruang): samakan.
                        $ubah = false;
                        foreach (['firstname', 'lastname', 'department', 'address'] as $kolom) {
                            if (isset($u[$kolom]) && (string) $u[$kolom] !== '' && (string) $existing->{$kolom} !== (string) $u[$kolom]) {
                                $existing->{$kolom} = (string) $u[$kolom];
                                $ubah = true;
                            }
                        }
                        if ($ubah) {
                            user_update_user($existing, false, false);
                        }
                        $item += ['id' => (int) $existing->id, 'status' => 'diperbarui'];
                    } else {
                        $user = (object) [
                            'auth'       => 'manual',
                            'confirmed'  => 1,
                            'mnethostid' => $CFG->mnet_localhost_id,
                            'username'   => $username,
                            'password'   => (string) $u['password'],
                            'firstname'  => (string) $u['firstname'],
                            'lastname'   => (string) $u['lastname'],
                            'email'      => (string) $u['email'],
                            'department' => (string) ($u['department'] ?? ''),
                            'address'    => (string) ($u['address'] ?? ''),
                            'lang'       => $CFG->lang,
                        ];
                        $item += ['id' => (int) user_create_user($user, true, false), 'status' => 'dibuat'];
                    }
                    // cohort: satu nama (Tambah user) atau daftar nama (Konvert: per kelas/mapel).
                    foreach ((array) ($u['cohort'] ?? []) as $namaCohort) {
                        if (trim((string) $namaCohort) !== '') {
                            cbt_add_to_cohort($item['id'], trim((string) $namaCohort));
                        }
                    }
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'reset_passwords':
            foreach ($in['users'] as $u) {
                $item = ['id' => (int) $u['id']];
                try {
                    $user = $DB->get_record('user', ['id' => (int) $u['id'], 'deleted' => 0], '*', MUST_EXIST);
                    if (is_siteadmin($user) || isguestuser($user)) {
                        throw new RuntimeException('Akun admin tidak boleh diubah dari panel.');
                    }
                    cbt_check_password((string) $u['password'], $user);
                    update_internal_user_password($user, (string) $u['password']);
                    $item['status'] = 'direset';
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'quiz_timelimit':
            // Durasi pengerjaan (detik, 0 = tanpa batas) untuk semua quiz mapel, atau hanya mapel di satu kategori ujian
            // (kategori = id kategori tingkat atas). Quiz yang sedang dikerjakan siswa dilewati supaya waktu siswa tidak
            // berubah di tengah ujian.
            $batas  = max(0, (int) $in['timelimit']);
            $kat    = (int) ($in['kategori'] ?? 0);
            $sql    = 'SELECT q.* FROM {quiz} q JOIN {course} c ON c.id = q.course JOIN {course_categories} cc ON cc.id = c.category WHERE c.id <> :site';
            $param  = ['site' => SITEID];
            if ($kat > 0) {
                $sql            .= ' AND (cc.id = :kat OR ' . $DB->sql_like('cc.path', ':path') . ')';
                $param['kat']    = $kat;
                $param['path']   = '%/' . $kat . '/%';
            }
            $diubah = $sama = $berjalan = 0;
            $kursus = [];
            foreach ($DB->get_records_sql($sql, $param) as $quiz) {
                if ((int) $quiz->timelimit === $batas) {
                    $sama++;

                    continue;
                }
                if ($DB->record_exists_select('quiz_attempts', "quiz = ? AND state IN ('inprogress', 'overdue') AND preview = 0", [$quiz->id])) {
                    $berjalan++;

                    continue;
                }
                $DB->set_field('quiz', 'timelimit', $batas, ['id' => $quiz->id]);
                $DB->set_field('quiz', 'timemodified', time(), ['id' => $quiz->id]);
                $kursus[(int) $quiz->course] = true;
                $diubah++;
            }
            foreach (array_keys($kursus) as $cid) {
                rebuild_course_cache($cid, true);
            }
            $result['items'][] = ['diubah' => $diubah, 'sama' => $sama, 'berjalan' => $berjalan];
            break;

        case 'finish_attempts':
            foreach ($in['attemptids'] as $attemptid) {
                $item = ['id' => (int) $attemptid];
                try {
                    $attemptobj = \mod_quiz\quiz_attempt::create((int) $attemptid);
                    $state      = $attemptobj->get_attempt()->state;
                    if (! in_array($state, [\mod_quiz\quiz_attempt::IN_PROGRESS, \mod_quiz\quiz_attempt::OVERDUE], true)) {
                        throw new RuntimeException('Attempt ini sudah tidak berjalan.');
                    }
                    $attemptobj->process_finish(time(), false);
                    $item['status'] = 'disubmit';
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'delete_attempts':
            foreach ($in['attemptids'] as $attemptid) {
                $item = ['id' => (int) $attemptid];
                try {
                    $attempt = $DB->get_record('quiz_attempts', ['id' => (int) $attemptid], '*', MUST_EXIST);
                    $quiz    = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
                    quiz_delete_attempt($attempt, $quiz);
                    $item['status'] = 'dihapus';
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'add_time':
            // Tambah waktu lewat override per user: batas waktu dan waktu tutup sama-sama diperpanjang.
            $quizobj = \mod_quiz\quiz_settings::create((int) $in['quizid']);
            $quiz    = $quizobj->get_quiz();
            $manager = $quizobj->get_override_manager();
            $extra   = max(1, (int) $in['minutes']) * 60;
            foreach ($in['userids'] as $userid) {
                $item = ['userid' => (int) $userid];
                try {
                    $existing = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => (int) $userid]);
                    $limit    = (int) ($existing->timelimit ?? 0) ?: (int) $quiz->timelimit;
                    $close    = (int) ($existing->timeclose ?? 0) ?: (int) $quiz->timeclose;
                    if ($limit === 0 && $close === 0) {
                        throw new RuntimeException('Quiz ini tidak punya batas waktu maupun waktu tutup.');
                    }
                    $data = ['userid' => (int) $userid];
                    if ($existing) {
                        $data['id'] = (int) $existing->id;
                        foreach (['timeopen', 'attempts', 'password'] as $keep) {
                            if ($existing->$keep !== null) {
                                $data[$keep] = $existing->$keep;
                            }
                        }
                    }
                    if ($limit > 0) {
                        $data['timelimit'] = $limit + $extra;
                    }
                    if ($close > 0) {
                        $data['timeclose'] = max($close, time()) + $extra;
                    }
                    $manager->save_override($data);
                    $item += ['status' => 'ditambah', 'timelimit' => $data['timelimit'] ?? 0, 'timeclose' => $data['timeclose'] ?? 0];
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'list_sessions':
            // Sesi login aktif per user (handler sesi apa pun, termasuk Redis).
            foreach ($in['userids'] as $userid) {
                $sessions = \core\session\manager::get_sessions_by_userid((int) $userid);
                if ($sessions === []) {
                    continue;
                }
                usort($sessions, static fn ($a, $b) => (int) $b->timemodified <=> (int) $a->timemodified);
                $result['items'][] = [
                    'userid'       => (int) $userid,
                    'count'        => count($sessions),
                    'lastip'       => (string) $sessions[0]->lastip,
                    'timecreated'  => (int) $sessions[0]->timecreated,
                    'timemodified' => (int) $sessions[0]->timemodified,
                ];
            }
            break;

        case 'kill_sessions':
            foreach ($in['userids'] as $userid) {
                $item = ['userid' => (int) $userid];
                try {
                    $user = $DB->get_record('user', ['id' => (int) $userid, 'deleted' => 0], '*', MUST_EXIST);
                    if (is_siteadmin($user)) {
                        throw new RuntimeException('Sesi admin situs tidak direset dari panel.');
                    }
                    $item['count'] = count(\core\session\manager::get_sessions_by_userid((int) $userid));
                    \core\session\manager::destroy_user_sessions((int) $userid);
                    $item['status'] = 'direset';
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'quiz_password':
            // Pasang password (token) yang sama di banyak quiz sekaligus. Password kosong = tanpa token.
            $password = (string) $in['password'];
            $courses  = [];
            foreach ($in['quizids'] as $quizid) {
                $item = ['id' => (int) $quizid];
                try {
                    $quiz = $DB->get_record('quiz', ['id' => (int) $quizid], 'id, course', MUST_EXIST);
                    $DB->update_record('quiz', (object) ['id' => $quiz->id, 'password' => $password, 'timemodified' => time()]);
                    $courses[(int) $quiz->course] = true;
                    $item['status'] = 'diubah';
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            foreach (array_keys($courses) as $courseid) {
                rebuild_course_cache($courseid, true);
            }
            break;

        case 'course_visibility':
            // Tampilkan atau sembunyikan kursus (mata pelajaran) lewat fungsi Moodle.
            require_once $CFG->dirroot . '/course/lib.php';
            foreach ($in['courses'] as $c) {
                $item = ['id' => (int) $c['id']];
                try {
                    if ((int) $c['id'] <= 1) {
                        throw new RuntimeException('Halaman depan situs tidak bisa diubah.');
                    }
                    course_change_visibility((int) $c['id'], ! empty($c['visible']));
                    $item['status'] = empty($c['visible']) ? 'disembunyikan' : 'ditampilkan';
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'login_logo':
            // Logo halaman login (Site administration → Appearance → Logos → Logo, core_admin/logo).
            // Masukan: path = berkas PNG/JPG yang bisa dibaca, atau hapus = true untuk mengosongkan.
            $sys = context_system::instance();
            $fs  = get_file_storage();
            $fs->delete_area_files($sys->id, 'core_admin', 'logo', 0);
            if (! empty($in['hapus'])) {
                set_config('logo', '', 'core_admin');
                theme_reset_all_caches();
                $result['items'][] = ['status' => 'dihapus'];
                break;
            }
            $path = (string) ($in['path'] ?? '');
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Berkas logo tidak ditemukan.');
            }
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
            $nama = 'logo-login.' . $ext;
            $fs->create_file_from_pathname([
                'contextid' => $sys->id, 'component' => 'core_admin', 'filearea' => 'logo',
                'itemid'    => 0, 'filepath' => '/', 'filename' => $nama,
            ], $path);
            set_config('logo', '/' . $nama, 'core_admin');
            theme_reset_all_caches();
            $result['items'][] = ['status' => 'dipasang', 'file' => $nama];
            break;

        case 'favicon':
            // Ikon tab browser Moodle (Site administration → Appearance → Logos → Favicon, core_admin/favicon).
            // Masukan: path = berkas PNG/ICO/JPG yang bisa dibaca, atau hapus = true untuk kembali ke ikon bawaan.
            $sys = context_system::instance();
            $fs  = get_file_storage();
            $fs->delete_area_files($sys->id, 'core_admin', 'favicon', 0);
            if (! empty($in['hapus'])) {
                set_config('favicon', '', 'core_admin');
                theme_reset_all_caches();
                $result['items'][] = ['status' => 'dihapus'];
                break;
            }
            $path = (string) ($in['path'] ?? '');
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Berkas favicon tidak ditemukan.');
            }
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $nama = 'favicon.' . (in_array($ext, ['png', 'ico', 'jpg'], true) ? $ext : 'png');
            $fs->create_file_from_pathname([
                'contextid' => $sys->id, 'component' => 'core_admin', 'filearea' => 'favicon',
                'itemid'    => 0, 'filepath' => '/', 'filename' => $nama,
            ], $path);
            set_config('favicon', '/' . $nama, 'core_admin');
            theme_reset_all_caches();
            $result['items'][] = ['status' => 'dipasang', 'file' => $nama];
            break;

        case 'kategori_nonaktif':
            // Pastikan kategori SESI NONAKTIF ada; kembalikan id-nya.
            $result['items'][] = ['id' => cbt_kategori_nonaktif()];
            break;

        case 'category_visibility':
            // Hanya kategori tes aktif (beserta induk dan subkategorinya) yang tampil; kategori lain disembunyikan.
            // Kategori ditampilkan dulu, baru disembunyikan, supaya induk tes aktif tidak ikut tertutup.
            $active = core_course_category::get((int) $in['active'], MUST_EXIST, true);
            $keep   = array_map('intval', array_filter(explode('/', (string) $active->path)));
            $all    = $DB->get_records('course_categories', null, 'depth ASC', 'id, path, visible');
            foreach ($all as $cat) {
                $inActive = in_array((int) $cat->id, $keep, true) || str_starts_with($cat->path . '/', $active->path . '/');
                if ($inActive && ! $cat->visible) {
                    core_course_category::get($cat->id, MUST_EXIST, true)->show();
                    $result['items'][] = ['id' => (int) $cat->id, 'status' => 'ditampilkan'];
                }
            }
            foreach (array_reverse($all, true) as $cat) {
                $inActive = in_array((int) $cat->id, $keep, true) || str_starts_with($cat->path . '/', $active->path . '/');
                if (! $inActive && $DB->get_field('course_categories', 'visible', ['id' => $cat->id])) {
                    core_course_category::get($cat->id, MUST_EXIST, true)->hide();
                    $result['items'][] = ['id' => (int) $cat->id, 'status' => 'disembunyikan'];
                }
            }
            break;

        case 'suspend_users':
            // Blokir (suspend) atau buka blokir akun siswa; yang diblokir langsung dikeluarkan.
            $suspend = ! empty($in['suspend']);
            foreach ($in['userids'] as $userid) {
                $item = ['userid' => (int) $userid];
                try {
                    $user = $DB->get_record('user', ['id' => (int) $userid, 'deleted' => 0], '*', MUST_EXIST);
                    if (is_siteadmin($user) || isguestuser($user)) {
                        throw new RuntimeException('Akun admin tidak diblokir dari panel.');
                    }
                    if ((int) $user->suspended !== (int) $suspend) {
                        $user->suspended = (int) $suspend;
                        user_update_user($user, false, true);
                    }
                    if ($suspend) {
                        \core\session\manager::destroy_user_sessions((int) $userid);
                    }
                    $item['status'] = $suspend ? 'diblokir' : 'dibuka';
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'cbtujian_config':
            // Aturan ujian di plugin local_cbtujian. Lock login menolak login baru, jadi batas
            // "satu akun satu perangkat" bawaan Moodle (yang menendang sesi lama) dimatikan.
            foreach (['antisubmit', 'locklogin', 'resumetimer', 'antinewtab'] as $key) {
                if (array_key_exists($key, $in)) {
                    set_config($key, max(0, (int) $in[$key]), 'local_cbtujian');
                }
            }
            if (array_key_exists('locklogin', $in)) {
                set_config('limitconcurrentlogins', empty($in['locklogin']) ? 1 : 0);
            }
            // Tombol Laporkan (Laporan Siswa panel): tampil/tidak, alamat form di panel, kunci HMAC tautan.
            if (array_key_exists('lapor', $in)) {
                set_config('lapor', empty($in['lapor']) ? 0 : 1, 'local_cbtujian');
                set_config('laporurl', clean_param((string) ($in['laporurl'] ?? ''), PARAM_LOCALURL), 'local_cbtujian');
                set_config('laporkunci', clean_param((string) ($in['laporkunci'] ?? ''), PARAM_ALPHANUM), 'local_cbtujian');
            }
            $result['items'][] = ['status' => 'disimpan', 'plugin' => (bool) core_plugin_manager::instance()->get_plugin_info('local_cbtujian')];
            break;

        case 'purge_caches':
            // Purge = cara resmi Moodle; Delete = hapus juga isi folder cache di moodledata lalu purge.
            if (! empty($in['hapus'])) {
                foreach ([$CFG->cachedir, $CFG->localcachedir] as $dir) {
                    if ($dir && is_dir($dir)) {
                        remove_dir($dir, true);
                    }
                }
            }
            purge_all_caches();
            $result['items'][] = ['status' => empty($in['hapus']) ? 'purge' : 'delete'];
            break;

        case 'create_category':
            $name = trim((string) $in['name']);
            if ($name === '') {
                throw new RuntimeException('Nama ujian wajib diisi.');
            }
            if ($DB->record_exists('course_categories', ['name' => $name, 'parent' => 0])) {
                throw new RuntimeException('Ujian "' . $name . '" sudah ada.');
            }
            $cat = core_course_category::create(['name' => $name, 'parent' => 0]);
            $result['items'][] = ['id' => (int) $cat->id, 'status' => 'dibuat'];
            break;

        case 'create_categories':
            // Banyak kategori ujian sekaligus (mis. H1..H8); nama yang sudah ada dilewati.
            foreach ((array) $in['names'] as $name) {
                $name = trim((string) $name);
                $item = ['name' => $name];
                if ($name === '') {
                    continue;
                }
                $ada = $DB->get_record('course_categories', ['name' => $name, 'parent' => 0], 'id', IGNORE_MULTIPLE);
                if ($ada) {
                    $item += ['id' => (int) $ada->id, 'status' => 'sudah ada'];
                } else {
                    $item += ['id' => (int) core_course_category::create(['name' => $name, 'parent' => 0])->id, 'status' => 'dibuat'];
                }
                $result['items'][] = $item;
            }
            break;

        case 'delete_category':
            // Hapus kategori ujian tingkat atas beserta isinya (course, quiz, attempt, nilai) lewat API Moodle.
            $cat = core_course_category::get((int) $in['id'], MUST_EXIST, true);
            if ((int) $cat->parent !== 0) {
                throw new RuntimeException('Hanya kategori ujian tingkat atas yang bisa dihapus dari panel.');
            }
            if (cbt_kategori_nonaktif_kah((string) $cat->name)) {
                throw new RuntimeException('Kategori ' . CBT_KAT_NONAKTIF . ' wajib ada (dipakai saat semua sesi nonaktif) dan tidak bisa dihapus.');
            }
            if ((int) $DB->count_records('course_categories', ['parent' => 0]) <= 1) {
                throw new RuntimeException('Moodle wajib punya minimal satu kategori; buat kategori lain dulu.');
            }
            $jumlahCourse = (int) $DB->count_records('course', ['category' => (int) $cat->id]);
            $nama         = $cat->name;
            $cat->delete_full(false);
            $result['items'][] = ['id' => (int) $in['id'], 'name' => $nama, 'course' => $jumlahCourse, 'status' => 'dihapus'];
            break;

        case 'delete_course':
            // Hapus satu mapel (course) beserta quiz, soal di bank course, attempt, dan nilainya.
            require_once $CFG->dirroot . '/course/lib.php';
            $course = $DB->get_record('course', ['id' => (int) $in['id']], '*', MUST_EXIST);
            if ((int) $course->id === (int) SITEID) {
                throw new RuntimeException('Halaman depan situs tidak bisa dihapus.');
            }
            if (! delete_course($course, false)) {
                throw new RuntimeException('Moodle gagal menghapus mapel.');
            }
            fix_course_sortorder();
            $result['items'][] = ['id' => (int) $course->id, 'shortname' => $course->shortname, 'status' => 'dihapus'];
            break;

        case 'move_courses':
            // Pindahkan mapel (course) ke kategori ujian tingkat atas lain: {courses: [id], category: id}.
            // Sesudah dipindah mapel tetap tersembunyi; dibuka lagi lewat Setting Ujian / status di Kategori Mapel.
            require_once $CFG->dirroot . '/course/lib.php';
            $cat = core_course_category::get((int) $in['category'], MUST_EXIST, true);
            if ((int) $cat->parent !== 0 || cbt_kategori_nonaktif_kah((string) $cat->name)) {
                throw new RuntimeException('Kategori tujuan harus kategori ujian tingkat atas (bukan ' . CBT_KAT_NONAKTIF . ').');
            }
            $ids = array_values(array_filter(array_map('intval', (array) $in['courses']), static fn ($id) => $id > 1));
            if ($ids === []) {
                throw new RuntimeException('Tidak ada mapel yang dipindah.');
            }
            if (! move_courses($ids, (int) $cat->id)) {
                throw new RuntimeException('Moodle gagal memindahkan mapel.');
            }
            foreach ($ids as $id) {
                if ($DB->get_field('course', 'visible', ['id' => $id])) {
                    course_change_visibility($id, false);
                }
                $result['items'][] = ['id' => $id, 'category' => (int) $cat->id, 'status' => 'dipindah'];
            }
            break;

        case 'rename_categories':
            // Ganti nama beberapa kategori tingkat atas sekaligus: [{id, name}]. Dua tahap (nama sementara dulu)
            // supaya pertukaran nama, mis. h1 -> H2 sementara H2 -> H3, tidak bentrok di tengah jalan.
            $ubah = [];
            foreach ((array) $in['renames'] as $r) {
                $cat = core_course_category::get((int) $r['id'], MUST_EXIST, true);
                if ((int) $cat->parent !== 0 || trim((string) $r['name']) === '' || cbt_kategori_nonaktif_kah((string) $cat->name)
                        || cbt_kategori_nonaktif_kah((string) $r['name'])) {
                    throw new RuntimeException('Kategori ' . (int) $r['id'] . ' tidak bisa diganti namanya.');
                }
                $ubah[] = [$cat, trim((string) $r['name'])];
            }
            foreach ($ubah as [$cat]) {
                $cat->update(['name' => '__cbt_sementara_' . $cat->id]);
            }
            foreach ($ubah as [$cat, $name]) {
                core_course_category::get((int) $cat->id, MUST_EXIST, true)->update(['name' => $name]);
                $result['items'][] = ['id' => (int) $cat->id, 'name' => $name, 'status' => 'diubah'];
            }
            break;

        case 'update_category':
            // Ganti nama kategori (ujian) tingkat atas.
            $name = trim((string) $in['name']);
            if ($name === '') {
                throw new RuntimeException('Nama ujian wajib diisi.');
            }
            $cat = core_course_category::get((int) $in['id'], MUST_EXIST, true);
            if ((int) $cat->parent !== 0) {
                throw new RuntimeException('Hanya kategori ujian tingkat atas yang bisa diubah dari panel.');
            }
            if (cbt_kategori_nonaktif_kah((string) $cat->name) || cbt_kategori_nonaktif_kah($name)) {
                throw new RuntimeException('Nama ' . CBT_KAT_NONAKTIF . ' khusus untuk kategori tanpa ujian dan tidak bisa diubah/dipakai.');
            }
            if ($DB->record_exists_select('course_categories', 'name = ? AND parent = 0 AND id <> ?', [$name, (int) $cat->id])) {
                throw new RuntimeException('Ujian "' . $name . '" sudah ada.');
            }
            $cat->update(['name' => $name]);
            $result['items'][] = ['id' => (int) $cat->id, 'status' => 'diubah'];
            break;

        case 'create_course':
            // Mapel baru memakai bawaan course (Single activity + Quiz) dan tersembunyi sampai
            // diaktifkan di Setting Ujian.
            require_once $CFG->dirroot . '/course/lib.php';
            $fullname  = trim((string) $in['fullname']);
            $shortname = trim((string) $in['shortname']);
            if ($fullname === '' || $shortname === '') {
                throw new RuntimeException('Nama pelajaran dan shortname wajib diisi.');
            }
            if ($DB->record_exists('course', ['shortname' => $shortname])) {
                throw new RuntimeException('Shortname "' . $shortname . '" sudah dipakai pelajaran lain.');
            }
            core_course_category::get((int) $in['category'], MUST_EXIST, true);
            $course = create_course((object) [
                'fullname' => $fullname, 'shortname' => $shortname, 'category' => (int) $in['category'], 'visible' => 0,
                'format' => 'singleactivity', 'activitytype' => 'quiz',
            ]);
            cbt_pastikan_quiz($course);
            $result['items'][] = ['id' => (int) $course->id, 'status' => 'dibuat'];
            break;

        case 'create_courses':
            // Banyak mapel sekaligus: [{category, fullname, shortname}]. Shortname yang sudah ada dilewati
            // (dilaporkan beserta kategorinya). Tersembunyi sampai diaktifkan di Setting Ujian.
            require_once $CFG->dirroot . '/course/lib.php';
            foreach ((array) $in['courses'] as $c) {
                $shortname = trim((string) $c['shortname']);
                $item      = ['shortname' => $shortname];
                try {
                    if ($shortname === '') {
                        throw new RuntimeException('Shortname kosong.');
                    }
                    $ada = $DB->get_record('course', ['shortname' => $shortname]);
                    if ($ada) {
                        $item += ['id' => (int) $ada->id, 'category' => (int) $ada->category, 'status' => 'sudah ada'];
                        $course = $ada;
                    } else {
                        core_course_category::get((int) $c['category'], MUST_EXIST, true);
                        $course = create_course((object) [
                            'fullname' => trim((string) $c['fullname']) ?: $shortname, 'shortname' => $shortname,
                            'category' => (int) $c['category'], 'visible' => 0,
                            'format' => 'singleactivity', 'activitytype' => 'quiz',
                        ]);
                        $item += ['id' => (int) $course->id, 'category' => (int) $c['category'], 'status' => 'dibuat'];
                    }
                    // Mapel langsung berbentuk quiz; mapel lama yang belum punya quiz ikut dilengkapi.
                    $item['quiz_baru'] = cbt_pastikan_quiz($course)[1];
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'sort_courses':
            // Susun ulang urutan mapel di kategori: urutan = {kategori: [courseid, ...]}; course yang disebut di
            // depan sesuai urutan kiriman (mis. per sesi XII → X → XI), sisanya di belakang dengan urutan lama.
            require_once $CFG->dirroot . '/course/lib.php';
            foreach ((array) $in['urutan'] as $katId => $ids) {
                $ada = $DB->get_records('course', ['category' => (int) $katId], 'sortorder, id', 'id, sortorder');
                if ($ada === []) {
                    continue;
                }
                $depan = array_values(array_filter(array_map('intval', (array) $ids), static fn ($id) => isset($ada[$id])));
                $urut  = array_values(array_unique(array_merge($depan, array_map('intval', array_keys($ada)))));
                $dasar = min(array_map(static fn ($c) => (int) $c->sortorder, $ada));
                foreach ($urut as $i => $id) {
                    $DB->set_field('course', 'sortorder', $dasar + $i, ['id' => $id]);
                }
                $result['items'][] = ['category' => (int) $katId, 'jumlah' => count($urut)];
            }
            fix_course_sortorder();
            cache_helper::purge_by_event('changesincourse');
            cache_helper::purge_by_event('changesincoursecat');
            break;

        case 'reset_moodle':
            // Reset Moodle bertahap (dipanggil berulang sampai sisa = 0, tiap panggilan dibatasi `batas` detik):
            //  - siswa : hapus semua user kecuali admin situs, guest, dan (simpan_guru) pemegang peran guru/manager
            //  - ujian : hapus semua mapel (course) lalu semua kategori tingkat atas kecuali satu (Moodle wajib punya 1)
            //  - log   : kosongkan log Moodle (logstore_standard_log)
            require_once $CFG->dirroot . '/course/lib.php';
            $mulai = microtime(true);
            $batas = max(5, min(60, (int) ($in['batas'] ?? 20)));
            $habis = static fn (): bool => microtime(true) - $mulai > $batas;
            $hapus = 0;
            $sisa  = 0;
            switch ((string) $in['tahap']) {
                case 'siswa':
                    $simpan = array_map('intval', explode(',', (string) $CFG->siteadmins));
                    $simpan[] = (int) $CFG->siteguest;
                    if (! empty($in['simpan_guru'])) {
                        $guru = $DB->get_fieldset_sql(
                            "SELECT DISTINCT ra.userid FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                              WHERE r.shortname IN ('editingteacher', 'teacher', 'manager', 'coursecreator')"
                        );
                        $simpan = array_merge($simpan, array_map('intval', $guru));
                    }
                    [$tidak, $param] = $DB->get_in_or_equal(array_unique($simpan), SQL_PARAMS_NAMED, 'u', false);
                    $ids = $DB->get_fieldset_select('user', 'id', "deleted = 0 AND id $tidak", $param);
                    foreach ($ids as $i => $id) {
                        if ($habis()) {
                            $sisa = count($ids) - $i;
                            break;
                        }
                        $user = $DB->get_record('user', ['id' => (int) $id, 'deleted' => 0]);
                        if ($user && ! is_siteadmin($user) && ! isguestuser($user)) {
                            delete_user($user);
                            $hapus++;
                        }
                    }
                    break;

                case 'ujian':
                    $ids = $DB->get_fieldset_select('course', 'id', 'id <> ?', [SITEID]);
                    foreach ($ids as $i => $id) {
                        if ($habis()) {
                            $sisa = count($ids) - $i;
                            break;
                        }
                        $course = $DB->get_record('course', ['id' => (int) $id]);
                        if ($course) {
                            delete_course($course, false);
                            $hapus++;
                        }
                    }
                    if ($sisa === 0) {
                        // Mapel sudah habis: hapus kategori (kosong). Kategori SESI NONAKTIF disisakan (dibuat bila belum ada).
                        $sisakan = cbt_kategori_nonaktif();
                        $atas    = array_values(array_diff(
                            array_map('intval', $DB->get_fieldset_select('course_categories', 'id', 'parent = 0', null, 'id')),
                            [$sisakan]
                        ));
                        foreach ($DB->get_fieldset_select('course_categories', 'id', 'parent = ?', [$sisakan]) as $sub) {
                            core_course_category::get((int) $sub, MUST_EXIST, true)->delete_full(false);
                        }
                        foreach ($atas as $kat) {
                            core_course_category::get((int) $kat, MUST_EXIST, true)->delete_full(false);
                        }
                        fix_course_sortorder();
                    }
                    break;

                case 'log':
                    $hapus = (int) $DB->count_records('logstore_standard_log');
                    $DB->delete_records('logstore_standard_log');
                    break;

                default:
                    throw new RuntimeException('Tahap reset tidak dikenal.');
            }
            $result['items'][] = ['tahap' => (string) $in['tahap'], 'hapus' => $hapus, 'sisa' => $sisa];
            break;

        case 'enrol_users':
            // Daftarkan user ke course (shortname) sebagai siswa lewat metode enrol manual.
            // Masukan: users = [{userid, courses: [shortname, ...]}]; yang sudah terdaftar dilewati.
            require_once $CFG->dirroot . '/lib/enrollib.php';
            $manual  = enrol_get_plugin('manual');
            $role    = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
            $courses = $instances = [];
            foreach ($DB->get_records('course', null, '', 'id, shortname') as $c) {
                $courses[core_text::strtolower($c->shortname)] = $c;
            }
            foreach ($in['users'] as $u) {
                $item = ['userid' => (int) $u['userid'], 'baru' => 0, 'sudah' => 0, 'tidak_ada' => []];
                try {
                    $user = $DB->get_record('user', ['id' => $item['userid'], 'deleted' => 0], '*', MUST_EXIST);
                    if (is_siteadmin($user) || isguestuser($user)) {
                        throw new RuntimeException('Akun admin tidak didaftarkan dari panel.');
                    }
                    foreach ((array) $u['courses'] as $sn) {
                        $course = $courses[core_text::strtolower(trim((string) $sn))] ?? null;
                        if ($course === null || (int) $course->id === (int) SITEID) {
                            $item['tidak_ada'][] = (string) $sn;

                            continue;
                        }
                        if (! isset($instances[$course->id])) {
                            $inst = null;
                            foreach (enrol_get_instances((int) $course->id, false) as $e) {
                                if ($e->enrol === 'manual') {
                                    $inst = $e;
                                    break;
                                }
                            }
                            if ($inst === null) {
                                $id   = $manual->add_default_instance(get_course((int) $course->id));
                                $inst = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
                            }
                            $instances[$course->id] = $inst;
                        }
                        $inst = $instances[$course->id];
                        if ($DB->record_exists('user_enrolments', ['enrolid' => $inst->id, 'userid' => $item['userid']])) {
                            $item['sudah']++;

                            continue;
                        }
                        $manual->enrol_user($inst, $item['userid'], (int) $role->id);
                        $item['baru']++;
                    }
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'unenrol_users':
            // Keluarkan user dari course (shortname) lewat semua metode enrol tempat ia terdaftar.
            // Masukan: users = [{userid, courses: [shortname, ...]}]. Course yang sudah ada attempt user ini dilewati
            // (ada_attempt) supaya jawaban dan nilainya tidak hilang dari laporan.
            require_once $CFG->dirroot . '/lib/enrollib.php';
            foreach ($in['users'] as $u) {
                $item = ['userid' => (int) $u['userid'], 'keluar' => 0, 'ada_attempt' => [], 'tidak_ada' => []];
                try {
                    $user = $DB->get_record('user', ['id' => $item['userid'], 'deleted' => 0], '*', MUST_EXIST);
                    if (is_siteadmin($user) || isguestuser($user)) {
                        throw new RuntimeException('Akun admin tidak diubah dari panel.');
                    }
                    foreach ((array) $u['courses'] as $sn) {
                        $course = $DB->get_record('course', ['shortname' => trim((string) $sn)], 'id, shortname');
                        if (! $course || (int) $course->id === (int) SITEID) {
                            $item['tidak_ada'][] = (string) $sn;

                            continue;
                        }
                        $attempt = $DB->record_exists_sql(
                            'SELECT 1 FROM {quiz_attempts} a JOIN {quiz} q ON q.id = a.quiz WHERE q.course = ? AND a.userid = ? AND a.preview = 0',
                            [$course->id, $item['userid']]
                        );
                        if ($attempt) {
                            $item['ada_attempt'][] = $course->shortname;

                            continue;
                        }
                        foreach (enrol_get_instances((int) $course->id, false) as $inst) {
                            if ($DB->record_exists('user_enrolments', ['enrolid' => $inst->id, 'userid' => $item['userid']])) {
                                enrol_get_plugin($inst->enrol)->unenrol_user($inst, $item['userid']);
                                $item['keluar']++;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $item['error'] = cbt_clean_error($e->getMessage());
                }
                $result['items'][] = $item;
            }
            break;

        case 'import_soal':
            // Masukkan soal (Moodle XML) ke quiz sebuah mapel: quiz dibuat bila belum ada (course Single activity
            // baru belum punya), soal diimpor ke bank soal quiz itu, lalu ditambahkan ke quiz.
            // ganti = true: soal lama di quiz dilepas dulu (ditolak bila sudah ada attempt siswa).
            require_once $CFG->dirroot . '/course/lib.php';
            require_once $CFG->dirroot . '/course/modlib.php';
            require_once $CFG->dirroot . '/lib/questionlib.php';
            require_once $CFG->dirroot . '/question/format.php';
            require_once $CFG->dirroot . '/question/format/xml/format.php';
            $course = $DB->get_record('course', ['id' => (int) $in['courseid']], '*', MUST_EXIST);
            if ((int) $course->id === (int) SITEID) {
                throw new RuntimeException('Halaman depan situs bukan mapel.');
            }
            [$cm, $quizbaru] = cbt_pastikan_quiz($course);
            $quizobj = \mod_quiz\quiz_settings::create((int) $cm->instance);
            $quiz    = $quizobj->get_quiz();
            if (! empty($in['ganti'])) {
                if ($DB->record_exists('quiz_attempts', ['quiz' => $quiz->id, 'preview' => 0])) {
                    throw new RuntimeException('Quiz ini sudah punya jawaban siswa; soal lama tidak bisa diganti. Pilih "Tambahkan" atau hapus attempt dulu.');
                }
                $structure = $quizobj->get_structure();
                foreach (array_reverse($structure->get_slots()) as $slot) {
                    $structure->remove_slot((int) $slot->slot);
                }
            }

            $context  = context_module::instance((int) $cm->id);
            $category = question_get_default_category($context->id, true);
            $berkas   = make_request_directory() . '/soal.xml';
            file_put_contents($berkas, (string) $in['xml']);
            $format = new qformat_xml();
            $format->setCategory($category);
            $format->setContexts([$context]);
            $format->setCourse($course);
            $format->setFilename($berkas);
            $format->setRealfilename('soal.xml');
            $format->setMatchgrades('error');
            $format->setCatfromfile(false);
            $format->setContextfromfile(false);
            $format->setStoponerror(true);
            ob_start();
            $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
            $keluaran = trim(strip_tags((string) ob_get_clean()));
            if (! $ok) {
                throw new RuntimeException('Impor soal gagal: ' . mb_substr($keluaran, 0, 300));
            }
            $quizobj = \mod_quiz\quiz_settings::create((int) $quiz->id);
            $quiz    = $quizobj->get_quiz();
            foreach ($format->questionids as $qid) {
                quiz_add_quiz_question((int) $qid, $quiz, 0);
            }
            quiz_repaginate_questions((int) $quiz->id, max(1, (int) $quiz->questionsperpage));
            \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
            $result['items'][] = [
                'quiz' => $quiz->name, 'cmid' => (int) $cm->id, 'quiz_baru' => $quizbaru,
                'ditambah' => count($format->questionids),
                'total' => (int) $DB->count_records('quiz_slots', ['quizid' => $quiz->id]),
            ];
            break;

        case 'soal_daftar':
            // Edit Soal: daftar soal di quiz mapel (urut slot) + jumlah attempt sedang/selesai.
            [$quiz, , , $slots] = cbt_soal_quiz((int) $in['courseid']);
            if (! $quiz) {
                $result['items'][] = ['quiz' => null, 'soal' => []];
                break;
            }
            $soal = [];
            foreach ($slots as $sl) {
                $qid   = (int) $sl->questionid;
                $kunci = null;
                $opsi  = 0;
                if ($sl->qtype === 'multichoice' || $sl->qtype === 'truefalse') {
                    $ans  = array_values($DB->get_records('question_answers', ['question' => $qid], 'id', 'id, fraction'));
                    $opsi = count($ans);
                    foreach ($ans as $i => $a) {
                        if ((float) $a->fraction > 0.999) {
                            $kunci = ($kunci ?? '') . chr(65 + $i);
                        }
                    }
                }
                $teks   = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $sl->questiontext), ENT_QUOTES)));
                $soal[] = [
                    'slot' => (int) $sl->slot, 'id' => $qid, 'qtype' => (string) $sl->qtype,
                    'teks' => mb_substr($teks, 0, 220), 'gambar' => substr_count((string) $sl->questiontext, '<img'),
                    'kunci' => $kunci, 'opsi' => $opsi,
                ];
            }
            $result['items'][] = [
                'quiz'    => ['id' => (int) $quiz->id, 'nama' => $quiz->name],
                'sedang'  => $DB->count_records_select('quiz_attempts', "quiz = ? AND preview = 0 AND state IN ('inprogress', 'overdue')", [$quiz->id]),
                'selesai' => $DB->count_records('quiz_attempts', ['quiz' => $quiz->id, 'preview' => 0, 'state' => 'finished']),
                'soal'    => $soal,
            ];
            break;

        case 'soal_semua':
            // Review Soal: semua soal quiz mapel (urut slot) lengkap dengan opsi, kunci, dan gambar (data URI).
            // Teks dibersihkan clean_text() dulu (tanpa skrip), baru gambar @@PLUGINFILE@@ diganti data URI.
            [$quiz, , , $slots] = cbt_soal_quiz((int) $in['courseid']);
            if (! $quiz) {
                $result['items'][] = ['quiz' => null, 'soal' => []];
                break;
            }
            $soal = [];
            foreach ($slots as $sl) {
                $qid = (int) $sl->questionid;
                $ctx = (int) $DB->get_field_sql(
                    'SELECT qc.contextid FROM {question_versions} qv JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                       JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid WHERE qv.questionid = ?',
                    [$qid]
                );
                $opsi = [];
                if ($sl->qtype === 'multichoice' || $sl->qtype === 'truefalse') {
                    foreach ($DB->get_records('question_answers', ['question' => $qid], 'id') as $a) {
                        $opsi[] = [
                            'teks'  => cbt_soal_gambar_ke_data(clean_text((string) $a->answer, FORMAT_HTML), $ctx, 'answer', (int) $a->id),
                            'kunci' => (float) $a->fraction > 0.999,
                        ];
                    }
                }
                $soal[] = [
                    'slot' => (int) $sl->slot, 'id' => $qid, 'qtype' => (string) $sl->qtype,
                    'teks' => cbt_soal_gambar_ke_data(clean_text((string) $sl->questiontext, FORMAT_HTML), $ctx, 'questiontext', $qid),
                    'opsi' => $opsi,
                ];
            }
            $result['items'][] = ['quiz' => ['id' => (int) $quiz->id, 'nama' => $quiz->name], 'soal' => $soal];
            break;

        case 'soal_baca':
            // Edit Soal: isi satu soal (teks + opsi, gambar sebagai data URI) untuk editor panel.
            [$quiz, , , $slots] = cbt_soal_quiz((int) $in['courseid']);
            $qid = (int) $in['questionid'];
            if (! $quiz || ! array_filter($slots, static fn ($sl) => (int) $sl->questionid === $qid)) {
                throw new RuntimeException('Soal tidak ada di quiz mapel ini.');
            }
            $q   = $DB->get_record('question', ['id' => $qid], '*', MUST_EXIST);
            $ctx = (int) $DB->get_field_sql(
                'SELECT qc.contextid FROM {question_versions} qv JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                   JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid WHERE qv.questionid = ?',
                [$qid]
            );
            $single = $q->qtype === 'multichoice' ? (int) $DB->get_field('qtype_multichoice_options', 'single', ['questionid' => $qid]) : 1;
            $opsi   = [];
            foreach ($DB->get_records('question_answers', ['question' => $qid], 'id') as $a) {
                $opsi[] = ['id' => (int) $a->id, 'teks' => cbt_soal_gambar_ke_data((string) $a->answer, $ctx, 'answer', (int) $a->id), 'kunci' => (float) $a->fraction > 0.999];
            }
            $result['items'][] = [
                'id'     => $qid, 'qtype' => $q->qtype, 'single' => $single === 1,
                'teks'   => cbt_soal_gambar_ke_data((string) $q->questiontext, $ctx, 'questiontext', $qid),
                'opsi'   => $q->qtype === 'multichoice' ? $opsi : [],
            ];
            break;

        case 'soal_ubah':
            // Edit Soal: ubah teks soal/opsi dan kunci DI TEMPAT (tanpa versi baru), supaya siswa yang sedang
            // mengerjakan langsung melihat perbaikannya saat halaman soal dimuat ulang. Opsi tidak ditambah/dihapus
            // (urutan acak di attempt memakai id opsi). Kunci berubah + nilai_ulang: attempt selesai dinilai ulang.
            [$quiz, , , $slots] = cbt_soal_quiz((int) $in['courseid']);
            $qid = (int) $in['questionid'];
            if (! $quiz || ! array_filter($slots, static fn ($sl) => (int) $sl->questionid === $qid)) {
                throw new RuntimeException('Soal tidak ada di quiz mapel ini.');
            }
            $q    = $DB->get_record('question', ['id' => $qid], '*', MUST_EXIST);
            $teks = trim((string) $in['teks']);
            if (trim(strip_tags($teks, '<img>')) === '') {
                throw new RuntimeException('Teks soal tidak boleh kosong.');
            }
            $answers = $DB->get_records('question_answers', ['question' => $qid], 'id');
            $single  = $q->qtype === 'multichoice' && (int) $DB->get_field('qtype_multichoice_options', 'single', ['questionid' => $qid]) === 1;
            $tx      = $DB->start_delegated_transaction();
            $DB->update_record('question', (object) ['id' => $qid, 'questiontext' => $teks, 'timemodified' => time(), 'modifiedby' => get_admin()->id]);
            $kunciBerubah = false;
            if ($q->qtype === 'multichoice') {
                foreach ((array) ($in['opsi'] ?? []) as $o) {
                    $aid = (int) ($o['id'] ?? 0);
                    if (! isset($answers[$aid])) {
                        throw new RuntimeException('Opsi tidak dikenal.');
                    }
                    if (trim(strip_tags((string) $o['teks'], '<img>')) === '') {
                        throw new RuntimeException('Teks opsi tidak boleh kosong.');
                    }
                    $DB->set_field('question_answers', 'answer', trim((string) $o['teks']), ['id' => $aid]);
                }
                $kunci = (int) ($in['kunci'] ?? 0);
                if ($single && $kunci > 0) {
                    if (! isset($answers[$kunci])) {
                        throw new RuntimeException('Kunci tidak dikenal.');
                    }
                    foreach ($answers as $a) {
                        $baru = (int) $a->id === $kunci ? 1.0 : 0.0;
                        if (abs((float) $a->fraction - $baru) > 1e-7) {
                            $DB->set_field('question_answers', 'fraction', $baru, ['id' => $a->id]);
                            $kunciBerubah = true;
                        }
                    }
                }
            }
            $tx->allow_commit();
            question_bank::notify_question_edited($qid);

            $dinilai = 0;
            if ($kunciBerubah && ! empty($in['nilai_ulang'])) {
                $users = [];
                $rows  = $DB->get_records_sql(
                    "SELECT a.id, a.uniqueid, a.userid, qa.slot FROM {quiz_attempts} a
                       JOIN {question_attempts} qa ON qa.questionusageid = a.uniqueid AND qa.questionid = ?
                      WHERE a.quiz = ? AND a.preview = 0 AND a.state = 'finished'",
                    [$qid, $quiz->id]
                );
                foreach ($rows as $r) {
                    $quba = question_engine::load_questions_usage_by_activity((int) $r->uniqueid);
                    $quba->regrade_question((int) $r->slot, true);
                    question_engine::save_questions_usage_by_activity($quba);
                    $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $r->id]);
                    $users[(int) $r->userid] = true;
                    $dinilai++;
                }
                $calc = \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator();
                foreach (array_keys($users) as $uid) {
                    $calc->recompute_final_grade($uid);
                    quiz_update_grades($quiz, $uid);
                }
            }
            $result['items'][] = [
                'kunci_berubah' => $kunciBerubah, 'dinilai_ulang' => $dinilai,
                'sedang'        => $DB->count_records_select('quiz_attempts', "quiz = ? AND preview = 0 AND state IN ('inprogress', 'overdue')", [$quiz->id]),
            ];
            break;

        default:
            $result = ['ok' => false, 'error' => 'Aksi tidak dikenal: ' . $in['action']];
    }
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}

echo "\n@@CBTJSON@@" . json_encode($result);
