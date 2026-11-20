<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Excel;
use App\Libraries\LogUjian;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\School;
use App\Libraries\Settings;
use Config\Session as SessionConfig;

/**
 * Tools (seperti AIOCBT):
 *  - Moodle Tools  : Daftar Test / Mapel, Cron Task, Purge / Delete Caches, Anti Submit
 *  - Info Sekolah (tools/cbtadmin): Info Sekolah, Info Kegiatan, Ganti Password (Lock login dkk. di Moodle Tools)
 *  - Panel (tools/lainnya): akun panel, pengaturan panel, backup, sesi, log aktivitas
 */
class Tools extends PanelController
{
    /** Kunci pengaturan per bagian formulir. */
    private const BAGIAN = [
        'sekolah'  => ['nama_sekolah', 'npsn', 'jabatan_pimpinan', 'kepala_sekolah', 'jenis_nomor_pimpinan', 'nip_kepala'],
        // Jam ujian per tingkatan pindah ke Admin Tools → Sesi (SesiTingkat).
        'kegiatan' => ['nama_kegiatan', 'tahun_ajaran', 'catatan_kartu'],
        'panel'    => ['webmin_url', 'refresh_interval', 'active_minutes'],
    ];

    /** Aturan ujian (plugin Moodle local_cbtujian). */
    private const UJIAN = ['lock_login' => 'locklogin', 'resume_timer' => 'resumetimer', 'anti_newtab' => 'antinewtab'];

    private const USERNAME_RE = '/^[A-Za-z0-9._-]{3,60}$/';

    /** Submenu Tools: satu halaman per grup, berisi semua bagiannya (id anchor = bagian). */
    public const GRUP = [
        'moodle'   => ['Moodle Tools', ['cron', 'cache', 'antisubmit', 'lockres']],
        'cbtadmin' => ['Info Sekolah', ['sekolah', 'kegiatan', 'password']],
        'lainnya'  => ['Panel', ['akun', 'panel', 'utilitas', 'log']],
    ];

    /** Alamat halaman grup yang memuat bagian itu, mis. halaman('sekolah') → tools/cbtadmin#sekolah. */
    public static function halaman(string $bagian): string
    {
        foreach (self::GRUP as $grup => [, $bagianList]) {
            if (in_array($bagian, $bagianList, true)) {
                return site_url('tools/' . $grup) . '#' . $bagian;
            }
        }

        return site_url('tools/moodle');
    }

    /** Alamat lama /tools → halaman Moodle Tools (anchor lama dipetakan JS di halaman grup). */
    public function awal()
    {
        return redirect()->to(site_url('tools/moodle'));
    }

    public function index(string $grup = 'moodle')
    {
        if (! isset(self::GRUP[$grup])) {
            return redirect()->to(site_url('tools/moodle'));
        }
        $db = db_connect();
        // Log pengawas (jam absen) dan siswa (jam mulai ujian) per tanggal; bawaan hari ini.
        $logTgl = $this->logTanggal();
        $log    = ['siswa' => [], 'login' => [], 'belum' => []];
        foreach (['siswa' => 'siswa', 'login' => 'loginSiswa', 'belum' => 'belumMulai'] as $k => $fn) {
            try {
                $log[$k] = LogUjian::$fn($logTgl);
            } catch (\Throwable $e) {
                log_message('error', 'Log ' . $k . ': ' . $e->getMessage());
            }
        }

        return $this->render('tools/index', [
            'logTgl'      => $logTgl,
            'logTab'      => in_array($this->request->getGet('log_tab'), self::LOG_TAB, true) ? $this->request->getGet('log_tab') : 'panel',
            'logTelat'    => $this->logTelat(),
            'logPengawas' => LogUjian::pengawas($logTgl),
            'logTindakan' => LogUjian::tindakanPengawas($logTgl),
            'logSiswa'    => $log['siswa'],
            'logLogin'    => $log['login'],
            'logBelum'    => $log['belum'],
            'title'   => self::GRUP[$grup][0],
            'grup'    => $grup,
            'values'  => Settings::all(),
            'hasLogo' => School::logoPath() !== null,
            'hasTtd'  => School::ttdPath() !== null,
            'hasLogo2' => School::logo2Path() !== null,
            'hasLogoLogin' => School::logoLoginPath() !== null,
            'admins'  => $db->table('admins')->select('id, username, nama, role, aktif, last_login')->orderBy('role')->orderBy('username')->get()->getResultArray(),
            'meId'    => (int) session()->get('admin_id'),
            'logs'    => $db->table('activity_log')->orderBy('id', 'DESC')->limit(300)->get()->getResultArray(),
        ]);
    }

    // ------------------------------------------------------------------ Log pengawas / siswa

    private const LOG_TAB = ['pengawas', 'siswa', 'login', 'belum'];

    private function logTanggal(): string
    {
        $t = (string) $this->request->getGet('log_tgl');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && strtotime($t) ? $t : date('Y-m-d');
    }

    /** Batas "telat mulai" (menit setelah jam ujian tingkatan). */
    private function logTelat(): int
    {
        $v = $this->request->getGet('telat');

        return $v === null || $v === '' ? 15 : max(0, min(240, (int) $v));
    }

    /** Jejak satu siswa pada satu tanggal (login, mulai, submit, absensi, tindakan pengawas). */
    public function jejak(): string
    {
        $username = trim((string) $this->request->getGet('u'));
        $tanggal  = $this->logTanggal();
        $data     = ['peserta' => null, 'items' => []];
        $error    = false;
        if ($username !== '') {
            try {
                $data = LogUjian::jejak($username, $tanggal);
            } catch (\Throwable $e) {
                $error = true;
                log_message('error', 'Jejak siswa: ' . $e->getMessage());
            }
        }

        return $this->render('tools/jejak', $data + [
            'title' => 'Jejak Siswa', 'username' => $username, 'tanggal' => $tanggal, 'error' => $error,
        ]);
    }

    /** Ekspor Excel tab log: pengawas, tindakan, siswa, login, belum. */
    public function logExport()
    {
        $jenis = (string) $this->request->getGet('jenis');
        $tgl   = $this->logTanggal();
        $jam   = static fn (?string $dt) => $dt ? substr($dt, 11, 8) : '';
        $wkt   = static fn (?int $ts) => $ts ? date('H:i:s', $ts) : '';

        switch ($jenis) {
            case 'pengawas':
                $headers = ['ruang', 'pengawas', 'akun', 'login_panel', 'ip_login', 'jam_absen', 'terakhir_diubah', 'jumlah_simpan', 'hasil'];
                $rows    = array_map(static fn ($r) => [
                    $r['ruang'], $r['pengawas'], $r['oleh'] !== '' ? $r['oleh'] : $r['akun'], $jam($r['login']['jam'] ?? null), $r['login']['ip'] ?? '',
                    $r['pertama'] ? $jam($r['pertama']) : 'Belum absen', $r['kali'] > 1 ? $jam($r['terakhir']) : '', $r['kali'], $r['detail'],
                ], LogUjian::pengawas($tgl));
                break;
            case 'tindakan':
                $headers = ['waktu', 'akun', 'nama', 'ruang', 'aksi', 'detail', 'ip'];
                $rows    = array_map(static fn ($r) => [$jam($r['waktu']), $r['akun'], $r['nama'], $r['ruang'], $r['aksi'], $r['detail'], $r['ip']], LogUjian::tindakanPengawas($tgl));
                break;
            case 'siswa':
                $headers = ['jam_mulai', 'username', 'nama', 'kelas', 'ruang', 'mapel', 'ujian', 'telat_menit', 'selesai', 'status'];
                $rows    = array_map(static fn ($s) => [
                    $wkt($s['mulai']), $s['username'], $s['nama'], $s['kelas'], $s['ruang'], $s['mapel'], $s['ujian'],
                    $s['telat'] !== null && $s['telat'] > 0 ? $s['telat'] : '', $wkt($s['selesai']), $s['state'] === 'inprogress' ? 'Mengerjakan' : ($s['selesai'] ? 'Selesai' : $s['state']),
                ], LogUjian::siswa($tgl));
                break;
            case 'login':
                $headers = ['username', 'nama', 'kelas', 'ruang', 'login_pertama', 'login_terakhir', 'jumlah_login', 'ip', 'logout_terakhir'];
                $rows    = array_map(static fn ($s) => [
                    $s['username'], $s['nama'], $s['kelas'], $s['ruang'], $wkt($s['pertama']), $wkt($s['terakhir']), $s['kali'], implode(', ', $s['ips']), $wkt($s['logout']),
                ], LogUjian::loginSiswa($tgl));
                break;
            case 'belum':
                $headers = ['username', 'nama', 'kelas', 'ruang', 'diabsen_oleh', 'jam_absen', 'sudah_login'];
                $rows    = array_map(static fn ($s) => [$s['username'], $s['nama'], $s['kelas'], $s['ruang'], $s['dicatat_oleh'], $s['jam_absen'], $s['login'] ? 'Ya' : 'Belum'], LogUjian::belumMulai($tgl));
                break;
            default:
                return redirect()->to(self::halaman('log'));
        }
        ActivityLog::add('export_log', $jenis . ' ' . $tgl);

        return $this->response->download('log_' . $jenis . '_' . $tgl . '.xlsx', Excel::build($headers, $rows));
    }

    // ------------------------------------------------------------------ Info Sekolah / Info Kegiatan / pengaturan panel

    public function save()
    {
        $bagian = (string) $this->request->getPost('bagian');
        if (! isset(self::BAGIAN[$bagian])) {
            return redirect()->to(site_url('tools/moodle'))->with('error', 'Bagian pengaturan tidak dikenal.');
        }
        foreach (self::BAGIAN[$bagian] as $key) {
            $value = trim((string) $this->request->getPost($key));
            if (in_array($key, ['refresh_interval', 'active_minutes'], true) && $value !== '') {
                $value = (string) max(1, (int) $value);
            }
            Settings::set($key, mb_substr($value, 0, 255));
        }

        $pesan = ['sekolah' => 'Info sekolah disimpan.', 'kegiatan' => 'Info kegiatan disimpan.', 'panel' => 'Pengaturan panel disimpan.'][$bagian];
        if ($bagian === 'sekolah') {
            foreach (['logo' => 'Logo sekolah', 'logo2' => 'Logo kanan kartu', 'ttd' => 'Tanda tangan', 'logologin' => 'Logo halaman login ujian'] as $jenis => $label) {
                $hasil = $this->unggahGambar($jenis);
                if ($hasil === false) {
                    return redirect()->to(self::halaman('sekolah'))->with('error', 'Info sekolah disimpan, tetapi ' . strtolower($label) . ' ditolak: harus PNG atau JPG, maksimal 1 MB.');
                }
                if ($hasil !== null) {
                    $pesan .= ' ' . $label . ' ' . $hasil . '.';
                }
                // Logo login dipasang juga ke Moodle (Appearance → Logos → Logo) lewat jembatan.
                if ($jenis === 'logologin' && $hasil !== null) {
                    try {
                        MoodleBridge::call('login_logo', $hasil === 'dihapus' ? ['hapus' => true] : ['path' => (string) School::logoLoginPath()]);
                        $pesan .= $hasil === 'dihapus' ? ' Logo dihapus dari halaman login Moodle.' : ' Logo dipasang di halaman login Moodle.';
                    } catch (\Throwable $e) {
                        return redirect()->to(self::halaman('sekolah'))->with('error', 'Logo login tersimpan di panel, tetapi gagal dipasang di Moodle: ' . $e->getMessage());
                    }
                }
            }
        }
        ActivityLog::add('ubah_pengaturan', $bagian);

        return redirect()->to(self::halaman($bagian))->with('success', $pesan);
    }

    /** null = tidak ada perubahan, string = keterangan, false = berkas ditolak. */
    private function unggahGambar(string $jenis): string|false|null
    {
        $file = $this->request->getFile($jenis);
        if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            $type = $file->isValid() ? $file->getMimeType() : '';
            if (! in_array($type, ['image/png', 'image/jpeg'], true) || $file->getSize() > 1024 * 1024) {
                return false;
            }
            School::hapusGambar($jenis);
            $name = $jenis . '.' . ($type === 'image/png' ? 'png' : 'jpg');
            $file->move(School::UPLOAD_DIR, $name, true);
            Settings::set($jenis . '_file', $name);

            return 'disimpan';
        }
        if ($this->request->getPost('hapus_' . $jenis)) {
            School::hapusGambar($jenis);

            return 'dihapus';
        }

        return null;
    }

    /** Pratinjau logo / tanda tangan di halaman Tools. */
    public function gambar(string $jenis)
    {
        $path = match ($jenis) { 'ttd' => School::ttdPath(), 'logo2' => School::logo2Path(), 'logologin' => School::logoLoginPath(), default => School::logoPath() };
        if ($path === null) {
            return $this->response->setStatusCode(404);
        }

        return $this->response->setHeader('Content-Type', mime_content_type($path))
            ->setHeader('Cache-Control', 'no-cache')
            ->setBody((string) file_get_contents($path));
    }

    // ------------------------------------------------------------------ Aturan ujian (plugin Moodle local_cbtujian)

    /** Anti Submit (menit) atau Lock login / Resume timer / Anti Newtab. */
    public function ujian()
    {
        if ($this->request->getPost('bagian') === 'antisubmit') {
            $menit = max(0, min(1000, (int) $this->request->getPost('anti_submit')));
            Settings::set('anti_submit', (string) $menit);
            $back = self::halaman('antisubmit');
        } else {
            foreach (array_keys(self::UJIAN) as $key) {
                Settings::set($key, $this->request->getPost($key) ? '1' : '0');
            }
            $back = self::halaman('lockres');
        }

        try {
            $this->kirimAturanUjian();
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Disimpan di panel, tetapi gagal diterapkan di Moodle: ' . $e->getMessage());
        }
        ActivityLog::add('aturan_ujian', 'anti submit ' . Settings::get('anti_submit', '0') . ' menit; '
            . implode(', ', array_map(static fn ($k) => $k . '=' . Settings::get($k, '0'), array_keys(self::UJIAN))));

        return redirect()->to($back)->with('success', 'Aturan ujian disimpan dan diterapkan di Moodle.');
    }

    /** Samakan setelan plugin Moodle dengan pengaturan panel. */
    private function kirimAturanUjian(): void
    {
        $menit = (int) Settings::get('anti_submit', '0');
        $data  = ['antisubmit' => $menit >= 1000 ? 0 : $menit];
        foreach (self::UJIAN as $key => $plugin) {
            $data[$plugin] = Settings::get($key, '0') === '1' ? 1 : 0;
        }
        $hasil = MoodleBridge::call('cbtujian_config', $data)[0] ?? [];
        if (empty($hasil['plugin'])) {
            throw new \RuntimeException('plugin local_cbtujian belum terpasang di Moodle (jalankan deploy/setelan-moodle.sh).');
        }
    }

    // ------------------------------------------------------------------ Ganti Password

    public function gantiPassword()
    {
        $password = (string) $this->request->getPost('password');
        if (mb_strlen($password) < 8) {
            return redirect()->to(self::halaman('password'))->with('error', 'Password minimal 8 karakter.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db_connect()->table('admins')->where('id', (int) session()->get('admin_id'))
            ->update(['password_hash' => $hash, 'password_plain' => null]);
        // Sesi lain akun ini putus (Guard), sesi ini tetap.
        session()->set('cred', \App\Filters\Guard::cap($hash));
        ActivityLog::add('ganti_password');

        return redirect()->to(self::halaman('password'))->with('success', 'Password Anda diganti. Catat password baru supaya tidak lupa.');
    }

    // ------------------------------------------------------------------ Cron dan cache Moodle

    public function cron()
    {
        try {
            [$ok, $akhir] = MoodleBridge::cron();
        } catch (\Throwable $e) {
            return redirect()->to(self::halaman('cron'))->with('error', 'Cron gagal: ' . $e->getMessage());
        }
        ActivityLog::add('cron_moodle', $ok ? 'selesai' : 'gagal');

        return redirect()->to(self::halaman('cron'))->with($ok ? 'success' : 'error', ($ok ? 'Cron task selesai.' : 'Cron task berhenti dengan galat.') . ($akhir !== '' ? ' ' . $akhir : ''));
    }

    public function cache()
    {
        $hapus = $this->request->getPost('jenis') === 'delete';
        try {
            MoodleBridge::call('purge_caches', ['hapus' => $hapus]);
        } catch (\Throwable $e) {
            return redirect()->to(self::halaman('cache'))->with('error', 'Gagal membersihkan cache: ' . $e->getMessage());
        }
        ActivityLog::add('cache_moodle', $hapus ? 'delete' : 'purge');

        return redirect()->to(self::halaman('cache'))->with('success', $hapus ? 'Cache Moodle dihapus dan dibangun ulang.' : 'Cache Moodle dibersihkan (purge).');
    }

    // ------------------------------------------------------------------ Daftar Test / Mapel

    public function testManage(): string
    {
        $kategori = [];
        $mapel    = [];
        $katId    = (int) $this->request->getGet('kat');
        $error    = false;
        try {
            $kategori = array_values(array_filter(Moodle::categories(), static fn ($c) => (int) $c['parent'] === 0));
            if ($katId > 0) {
                $mapel = Moodle::coursesInCategory($katId);
                // Jumlah attempt per mapel, ditampilkan di dialog Hapus mapel.
                $ids     = array_map('intval', array_column($mapel, 'id'));
                $attempt = $ids === [] ? [] : array_column(Moodle::db()->query(
                    'SELECT q.course, COUNT(*) AS n FROM ' . Moodle::t('quiz_attempts') . ' a JOIN ' . Moodle::t('quiz')
                    . ' q ON q.id = a.quiz WHERE q.course IN (' . implode(',', $ids) . ') GROUP BY q.course'
                )->getResultArray(), 'n', 'course');
                foreach ($mapel as &$c) {
                    $c['attempt'] = (int) ($attempt[$c['id']] ?? 0);
                }
                unset($c);
            }
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Kelola Test: ' . $e->getMessage());
        }
        $aktif = null;
        foreach ($kategori as $k) {
            if ((int) $k['id'] === $katId) {
                $aktif = $k;
            }
        }

        $jumlah = null;
        try {
            $m      = Moodle::db();
            $admins = array_filter(array_map('intval', explode(',', (string) ($m->table(Moodle::t('config'))->select('value')->where('name', 'siteadmins')->get()->getRowArray()['value'] ?? ''))));
            $jumlah = [
                'user'     => $m->table(Moodle::t('user'))->where('deleted', 0)->where('username !=', 'guest')->whereNotIn('id', $admins ?: [0])->countAllResults(),
                'mapel'    => $m->table(Moodle::t('course'))->where('id >', 1)->countAllResults(),
                'kategori' => $m->table(Moodle::t('course_categories'))->countAllResults(),
                'log'      => $m->table(Moodle::t('logstore_standard_log'))->countAllResults(),
            ];
        } catch (\Throwable $e) {
            log_message('error', 'Jumlah reset: ' . $e->getMessage());
        }

        return $this->render('tools/test_manage', [
            'title'    => 'Kategori Mapel',
            'durasi'   => (int) \App\Libraries\Settings::get('durasi_menit', '90'),
            'jumlah'   => $jumlah,
            'kategori' => $kategori,
            'aktif'    => $aktif,
            'mapel'    => $mapel,
            'error'    => $error,
            'moodle'   => rtrim(Moodle::url(), '/'),
        ]);
    }

    public function tambahUjian()
    {
        $nama = trim((string) $this->request->getPost('nama'));
        try {
            $id = (int) (MoodleBridge::call('create_category', ['name' => mb_substr($nama, 0, 255)])[0]['id'] ?? 0);
        } catch (\Throwable $e) {
            return redirect()->to(site_url('tools/test_manage'))->with('error', 'Gagal menambah ujian: ' . $e->getMessage());
        }
        ActivityLog::add('tambah_ujian', $nama);

        return redirect()->to(site_url('tools/test_manage') . '?kat=' . $id)->with('success', 'Ujian "' . $nama . '" ditambahkan.');
    }

    /** Buat kategori hari ujian sekaligus: awalan + nomor, mis. H1..H8 (jumlah 8, mulai 1). */
    public function tambahHari()
    {
        $jumlah = (int) $this->request->getPost('jumlah');
        $mulai  = max(1, (int) ($this->request->getPost('mulai') ?: 1));
        $awalan = trim((string) $this->request->getPost('awalan'));
        $awalan = $awalan === '' ? 'H' : mb_substr($awalan, 0, 50);
        $back   = site_url('tools/test_manage');
        if ($jumlah < 1 || $jumlah > 50) {
            return redirect()->to($back)->with('error', 'Isi jumlah hari ujian antara 1 dan 50.');
        }
        $target = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $target[] = $awalan . ($mulai + $i);
        }

        try {
            $rencana = self::rencanaHari(array_values(array_filter(Moodle::categories(), static fn ($c) => (int) $c['parent'] === 0 && ! \App\Libraries\ActiveTest::isNonaktif($c['name']))), $target, $awalan, $mulai);
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Hari ujian tidak dibuat: ' . $e->getMessage());
        }
        try {
            if ($rencana['ganti'] !== []) {
                MoodleBridge::call('rename_categories', ['renames' => array_map(static fn ($g) => ['id' => $g['id'], 'name' => $g['baru']], $rencana['ganti'])]);
            }
            if ($rencana['buat'] !== []) {
                MoodleBridge::call('create_categories', ['names' => $rencana['buat']]);
            }
            // Kategori tanpa ujian SESI NONAKTIF selalu ada bersama hari-hari ujian (dibuat bila belum ada).
            \App\Libraries\ActiveTest::kategoriNonaktif();
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal membuat hari ujian: ' . $e->getMessage());
        }

        $bagian = [];
        if ($rencana['ganti'] !== []) {
            $bagian[] = 'diganti namanya: ' . implode(', ', array_map(static fn ($g) => $g['lama'] . ' → ' . $g['baru'], $rencana['ganti']));
        }
        if ($rencana['buat'] !== []) {
            $bagian[] = 'dibuat baru: ' . implode(', ', $rencana['buat']);
        }
        ActivityLog::add('tambah_ujian', 'hari ujian ' . implode(' – ', $target) . '; ' . ($bagian === [] ? 'sudah lengkap' : implode('; ', $bagian)));

        return redirect()->to($back)->with('success', 'Hari ujian ' . reset($target) . '–' . end($target) . ' siap. '
            . ($bagian === [] ? 'Semua sudah ada, tidak ada perubahan.' : ucfirst(implode('. ', $bagian)) . '.'));
    }

    /**
     * Dari Konvert User (Jadwal → Mapel, di dalam panel): daftar mapel JSON {awalan, mapel: [{category, shortname, fullname}]}
     * langsung dibuat di Moodle: hari N masuk ke kategori bernama awalan+N (bukan id). Balasan JSON + token CSRF baru.
     */
    public function mapelJson()
    {
        @set_time_limit(300);
        $in    = $this->request->getJSON(true) ?? [];
        $rows  = array_values(array_filter((array) ($in['mapel'] ?? []), 'is_array'));
        $balas = static fn (array $data, int $status = 200) => service('response')->setStatusCode($status)
            ->setJSON($data + ['csrf' => csrf_hash()]);
        if ($rows === [] || count($rows) > 500) {
            return $balas(['ok' => false, 'pesan' => 'Tidak ada mapel untuk dibuat (maksimal 500 baris).'], 400);
        }
        try {
            return $balas($this->buatMapel($rows, trim((string) ($in['awalan'] ?? '')) ?: 'H', 'Konvert User'));
        } catch (\Throwable $e) {
            return $balas(['ok' => false, 'pesan' => $e->getMessage()], 500);
        }
    }

    /**
     * Inti impor mapel: kolom category = NOMOR HARI → kategori bernama awalan+N (bukan id); category_path/kategori = nama.
     *
     * @return array{ok: bool, pesan: string, dibuat: int, ada: int, gagal: int, tanpa_kategori: list<string>}
     */
    private function buatMapel(array $rows, string $awalan, string $sumber): array
    {
        try {
            $kategori = [];
            foreach (Moodle::categories() as $c) {
                if ((int) $c['parent'] === 0) {
                    $kategori[mb_strtolower(trim($c['name']))] = $c;
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database Moodle tidak bisa dibaca.');
        }

        $courses = $tanpaKategori = [];
        foreach ($rows as $r) {
            $shortname = trim((string) ($r['shortname'] ?? ''));
            if ($shortname === '') {
                continue;
            }
            $sebut = trim((string) ($r['category_path'] ?? $r['kategori'] ?? $r['category'] ?? ''));
            $nama  = preg_match('/^\d+$/', $sebut) ? $awalan . (int) $sebut : $sebut;
            $kat   = $kategori[mb_strtolower($nama)] ?? null;
            if ($kat === null) {
                $tanpaKategori[$nama !== '' ? $nama : '(kosong)'][] = $shortname;

                continue;
            }
            $courses[] = ['category' => (int) $kat['id'], 'fullname' => trim((string) ($r['fullname'] ?? '')), 'shortname' => $shortname, 'kat' => $kat['name']];
        }

        $dibuat = $ada = $gagal = [];
        $urutan = [];  // kategori => [courseid] sesuai urutan kiriman (urutan sesi dari Konvert)
        foreach (array_chunk($courses, 25) as $potong) {
            try {
                $items = MoodleBridge::call('create_courses', ['courses' => array_map(static fn ($c) => array_diff_key($c, ['kat' => 1]), $potong), 'timelimit' => self::durasiDetik()]);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Gagal membuat mapel: ' . $e->getMessage() . ($dibuat !== [] ? ' (' . array_sum($dibuat) . ' mapel sudah sempat dibuat)' : ''));
            }
            foreach ($items as $i => $item) {
                $kat = $potong[$i]['kat'];
                if (isset($item['id'], $item['category']) && ! isset($item['error'])) {
                    $urutan[(int) $item['category']][] = (int) $item['id'];
                }
                if (isset($item['error'])) {
                    $gagal[] = $item['shortname'] . ' (' . $item['error'] . ')';
                } elseif ($item['status'] === 'dibuat') {
                    $dibuat[$kat] = ($dibuat[$kat] ?? 0) + 1;
                } else {
                    $ada[] = $item['shortname'];
                }
            }
        }
        // Course baru masuk di urutan paling atas kategori; susun ulang supaya urutannya sama dengan kiriman.
        $tersusun = true;
        if ($urutan !== []) {
            try {
                MoodleBridge::call('sort_courses', ['urutan' => $urutan]);
            } catch (\Throwable $e) {
                $tersusun = false;
                log_message('error', 'Urutkan mapel: ' . $e->getMessage());
            }
        }
        $total = array_sum($dibuat);
        ActivityLog::add('impor_mapel', "dari $sumber: $total mapel dibuat, " . count($ada) . ' sudah ada, ' . count($gagal) . ' gagal, ' . count($tanpaKategori) . ' kategori tidak ditemukan');

        $pesan = $total . ' mapel dibuat' . ($dibuat !== [] ? ' (' . implode(', ', array_map(static fn ($k, $n) => "$k: $n", array_keys($dibuat), $dibuat)) . ')' : '') . '.';
        if ($ada !== []) {
            $pesan .= ' ' . count($ada) . ' shortname sudah ada di Moodle, dilewati.';
        }
        if ($tanpaKategori !== []) {
            $pesan .= ' Kategori tidak ditemukan: ' . implode(', ', array_map(static fn ($k, $v) => "$k (" . count($v) . ' mapel)', array_keys($tanpaKategori), $tanpaKategori))
                . '. Buat dulu lewat "Jumlah hari", lalu kirim ulang dari Konvert User.';
        }
        if ($gagal !== []) {
            $pesan .= ' Gagal: ' . implode('; ', array_slice($gagal, 0, 5)) . (count($gagal) > 5 ? '; …' : '') . '.';
        }
        $pesan .= $tersusun ? ' Urutan mapel di setiap hari disusun menurut sesi.' : ' Urutan mapel di Moodle gagal disusun ulang.';

        return [
            'ok'             => $tanpaKategori === [] && $gagal === [],
            'pesan'          => $pesan,
            'dibuat'         => $total,
            'ada'            => count($ada),
            'gagal'          => count($gagal),
            'tanpa_kategori' => array_keys($tanpaKategori),
        ];
    }

    /**
     * Rencana "Jumlah hari": pakai kategori tingkat atas yang sudah ada dulu, urut id, lalu buat kekurangannya.
     * Kategori bernama awalan+nomor di bawah "mulai" (mis. H1-H4 saat mulai 5) dibiarkan. Kategori sisa
     * (lebih banyak dari jumlah hari) dibiarkan, kecuali namanya akan sama dengan salah satu hasil: dibatalkan.
     *
     * @return array{ganti: list<array{id:int, lama:string, baru:string}>, buat: list<string>}
     */
    private static function rencanaHari(array $kategori, array $target, string $awalan, int $mulai): array
    {
        usort($kategori, static fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        $pola  = '/^' . preg_quote($awalan, '/') . '(\d+)$/i';
        $pakai = [];
        foreach ($kategori as $k) {
            if (! (preg_match($pola, trim($k['name']), $m) && (int) $m[1] < $mulai)) {
                $pakai[] = $k;
            }
        }

        $ganti = $buat = [];
        foreach ($target as $i => $nama) {
            if (isset($pakai[$i])) {
                if ($pakai[$i]['name'] !== $nama) {
                    $ganti[] = ['id' => (int) $pakai[$i]['id'], 'lama' => (string) $pakai[$i]['name'], 'baru' => $nama];
                }
            } else {
                $buat[] = $nama;
            }
        }
        $hasil = array_map('strtolower', $target);
        foreach (array_slice($pakai, count($target)) as $sisa) {
            if (in_array(strtolower(trim($sisa['name'])), $hasil, true)) {
                throw new \RuntimeException('kategori "' . $sisa['name'] . '" (id ' . $sisa['id'] . ') akan bernama sama dengan hasil. Tambah jumlah hari atau ganti nama kategori itu dulu.');
            }
        }

        return ['ganti' => $ganti, 'buat' => $buat];
    }

    /**
     * Hapus kategori (ujian) beserta semua mapel, soal, attempt, dan nilainya di Moodle.
     * Kategori berisi mapel wajib dikonfirmasi dengan mengetik namanya; tes aktif (Setting Ujian) tidak bisa dihapus.
     */
    public function hapusUjian()
    {
        $id   = (int) $this->request->getPost('id');
        $back = site_url('tools/test_manage');
        $kat  = null;
        try {
            foreach (Moodle::categories() as $c) {
                if ((int) $c['id'] === $id && (int) $c['parent'] === 0) {
                    $kat = $c;
                }
            }
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        if ($kat === null) {
            return redirect()->to($back)->with('error', 'Ujian tidak ditemukan.');
        }
        if (\App\Libraries\ActiveTest::isNonaktif($kat['name'])) {
            return redirect()->to($back . '?kat=' . $id)->with('error', 'Kategori ' . \App\Libraries\ActiveTest::NAMA_NONAKTIF . ' wajib ada (dipakai saat semua sesi nonaktif) dan tidak bisa dihapus.');
        }
        if ($id === \App\Libraries\ActiveTest::categoryId()) {
            return redirect()->to($back . '?kat=' . $id)->with('error', 'Ujian "' . $kat['name'] . '" sedang dipakai sebagai tes aktif di Setting Ujian. Pilih tes lain dulu.');
        }
        if ((int) $kat['coursecount'] > 0 && trim((string) $this->request->getPost('konfirmasi')) !== $kat['name']) {
            return redirect()->to($back . '?kat=' . $id)->with('error', 'Ketik nama ujian "' . $kat['name'] . '" persis untuk menghapusnya beserta ' . (int) $kat['coursecount'] . ' mapel di dalamnya.');
        }
        try {
            MoodleBridge::call('delete_category', ['id' => $id]);
        } catch (\Throwable $e) {
            return redirect()->to($back . '?kat=' . $id)->with('error', 'Gagal menghapus ujian: ' . $e->getMessage());
        }
        ActivityLog::add('hapus_ujian', $kat['name'] . ' (id ' . $id . ', ' . (int) $kat['coursecount'] . ' mapel)');

        return redirect()->to($back)->with('success', 'Ujian "' . $kat['name'] . '" dihapus' . ((int) $kat['coursecount'] > 0 ? ' beserta ' . (int) $kat['coursecount'] . ' mapel di dalamnya.' : '.'));
    }

    /** Ganti nama kategori (ujian) di Moodle. */
    public function ubahUjian()
    {
        $id   = (int) $this->request->getPost('id');
        $nama = trim((string) $this->request->getPost('nama'));
        $back = site_url('tools/test_manage') . '?kat=' . $id;
        $lama = null;
        try {
            foreach (Moodle::categories() as $c) {
                if ((int) $c['id'] === $id) {
                    $lama = $c['name'];
                }
            }
            if ($lama === null) {
                return redirect()->to(site_url('tools/test_manage'))->with('error', 'Ujian tidak ditemukan.');
            }
            if ($nama === $lama) {
                return redirect()->to($back)->with('success', 'Nama ujian tidak berubah.');
            }
            if (\App\Libraries\ActiveTest::isNonaktif($lama) || \App\Libraries\ActiveTest::isNonaktif($nama)) {
                return redirect()->to($back)->with('error', 'Nama ' . \App\Libraries\ActiveTest::NAMA_NONAKTIF . ' khusus untuk kategori tanpa ujian dan tidak bisa diubah/dipakai.');
            }
            MoodleBridge::call('update_category', ['id' => $id, 'name' => mb_substr($nama, 0, 255)]);
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal mengubah ujian: ' . $e->getMessage());
        }
        ActivityLog::add('ubah_ujian', $lama . ' → ' . $nama);

        return redirect()->to($back)->with('success', 'Nama ujian "' . $lama . '" diubah menjadi "' . $nama . '".');
    }

    public function tambahMapel()
    {
        $kat       = (int) $this->request->getPost('kategori');
        $fullname  = trim((string) $this->request->getPost('fullname'));
        $shortname = trim((string) $this->request->getPost('shortname'));
        $back      = site_url('tools/test_manage') . '?kat=' . $kat;
        try {
            MoodleBridge::call('create_course', ['category' => $kat, 'fullname' => mb_substr($fullname, 0, 254), 'shortname' => mb_substr($shortname, 0, 100), 'timelimit' => self::durasiDetik()]);
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal menambah pelajaran: ' . $e->getMessage());
        }
        ActivityLog::add('tambah_mapel', $shortname . ' (' . $fullname . ')');

        return redirect()->to($back)->with('success', 'Pelajaran "' . $fullname . '" ditambahkan (tersembunyi sampai diaktifkan di Setting Ujian). Isi soalnya di Moodle.');
    }

    /**
     * Hapus satu mapel (course) dari Moodle beserta soal, jawaban, dan nilainya. Wajib ketik shortname;
     * mapel yang sedang aktif di tes aktif ditolak (nonaktifkan dulu di Setting Ujian).
     */
    public function hapusMapel()
    {
        $id   = (int) $this->request->getPost('id');
        $back = site_url('tools/test_manage');
        try {
            $c = Moodle::db()->query(
                'SELECT c.id, c.fullname, c.shortname, c.visible, cc.path FROM ' . Moodle::t('course') . ' c
                   JOIN ' . Moodle::t('course_categories') . ' cc ON cc.id = c.category WHERE c.id = ? AND c.id > 1',
                [$id]
            )->getRowArray();
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        if (! $c) {
            return redirect()->to($back)->with('error', 'Mapel tidak ditemukan.');
        }
        // Kategori ujian = kategori tingkat atas, angka pertama di path "/<id>/...".
        $katUjian = (int) explode('/', trim((string) $c['path'], '/'))[0];
        $back    .= '?kat=' . $katUjian;
        if ((int) $c['visible'] === 1 && $katUjian === \App\Libraries\ActiveTest::categoryId()) {
            return redirect()->to($back)->with('error', 'Mapel "' . $c['fullname'] . '" sedang aktif di tes aktif. Nonaktifkan dulu di Setting Ujian.');
        }
        if (trim((string) $this->request->getPost('konfirmasi')) !== $c['shortname']) {
            return redirect()->to($back)->with('error', 'Ketik shortname "' . $c['shortname'] . '" persis untuk menghapus mapel ini.');
        }
        try {
            MoodleBridge::call('delete_course', ['id' => $id]);
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal menghapus mapel: ' . $e->getMessage());
        }
        ActivityLog::add('hapus_mapel', $c['shortname'] . ' (' . $c['fullname'] . ', id ' . $id . ')');

        return redirect()->to($back)->with('success', 'Mapel "' . $c['fullname'] . '" dihapus dari Moodle.');
    }

    // ------------------------------------------------------------------ Durasi pengerjaan

    /** Durasi pengerjaan quiz (detik) dari pengaturan Kategori Mapel; dipakai juga saat quiz baru dibuat. */
    public static function durasiDetik(): int
    {
        return max(0, (int) \App\Libraries\Settings::get('durasi_menit', '90')) * 60;
    }

    /** Terapkan durasi pengerjaan ke semua mapel atau hanya mapel di ujian (kategori) yang sedang dibuka. */
    public function durasi()
    {
        $menit = $this->request->getPost('menit');
        // cakupan: 'semua' atau id kategori ujian (H1, H2, …).
        $kat   = max(0, (int) $this->request->getPost('cakupan'));
        $buka  = (int) $this->request->getPost('kategori');
        $back  = site_url('tools/test_manage') . ($buka > 0 ? '?kat=' . $buka : ($kat > 0 ? '?kat=' . $kat : ''));
        if (! is_numeric($menit) || (int) $menit < 0 || (int) $menit > 600) {
            return redirect()->to($back)->with('error', 'Isi durasi 0–600 menit (0 = tanpa batas waktu).');
        }
        $menit = (int) $menit;
        \App\Libraries\Settings::set('durasi_menit', (string) $menit);
        try {
            $r = MoodleBridge::call('quiz_timelimit', ['timelimit' => $menit * 60, 'kategori' => $kat])[0] ?? [];
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Durasi tersimpan, tetapi gagal diterapkan ke quiz: ' . $e->getMessage());
        }
        $label = $menit > 0 ? "$menit menit" : 'tanpa batas waktu';
        ActivityLog::add('durasi_quiz', $label . ($kat > 0 ? " (kategori $kat)" : ' (semua mapel)') . ": {$r['diubah']} diubah, {$r['berjalan']} sedang dikerjakan");
        $pesan = "Durasi pengerjaan $label diterapkan ke " . (int) $r['diubah'] . ' quiz' . ((int) $r['sama'] > 0 ? ' (' . (int) $r['sama'] . ' sudah sama)' : '') . '. Quiz baru otomatis memakai durasi ini.';
        if ((int) $r['berjalan'] > 0) {
            $pesan .= ' ' . (int) $r['berjalan'] . ' quiz sedang dikerjakan siswa sehingga dilewati; terapkan lagi setelah ujiannya selesai.';
        }

        return redirect()->to($back)->with((int) $r['berjalan'] > 0 ? 'error' : 'success', $pesan);
    }

    // ------------------------------------------------------------------ Reset Moodle

    private const RESET_TAHAP = ['siswa', 'ujian', 'log'];

    /**
     * Reset Moodle: hapus akun siswa, semua mapel/kategori (soal, jawaban, nilai), dan log, sesuai centang.
     * Setelan, plugin, tema, dan akun admin tetap. Sebelum mulai, database Moodle + panel dibackup ke
     * writable/backup. Berjalan bertahap (±20 detik per permintaan) lewat halaman proses yang mengirim ulang.
     */
    public function resetMoodle()
    {
        $back = site_url('tools/test_manage');
        if (trim((string) $this->request->getPost('konfirmasi')) !== 'RESET MOODLE') {
            return redirect()->to($back)->with('error', 'Ketik RESET MOODLE (huruf kapital) untuk mengonfirmasi.');
        }
        $opsi = array_values(array_intersect(self::RESET_TAHAP, (array) $this->request->getPost('tahap')));
        // Data peserta panel (peserta, absensi, blokir) dikosongkan lewat Daftar Peserta → Reset Peserta, bukan di sini.
        if ($opsi === []) {
            return redirect()->to($back)->with('error', 'Centang minimal satu data yang akan dihapus.');
        }
        if (\App\Libraries\ActiveTest::sesiId() > 0) {
            return redirect()->to($back)->with('error', 'Masih ada sesi ujian aktif. Nonaktifkan sesi di Setting Ujian dulu.');
        }
        try {
            $mengerjakan = Moodle::db()->table(Moodle::t('quiz_attempts'))->whereIn('state', ['inprogress', 'overdue'])->where('preview', 0)->countAllResults();  // pratinjau guru/admin tidak dihitung
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        if ($mengerjakan > 0) {
            return redirect()->to($back)->with('error', "Masih ada $mengerjakan attempt yang sedang dikerjakan siswa. Selesaikan (paksa submit) dulu.");
        }

        try {
            $berkas = $this->backupReset();
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Reset dibatalkan karena backup gagal: ' . $e->getMessage());
        }
        ActivityLog::add('reset_moodle', 'mulai: ' . implode(', ', $opsi) . '; backup ' . implode(', ', array_map('basename', $berkas)));
        session()->set('reset_moodle', [
            'tahap'  => $opsi,
            'guru'   => $this->request->getPost('simpan_guru') === '1',
            'hasil'  => [],
            'backup' => array_map('basename', $berkas),
        ]);

        return $this->langkahReset();
    }

    public function resetLanjut()
    {
        if (! is_array(session()->get('reset_moodle'))) {
            return redirect()->to(site_url('tools/test_manage'))->with('error', 'Tidak ada reset yang sedang berjalan.');
        }

        return $this->langkahReset();
    }

    /** Jalankan tahap berikutnya selama ±20 detik; selesai → bereskan data panel lalu kembali ke halaman. */
    private function langkahReset()
    {
        $job = session()->get('reset_moodle');
        @set_time_limit(300);
        $label = ['siswa' => 'akun siswa', 'ujian' => 'mapel & kategori', 'log' => 'log Moodle'];
        while ($job['tahap'] !== []) {
            $tahap = $job['tahap'][0];
            try {
                $item = MoodleBridge::call('reset_moodle', ['tahap' => $tahap, 'batas' => 20, 'simpan_guru' => $job['guru']])[0];
            } catch (\Throwable $e) {
                session()->remove('reset_moodle');
                ActivityLog::add('reset_moodle', "gagal di tahap $tahap: " . $e->getMessage());

                return redirect()->to(site_url('tools/test_manage'))->with('error', 'Reset berhenti di tahap ' . $label[$tahap] . ': ' . $e->getMessage()
                    . ' Backup: writable/backup/' . implode(', ', $job['backup']) . '.');
            }
            $job['hasil'][$tahap] = ($job['hasil'][$tahap] ?? 0) + (int) $item['hapus'];
            if ((int) $item['sisa'] > 0) {
                session()->set('reset_moodle', $job);

                return $this->render('tools/reset_proses', [
                    'title' => 'Reset Moodle',
                    'pesan' => 'Menghapus ' . $label[$tahap] . ': ' . $job['hasil'][$tahap] . ' terhapus, ' . (int) $item['sisa'] . ' tersisa…',
                ]);
            }
            array_shift($job['tahap']);
            session()->set('reset_moodle', $job);
        }

        // Selesai: tes aktif menunjuk kategori yang sudah dihapus; akun Moodle siswa sudah tidak ada.
        $db = db_connect();
        if (isset($job['hasil']['ujian'])) {
            // Yang tersisa hanya kategori SESI NONAKTIF: jadikan tes aktif (tanpa ujian).
            try {
                \App\Libraries\Settings::set('tes_kategori', (string) \App\Libraries\ActiveTest::kategoriNonaktif());
            } catch (\Throwable $e) {
                \App\Libraries\Settings::set('tes_kategori', '0');
            }
        }
        if (isset($job['hasil']['siswa'])) {
            $db->table('peserta')->update(['moodle_userid' => null]);
        }
        try {
            MoodleBridge::call('purge_caches', []);
        } catch (\Throwable $e) {
            log_message('error', 'Purge setelah reset: ' . $e->getMessage());
        }
        session()->remove('reset_moodle');
        $ringkas = implode(', ', array_map(static fn ($t, $n) => "$n {$label[$t]}", array_keys($job['hasil']), $job['hasil']));
        ActivityLog::add('reset_moodle', 'selesai: ' . $ringkas);

        return redirect()->to(site_url('tools/test_manage'))->with('success', 'Reset Moodle selesai: ' . $ringkas . ' dihapus. Backup sebelum reset: writable/backup/'
            . implode(', ', $job['backup']) . '.');
    }

    /**
     * Backup database Moodle dan panel (mysqldump, gzip) ke writable/backup sebelum reset; berkas lebih dari
     * 30 hari dihapus. Gagal → reset tidak dijalankan.
     *
     * @return list<string> path berkas
     */
    private function backupReset(): array
    {
        $dir = WRITEPATH . 'backup/';
        if (! is_dir($dir) && ! mkdir($dir, 0750, true)) {
            throw new \RuntimeException('folder writable/backup tidak bisa dibuat.');
        }
        foreach (glob($dir . 'reset_*.sql.gz') ?: [] as $lama) {
            if (filemtime($lama) < time() - 30 * 86400) {
                @unlink($lama);
            }
        }
        $panel = config('Database')->default;
        $sumber = [
            'moodle' => [env('moodle.hostname', 'localhost'), (int) env('moodle.port', 3306), env('moodle.username', ''), env('moodle.password', ''), env('moodle.database', 'moodle')],
            'panel'  => [$panel['hostname'], (int) ($panel['port'] ?: 3306), $panel['username'], $panel['password'], $panel['database']],
        ];
        $waktu  = date('Ymd_His');
        $berkas = [];
        foreach ($sumber as $nama => [$host, $port, $user, $pass, $database]) {
            $path = $dir . "reset_{$waktu}_{$nama}.sql.gz";
            $cmd  = ['mysqldump', '--single-transaction', '--quick', '--no-tablespaces', '--default-character-set=utf8mb4',
                '-h', (string) $host, '-P', (string) $port, '-u', (string) $user, (string) $database];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => (string) $pass, 'PATH' => '/usr/bin:/bin']);
            if (! is_resource($proc)) {
                throw new \RuntimeException('mysqldump tidak bisa dijalankan.');
            }
            $gz = gzopen($path, 'wb6');
            while (! feof($pipes[1])) {
                gzwrite($gz, (string) fread($pipes[1], 1 << 20));
            }
            gzclose($gz);
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($proc) !== 0) {
                @unlink($path);
                throw new \RuntimeException("backup database $nama gagal" . ($err !== '' ? ": $err" : '') . '.');
            }
            chmod($path, 0640);
            $berkas[] = $path;
        }

        return $berkas;
    }

    // ------------------------------------------------------------------ Akun panel

    public function simpanAkun()
    {
        $db       = db_connect();
        $id       = (int) $this->request->getPost('id');
        $me       = (int) session()->get('admin_id');
        $username = trim((string) $this->request->getPost('username'));
        $nama     = trim((string) $this->request->getPost('nama'));
        $role     = $this->request->getPost('role') === 'proktor' ? 'proktor' : 'admin';
        $aktif    = $this->request->getPost('aktif') ? 1 : 0;
        $password = (string) $this->request->getPost('password');

        if (! preg_match(self::USERNAME_RE, $username) || $nama === '') {
            return redirect()->to(self::halaman('akun'))->with('error', 'Nama wajib diisi, username 3–60 karakter huruf, angka, titik, strip, atau garis bawah.');
        }
        if ($db->table('admins')->where('username', $username)->where('id !=', $id)->countAllResults() > 0) {
            return redirect()->to(self::halaman('akun'))->with('error', 'Username "' . $username . '" sudah dipakai.');
        }
        if (($id === 0 || $password !== '') && mb_strlen($password) < 8) {
            return redirect()->to(self::halaman('akun'))->with('error', 'Password minimal 8 karakter.');
        }
        if ($id === $me && ($role !== 'admin' || ! $aktif)) {
            return redirect()->to(self::halaman('akun'))->with('error', 'Anda tidak bisa menurunkan role atau menonaktifkan akun Anda sendiri.');
        }

        $data = ['username' => $username, 'nama' => mb_substr($nama, 0, 120), 'role' => $role, 'aktif' => $aktif];
        if ($password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            // Password yang diketik admin tidak ditampilkan lagi di Daftar Ruang.
            $data['password_plain'] = null;
        }

        if ($id > 0) {
            if (! $db->table('admins')->where('id', $id)->countAllResults()) {
                return redirect()->to(self::halaman('akun'))->with('error', 'Akun tidak ditemukan.');
            }
            $db->table('admins')->where('id', $id)->update($data);
            if ($id === $me && isset($data['password_hash'])) {
                session()->set('cred', \App\Filters\Guard::cap($data['password_hash']));
            }
            ActivityLog::add('ubah_akun', $username . ($password !== '' ? ' (password diganti)' : ''));
        } else {
            $db->table('admins')->insert($data + ['created_at' => date('Y-m-d H:i:s')]);
            ActivityLog::add('tambah_akun', $username . ' (' . $role . ')');
        }

        return redirect()->to(self::halaman('akun'))->with('success', 'Akun ' . $username . ' disimpan.');
    }

    public function hapusAkun(int $id)
    {
        $db  = db_connect();
        $row = $db->table('admins')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->to(self::halaman('akun'))->with('error', 'Akun tidak ditemukan.');
        }
        if ($id === (int) session()->get('admin_id')) {
            return redirect()->to(self::halaman('akun'))->with('error', 'Anda tidak bisa menghapus akun Anda sendiri.');
        }
        $db->table('admins')->where('id', $id)->delete();
        ActivityLog::add('hapus_akun', $row['username']);

        return redirect()->to(self::halaman('akun'))->with('success', 'Akun ' . $row['username'] . ' dihapus.');
    }

    // ------------------------------------------------------------------ Utilitas

    /** Unduh salinan database panel (.sql). Berisi password peserta apa adanya. */
    public function backup()
    {
        $db  = db_connect();
        $sql = "-- Backup database panel CBT Admin\n-- Dibuat " . date('Y-m-d H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($db->listTables() as $table) {
            $create = $db->query('SHOW CREATE TABLE `' . $table . '`')->getRowArray();
            $sql   .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n";

            $rows = $db->table($table)->get()->getResultArray();
            foreach (array_chunk($rows, 200) as $chunk) {
                $cols   = '`' . implode('`, `', array_keys($chunk[0])) . '`';
                $values = array_map(static fn ($row) => '(' . implode(', ', array_map(
                    static fn ($v) => $v === null ? 'NULL' : $db->escape((string) $v),
                    $row
                )) . ')', $chunk);
                $sql .= "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $values) . ";\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        ActivityLog::add('backup_database', strlen($sql) . ' byte');

        return $this->response->download('cbtadmin_backup_' . date('Ymd_His') . '.sql', $sql);
    }

    /** Hapus sesi login panel dan sesi Moodle yang sudah kedaluwarsa. */
    public function bersihkanSesi()
    {
        $config  = config(SessionConfig::class);
        $limit   = time() - max(600, (int) $config->expiration);
        $current = session_id();
        $panel   = 0;
        foreach (glob(rtrim($config->savePath, '/') . '/' . $config->cookieName . '*') ?: [] as $file) {
            if (! str_ends_with($file, $current) && filemtime($file) < $limit && @unlink($file)) {
                $panel++;
            }
        }

        try {
            MoodleBridge::call('session_gc', []);
            $moodle = 'sesi Moodle kedaluwarsa juga dibersihkan';
        } catch (\Throwable $e) {
            $moodle = 'sesi Moodle gagal dibersihkan: ' . $e->getMessage();
        }
        ActivityLog::add('bersihkan_sesi', "$panel sesi panel; $moodle");

        return redirect()->to(self::halaman('utilitas'))->with('success', "$panel sesi panel kedaluwarsa dihapus, $moodle.");
    }
}
