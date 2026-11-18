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
        $db->table('admins')->where('id', $admin['id'])->update(['last_login' => date('Y-m-d H:i:s')]);
        ActivityLog::add('login');

        return redirect()->to(site_url('/'));
    }

    public function logout()
    {
        ActivityLog::add('logout');
        session()->destroy();

        return redirect()->to(site_url('login'));
    }
}
