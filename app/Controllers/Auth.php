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

    public function attempt()
    {
        $ip = $this->request->getIPAddress();
        if (! service('throttler')->check('login-' . md5($ip), 5, MINUTE)) {
            return redirect()->back()->with('error', 'Terlalu banyak percobaan login. Coba lagi satu menit lagi.');
        }

        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        $db    = db_connect();
        $admin = $db->table('admins')->where('username', $username)->where('aktif', 1)->get()->getRowArray();

        if (! $admin || ! password_verify($password, $admin['password_hash'])) {
            ActivityLog::add('login_gagal', 'username: ' . $username, $username);

            return redirect()->back()->withInput()->with('error', 'Username atau password salah.');
        }

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
