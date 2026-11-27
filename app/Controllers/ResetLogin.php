<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\Pengawas;
use App\Libraries\Tingkat;

class ResetLogin extends PanelController
{
    public function index(): string
    {
        $cari    = trim((string) $this->request->getGet('cari'));
        $ruangId = $this->myRuang() !== null ? (string) $this->myRuang() : (string) $this->request->getGet('ruang');
        $tingkat = Tingkat::dariRequest();
        $rows    = [];
        $error   = false;
        // ?aktif=1 (tombol Lihat kartu User Aktif di Dashboard): hanya user yang aktif dalam N menit terakhir
        // (Moodle user.lastaccess, setelan active_minutes), sama persis dengan angka di kartu itu.
        $menit = $this->request->getGet('aktif') ? max(1, (int) \App\Libraries\Settings::get('active_minutes', '5')) : 0;

        try {
            $users    = $this->candidates($cari, $menit);
            $sessions = [];
            foreach (array_chunk(array_keys($users), 500) as $chunk) {
                foreach (MoodleBridge::call('list_sessions', ['userids' => $chunk]) as $s) {
                    $sessions[$s['userid']] = $s;
                }
            }

            $panel = array_column(db_connect()->table('peserta p')
                ->select('p.username, p.kelas, p.ruang_id, r.nama AS ruang_nama')
                ->join('ruang r', 'r.id = p.ruang_id', 'left')
                ->get()->getResultArray(), null, 'username');

            // Percobaan login dari perangkat lain yang ditolak Lock login (plugin cbtujian), hapus saat di-reset.
            $ditolak = [];
            foreach (array_chunk(array_keys($users), 1000) as $chunk) {
                $prefs = Moodle::db()->table(Moodle::t('user_preferences'))->select('userid, value')
                    ->where('name', 'cbtujian_ditolak')->whereIn('userid', $chunk)->get()->getResultArray();
                foreach ($prefs as $p) {
                    $ditolak[(int) $p['userid']] = json_decode((string) $p['value'], true) ?: [];
                }
            }

            foreach ($users as $id => $u) {
                $s = $sessions[$id] ?? null;
                if (! $s && $cari === '' && $menit === 0) {
                    continue;
                }
                $info = $panel[$u['username']] ?? null;
                if ($ruangId === '0' ? ($info['ruang_id'] ?? null) !== null : ($ruangId !== '' && (string) ($info['ruang_id'] ?? '') !== $ruangId)) {
                    continue;
                }
                if (! Tingkat::cocok($info['kelas'] ?? '', $tingkat)) {
                    continue;
                }
                $rows[] = [
                    'userid'   => (int) $id,
                    'username' => $u['username'],
                    'nama'     => trim($u['firstname'] . ' ' . $u['lastname']),
                    'kelas'    => $info['kelas'] ?? '',
                    'ruang'    => $info['ruang_nama'] ?? null,
                    'sesi'     => (int) ($s['count'] ?? 0),
                    'ip'       => $s['lastip'] ?? '',
                    'login'    => (int) ($s['timecreated'] ?? 0),
                    'aktif'    => (int) ($s['timemodified'] ?? 0),
                    'tolak'    => isset($ditolak[$id]) ? ['w' => (int) ($ditolak[$id]['w'] ?? 0), 'ip' => (string) ($ditolak[$id]['ip'] ?? '')] : null,
                ];
            }
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Reset Login: ' . $e->getMessage());
        }
        $rows = $this->saringSesi($rows);

        return $this->render('reset_login/index', [
            'title'     => 'Reset Login Peserta',
            'rows'      => $rows,
            'cari'      => $cari,
            'ruangId'   => $ruangId,
            'tingkat'   => $tingkat,
            'ruangList' => $this->ruangList(),
            'lockRuang' => $this->myRuang() !== null,
            'error'     => $error,
            'menit'     => $menit,
        ]);
    }

    public function reset()
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->getPost('ids')))));
        $label = count($ids) . ' peserta terpilih';

        $ruangId = (int) $this->request->getPost('ruang_id');
        if ($this->myRuang() !== null) {
            // Pengawas ruang hanya boleh mereset siswa ruangnya yang ikut sesi aktif.
            $boleh = $this->moodleIdsSesi() ?? [];
            if ($ruangId > 0) {
                $ruang = db_connect()->table('ruang')->select('nama')->where('id', $this->myRuang())->get()->getRowArray();
                $ids   = $boleh;
                $label = 'ruang ' . ($ruang['nama'] ?? $this->myRuang()) . ' (sesi aktif)';
            } else {
                $ids = array_values(array_intersect($ids, $boleh));
            }
            $ruangId = 0;
        }
        if ($ruangId > 0) {
            $ruang     = db_connect()->table('ruang')->where('id', $ruangId)->get()->getRowArray();
            $usernames = array_column(db_connect()->table('peserta')->select('username')->where('ruang_id', $ruangId)->get()->getResultArray(), 'username');
            $ids       = $usernames === [] ? [] : array_map('intval', array_column(
                Moodle::db()->table(Moodle::t('user'))->select('id')->where('deleted', 0)->whereIn('username', $usernames)->get()->getResultArray(),
                'id'
            ));
            $label = 'ruang ' . ($ruang['nama'] ?? $ruangId);
        }

        if ($ids === []) {
            return redirect()->back()->with('error', $ruangId > 0 ? 'Ruang ini belum punya peserta yang terdaftar di Moodle.' : 'Belum ada peserta yang dicentang.');
        }

        try {
            $items = MoodleBridge::call('kill_sessions', ['userids' => $ids]);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Reset login gagal: ' . $e->getMessage());
        }

        $users  = 0;
        $killed = 0;
        $errors = [];
        Moodle::catatReset(array_column(array_filter($items, static fn ($i) => ! isset($i['error'])), 'userid'));
        foreach ($items as $item) {
            if (isset($item['error'])) {
                $errors[] = $item['error'];
            } elseif ($item['count'] > 0) {
                $users++;
                $killed += $item['count'];
            }
        }
        ActivityLog::add('reset_login', "$label: $users peserta, $killed sesi" . $this->daftarSiswaLog($ids));

        $message = "Login direset untuk $users peserta ($killed sesi) dari $label. Peserta bisa login lagi di perangkat lain; attempt yang sedang berjalan tetap tersimpan.";
        if ($errors !== []) {
            $message .= ' Dilewati: ' . implode('; ', array_unique($errors));
        }

        return redirect()->back()->with($errors === [] ? 'success' : 'error', $message);
    }

    /** User Moodle yang mungkin sedang login, atau yang cocok dengan pencarian. */
    private function candidates(string $cari, int $menit = 0): array
    {
        $builder = Moodle::db()->table(Moodle::t('user'))
            ->select('id, username, firstname, lastname')
            ->where('deleted', 0)
            ->whereNotIn('id', Moodle::nonParticipantIds());

        if ($menit > 0) {
            $builder->whereIn('id', Moodle::activeUserIds($menit) ?: [0]); // sama dengan Moodle::activeUsers()
        }
        if ($cari !== '') {
            $builder->groupStart()
                ->like('username', $cari)->orLike('firstname', $cari)->orLike('lastname', $cari)
                ->groupEnd()->limit(200);
        } elseif ($menit === 0) {
            $row     = Moodle::db()->table(Moodle::t('config'))->select('value')->where('name', 'sessiontimeout')->get()->getRowArray();
            $timeout = max(600, (int) ($row['value'] ?? 28800));
            $since   = time() - $timeout;
            $builder->groupStart()->where('lastaccess >', $since)->orWhere('currentlogin >', $since)->groupEnd();
        }

        return array_column($builder->orderBy('username')->get()->getResultArray(), null, 'id');
    }
}
