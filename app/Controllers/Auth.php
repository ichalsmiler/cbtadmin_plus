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
        // Pengawas: satu akun satu perangkat. Token baru menggantikan yang lama; sesi di perangkat lama ditolak Guard.
        $sesiLama = null;
        if ($admin['role'] === 'proktor' && array_key_exists('sesi_token', $admin)) {
            $token = bin2hex(random_bytes(16));
            $ubah += ['sesi_token' => $token, 'sesi_ip' => $ip, 'sesi_mulai' => date('Y-m-d H:i:s')];
            $session->set('sesi_token', $token);
            if ((string) $admin['sesi_token'] !== '') {
                $sesiLama = 'login di perangkat baru (IP ' . $ip . '); sesi sebelumnya (IP ' . ($admin['sesi_ip'] ?: '?')
                    . ', login ' . ($admin['sesi_mulai'] ? date('d-m H:i', strtotime((string) $admin['sesi_mulai'])) : '?') . ') diputus';
            }
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
        session()->destroy();

        return redirect()->to(site_url('login'));
    }
}
