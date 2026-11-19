<?php

namespace App\Libraries;

/** Status peserta per quiz (dipakai Status Peserta dan menu laporan). */
class QuizStatus
{
    public static function findQuiz(int $quizId): ?array
    {
        foreach (Moodle::quizzes() as $q) {
            if ((int) $q['id'] === $quizId) {
                return $q;
            }
        }

        return null;
    }

    /** Semua peserta terdaftar di kursus quiz, termasuk yang belum mulai. */
    public static function forQuiz(array $quiz): array
    {
        $db  = Moodle::db();
        $u   = Moodle::t('user');
        $ue  = Moodle::t('user_enrolments');
        $e   = Moodle::t('enrol');
        $a   = Moodle::t('quiz_attempts');
        $non = Moodle::nonParticipantIds();

        $users = $db->query(
            "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname
               FROM {$u} u
               JOIN {$ue} ue ON ue.userid = u.id AND ue.status = 0
               JOIN {$e} e ON e.id = ue.enrolid AND e.status = 0 AND e.courseid = ?
              WHERE u.deleted = 0 AND u.id NOT IN ?
             UNION
             SELECT u.id, u.username, u.firstname, u.lastname
               FROM {$u} u JOIN {$a} qa ON qa.userid = u.id AND qa.quiz = ? AND qa.preview = 0
              WHERE u.id NOT IN ?",
            [(int) $quiz['course'], $non, (int) $quiz['id'], $non]
        )->getResultArray();

        $attempts = [];
        foreach ($db->table($a)->where('quiz', (int) $quiz['id'])->where('preview', 0)->orderBy('attempt', 'ASC')->get()->getResultArray() as $att) {
            $attempts[$att['userid']] = $att;
        }

        $rows = [];
        foreach ($users as $user) {
            $rows[$user['id']] = self::row($quiz, $user, $attempts[$user['id']] ?? null);
        }

        return self::annotate(array_values($rows));
    }

    /** Tanpa quiz terpilih: attempt terakhir tiap peserta di semua quiz. */
    public static function allQuizzes(): array
    {
        $a = Moodle::t('quiz_attempts');
        $u = Moodle::t('user');
        $q = Moodle::t('quiz');

        $list = Moodle::db()->query(
            "SELECT qa.*, q.name AS quiz_name, u.username, u.firstname, u.lastname
               FROM {$a} qa
               JOIN {$q} q ON q.id = qa.quiz
               JOIN {$u} u ON u.id = qa.userid
              WHERE qa.preview = 0
                AND qa.attempt = (SELECT MAX(a2.attempt) FROM {$a} a2 WHERE a2.quiz = qa.quiz AND a2.userid = qa.userid AND a2.preview = 0)
              ORDER BY qa.timestart DESC
              LIMIT 3000"
        )->getResultArray();

        $rows = [];
        foreach ($list as $att) {
            $rows[] = self::row(
                ['id' => $att['quiz'], 'name' => $att['quiz_name']],
                ['id' => $att['userid'], 'username' => $att['username'], 'firstname' => $att['firstname'], 'lastname' => $att['lastname']],
                $att
            );
        }

        return self::annotate($rows);
    }

    /**
     * Lengkapi baris dengan data panel (kelas, ruang, nomor peserta) dan saring
     * menurut ruang ('' = semua, '0' = tanpa ruang) dan kelas ('' = semua).
     */
    public static function filter(array $rows, string $ruangId, string $kelas = '', string $tingkat = ''): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($ruangId, $kelas, $tingkat): bool {
            if ($kelas !== '' && $row['kelas'] !== $kelas) {
                return false;
            }
            if (! Tingkat::cocok($row['kelas'], $tingkat)) {
                return false;
            }
            if ($ruangId === '0') {
                return $row['ruang_id'] === null;
            }

            return $ruangId === '' || (string) $row['ruang_id'] === $ruangId;
        }));
    }

    /**
     * ID attempt terakhir untuk tiap pasangan quiz/user ($pairs = [quizId => [userId, ...]]),
     * opsional hanya yang state-nya ada di $states.
     */
    public static function latestAttemptIds(array $pairs, ?array $states = null): array
    {
        $builder = Moodle::db()->table(Moodle::t('quiz_attempts'))
            ->select('id, quiz, userid, attempt, state')
            ->where('preview', 0)
            ->groupStart();
        foreach ($pairs as $quizId => $userIds) {
            $builder->orGroupStart()->where('quiz', $quizId)->whereIn('userid', $userIds)->groupEnd();
        }
        $builder->groupEnd()->orderBy('attempt', 'ASC');

        $latest = [];
        foreach ($builder->get()->getResultArray() as $a) {
            $latest[$a['quiz'] . ':' . $a['userid']] = $a;
        }

        $ids = [];
        foreach ($latest as $a) {
            if ($states === null || in_array($a['state'], $states, true)) {
                $ids[] = (int) $a['id'];
            }
        }

        return $ids;
    }

    public static function statusOf(?string $state): string
    {
        return match ($state) {
            null                    => 'notstarted',
            'inprogress', 'overdue' => 'inprogress',
            default                 => 'finished',
        };
    }

    private static function row(array $quiz, array $user, ?array $attempt): array
    {
        $state = $attempt['state'] ?? null;

        return [
            'key'        => $quiz['id'] . ':' . $user['id'],
            'quiz_id'    => (int) $quiz['id'],
            'quiz_name'  => $quiz['name'],
            'userid'     => (int) $user['id'],
            'username'   => $user['username'],
            // Nama depan saja: last name Moodle berisi kelas, yang sudah punya kolom sendiri.
            'nama'       => trim((string) $user['firstname']) ?: trim($user['firstname'] . ' ' . $user['lastname']),
            'lastname'   => trim((string) $user['lastname']),
            'status'     => self::statusOf($state),
            'overdue'    => $state === 'overdue',
            'timestart'  => (int) ($attempt['timestart'] ?? 0),
            'timefinish' => (int) ($attempt['timefinish'] ?? 0),
            'deadline'   => $state === 'inprogress' ? (int) ($attempt['timecheckstate'] ?? 0) : 0,
            'attempt_no' => (int) ($attempt['attempt'] ?? 0),
            'sumgrades'  => $attempt['sumgrades'] ?? null,
        ];
    }

    private static function annotate(array $rows): array
    {
        $panel = array_column(db_connect()->table('peserta p')
            ->select('p.username, p.nomor_peserta, p.kelas, p.ruang_id, r.nama AS ruang_nama')
            ->join('ruang r', 'r.id = p.ruang_id', 'left')
            ->get()->getResultArray(), null, 'username');

        foreach ($rows as &$row) {
            $info                 = $panel[$row['username']] ?? null;
            $row['nomor_peserta'] = $info['nomor_peserta'] ?? '';
            $row['kelas']         = $info['kelas'] ?? $row['lastname'];  // bukan peserta panel: kelas = last name Moodle
            $row['ruang_id']      = $info['ruang_id'] ?? null;
            $row['ruang']         = $info['ruang_nama'] ?? null;
        }

        return $rows;
    }
}
