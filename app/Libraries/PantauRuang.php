<?php

namespace App\Libraries;

/**
 * Pantauan siswa ruang pengawas di dashboard: setiap siswa (ruang + sesi aktif) masuk tepat satu kelompok,
 * berurutan Belum login → Belum mulai → Mengerjakan → Sudah submit (jumlahnya = siswa di ruang).
 * Hanya hari ini; ujian = quiz di tes aktif (Setting Ujian), tanpa tes aktif semua quiz.
 */
class PantauRuang
{
    public const KELOMPOK = ['belumLogin', 'belumMulai', 'mengerjakan', 'sudah'];

    /** @return array{belumLogin:list<array>, belumMulai:list<array>, mengerjakan:list<array>, sudah:list<array>} */
    public static function get(int $ruangId, ?int $sesiId): array
    {
        $hasil     = array_fill_keys(self::KELOMPOK, []);
        $usernames = Pengawas::usernamesInRuang($ruangId, $sesiId);
        if ($usernames === []) {
            return $hasil;
        }

        $db      = db_connect();
        $peserta = $db->table('peserta')->select('id, username, nama, kelas')->whereIn('username', $usernames)
            ->orderBy('kelas')->orderBy('nama')->get()->getResultArray();
        $absen   = array_column($db->table('absensi')->select('peserta_id, status')->where('tanggal', date('Y-m-d'))
            ->whereIn('peserta_id', array_column($peserta, 'id') ?: [0])->get()->getResultArray(), 'status', 'peserta_id');

        $m     = Moodle::db();
        $ids   = array_column($m->table(Moodle::t('user'))->select('id, username')->where('deleted', 0)
            ->whereIn('username', $usernames)->get()->getResultArray(), 'id', 'username');
        $awal  = strtotime('today');
        $login = $mulai = $kerja = $selesai = [];
        if ($ids !== []) {
            $uid   = array_map('intval', array_values($ids));
            $login = array_flip(array_column($m->query(
                'SELECT DISTINCT userid FROM ' . Moodle::t('logstore_standard_log') . ' WHERE eventname = ? AND timecreated >= ? AND userid IN ?',
                ['\\core\\event\\user_loggedin', $awal, $uid]
            )->getResultArray(), 'userid'));

            $b = $m->table(Moodle::t('quiz_attempts') . ' qa')->select('qa.userid, qa.state, qa.timestart, qa.timefinish, q.name')
                ->join(Moodle::t('quiz') . ' q', 'q.id = qa.quiz')
                ->where('qa.preview', 0)->whereIn('qa.userid', $uid)
                ->groupStart()->whereIn('qa.state', ['inprogress', 'overdue'])->orWhere('qa.timestart >=', $awal)->groupEnd();
            $catId = ActiveTest::categoryId() ?: null;
            if ($catId !== null) {
                $quiz = array_map('intval', array_column(Moodle::quizzes($catId), 'id'));
                $b->whereIn('qa.quiz', $quiz ?: [0]);
            }
            foreach ($b->orderBy('qa.timestart')->get()->getResultArray() as $a) {
                if (in_array($a['state'], ['inprogress', 'overdue'], true)) {
                    $kerja[$a['userid']] = $a;
                } elseif ($a['state'] === 'finished') {
                    $selesai[$a['userid']] = $a;
                }
            }
        }

        foreach ($peserta as $p) {
            $id  = isset($ids[$p['username']]) ? (int) $ids[$p['username']] : 0;
            $row = ['nama' => $p['nama'], 'kelas' => $p['kelas'], 'username' => $p['username'], 'info' => ''];
            if (isset($kerja[$id])) {
                $row['info'] = 'mulai ' . date('H:i', (int) $kerja[$id]['timestart']);
                $hasil['mengerjakan'][] = $row;
            } elseif (isset($selesai[$id])) {
                $row['info'] = 'selesai ' . date('H:i', (int) $selesai[$id]['timefinish']);
                $hasil['sudah'][] = $row;
            } elseif (isset($login[$id])) {
                $hasil['belumMulai'][] = $row;
            } else {
                $row['info'] = ($absen[$p['id']] ?? '') === 'tidak_hadir' ? 'tidak hadir' : '';
                $hasil['belumLogin'][] = $row;
            }
        }

        return $hasil;
    }
}
