<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\Pengawas;
use App\Libraries\QuizStatus;
use App\Libraries\Settings;
use App\Libraries\Tingkat;

class StatusPeserta extends PanelController
{
    public const FILTERS = ['inprogress' => 'Sedang Mengerjakan', 'finished' => 'Selesai', 'notstarted' => 'Belum Mulai'];

    public function index(string $filter = '')
    {
        if ($filter !== '' && ! isset(self::FILTERS[$filter])) {
            return redirect()->to(site_url('status_peserta'));
        }

        $quizId  = (int) $this->request->getGet('quiz');
        $ruangId = $this->myRuang() !== null ? (string) $this->myRuang() : (string) $this->request->getGet('ruang');
        $rows    = [];
        $quizzes = [];
        $quiz    = null;
        $error   = false;

        try {
            $quizzes = Moodle::quizzes();
            foreach ($quizzes as $q) {
                if ((int) $q['id'] === $quizId) {
                    $quiz = $q;
                }
            }
            $rows = $this->saringSesi(QuizStatus::filter($quiz ? QuizStatus::forQuiz($quiz) : QuizStatus::allQuizzes(), $ruangId, '', Tingkat::dariRequest()));
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Status Peserta: ' . $e->getMessage());
        }

        $counts = array_fill_keys(array_keys(self::FILTERS), 0);
        $shown  = [];
        foreach ($rows as $row) {
            $counts[$row['status']]++;
            if ($filter === '' || $row['status'] === $filter) {
                $shown[] = $row;
            }
        }

        return $this->render('status/peserta', [
            'title'     => 'Status Peserta',
            'rows'      => $shown,
            'counts'    => $counts,
            'filter'    => $filter,
            'quiz'      => $quiz,
            'quizzes'   => $quizzes,
            'ruangList' => $this->ruangList(),
            'lockRuang' => $this->myRuang() !== null,
            'ruangId'   => $ruangId,
            'tingkat'   => Tingkat::dariRequest(),
            'error'     => $error,
            'refresh'   => max(5, (int) Settings::get('refresh_interval', '30')),
            'now'       => time(),
        ]);
    }

    /**
     * Export Excel peserta yang belum mulai mapel terpilih (pengganti menu No Attempt Check).
     * Filter sama dengan halaman (ruang, tingkatan); pengawas hanya siswa ruangnya di sesi aktif.
     */
    public function exportBelum()
    {
        $quizId  = (int) $this->request->getGet('quiz');
        $ruangId = $this->myRuang() !== null ? (string) $this->myRuang() : (string) $this->request->getGet('ruang');
        $back    = site_url('status_peserta/notstarted');
        try {
            $quiz = $quizId > 0 ? QuizStatus::findQuiz($quizId) : null;
            if (! $quiz) {
                return redirect()->to($back)->with('error', 'Pilih mapel dulu.');
            }
            $rows = array_values(array_filter(
                $this->saringSesi(QuizStatus::filter(QuizStatus::forQuiz($quiz), $ruangId, '', Tingkat::dariRequest())),
                static fn ($r) => $r['status'] === 'notstarted'
            ));
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        usort($rows, static fn ($a, $b) => strnatcasecmp((string) $a['ruang'], (string) $b['ruang']) ?: [$a['kelas'], $a['nama']] <=> [$b['kelas'], $b['nama']]);
        $data = [];
        foreach ($rows as $i => $r) {
            $data[] = [$i + 1, $r['nomor_peserta'] ?? '', $r['nama'], $r['kelas'], $r['ruang'] ?? ''];
        }
        ActivityLog::add('export_belum_mulai', $quiz['name'] . ': ' . count($data) . ' peserta');
        $file = 'belum_mengerjakan_' . preg_replace('/[^A-Za-z0-9]+/', '_', $quiz['name']) . '_' . date('Ymd_His') . '.xlsx';

        return $this->response->download($file, \App\Libraries\Excel::build(['no', 'nomor_peserta', 'nama', 'kelas', 'ruang'], $data));
    }

    /**
     * Login ganda: akun peserta yang pada tanggal itu login dari lebih dari satu alamat IP (komputer),
     * dibaca dari log login Moodle. Hanya untuk pengawas (siswa di ruangnya); admin memakai
     * Tools → Panel → Log aktivitas → tab Login siswa (isinya sama, lebih lengkap, bisa Excel).
     */
    public function loginGanda()
    {
        $tanggal = (string) $this->request->getGet('tanggal');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || ! strtotime($tanggal)) {
            $tanggal = date('Y-m-d');
        }
        // Administrator memakai log lengkap di Tools → Panel; proktor (tanpa menu itu) memakai halaman ini untuk semua ruang.
        if ($this->myRuang() === null && session()->get('admin_role') === 'admin') {
            return redirect()->to(site_url('tools/lainnya') . '?' . http_build_query(['log_tab' => 'login', 'log_tgl' => $tanggal]) . '#log');
        }
        $ruangId = $this->myRuang() !== null ? (string) $this->myRuang() : (string) $this->request->getGet('ruang');
        $tingkat = Tingkat::dariRequest();
        $rows    = [];
        $error   = false;

        try {
            $mulai = strtotime($tanggal . ' 00:00:00');
            $log   = Moodle::db()->query(
                'SELECT l.userid, l.ip, COUNT(*) AS kali, MIN(l.timecreated) AS pertama, MAX(l.timecreated) AS terakhir, u.username, u.firstname
                   FROM ' . Moodle::t('logstore_standard_log') . ' l
                   JOIN ' . Moodle::t('user') . ' u ON u.id = l.userid
                  WHERE l.eventname = ? AND l.timecreated >= ? AND l.timecreated < ?
                  GROUP BY l.userid, l.ip, u.username, u.firstname',
                ['\\core\\event\\user_loggedin', $mulai, $mulai + 86400]
            )->getResultArray();

            $per = [];
            foreach ($log as $l) {
                $per[$l['userid']][] = $l;
            }
            $panel = array_column(db_connect()->table('peserta p')
                ->select('p.username, p.nama, p.kelas, p.ruang_id, r.nama AS ruang_nama')
                ->join('ruang r', 'r.id = p.ruang_id', 'left')->get()->getResultArray(), null, 'username');

            foreach ($per as $ips) {
                if (count($ips) < 2) {
                    continue;
                }
                $info = $panel[$ips[0]['username']] ?? null;
                if ($info === null) {
                    continue;  // bukan peserta (admin, guru)
                }
                if ($ruangId === '0' ? $info['ruang_id'] !== null : ($ruangId !== '' && (string) $info['ruang_id'] !== $ruangId)) {
                    continue;
                }
                if (! Tingkat::cocok($info['kelas'], $tingkat)) {
                    continue;
                }
                usort($ips, static fn ($a, $b) => (int) $a['pertama'] <=> (int) $b['pertama']);
                $rows[] = $info + ['ips' => $ips, 'jumlah_ip' => count($ips)];
            }
            $rows = $this->saringSesi($rows);
            usort($rows, static fn ($a, $b) => [-$a['jumlah_ip'], (string) $a['ruang_nama'], $a['nama']] <=> [-$b['jumlah_ip'], (string) $b['ruang_nama'], $b['nama']]);
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Login ganda: ' . $e->getMessage());
        }

        return $this->render('status/login_ganda', [
            'title'     => 'Login Ganda',
            'rows'      => $rows,
            'tanggal'   => $tanggal,
            'ruangId'   => $ruangId,
            'tingkat'   => $tingkat,
            'ruangList' => $this->ruangList(),
            'lockRuang' => $this->myRuang() !== null,
            'error'     => $error,
        ]);
    }

    /** Aksi massal untuk pilihan "quizid:userid". */
    public function aksi()
    {
        $pairs = [];
        foreach ((array) $this->request->getPost('ids') as $value) {
            if (preg_match('/^(\d+):(\d+)$/', (string) $value, $m)) {
                $pairs[(int) $m[1]][] = (int) $m[2];
            }
        }
        // Pengawas ruang hanya boleh menindak siswa di ruangnya.
        if ($this->myRuang() !== null) {
            $allowed = $this->moodleIdsSesi() ?? [];
            foreach ($pairs as $quizId => $userIds) {
                $pairs[$quizId] = array_values(array_intersect($userIds, $allowed));
                if ($pairs[$quizId] === []) {
                    unset($pairs[$quizId]);
                }
            }
        }
        if ($pairs === []) {
            return redirect()->back()->with('error', 'Belum ada peserta yang dicentang.');
        }

        $aksi = (string) $this->request->getPost('aksi');

        try {
            if ($aksi === 'waktu') {
                $minutes = (int) $this->request->getPost('menit');
                if ($minutes < 1 || $minutes > 600) {
                    return redirect()->back()->with('error', 'Isi tambahan waktu antara 1 dan 600 menit.');
                }
                $items = [];
                foreach ($pairs as $quizId => $userIds) {
                    $items = array_merge($items, MoodleBridge::call('add_time', ['quizid' => $quizId, 'userids' => $userIds, 'minutes' => $minutes]));
                }

                return $this->report($items, "tambah_waktu_{$minutes}_menit", "Waktu ditambah {$minutes} menit untuk %d peserta.", array_merge(...array_values($pairs)));
            }

            // Hapus attempt hanya lewat menu Hapus Attempt (khusus administrator).
            if ($aksi !== 'submit') {
                return redirect()->back()->with('error', 'Aksi tidak dikenal.');
            }
            $attempts = QuizStatus::latestAttemptIds($pairs, ['inprogress', 'overdue']);
            if ($attempts === []) {
                return redirect()->back()->with('error', 'Tidak ada peserta terpilih yang sedang mengerjakan.');
            }

            return $this->report(MoodleBridge::call('finish_attempts', ['attemptids' => $attempts]), 'paksa_submit', '%d attempt disubmit.', array_merge(...array_values($pairs)));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Aksi gagal: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ Bantuan

    private function report(array $items, string $logAction, string $successText, array $userIds = [])
    {
        $ok     = 0;
        $errors = [];
        foreach ($items as $item) {
            if (isset($item['error'])) {
                $errors[] = $item['error'];
            } else {
                $ok++;
            }
        }
        ActivityLog::add($logAction, $ok . ' berhasil, ' . count($errors) . ' gagal' . $this->daftarSiswaLog($userIds));

        $message = sprintf($successText, $ok);
        if ($errors !== []) {
            $message .= ' Gagal ' . count($errors) . ': ' . implode('; ', array_slice(array_unique($errors), 0, 3));
        }

        return redirect()->back()->with($errors === [] ? 'success' : 'error', $message);
    }
}
