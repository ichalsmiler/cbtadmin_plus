<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;

class Auth extends PanelController
{
    public function login()
    {
        if (session()->get('admin_id')) {
            return redirect()->to(site_url('/'));
        }

        return view('auth/login', ['version' => self::VERSION]);
    }

    /** Salah password berturut-turut per username sebelum dikunci sementara. */
    private const MAKS_SALAH = 5;

    /** Lama kunci (menit) per username; per IP (banyak username dicoba dari satu perangkat) memakai 3x nilai ini. */
    private const KUNCI_MENIT = 5;

    /** Salah password dari satu IP (semua username) sebelum IP itu dikunci; satu sekolah bisa satu IP publik. */
    private const MAKS_SALAH_IP = 30;

    public function attempt()
    {
        $ip       = $this->request->getIPAddress();
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');
        $kunciUser = 'login_gagal_u_' . md5(strtolower($username));
        $kunciIp   = 'login_gagal_ip_' . md5($ip);
        $cache     = cache();

        // Kunci sementara: dicek sebelum password, jadi tebakan saat terkunci tidak pernah diperiksa.
        foreach ([[$kunciUser, self::MAKS_SALAH, 'Akun ini'], [$kunciIp, self::MAKS_SALAH_IP, 'Perangkat ini']] as [$kunci, $maks, $siapa]) {
            $catat = $cache->get($kunci);
            if (is_array($catat) && $catat['n'] >= $maks && $catat['sampai'] > time()) {
                $menit = (int) ceil(($catat['sampai'] - time()) / 60);

                return redirect()->back()->withInput()->with('error', $siapa . ' dikunci sementara karena terlalu sering salah password. '
                    . 'Coba lagi dalam ' . $menit . ' menit, atau minta admin mengatur ulang password.');
            }
        }

        $db    = db_connect();
        $admin = $db->table('admins')->where('username', $username)->where('aktif', 1)->get()->getRowArray();

        if (! $admin || ! password_verify($password, $admin['password_hash'])) {
            ActivityLog::add('login_gagal', 'username: ' . $username . ', IP ' . $ip, $username);
            $n = $this->catatGagal($kunciUser, self::KUNCI_MENIT);
            $this->catatGagal($kunciIp, self::KUNCI_MENIT * 3);
            if ($n >= self::MAKS_SALAH) {
                ActivityLog::add('login_dikunci', 'username: ' . $username . ' dikunci ' . self::KUNCI_MENIT . ' menit (' . $n . 'x salah password, IP ' . $ip . ')', $username);

                return redirect()->back()->withInput()->with('error', 'Password salah ' . $n . ' kali. Akun ini dikunci ' . self::KUNCI_MENIT
                    . ' menit. Tunggu, atau minta admin mengatur ulang password.');
            }
            $sisa = self::MAKS_SALAH - $n;

            return redirect()->back()->withInput()->with('error', 'Username atau password salah. Sisa percobaan: ' . $sisa
                . ($sisa <= 2 ? ' (setelah itu akun dikunci ' . self::KUNCI_MENIT . ' menit).' : '.'));
        }
        $cache->delete($kunciUser);

        $session = session();
        $session->regenerate(true);
        $session->set([
            'admin_id'   => (int) $admin['id'],
            'admin_user' => $admin['username'],
            'admin_nama' => $admin['nama'],
            'admin_role' => $admin['role'],
            'last_seen'  => time(),
            'cred'       => \App\Filters\Guard::cap((string) $admin['password_hash']),
        ]);
        $ubah = ['last_login' => date('Y-m-d H:i:s')];
        // Pengawas: maksimal Guard::MAKS_PERANGKAT perangkat. Token per login disimpan berurutan (terlama di depan,
        // dipisah koma); login di perangkat berikutnya memutus perangkat terlama, sesinya lalu ditolak Guard.
        $sesiLama = null;
        if ($admin['role'] === 'proktor' && array_key_exists('sesi_token', $admin)) {
            $token  = bin2hex(random_bytes(15));
            $tokens = \App\Filters\Guard::tokenPerangkat((string) $admin['sesi_token']);
            $tokens[] = $token;
            if (count($tokens) > \App\Filters\Guard::MAKS_PERANGKAT) {
                array_shift($tokens);
                $sesiLama = 'login di perangkat ke-' . (\App\Filters\Guard::MAKS_PERANGKAT + 1) . ' (IP ' . $ip . '); perangkat terlama diputus'
                    . ' (login terakhir sebelumnya IP ' . ($admin['sesi_ip'] ?: '?') . ', ' . ($admin['sesi_mulai'] ? date('d-m H:i', strtotime((string) $admin['sesi_mulai'])) : '?') . ')';
            }
            $ubah += ['sesi_token' => implode(',', $tokens), 'sesi_ip' => $ip, 'sesi_mulai' => date('Y-m-d H:i:s')];
            $session->set('sesi_token', $token);
        }
        try {
            $db->table('admins')->where('id', $admin['id'])->update($ubah);
        } catch (\Throwable $e) {
            // Server cadangan siaga: database hanya-baca, login tetap boleh (untuk tombol PINDAH).
        }
        ActivityLog::add('login');
        if ($sesiLama !== null) {
            ActivityLog::add('login_perangkat_lain', $sesiLama);
        }

        return redirect()->to(site_url(\App\Libraries\ServerCadangan::siaga() ? 'server_cadangan' : '/'));
    }

    /** Buka kunci salah password untuk username ini (dipanggil saat admin mengatur ulang password). */
    public static function bukaKunci(string $username): void
    {
        cache()->delete('login_gagal_u_' . md5(strtolower(trim($username))));
    }

    /**
     * Tambah hitungan salah password (jendela bergeser: tiap salah memperpanjang batas waktu). Kembalikan jumlahnya.
     */
    private function catatGagal(string $kunci, int $menit): int
    {
        $catat = cache($kunci);
        $n     = is_array($catat) && $catat['sampai'] > time() ? $catat['n'] + 1 : 1;
        cache()->save($kunci, ['n' => $n, 'sampai' => time() + $menit * 60], $menit * 60);

        return $n;
    }

    public function logout()
    {
        ActivityLog::add('logout');
        $session = session();
        // Pengawas logout: jatah perangkatnya dilepas supaya bisa login di perangkat lain tanpa memutus siapa pun.
        $token = (string) $session->get('sesi_token');
        if ($token !== '' && ! $session->get('tukar_dari')) {
            try {
                $db  = db_connect();
                $row = $db->table('admins')->select('sesi_token')->where('id', (int) $session->get('admin_id'))->get()->getRowArray();
                if ($row !== null) {
                    $sisa = array_values(array_filter(\App\Filters\Guard::tokenPerangkat((string) $row['sesi_token']), static fn ($t) => ! hash_equals($t, $token)));
                    $db->table('admins')->where('id', (int) $session->get('admin_id'))->update(['sesi_token' => $sisa === [] ? null : implode(',', $sisa)]);
                }
            } catch (\Throwable $e) {
                // Database hanya-baca (server cadangan siaga): cukup akhiri sesi.
            }
        }
        $session->destroy();

        return redirect()->to(site_url('login'));
    }
}
