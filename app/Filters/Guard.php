<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * Satu pintu untuk semua halaman panel: cek CSRF, wajib login,
 * batas waktu diam, dan pembatasan role proktor.
 */
class Guard implements FilterInterface
{
    /** Pengawas (role proktor): jumlah perangkat yang boleh login bersamaan; login berikutnya memutus yang terlama. */
    public const MAKS_PERANGKAT = 2;

    /** Pengawas: sesi berakhir setelah 30 menit tanpa aktivitas (pembaruan otomatis dashboard ikut dihitung). */
    public const TIMEOUT_PENGAWAS = 1800;

    /** Token perangkat pengawas dari kolom admins.sesi_token (dipisah koma, terlama di depan). */
    public static function tokenPerangkat(string $kolom): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $kolom)), static fn ($t) => $t !== ''));
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        helper('url');
        $path    = trim($request->getPath(), '/');

        // Lewat server perantara (deploy/pasang-simulasi.sh, header X-CBT-Perantara): hanya dashboard simulasi, login,
        // dan menu pengawas (PROKTOR_PATHS) untuk SEMUA akun; selain itu 404 seolah tidak ada. Menu pengawas baru
        // otomatis ikut terbuka di perantara, tanpa mengubah konfigurasi nginx.
        if ($request->getHeaderLine('X-CBT-Perantara') === '1' && ! self::bolehPerantara($path)) {
            return service('response')->setStatusCode(404)->setBody('Halaman tidak tersedia.');
        }

        $session = session();

        if ($request->is('post')) {
            try {
                service('security')->verify($request);
            } catch (SecurityException $e) {
                return redirect()->back()->with('error', 'Sesi formulir kedaluwarsa. Silakan ulangi.');
            }
        }

        // Halaman login, favicon (dipakai juga di halaman login), dashboard simulasi publik, dan form laporan siswa
        // (identitas dari tautan bertanda tangan Moodle, lihat Controllers/Lapor) terbuka tanpa sesi.
        if ($path === 'login' || $path === 'tools/gambar/favicon' || ($path === 'simulasi' && $request->is('get')) || $path === 'lapor' || $path === 'lapor/kirim') {
            return null;
        }

        if (! $session->get('admin_id')) {
            return $this->deny($request, 'Silakan login dulu.');
        }

        // Akun bisa dihapus, dinonaktifkan, atau diganti rolenya saat sesinya masih berjalan.
        $admin = db_connect()->table('admins')->select('*')
            ->where('id', (int) $session->get('admin_id'))->get()->getRowArray();
        if (! $admin || ! (int) $admin['aktif']) {
            $session->destroy();

            return $this->deny($request, 'Akun Anda sudah tidak aktif.');
        }
        // Password/kode login diganti (Generate Kode, reset, Ganti Password) → sesi lama ikut putus.
        // Sesi yang dibuat sebelum pengecekan ini ada belum punya cap: dicap sekali tanpa logout.
        $cap = self::cap((string) $admin['password_hash']);
        if ($session->get('cred') === null) {
            $session->set('cred', $cap);
        } elseif (! hash_equals((string) $session->get('cred'), $cap)) {
            $session->destroy();

            return $this->deny($request, 'Password akun ini sudah diganti. Silakan login lagi.');
        }
        // Pengawas: maksimal MAKS_PERANGKAT perangkat. Token sesi harus ada di daftar token akun ini; login di
        // perangkat berikutnya mengeluarkan token terlama. Tukar Akses (admin masuk sebagai pengawas) tidak ikut dicek.
        $tokenAkun = self::tokenPerangkat((string) ($admin['sesi_token'] ?? ''));
        $tokenSesi = (string) $session->get('sesi_token');
        // Akun demo (role demo) tidak dibatasi: dipakai bersama di banyak perangkat saat demonstrasi.
        if ($admin['role'] === 'proktor' && $tokenAkun !== [] && ! $session->get('tukar_dari')
            && ! array_filter($tokenAkun, static fn ($t) => hash_equals($t, $tokenSesi))) {
            $session->destroy();

            return $this->deny($request, 'Akun pengawas ini sudah login di ' . self::MAKS_PERANGKAT . ' perangkat lain, jadi sesi di perangkat ini diakhiri.');
        }
        $session->set([
            'admin_role'  => $admin['role'],
            'admin_nama'  => $admin['nama'],
            // Pengawas ruang hanya melihat siswa di ruangnya. Pengawas tanpa ruang = ruang 0 (tidak ada):
            // tidak melihat siswa dan tokennya terkunci, bukan tanpa batas seperti admin.
            'admin_ruang' => in_array($admin['role'], ['proktor', 'demo'], true) ? (int) ($admin['ruang_id'] ?? 0) : null,
        ]);

        $pengawas = $admin['role'] === 'proktor' && ! $session->get('tukar_dari');
        $timeout  = $pengawas ? self::TIMEOUT_PENGAWAS : (int) env('cbt.sessionTimeout', 3600);
        if (time() - (int) $session->get('last_seen') > $timeout) {
            $session->destroy();

            return $this->deny($request, 'Sesi berakhir karena ' . intdiv($timeout, 60) . ' menit tidak ada aktivitas.');
        }
        // Permintaan auto-refresh tidak dihitung sebagai aktivitas, kecuali untuk pengawas: dashboard pantauan yang
        // terbuka menjaga sesinya; sesi berakhir 30 menit setelah halaman ditutup.
        if ($pengawas || ! in_array($path, ['dashboard/stats', 'status_tes/token'], true)) {
            $session->set('last_seen', time());
        }

        // Server cadangan siaga (database hanya-baca): panel hanya dipakai untuk menu Server Cadangan (tombol PINDAH).
        if (\App\Libraries\ServerCadangan::siaga()) {
            if ($session->get('admin_role') !== 'admin') {
                $session->destroy();

                return $this->deny($request, 'Server ini sedang siaga (tidak melayani ujian). Pakai panel di alamat server yang aktif.');
            }
            if (! in_array(explode('/', $path)[0], ['server_cadangan', 'logout'], true)) {
                if ($request->isAJAX()) {
                    return service('response')->setStatusCode(403)->setJSON(['error' => 'Server cadangan siaga.']);
                }

                return redirect()->to(site_url('server_cadangan'));
            }
        }

        if (! self::allowed((string) $session->get('admin_role'), $path)) {
            if ($request->isAJAX()) {
                return service('response')->setStatusCode(403)->setJSON(['error' => 'Hanya untuk administrator.']);
            }
            // GET ke halaman terlarang → dashboard; POST (form) kembali ke halaman asal bila masih boleh dibuka.
            $asal = (string) $request->getServer('HTTP_REFERER');

            $balik = strtoupper($request->getMethod()) === 'POST' && str_starts_with($asal, site_url('/')) ? $asal : site_url('/');

            return redirect()->to($balik)
                ->with('error', 'Menu atau tindakan itu hanya untuk administrator.');
        }

        return null;
    }

    /** Alamat yang boleh dibuka lewat server perantara (apa pun role-nya). */
    public static function bolehPerantara(string $path): bool
    {
        return in_array($path, ['login', 'simulasi', 'tools/gambar/favicon'], true) || self::allowed('proktor', $path);
    }

    /** Halaman yang boleh dibuka pengawas (proktor); administrator boleh semua. */
    // tools/gambar/logo: logo sekolah di bilah atas (gambar tanda tangan tetap khusus admin).
    private const PROKTOR_PATHS = ['', 'tools/gambar/logo', 'dashboard/stats', 'peserta_ruang', 'status_peserta', 'reset_login', 'kehadiran', 'blokir_siswa', 'token', 'status_tes/token', 'daftar_ruang/kembali', 'logout'];

    /**
     * Proktor ujian (role `operator`): menjalankan ujian hari-H di semua ruang, tanpa menu persiapan/berbahaya.
     * Akhiran '*' = alamat itu beserta turunannya; tanpa '*' = alamat itu saja (mis. halaman daftar tanpa simpan/hapus).
     */
    private const OPERATOR_PATHS = [
        '', 'logout', 'dashboard/stats', 'tools/gambar/logo',
        'status_tes', 'status_tes/kategori', 'status_tes/sesi', 'status_tes/release', 'status_tes/token', 'status_tes/token_setting', 'token_ujian', 'hapus_attempt*',
        'status_peserta*', 'reset_login*', 'blokir_siswa*', 'kehadiran*', 'laporan_siswa*',
        'daftar_peserta/cbtadmin', 'daftar_peserta/moodle', 'daftar_peserta/moodle/mapel_pilihan',
        'dokumen*', 'hasil_ujian*',
        'tools/moodle', 'tools/cron', 'tools/cache', 'tools/ujian',
        'tools/lainnya', 'tools/log_export', 'tools/jejak', // Tools → Panel: hanya kartu Log aktivitas (view tools/index)
        'soal', 'soal/baca', 'soal/ubah', // Soal: hanya tab Edit Soal (Masukkan Soal = soal/periksa, soal/kirim tetap admin)
    ];

    /** Nama peran untuk ditampilkan. */
    public static function namaPeran(string $role): string
    {
        return ['admin' => 'Administrator', 'operator' => 'Proktor', 'demo' => 'Pengawas (demo)'][$role] ?? 'Pengawas';
    }

    /** Melihat semua ruang (administrator dan proktor), bukan hanya ruang sendiri seperti pengawas. */
    public static function lihatSemua(string $role): bool
    {
        return $role === 'admin' || $role === 'operator';
    }

    public static function allowed(string $role, string $path): bool
    {
        if ($role === 'admin') {
            return true;
        }
        $path = trim($path, '/');
        if ($role === 'operator') {
            foreach (self::OPERATOR_PATHS as $allowed) {
                $awal = rtrim($allowed, '*');
                if ($path === $awal || ($allowed !== $awal && str_starts_with($path, $awal . '/'))) {
                    return true;
                }
            }

            return false;
        }
        foreach (self::PROKTOR_PATHS as $allowed) {
            if ($path === $allowed || ($allowed !== '' && str_starts_with($path, $allowed . '/'))) {
                return true;
            }
        }

        return false;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    /** Cap sesi dari hash password akun: berubah bila password diganti. */
    public static function cap(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 24);
    }

    private function deny(RequestInterface $request, string $message)
    {
        if ($request->isAJAX()) {
            return service('response')->setStatusCode(401)->setJSON(['error' => $message]);
        }

        return redirect()->to(site_url('login'))->with('error', $message);
    }
}
