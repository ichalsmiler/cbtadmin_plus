<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Excel;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\Pengawas;
use App\Libraries\Settings;
use App\Libraries\Tingkat;

class Peserta extends PanelController
{
    private const USERNAME_RE = '/^[a-z0-9._@-]{2,100}$/';

    // ------------------------------------------------------------------ Pengawas

    /** Daftar peserta di ruang pengawas (baca saja); admin bisa memilih ruang lewat ?ruang=. */
    public function ruang(): string
    {
        $ruangId = $this->myRuang() !== null ? (string) $this->myRuang() : (string) $this->request->getGet('ruang');
        $ruang   = $ruangId !== '' ? db_connect()->table('ruang')->where('id', (int) $ruangId)->get()->getRowArray() : null;

        return $this->render('peserta/ruang', [
            'title'     => 'Daftar Peserta' . ($ruang ? ' · Ruang ' . $ruang['nama'] : ''),
            'rows'      => $ruangId !== '' ? $this->saringSesi($this->pesertaRows('', $ruangId)) : [],
            'ruang'     => $ruang,
            'ruangId'   => $ruangId,
            'ruangList' => $this->ruangList(),
            'lockRuang' => $this->myRuang() !== null,
            'tingkat'   => Tingkat::dariRequest(),
        ]);
    }

    // ------------------------------------------------------------------ Data CBTAdmin

    public function cbtadmin(): string
    {
        $db      = db_connect();
        $kelas   = trim((string) $this->request->getGet('kelas'));
        $ruangId = (string) $this->request->getGet('ruang');

        return $this->render('peserta/cbtadmin', [
            'title'       => 'Daftar Peserta · Data CBTAdmin',
            'rows'        => $this->pesertaRows($kelas, $ruangId),
            'kelasList'   => array_column($db->table('peserta')->distinct()->select('kelas')->where('kelas !=', '')->orderBy('kelas')->get()->getResultArray(), 'kelas'),
            'ruangList'   => $this->ruangList(),
            'sesiList'    => $db->table('sesi')->orderBy('nama')->get()->getResultArray(),
            'filterKelas' => $kelas,
            'filterRuang' => $ruangId,
            'tingkat'     => Tingkat::dariRequest(),
        ]);
    }

    public function save()
    {
        $db       = db_connect();
        $id       = (int) $this->request->getPost('id');
        $nama     = trim((string) $this->request->getPost('nama'));
        $username = strtolower(trim((string) $this->request->getPost('username')));
        $password = trim((string) $this->request->getPost('password'));

        if ($nama === '' || ! preg_match(self::USERNAME_RE, $username)) {
            return redirect()->back()->with('error', 'Nama wajib diisi, dan username hanya boleh huruf kecil, angka, titik, strip, garis bawah, atau @.');
        }
        if ($db->table('peserta')->where('username', $username)->where('id !=', $id)->countAllResults() > 0) {
            return redirect()->back()->with('error', 'Username "' . $username . '" sudah dipakai peserta lain.');
        }

        $ruangId = (int) $this->request->getPost('ruang_id');
        $sesiId  = (int) $this->request->getPost('sesi_id');
        $data    = [
            // No. Peserta kosong = pakai username.
            'nomor_peserta' => mb_substr(trim((string) $this->request->getPost('nomor_peserta')) ?: $username, 0, 40),
            'nama'          => mb_substr($nama, 0, 150),
            'kelas'         => mb_substr(trim((string) $this->request->getPost('kelas')), 0, 60),
            'username'      => $username,
            'ruang_id'      => $ruangId > 0 ? $ruangId : null,
            'sesi_id'       => $sesiId > 0 ? $sesiId : null,
            'mapel'         => self::mapelTeks(preg_split('/[,;\r\n]+/', (string) $this->request->getPost('mapel'))),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        $message = 'Peserta disimpan.';
        if ($id > 0) {
            $old = $db->table('peserta')->where('id', $id)->get()->getRowArray();
            if (! $old) {
                return redirect()->back()->with('error', 'Peserta tidak ditemukan.');
            }
            if ($password !== '' && $password !== $old['password_plain']) {
                $data['password_plain'] = mb_substr($password, 0, 100);
                if ($old['moodle_userid']) {
                    try {
                        $this->resetInMoodle((int) $old['moodle_userid'], $data['password_plain']);
                        $message = 'Peserta disimpan dan password di Moodle ikut diganti.';
                    } catch (\Throwable $e) {
                        return redirect()->back()->with('error', 'Password di Moodle gagal diganti: ' . $e->getMessage());
                    }
                }
            }
            $db->table('peserta')->where('id', $id)->update($data);
            ActivityLog::add('ubah_peserta', $username);
        } else {
            $data['password_plain'] = $password !== '' ? mb_substr($password, 0, 100) : self::randomPassword();
            $data['created_at']     = $data['updated_at'];
            $db->table('peserta')->insert($data);
            ActivityLog::add('tambah_peserta', $username);
        }

        return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with('success', $message);
    }

    public function delete(int $id)
    {
        $db  = db_connect();
        $row = $db->table('peserta')->where('id', $id)->get()->getRowArray();
        if ($row) {
            $db->table('peserta')->where('id', $id)->delete();
            ActivityLog::add('hapus_peserta', $row['username']);
        }

        return redirect()->back()->with('success', 'Peserta dihapus dari Data CBTAdmin.');
    }

    public function bulk()
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->getPost('ids'))));
        if ($ids === []) {
            return redirect()->back()->with('error', 'Belum ada peserta yang dicentang.');
        }

        db_connect()->table('peserta')->whereIn('id', $ids)->delete();
        ActivityLog::add('hapus_peserta_massal', count($ids) . ' peserta');

        return redirect()->back()->with('success', count($ids) . ' peserta dihapus dari Data CBTAdmin.');
    }

    /** Hapus semua Data CBTAdmin (harus mengetik HAPUS). Akun Moodle tidak ikut terhapus. */
    public function reset()
    {
        if (trim((string) $this->request->getPost('konfirmasi')) !== 'HAPUS') {
            return redirect()->back()->with('error', 'Ketik HAPUS untuk mengonfirmasi reset peserta.');
        }

        $ruang = $this->request->getPost('ruang') === '1';
        try {
            $jumlah = $this->hapusSemua($ruang);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Reset dibatalkan: ' . $e->getMessage());
        }
        ActivityLog::add('reset_peserta', $jumlah . ' peserta dihapus' . ($ruang ? ', termasuk ruang, sesi, dan akun pengawas' : ''));

        return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with('success', "$jumlah peserta dihapus dari Data CBTAdmin"
            . ($ruang ? ', beserta daftar ruang, sesi, dan akun pengawas' : '') . '. Akun Moodle tidak ikut terhapus.');
    }

    /**
     * Kosongkan peserta beserta absensi dan blokirnya. Siswa yang sedang di-suspend karena blokir atau
     * absensi hari ini dibuka dulu di Moodle supaya tidak tertinggal terkunci tanpa catatan di panel.
     * $ruang: ikut kosongkan ruang, sesi, dan akun pengawas ruang (dibuat ulang saat impor berikutnya).
     *
     * @return int jumlah peserta yang dihapus
     */
    private function hapusSemua(bool $ruang = false): int
    {
        $db        = db_connect();
        $terkunci  = array_column($db->query(
            "SELECT DISTINCT p.username FROM peserta p
             LEFT JOIN blokir b ON b.peserta_id = p.id AND b.aktif = 1
             LEFT JOIN absensi a ON a.peserta_id = p.id AND a.tanggal = ? AND a.diblokir = 1
             WHERE b.id IS NOT NULL OR a.id IS NOT NULL",
            [date('Y-m-d')]
        )->getResultArray(), 'username');
        if ($terkunci !== []) {
            $ids = array_map('intval', array_column(
                Moodle::db()->table(Moodle::t('user'))->select('id')->where('deleted', 0)->whereIn('username', $terkunci)->get()->getResultArray(),
                'id'
            ));
            if ($ids !== []) {
                $gagal = array_filter(MoodleBridge::call('suspend_users', ['userids' => $ids, 'suspend' => false]), static fn ($i) => isset($i['error']));
                if ($gagal !== []) {
                    throw new \RuntimeException('blokir di Moodle gagal dibuka: ' . reset($gagal)['error']);
                }
            }
        }

        $jumlah = $db->table('peserta')->countAllResults();
        $db->transStart();
        $db->table('absensi')->emptyTable();
        $db->table('blokir')->emptyTable();
        $db->table('peserta')->emptyTable();
        if ($ruang) {
            $db->table('admins')->where('role', 'proktor')->where('ruang_id IS NOT NULL')->delete();
            $db->table('ruang')->emptyTable();
            $db->table('sesi')->emptyTable();
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new \RuntimeException('database gagal dikosongkan.');
        }

        return $jumlah;
    }

    /** Template tunggal (format AIOCBT + Mapel 1..n), berisi dua baris contoh. */
    public function template()
    {
        $mapel  = $this->daftarMapelMoodle();
        $contoh = array_slice(array_column($mapel, 'shortname'), 0, 3);
        $rows   = [
            array_merge(['Contoh Siswa Satu', 'X-1', 'X-1', '1', '1', '', '', ''], $contoh),
            array_merge(['Contoh Siswa Dua', 'X-2', 'X-2', '2', '1', '', '', ''], $contoh),
        ];

        return $this->response->download('template_peserta_cbtadmin.xlsx', Excel::templatePeserta($rows, $mapel));
    }

    /** Export dengan format template yang sama, jadi bisa diubah lalu diimpor kembali. */
    public function export()
    {
        $rows = [];
        foreach ($this->pesertaRows((string) $this->request->getGet('kelas'), (string) $this->request->getGet('ruang')) as $p) {
            $rows[] = array_merge(
                [$p['nama'], $p['kelas'], $p['kelas'], (string) $p['ruang_nama'], (string) $p['sesi_nama'], $p['username'], (string) $p['password_plain'], $p['nomor_peserta']],
                array_values(array_filter(array_map('trim', explode(',', (string) $p['mapel']))))
            );
        }
        ActivityLog::add('export_peserta', count($rows) . ' baris');

        return $this->response->download('peserta_' . date('Ymd_His') . '.xlsx', Excel::templatePeserta($rows, $this->daftarMapelMoodle()));
    }

    /** Shortname course Moodle (tanpa halaman depan situs) untuk lembar "Daftar Mapel"; [] bila Moodle tak terbaca. */
    private function daftarMapelMoodle(): array
    {
        try {
            $rows = Moodle::db()->query(
                'SELECT c.shortname, c.fullname AS nama, cc.name AS tes FROM ' . Moodle::t('course') . ' c
                   LEFT JOIN ' . Moodle::t('course_categories') . ' cc ON cc.id = c.category
                  WHERE c.id > 1 ORDER BY cc.name, c.shortname'
            )->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Daftar mapel template: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn ($r) => ['shortname' => (string) $r['shortname'], 'nama' => (string) $r['nama'], 'tes' => (string) $r['tes']], $rows);
    }

    public function import()
    {
        $file = $this->request->getFile('berkas');
        if (! $file || ! $file->isValid() || ! in_array(strtolower($file->getClientExtension()), ['xlsx', 'xls', 'csv'], true)) {
            return redirect()->back()->with('error', 'Pilih berkas Excel (.xlsx, .xls) atau .csv yang valid.');
        }

        try {
            $rows = array_map(static fn (array $row) => self::normalizeImportRow($row), Excel::read($file->getTempName(), ['nama', 'nama_peserta', 'firstname']));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Berkas tidak bisa dibaca: ' . $e->getMessage());
        }
        if ($rows === [] || ! array_key_exists('nama', $rows[0])) {
            return redirect()->back()->with('error', 'Kolom "Nama" / "Nama Peserta" tidak ditemukan. Pakai template CBT Admin atau template AIOCBT.');
        }

        // "Hapus data lama": hanya bila berkas berisi peserta, supaya berkas salah tidak mengosongkan data.
        $dihapus = 0;
        if ($this->request->getPost('ganti') === '1') {
            if (array_filter($rows, static fn ($r) => ($r['nama'] ?? '') !== '') === []) {
                return redirect()->back()->with('error', 'Berkas tidak berisi peserta; data lama tidak dihapus.');
            }
            try {
                $dihapus = $this->hapusSemua();
            } catch (\Throwable $e) {
                return redirect()->back()->with('error', 'Import dibatalkan, data lama tetap ada: ' . $e->getMessage());
            }
            ActivityLog::add('reset_peserta', "$dihapus peserta dihapus sebelum import");
        }

        $db    = db_connect();
        $maps  = [
            'ruang' => array_change_key_case(array_column($db->table('ruang')->get()->getResultArray(), 'id', 'nama')),
            'sesi'  => array_change_key_case(array_column($db->table('sesi')->get()->getResultArray(), 'id', 'nama')),
        ];
        $now   = date('Y-m-d H:i:s');
        $baru  = $ubah = 0;
        $gagal = [];
        $taken = [];  // username yang sudah dipakai baris lain di berkas ini

        foreach ($rows as $i => $row) {
            $line     = $i + 2;
            $username = strtolower($row['username'] ?? '');
            if ($username === '' && ($row['nama'] ?? '') !== '') {
                // Username boleh kosong (seperti template AIOCBT): pakai milik peserta yang sama, atau buat baru.
                $username = $this->autoUsername($row['nama'], $row['kelas'] ?? '', $taken);
            }
            $taken[$username] = true;
            if (($row['nama'] ?? '') === '' || ! preg_match(self::USERNAME_RE, $username)) {
                $gagal[] = $line;

                continue;
            }

            $data = [
                'nama'          => mb_substr($row['nama'], 0, 150),
                'kelas'         => mb_substr($row['kelas'] ?? '', 0, 60),
                'username'      => $username,
                'updated_at'    => $now,
            ];
            foreach (['ruang', 'sesi'] as $table) {
                $name = $row[$table] ?? '';
                if ($name === '') {
                    continue;
                }
                $key = strtolower($name);
                if (! isset($maps[$table][$key])) {
                    $db->table($table)->insert(['nama' => mb_substr($name, 0, 60)]);
                    $maps[$table][$key] = $db->insertID();
                }
                $data[$table . '_id'] = (int) $maps[$table][$key];
            }
            if (($row['password'] ?? '') !== '') {
                $data['password_plain'] = mb_substr($row['password'], 0, 100);
            }
            // Mapel (kolom "mapel", "mapel_1".., atau "course1".. seperti CSV upload user Moodle).
            // Berkas tanpa kolom mapel tidak mengubah mapel yang sudah tersimpan.
            $mapel = self::mapelDariBaris($row);
            if ($mapel !== null) {
                $data['mapel'] = self::mapelTeks($mapel);
            }

            $old = $db->table('peserta')->where('username', $username)->get()->getRowArray();
            // No. Peserta dari berkas; bila tidak ada (template AIOCBT), pakai username.
            // Nomor yang sudah ada tidak ditimpa username.
            $nomor = mb_substr(trim((string) ($row['nomor_peserta'] ?? '')), 0, 40);
            if ($nomor !== '') {
                $data['nomor_peserta'] = $nomor;
            } elseif (! $old || (string) $old['nomor_peserta'] === '') {
                $data['nomor_peserta'] = $username;
            }
            if ($old) {
                $db->table('peserta')->where('id', $old['id'])->update($data);
                $ubah++;
            } else {
                $data['password_plain'] ??= self::randomPassword();
                $data['created_at']       = $now;
                $db->table('peserta')->insert($data);
                $baru++;
            }
        }
        // Kolom email (CSV Moodle, mis. 01-26-0001@smada.id): bila semua memakai satu domain, akun Moodle yang
        // dibuat panel ikut memakai domain itu (setting email_domain).
        $domains = array_unique(array_filter(array_map(static fn ($r) => strtolower(substr(strrchr((string) ($r['email'] ?? ''), '@') ?: '', 1)), $rows)));
        if (count($domains) === 1 && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', reset($domains))) {
            Settings::set('email_domain', reset($domains));
        }
        // Satu akun pengawas untuk setiap ruang (ruang baru dari import ini ikut dibuatkan).
        $pengawas = Pengawas::sync();
        ActivityLog::add('import_peserta', "$baru baru, $ubah diperbarui, " . count($gagal) . " gagal, $pengawas akun pengawas");

        $message = ($dihapus > 0 ? "$dihapus data lama dihapus. " : '') . "$baru peserta baru, $ubah diperbarui.";
        if ($pengawas > 0) {
            $message .= " $pengawas akun pengawas dibuat (lihat menu Pengawas).";
        }
        if ($gagal !== []) {
            $message .= ' ' . count($gagal) . ' baris dilewati karena nama kosong atau username tidak valid (baris ' . implode(', ', array_slice($gagal, 0, 15)) . (count($gagal) > 15 ? ', …' : '') . ').';
        }

        return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with($gagal === [] ? 'success' : 'error', $message);
    }

    // ------------------------------------------------------------------ Data Moodle

    public function moodle(): string
    {
        $rows  = [];
        $error = false;

        try {
            $rows = $this->moodleUsers();
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Data Moodle: ' . $e->getMessage());
        }

        $db     = db_connect();
        $linked = array_column($db->table('peserta')->select('username')->get()->getResultArray(), 'username');

        return $this->render('peserta/moodle', [
            'title'       => 'Daftar Peserta · Data Moodle',
            'rows'        => $rows,
            'moodleError' => $error,
            'linked'      => array_flip($linked),
            'pending'     => $db->table('peserta')->where('moodle_userid', null)->countAllResults(),
        ]);
    }

    /** Salin user Moodle yang belum ada di Data CBTAdmin, dan tautkan yang sudah ada. */
    public function sync()
    {
        try {
            $users = $this->moodleUsers();
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Database Moodle tidak bisa dibaca.');
        }

        $db   = db_connect();
        $have = array_column($db->table('peserta')->select('id, username, moodle_userid')->get()->getResultArray(), null, 'username');
        $now  = date('Y-m-d H:i:s');
        $baru = $taut = 0;

        foreach ($users as $u) {
            if (isset($have[$u['username']])) {
                if ((int) $have[$u['username']]['moodle_userid'] !== (int) $u['id']) {
                    $db->table('peserta')->where('id', $have[$u['username']]['id'])->update(['moodle_userid' => $u['id']]);
                    $taut++;
                }

                continue;
            }
            $kelas = trim(explode(',', (string) $u['cohort'])[0]);
            $last  = trim((string) $u['lastname']);
            $db->table('peserta')->insert([
                'nama'          => mb_substr(trim($u['firstname'] . (in_array($last, ['', '-', '.', $kelas], true) ? '' : ' ' . $last)), 0, 150),
                'kelas'         => mb_substr($kelas, 0, 60),
                'username'      => $u['username'],
                'moodle_userid' => $u['id'],
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $baru++;
        }
        ActivityLog::add('sinkron_moodle', "$baru baru, $taut ditautkan");

        return redirect()->back()->with('success', "$baru user Moodle disalin ke Data CBTAdmin, $taut ditautkan. Password user yang disalin tidak diketahui panel; reset dulu bila perlu dicetak di kartu.");
    }

    /**
     * Sinkron ke Moodle: (1) buat akun Moodle peserta yang belum punya, (2) daftarkan setiap peserta ke mapel
     * di kolom "mapel". Dikerjakan bertahap ±20 detik per permintaan (batas waktu server); bila masih ada sisa,
     * halaman kemajuan mengirim ulang otomatis sampai selesai. Mapel yang shortname-nya tidak ada di Moodle dilaporkan.
     */
    public function createMoodle()
    {
        @set_time_limit(120);
        $db     = db_connect();
        $mulai  = microtime(true);
        $batas  = 20.0;
        $hasil  = (array) (session()->get('sinkron_moodle') ?? []);
        if ($this->request->getPost('lanjut') !== '1') {
            $hasil = ['dibuat' => 0, 'ditautkan' => 0, 'daftar' => 0, 'gagal' => [], 'tidak_ada' => []];
        }

        // Tahap 1: akun Moodle.
        $domain = Settings::get('email_domain', 'cbt.local');
        while (microtime(true) - $mulai < $batas) {
            $pending = $db->table('peserta')->where('moodle_userid', null)->whereNotIn('username', array_keys($hasil['gagal']) ?: [''])
                ->orderBy('id')->limit(40)->get()->getResultArray();
            if ($pending === []) {
                break;
            }
            $users = [];
            foreach ($pending as $p) {
                if ((string) $p['password_plain'] === '') {
                    $p['password_plain'] = self::randomPassword();
                    $db->table('peserta')->where('id', $p['id'])->update(['password_plain' => $p['password_plain']]);
                }
                $users[] = [
                    'username'  => $p['username'],
                    'password'  => $p['password_plain'],
                    'firstname' => $p['nama'],
                    'lastname'  => $p['kelas'] !== '' ? $p['kelas'] : '-',
                    'email'     => str_contains($p['username'], '@') ? $p['username'] : $p['username'] . '@' . $domain,
                    'cohort'    => $p['kelas'],
                ];
            }
            try {
                $items = MoodleBridge::call('create_users', ['users' => $users]);
            } catch (\Throwable $e) {
                return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with('error', 'Gagal membuat user Moodle: ' . $e->getMessage());
            }
            foreach ($items as $item) {
                if (isset($item['error']) || empty($item['id'])) {
                    $hasil['gagal'][$item['username']] = $item['error'] ?? 'tanpa keterangan';

                    continue;
                }
                $db->table('peserta')->where('username', $item['username'])->update(['moodle_userid' => $item['id']]);
                $item['status'] === 'dibuat' ? $hasil['dibuat']++ : $hasil['ditautkan']++;
            }
        }

        // Tahap 2: pendaftaran mapel yang belum ada (dihitung ulang tiap putaran dari database Moodle).
        $sisaDaftar = 0;
        if (microtime(true) - $mulai < $batas) {
            try {
                [$kurang, $tidakAda] = $this->mapelBelumTerdaftar();
            } catch (\Throwable $e) {
                return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with('error', 'Database Moodle tidak bisa dibaca: ' . $e->getMessage());
            }
            $hasil['tidak_ada'] = $tidakAda;
            foreach (array_chunk($kurang, 60, true) as $potong) {
                if (microtime(true) - $mulai >= $batas) {
                    break;
                }
                $users = [];
                foreach ($potong as $uid => $courses) {
                    $users[] = ['userid' => $uid, 'courses' => $courses];
                }
                try {
                    $items = MoodleBridge::call('enrol_users', ['users' => $users]);
                } catch (\Throwable $e) {
                    return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with('error', 'Gagal mendaftarkan mapel: ' . $e->getMessage());
                }
                foreach ($items as $item) {
                    $hasil['daftar'] += (int) ($item['baru'] ?? 0);
                    if (isset($item['error'])) {
                        $hasil['gagal']['user#' . $item['userid']] = $item['error'];
                    }
                }
                foreach (array_keys($potong) as $uid) {
                    unset($kurang[$uid]);
                }
            }
            $sisaDaftar = array_sum(array_map('count', $kurang));
        }

        $sisaAkun = $db->table('peserta')->where('moodle_userid', null)->whereNotIn('username', array_keys($hasil['gagal']) ?: [''])->countAllResults();
        session()->set('sinkron_moodle', $hasil);
        $macet = $hasil['gagal'] !== [] && $sisaAkun + $sisaDaftar > 0 && microtime(true) - $mulai < $batas;
        if (($sisaAkun > 0 || $sisaDaftar > 0) && ! $macet) {
            // Belum selesai: halaman kemajuan yang mengirim ulang permintaan ini secara otomatis.
            return view('peserta/sinkron_proses', ['hasil' => $hasil, 'sisaAkun' => $sisaAkun, 'sisaDaftar' => $sisaDaftar]);
        }

        session()->remove('sinkron_moodle');
        ActivityLog::add('sinkron_ke_moodle', "{$hasil['dibuat']} akun dibuat, {$hasil['ditautkan']} ditautkan, {$hasil['daftar']} pendaftaran mapel, " . count($hasil['gagal']) . ' gagal');
        $message = "Sinkron ke Moodle selesai: {$hasil['dibuat']} akun dibuat, {$hasil['ditautkan']} akun lama ditautkan (password disamakan), {$hasil['daftar']} pendaftaran mapel baru.";
        if ($hasil['tidak_ada'] !== []) {
            $message .= ' Mapel tidak ditemukan di Moodle (periksa shortname): ' . implode(', ', array_slice(array_keys($hasil['tidak_ada']), 0, 10))
                . (count($hasil['tidak_ada']) > 10 ? ', …' : '') . '.';
        }
        if ($hasil['gagal'] !== []) {
            $daftar = [];
            foreach (array_slice($hasil['gagal'], 0, 5, true) as $u => $err) {
                $daftar[] = "$u ($err)";
            }
            $message .= ' Gagal: ' . implode('; ', $daftar) . (count($hasil['gagal']) > 5 ? '; …' : '');
        }

        return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with($hasil['gagal'] === [] && $hasil['tidak_ada'] === [] ? 'success' : 'error', $message);
    }

    /**
     * Pendaftaran mapel yang belum ada di Moodle: [moodle_userid => [shortname, ...]] untuk mapel yang course-nya ada,
     * dan [shortname => jumlah siswa] untuk mapel yang tidak ditemukan.
     */
    private function mapelBelumTerdaftar(): array
    {
        $peserta = db_connect()->table('peserta')->select('moodle_userid, mapel')
            ->where('moodle_userid IS NOT NULL', null, false)->where('mapel IS NOT NULL', null, false)->where('mapel !=', '')->get()->getResultArray();
        if ($peserta === []) {
            return [[], []];
        }
        $m       = Moodle::db();
        $courses = [];
        foreach ($m->table(Moodle::t('course'))->select('shortname')->where('id >', 1)->get()->getResultArray() as $c) {
            $courses[strtolower($c['shortname'])] = $c['shortname'];
        }
        $sudah = [];
        foreach (array_chunk(array_map('intval', array_column($peserta, 'moodle_userid')), 500) as $ids) {
            $rows = $m->query(
                'SELECT ue.userid, c.shortname FROM ' . Moodle::t('user_enrolments') . ' ue
                   JOIN ' . Moodle::t('enrol') . ' e ON e.id = ue.enrolid JOIN ' . Moodle::t('course') . ' c ON c.id = e.courseid
                  WHERE ue.userid IN ?',
                [$ids]
            )->getResultArray();
            foreach ($rows as $r) {
                $sudah[$r['userid'] . '|' . strtolower($r['shortname'])] = true;
            }
        }

        $kurang = $tidakAda = [];
        foreach ($peserta as $p) {
            foreach (explode(',', (string) $p['mapel']) as $sn) {
                $key = strtolower(trim($sn));
                if ($key === '') {
                    continue;
                }
                if (! isset($courses[$key])) {
                    $tidakAda[trim($sn)] = ($tidakAda[trim($sn)] ?? 0) + 1;
                } elseif (! isset($sudah[$p['moodle_userid'] . '|' . $key])) {
                    $kurang[(int) $p['moodle_userid']][] = $courses[$key];
                }
            }
        }

        return [$kurang, $tidakAda];
    }

    public function resetPassword()
    {
        $userId   = (int) $this->request->getPost('userid');
        $password = trim((string) $this->request->getPost('password'));
        $password = $password !== '' ? mb_substr($password, 0, 100) : self::randomPassword();

        try {
            $this->resetInMoodle($userId, $password);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Reset password gagal: ' . $e->getMessage());
        }

        db_connect()->table('peserta')->where('moodle_userid', $userId)->update(['password_plain' => $password, 'updated_at' => date('Y-m-d H:i:s')]);
        $username = (string) $this->request->getPost('username');
        ActivityLog::add('reset_password', $username . ' (id ' . $userId . ')');

        return redirect()->back()->with('success', 'Password ' . $username . ' sekarang: ' . $password);
    }

    // ------------------------------------------------------------------ Bantuan

    private function resetInMoodle(int $userId, string $password): void
    {
        $items = MoodleBridge::call('reset_passwords', ['users' => [['id' => $userId, 'password' => $password]]]);
        if (isset($items[0]['error'])) {
            throw new \RuntimeException($items[0]['error']);
        }
    }

    private function pesertaRows(string $kelas, string $ruangId): array
    {
        $builder = db_connect()->table('peserta p')
            ->select('p.*, r.nama AS ruang_nama, s.nama AS sesi_nama')
            ->join('ruang r', 'r.id = p.ruang_id', 'left')
            ->join('sesi s', 's.id = p.sesi_id', 'left');
        if ($kelas !== '') {
            $builder->where('p.kelas', $kelas);
        }
        $tingkat = Tingkat::dariRequest();
        if ($tingkat !== '') {
            $builder->where('p.kelas REGEXP ' . db_connect()->escape(Tingkat::regexp($tingkat)), null, false);
        }
        if ($ruangId === '0') {
            $builder->where('p.ruang_id', null);
        } elseif ($ruangId !== '') {
            $builder->where('p.ruang_id', (int) $ruangId);
        }

        return $builder->orderBy('p.kelas')->orderBy('p.nama')->get()->getResultArray();
    }

    private function moodleUsers(): array
    {
        $user    = Moodle::t('user');
        $members = Moodle::t('cohort_members');
        $cohort  = Moodle::t('cohort');

        return Moodle::db()->query(
            "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.lastaccess, u.suspended,
                    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                       FROM {$members} cm JOIN {$cohort} c ON c.id = cm.cohortid
                      WHERE cm.userid = u.id) AS cohort
               FROM {$user} u
              WHERE u.deleted = 0 AND u.id NOT IN ?
              ORDER BY u.username",
            [Moodle::nonParticipantIds()]
        )->getResultArray();
    }

    /**
     * Password berbentuk "Kmtr-482": memenuhi kebijakan password bawaan Moodle
     * (8 karakter, huruf besar, huruf kecil, angka, simbol) dan tetap mudah diketik.
     * Karakter yang mudah tertukar (0/O, 1/l/I) tidak dipakai.
     */
    /**
     * Daftar mapel dari satu baris impor: kolom mapel / mapel_1 / mata_pelajaran / course1..course14
     * (format CSV upload user Moodle). Isi sel boleh berisi beberapa mapel dipisah koma.
     * null = berkas tidak punya kolom mapel sama sekali.
     */
    private static function mapelDariBaris(array $row): ?array
    {
        $ada  = false;
        $list = [];
        foreach ($row as $key => $value) {
            if (preg_match('/^(mapel|mata_pelajaran|course)(_?\d+)?$/', (string) $key)) {
                $ada  = true;
                $list = array_merge($list, preg_split('/[,;]+/', (string) $value));
            }
        }

        return $ada ? $list : null;
    }

    /** Rapikan daftar mapel menjadi "A, B, C" (tanpa kosong/duplikat, urutan tetap); null bila kosong. */
    public static function mapelTeks(array $list): ?string
    {
        $hasil = [];
        foreach ($list as $m) {
            $m = trim(preg_replace('/\s+/', ' ', (string) $m));
            if ($m !== '' && ! isset($hasil[strtolower($m)])) {
                $hasil[strtolower($m)] = mb_substr($m, 0, 100);
            }
        }

        return $hasil === [] ? null : implode(', ', $hasil);
    }

    /** Samakan nama kolom template AIOCBT dengan kolom CBT Admin. */
    private static function normalizeImportRow(array $row): array
    {
        $alias = [
            // CSV upload user Moodle: firstname = nama, lastname/department = kelas.
            'firstname'    => 'nama',
            'lastname'     => 'kelas',
            'department'   => 'kelas',
            'address'      => 'ruang',   // CSV Moodle hasil AIOCBT: kolom address berisi nomor ruang
            'nama_peserta' => 'nama',
            'nama_rombel'  => 'kelas',
            'rombel'       => 'kelas',
            'kel_sesi'     => 'sesi',
            'kelompok_sesi' => 'sesi',
            'no_peserta'   => 'nomor_peserta',
            'nomor'        => 'nomor_peserta',
        ];
        foreach ($alias as $from => $to) {
            if (isset($row[$from]) && ($row[$to] ?? '') === '') {
                $row[$to] = $row[$from];
            }
        }
        // Rombel kosong: pakai kolom "Kelas / Jurusan".
        if (($row['kelas'] ?? '') === '' && ($row['kelas_jurusan'] ?? '') !== '') {
            $row['kelas'] = $row['kelas_jurusan'];
        }

        return $row;
    }

    /**
     * Username untuk baris tanpa username: milik peserta dengan nama dan kelas yang sama
     * (supaya import ulang tidak membuat peserta ganda), atau "peserta0001" dst.
     * Dua siswa bernama sama di berkas yang sama tetap mendapat username berbeda.
     */
    private function autoUsername(string $nama, string $kelas, array $taken): string
    {
        $db = db_connect();
        foreach ($db->table('peserta')->select('username')->where('nama', $nama)->where('kelas', $kelas)->get()->getResultArray() as $old) {
            if (! isset($taken[$old['username']])) {
                return $old['username'];
            }
        }
        $max = 0;
        foreach ($db->table('peserta')->select('username')->like('username', 'peserta', 'after')->get()->getResultArray() as $r) {
            if (preg_match('/^peserta(\d+)$/', $r['username'], $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('peserta%04d', $max + 1);
    }

    public static function randomPassword(): string
    {
        $pick = static function (string $chars, int $n): string {
            $out = '';
            for ($i = 0; $i < $n; $i++) {
                $out .= $chars[random_int(0, strlen($chars) - 1)];
            }

            return $out;
        };

        return $pick('ABCDEFGHJKMNPQRSTUVWXYZ', 1) . $pick('abcdefghjkmnpqrstuvwxyz', 3) . '-' . $pick('23456789', 3);
    }
}
