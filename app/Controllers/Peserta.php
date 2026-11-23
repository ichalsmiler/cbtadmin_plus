<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Excel;
use App\Libraries\MapelPilihan;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\Pengawas;
use App\Libraries\Settings;
use App\Libraries\Tingkat;

class Peserta extends PanelController
{
    private const USERNAME_RE = '/^[a-z0-9._@-]{2,100}$/';
    private const COLUMNS     = ['nomor_peserta', 'nama', 'kelas', 'username', 'password', 'ruang', 'sesi'];

    // ------------------------------------------------------------------ Pengawas

    /** Daftar peserta di ruang pengawas (baca saja); admin bisa memilih ruang lewat ?ruang=. */
    public function ruang(): string
    {
        $ruangId = $this->myRuang() !== null ? (string) $this->myRuang() : (string) $this->request->getGet('ruang');
        $ruang   = $ruangId !== '' ? db_connect()->table('ruang')->where('id', (int) $ruangId)->get()->getRowArray() : null;

        return $this->render('peserta/ruang', [
            'title'     => 'Daftar Peserta' . ($ruang ? ' · Ruang ' . $ruang['nama'] : ''),
            'rows'      => $ruangId !== '' ? $this->pesertaRows('', $ruangId) : [],
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
            'title'       => 'Daftar Peserta CBTAdmin',
            'rows'        => $this->pesertaRows($kelas, $ruangId, $this->tingkatData()),
            'kelasList'   => array_column($db->table('peserta')->distinct()->select('kelas')->where('kelas !=', '')->orderBy('kelas')->get()->getResultArray(), 'kelas'),
            'ruangList'   => $this->ruangList(),
            'sesiList'    => $db->table('sesi')->orderBy('nama')->get()->getResultArray(),
            'filterKelas' => $kelas,
            'filterRuang' => $ruangId,
            'tingkat'     => $this->tingkatData(),
        ]);
    }

    /**
     * Tingkatan untuk Data CBTAdmin dan ekspornya: bawaan Semua setiap halaman dibuka (tidak memakai pilihan
     * yang diingat menu lain); ?tingkat= tetap dipakai dan diingat.
     */
    private function tingkatData(): string
    {
        return $this->request->getGet('tingkat') === null ? '' : Tingkat::dariRequest();
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
            $db->table('admins')->whereIn('role', ['proktor', 'demo'])->where('ruang_id IS NOT NULL')->delete();
            $db->table('ruang')->emptyTable();
            $db->table('sesi')->emptyTable();
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new \RuntimeException('database gagal dikosongkan.');
        }

        return $jumlah;
    }

    public function template()
    {
        $data = Excel::build(self::COLUMNS, [
            ['0001', 'Contoh Siswa', 'XII IPA 1', 'siswa0001', '', 'Ruang 1', 'Sesi 1'],
        ]);

        return $this->response->download('template_peserta.xlsx', $data);
    }

    public function import()
    {
        $file = $this->request->getFile('berkas');
        if (! $file || ! $file->isValid() || ! in_array(strtolower($file->getClientExtension()), ['xlsx', 'xls', 'csv'], true)) {
            return redirect()->back()->with('error', 'Pilih berkas Excel (.xlsx, .xls) atau .csv yang valid.');
        }

        try {
            $rows = array_map(static fn (array $row) => self::normalizeImportRow($row), Excel::read($file->getTempName(), ['nama', 'nama_peserta']));
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

        ['baru' => $baru, 'ubah' => $ubah, 'gagal' => $gagal, 'pengawas' => $pengawas] = $this->simpanBaris($rows, 'berkas');

        $message = ($dihapus > 0 ? "$dihapus data lama dihapus. " : '') . "$baru peserta baru, $ubah diperbarui.";
        if ($pengawas > 0) {
            $message .= " $pengawas akun pengawas dibuat (lihat menu Pengawas).";
        }
        if ($gagal !== []) {
            $message .= ' ' . count($gagal) . ' baris dilewati karena nama kosong atau username tidak valid (baris ' . implode(', ', array_slice($gagal, 0, 15)) . (count($gagal) > 15 ? ', …' : '') . ').';
        }

        return redirect()->to(site_url('daftar_peserta/cbtadmin'))->with($gagal === [] ? 'success' : 'error', $message);
    }

    /**
     * Terima data peserta hasil Konvert User (tombol "Kirim ke Data CBTAdmin", POST JSON + header CSRF).
     * Sama dengan Import Excel: username yang sudah ada diperbarui, yang baru ditambahkan, ruang/sesi dibuat.
     */
    public function imporJson()
    {
        @set_time_limit(300);
        $in    = $this->request->getJSON(true) ?? [];
        $balas = static fn (array $data, int $status = 200) => service('response')->setStatusCode($status)->setJSON($data + ['csrf' => csrf_hash()]);
        $rows  = [];
        foreach ((array) ($in['peserta'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => trim((string) (is_scalar($v) ? $v : '')), array_intersect_key($p, array_flip(['nama', 'kelas', 'ruang', 'sesi', 'username', 'password', 'nomor_peserta'])));
        }
        if ($rows === [] || count($rows) > 5000) {
            return $balas(['ok' => false, 'pesan' => 'Tidak ada peserta untuk dikirim (maksimal 5.000 baris).'], 400);
        }
        try {
            ['baru' => $baru, 'ubah' => $ubah, 'gagal' => $gagal, 'pengawas' => $pengawas] = $this->simpanBaris($rows, 'Konvert User');
        } catch (\Throwable $e) {
            return $balas(['ok' => false, 'pesan' => 'Gagal menyimpan peserta: ' . $e->getMessage()], 500);
        }
        $pesan = "$baru peserta baru, $ubah diperbarui di Data CBTAdmin.";
        if ($pengawas > 0) {
            $pesan .= " $pengawas akun pengawas ruang dibuat.";
        }
        if ($gagal !== []) {
            $pesan .= ' ' . count($gagal) . ' baris dilewati karena nama kosong atau username tidak valid (baris ' . implode(', ', array_slice($gagal, 0, 15)) . (count($gagal) > 15 ? ', …' : '') . ').';
        }

        return $balas(['ok' => $gagal === [], 'pesan' => $pesan, 'baru' => $baru, 'ubah' => $ubah, 'gagal' => count($gagal)]);
    }

    /**
     * Kirim siswa dari Konvert User → User Moodle langsung ke Moodle (dipanggil bertahap, maks 100 siswa per
     * permintaan): buat akun (yang sudah ada: password & profil disamakan), lalu daftarkan ke mapel (course
     * shortname) sebagai siswa. moodle_userid Data CBTAdmin ikut diisi bila username-nya ada.
     */
    public function kirimMoodleJson()
    {
        @set_time_limit(300);
        $in    = $this->request->getJSON(true) ?? [];
        $balas = static fn (array $data, int $status = 200) => service('response')->setStatusCode($status)->setJSON($data + ['csrf' => csrf_hash()]);
        $users = [];
        foreach ((array) ($in['users'] ?? []) as $u) {
            if (! is_array($u) || trim((string) ($u['username'] ?? '')) === '') {
                continue;
            }
            $teks = static fn (string $k, int $max) => mb_substr(trim((string) (is_scalar($u[$k] ?? null) ? $u[$k] : '')), 0, $max);
            $users[] = [
                'username'   => strtolower($teks('username', 100)),
                'password'   => $teks('password', 100),
                'firstname'  => $teks('firstname', 100),
                'lastname'   => $teks('lastname', 100),
                'email'      => $teks('email', 100),
                'department' => $teks('department', 255),
                'address'    => $teks('address', 255),
                // Satu atau beberapa cohort (per kelas dan/atau per mapel), maks 60 nama.
                'cohort'     => array_slice(array_values(array_unique(array_filter(array_map(static fn ($c) => mb_substr(trim((string) $c), 0, 254), array_filter((array) ($u['cohort'] ?? []), 'is_scalar')), 'strlen'))), 0, 60),
                'course'     => array_values(array_filter(array_map(static fn ($c) => mb_substr(trim((string) $c), 0, 255), array_filter((array) ($u['course'] ?? []), 'is_scalar')))),
            ];
        }
        if ($users === [] || count($users) > 100) {
            return $balas(['ok' => false, 'pesan' => 'Kirim 1–100 siswa per permintaan.'], 400);
        }

        try {
            $akun = MoodleBridge::call('create_users', ['users' => array_map(static fn ($u) => array_diff_key($u, ['course' => 1]), $users)]);
        } catch (\Throwable $e) {
            return $balas(['ok' => false, 'pesan' => 'Gagal membuat akun Moodle: ' . $e->getMessage()], 500);
        }
        $hasil = ['dibuat' => 0, 'diperbarui' => 0, 'gagal' => [], 'enrol_baru' => 0, 'enrol_sudah' => 0, 'tidak_ada' => []];
        $enrol = [];
        $db    = db_connect();
        foreach ($akun as $i => $a) {
            if (isset($a['error']) || empty($a['id'])) {
                $hasil['gagal'][] = $users[$i]['username'] . ': ' . ($a['error'] ?? 'gagal');

                continue;
            }
            $hasil[$a['status'] === 'dibuat' ? 'dibuat' : 'diperbarui']++;
            $db->table('peserta')->where('username', $users[$i]['username'])->update(['moodle_userid' => (int) $a['id']]);
            if ($users[$i]['course'] !== []) {
                $enrol[] = ['userid' => (int) $a['id'], 'courses' => $users[$i]['course']];
            }
        }
        if ($enrol !== []) {
            try {
                foreach (MoodleBridge::call('enrol_users', ['users' => $enrol]) as $j => $e) {
                    if (isset($e['error'])) {
                        $hasil['gagal'][] = 'id ' . $enrol[$j]['userid'] . ': ' . $e['error'];
                    }
                    $hasil['enrol_baru']  += (int) ($e['baru'] ?? 0);
                    $hasil['enrol_sudah'] += (int) ($e['sudah'] ?? 0);
                    foreach ((array) ($e['tidak_ada'] ?? []) as $sn) {
                        $hasil['tidak_ada'][$sn] = true;
                    }
                }
            } catch (\Throwable $e) {
                return $balas(['ok' => false, 'pesan' => 'Akun dibuat, tetapi pendaftaran mapel gagal: ' . $e->getMessage()] + $hasil, 500);
            }
        }
        $hasil['tidak_ada'] = array_keys($hasil['tidak_ada']);
        ActivityLog::add('kirim_moodle', count($users) . " siswa: {$hasil['dibuat']} dibuat, {$hasil['diperbarui']} diperbarui, {$hasil['enrol_baru']} pendaftaran mapel baru, " . count($hasil['gagal']) . ' gagal');

        return $balas(['ok' => $hasil['gagal'] === [], 'pesan' => ''] + $hasil);
    }

    /**
     * Simpan baris peserta (kunci nama, kelas, ruang, sesi, username, password, nomor_peserta) ke Data CBTAdmin.
     *
     * @return array{baru: int, ubah: int, gagal: list<int>, pengawas: int}
     */
    private function simpanBaris(array $rows, string $sumber): array
    {
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
        // Satu akun pengawas untuk setiap ruang (ruang baru dari import ini ikut dibuatkan).
        $pengawas = Pengawas::sync();
        ActivityLog::add('import_peserta', "dari $sumber: $baru baru, $ubah diperbarui, " . count($gagal) . " gagal, $pengawas akun pengawas");

        return ['baru' => $baru, 'ubah' => $ubah, 'gagal' => $gagal, 'pengawas' => $pengawas];
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

        // Kelas user Moodle = department (dari Konvert/CSV), atau last name (konvensi "nama - kelas").
        foreach ($rows as &$u) {
            $u['kelas'] = trim((string) ($u['department'] ?? '')) !== '' ? trim((string) $u['department']) : trim((string) $u['lastname']);
        }
        unset($u);
        $kelasList = array_values(array_unique(array_filter(array_column($rows, 'kelas'), static fn ($k) => Tingkat::of($k) !== '')));
        natsort($kelasList);
        // Filter Tingkatan & Kelas, bawaan Semua setiap halaman dibuka (sama dengan Data CBTAdmin).
        $tingkat = $this->tingkatData();
        $kelas   = trim((string) $this->request->getGet('kelas'));
        $semua   = count($rows);
        $rows    = array_values(array_filter($rows, static fn ($u) => Tingkat::cocok($u['kelas'], $tingkat) && ($kelas === '' || $u['kelas'] === $kelas)));

        $db     = db_connect();
        $linked = array_column($db->table('peserta')->select('username')->get()->getResultArray(), 'username');

        // Mapel pilihan (moving) XI/XII: daftar course per tingkat dan yang sudah diikuti tiap siswa.
        $pilihan      = array_fill_keys(MapelPilihan::TINGKAT, []);
        $pilihanSiswa = [];
        if (! $error) {
            try {
                $pilihan      = MapelPilihan::courses();
                $pilihanSiswa = MapelPilihan::terdaftar(array_merge(...array_map('array_keys', array_values($pilihan))));
            } catch (\Throwable $e) {
                log_message('error', 'Mapel pilihan: ' . $e->getMessage());
            }
        }

        return $this->render('peserta/moodle', [
            'title'       => 'Daftar Peserta Moodle',
            'rows'        => $rows,
            'semua'       => $semua,
            'tingkat'     => $tingkat,
            'filterKelas' => $kelas,
            'kelasList'   => array_values($kelasList),
            'moodleError' => $error,
            'linked'      => array_flip($linked),
            'pilihan'     => $pilihan,
            'pilihanSiswa' => $pilihanSiswa,
        ]);
    }

    /**
     * Atur mapel pilihan (moving) satu siswa XI/XII: course pilihan tingkatnya yang dicentang didaftarkan
     * (bridge enrol_users), yang tidak dicentang dikeluarkan (bridge unenrol_users). Mapel yang sudah ada
     * attempt siswa ini tidak dikeluarkan supaya jawaban dan nilainya tetap ada.
     */
    public function mapelPilihan()
    {
        $back   = site_url('daftar_peserta/moodle') . (($q = (string) $this->request->getPost('kembali')) !== '' ? '?' . $q : '');
        $userid = (int) $this->request->getPost('userid');
        $pilih  = array_map('intval', (array) $this->request->getPost('course'));
        try {
            $u = $userid > 0 && ! in_array($userid, Moodle::nonParticipantIds(), true)
                ? Moodle::db()->table(Moodle::t('user'))->select('id, username, firstname, lastname, department')->where('id', $userid)->where('deleted', 0)->get()->getRowArray()
                : null;
            if (! $u) {
                return redirect()->to($back)->with('error', 'Siswa tidak ditemukan di Moodle.');
            }
            $kelas   = trim((string) $u['department']) !== '' ? trim((string) $u['department']) : trim((string) $u['lastname']);
            $tingkat = Tingkat::of($kelas);
            $courses = MapelPilihan::courses()[$tingkat] ?? null;
            if ($courses === null) {
                return redirect()->to($back)->with('error', 'Mapel pilihan hanya untuk siswa kelas XI dan XII.');
            }
            $sekarang = array_values(array_intersect(MapelPilihan::terdaftar(array_keys($courses))[$userid] ?? [], array_keys($courses)));
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        $pilih  = array_values(array_intersect($pilih, array_keys($courses)));
        $tambah = array_map(static fn ($id) => $courses[$id]['shortname'], array_diff($pilih, $sekarang));
        $hapus  = array_map(static fn ($id) => $courses[$id]['shortname'], array_diff($sekarang, $pilih));
        $nama   = trim($u['firstname'] . ' ' . $u['lastname']);
        if ($tambah === [] && $hapus === []) {
            return redirect()->to($back)->with('success', 'Mapel pilihan ' . $nama . ' tidak berubah.');
        }
        $gagal = [];
        try {
            if ($hapus !== []) {
                $h = MoodleBridge::call('unenrol_users', ['users' => [['userid' => $userid, 'courses' => array_values($hapus)]]])[0] ?? [];
                if (isset($h['error'])) {
                    $gagal[] = $h['error'];
                }
                if (($h['ada_attempt'] ?? []) !== []) {
                    $gagal[] = 'tidak dikeluarkan karena sudah ada jawaban: ' . implode(', ', $h['ada_attempt']);
                    $hapus   = array_diff($hapus, $h['ada_attempt']);
                }
            }
            if ($tambah !== []) {
                $t = MoodleBridge::call('enrol_users', ['users' => [['userid' => $userid, 'courses' => array_values($tambah)]]])[0] ?? [];
                if (isset($t['error'])) {
                    $gagal[] = $t['error'];
                }
            }
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal mengubah mapel pilihan: ' . $e->getMessage());
        }
        $ringkas = trim(($tambah !== [] ? 'ditambah ' . implode(', ', $tambah) : '') . ($tambah !== [] && $hapus !== [] ? '; ' : '') . ($hapus !== [] ? 'dikeluarkan dari ' . implode(', ', $hapus) : ''));
        ActivityLog::add('mapel_pilihan', $u['username'] . ': ' . ($ringkas !== '' ? $ringkas : 'tidak berubah'));
        if ($gagal !== []) {
            return redirect()->to($back)->with('error', 'Mapel pilihan ' . $nama . ($ringkas !== '' ? ' ' . $ringkas : '') . '. Sebagian gagal: ' . implode('; ', $gagal) . '.');
        }

        return redirect()->to($back)->with('success', 'Mapel pilihan ' . $nama . ' ' . $ringkas . '.');
    }

    /**
     * Tambah satu user langsung ke Moodle (Daftar Peserta Moodle → Tambah user): buat akun, daftarkan ke mapel
     * (opsional), dan simpan juga ke Data CBTAdmin bila dicentang. Username yang sudah ada di Moodle ditolak
     * (pakai Reset password) supaya akun lain tidak tertimpa.
     */
    public function tambahMoodle()
    {
        $back     = site_url('daftar_peserta/moodle');
        $username = strtolower(trim((string) $this->request->getPost('username')));
        $nama     = mb_substr(trim((string) $this->request->getPost('nama')), 0, 100);
        $kelas    = mb_substr(trim((string) $this->request->getPost('kelas')), 0, 60);
        $ruang    = mb_substr(trim((string) $this->request->getPost('ruang')), 0, 60);
        $password = mb_substr(trim((string) $this->request->getPost('password')), 0, 100);
        $email    = mb_substr(trim((string) $this->request->getPost('email')), 0, 100);
        $mapel    = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', (string) $this->request->getPost('mapel'))))));
        if ($nama === '' || ! preg_match(self::USERNAME_RE, $username)) {
            return redirect()->to($back)->withInput()->with('error', 'Nama wajib diisi; username 2–100 karakter huruf kecil, angka, titik, strip, @ atau garis bawah.');
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->to($back)->withInput()->with('error', 'Format email tidak valid.');
        }
        try {
            $ada = Moodle::db()->table(Moodle::t('user'))->where('username', $username)->where('deleted', 0)->countAllResults();
        } catch (\Throwable $e) {
            return redirect()->to($back)->withInput()->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        if ($ada > 0) {
            return redirect()->to($back)->withInput()->with('error', 'Username "' . $username . '" sudah ada di Moodle. Pakai tombol Reset password di barisnya.');
        }
        $password = $password !== '' ? $password : self::randomPassword();
        $email    = $email !== '' ? $email : (str_contains($username, '@') ? $username : $username . '@' . Settings::get('email_domain', 'cbt.local'));

        try {
            $item = MoodleBridge::call('create_users', ['users' => [[
                'username' => $username, 'password' => $password, 'firstname' => $nama, 'lastname' => $kelas !== '' ? $kelas : '-',
                'email' => $email, 'department' => $kelas, 'address' => $ruang, 'cohort' => $kelas,
            ]]])[0] ?? [];
        } catch (\Throwable $e) {
            return redirect()->to($back)->withInput()->with('error', 'Gagal membuat user Moodle: ' . $e->getMessage());
        }
        if (isset($item['error']) || empty($item['id'])) {
            return redirect()->to($back)->withInput()->with('error', 'Gagal membuat user Moodle: ' . ($item['error'] ?? 'tanpa keterangan'));
        }
        $userId = (int) $item['id'];

        $pesan = "User $username dibuat di Moodle dengan password $password.";
        if ($mapel !== []) {
            try {
                $e = MoodleBridge::call('enrol_users', ['users' => [['userid' => $userId, 'courses' => $mapel]]])[0] ?? [];
                $pesan .= ' Didaftarkan ke ' . (int) ($e['baru'] ?? 0) . ' mapel.';
                if (! empty($e['tidak_ada'])) {
                    $pesan .= ' Mapel tidak ditemukan: ' . implode(', ', $e['tidak_ada']) . '.';
                }
                if (isset($e['error'])) {
                    $pesan .= ' Pendaftaran mapel gagal: ' . $e['error'] . '.';
                }
            } catch (\Throwable $ex) {
                $pesan .= ' Pendaftaran mapel gagal: ' . $ex->getMessage() . '.';
            }
        }

        if ($this->request->getPost('simpan_data') === '1') {
            $db   = db_connect();
            $data = ['nama' => mb_substr($nama, 0, 150), 'kelas' => $kelas, 'password_plain' => $password, 'moodle_userid' => $userId, 'updated_at' => date('Y-m-d H:i:s')];
            if ($ruang !== '') {
                $r = $db->table('ruang')->where('nama', $ruang)->get()->getRowArray();
                if (! $r) {
                    $db->table('ruang')->insert(['nama' => $ruang]);
                    $data['ruang_id'] = $db->insertID();
                    Pengawas::sync();
                } else {
                    $data['ruang_id'] = (int) $r['id'];
                }
            }
            if ($db->table('peserta')->where('username', $username)->countAllResults() > 0) {
                $db->table('peserta')->where('username', $username)->update($data);
            } else {
                $db->table('peserta')->insert($data + ['username' => $username, 'nomor_peserta' => $username, 'created_at' => date('Y-m-d H:i:s')]);
            }
            $pesan .= ' Disimpan juga di Data CBTAdmin.';
        }
        ActivityLog::add('tambah_user_moodle', $username . ($mapel !== [] ? ' (' . count($mapel) . ' mapel)' : ''));

        return redirect()->to($back)->with('success', $pesan);
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

    private function pesertaRows(string $kelas, string $ruangId, ?string $tingkat = null): array
    {
        $builder = db_connect()->table('peserta p')
            ->select('p.*, r.nama AS ruang_nama, s.nama AS sesi_nama')
            ->join('ruang r', 'r.id = p.ruang_id', 'left')
            ->join('sesi s', 's.id = p.sesi_id', 'left');
        if ($kelas !== '') {
            $builder->where('p.kelas', $kelas);
        }
        $tingkat ??= Tingkat::dariRequest();
        if ($tingkat !== '') {
            $builder->where('p.kelas REGEXP ' . db_connect()->escape(Tingkat::regexp($tingkat)), null, false);
        }
        if ($ruangId === '0') {
            $builder->where('p.ruang_id', null);
        } elseif ($ruangId !== '') {
            $builder->where('p.ruang_id', (int) $ruangId);
        }

        // Urut No. Peserta dari yang terkecil (sama dengan urutan bawaan tabel).
        return $builder->orderBy('p.nomor_peserta')->orderBy('p.nama')->get()->getResultArray();
    }

    private function moodleUsers(): array
    {
        $user    = Moodle::t('user');
        $members = Moodle::t('cohort_members');
        $cohort  = Moodle::t('cohort');

        return Moodle::db()->query(
            "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.department, u.lastaccess, u.suspended,
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
    /** Samakan nama kolom template AIOCBT dengan kolom CBT Admin. */
    private static function normalizeImportRow(array $row): array
    {
        $alias = [
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
