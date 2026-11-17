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
            'idnumber'          => '',
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
                        if (! empty($u['password'])) {
                            cbt_check_password((string) $u['password'], $existing);
                            update_internal_user_password($existing, (string) $u['password']);
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
                            'lang'       => $CFG->lang,
                        ];
                        $item += ['id' => (int) user_create_user($user, true, false), 'status' => 'dibuat'];
                    }
                    if (! empty($u['cohort'])) {
                        cbt_add_to_cohort($item['id'], (string) $u['cohort']);
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

        case 'quiz_times':
            // Ubah waktu buka/tutup quiz, lalu perbarui kalender, cache kursus, dan attempt yang sedang berjalan.
            $quiz            = $DB->get_record('quiz', ['id' => (int) $in['quizid']], '*', MUST_EXIST);
            $quiz->timeopen  = max(0, (int) $in['timeopen']);
            $quiz->timeclose = max(0, (int) $in['timeclose']);
            if ($quiz->timeopen && $quiz->timeclose && $quiz->timeclose <= $quiz->timeopen) {
                throw new RuntimeException('Waktu tutup harus setelah waktu buka.');
            }
            $quiz->timemodified = time();
            $DB->update_record('quiz', $quiz);
            quiz_update_events($quiz);
            rebuild_course_cache((int) $quiz->course, true);
            quiz_update_open_attempts(['quizid' => (int) $quiz->id]);
            $result['items'][] = ['id' => (int) $quiz->id, 'status' => 'diubah'];
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
            set_config('limitconcurrentlogins', empty($in['locklogin']) ? 1 : 0);
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

        case 'create_course':
            // Mapel baru memakai bawaan course (Single activity + Quiz) dan tersembunyi sampai
            // diaktifkan di Status Test.
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
            ]);
            $result['items'][] = ['id' => (int) $course->id, 'status' => 'dibuat'];
            break;

        case 'enrol_users':
            // Daftarkan user ke course (shortname) sebagai siswa lewat metode enrol manual.
            // Masukan: users = [{userid, courses: [shortname, ...]}]; yang sudah terdaftar dilewati.
            require_once $CFG->dirroot . '/lib/enrollib.php';
            $manual = enrol_get_plugin('manual');
            $role   = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
            $courses = $instances = [];
            foreach ($DB->get_records('course', null, '', 'id, shortname') as $c) {
                $courses[core_text::strtolower($c->shortname)] = $c;
            }
            foreach ($in['users'] as $u) {
                $item = ['userid' => (int) $u['userid'], 'baru' => 0, 'sudah' => 0, 'tidak_ada' => []];
                try {
                    foreach ((array) $u['courses'] as $sn) {
                        $course = $courses[core_text::strtolower(trim((string) $sn))] ?? null;
                        if ($course === null || (int) $course->id === SITEID) {
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

        case 'session_gc':
            // Hapus sesi Moodle yang sudah melewati batas waktu sesi situs.
            \core\session\manager::gc();
            $result['items'][] = ['status' => 'dibersihkan', 'timeout' => (int) $CFG->sessiontimeout];
            break;

        default:
            $result = ['ok' => false, 'error' => 'Aksi tidak dikenal: ' . $in['action']];
    }
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}

echo "\n@@CBTJSON@@" . json_encode($result);
