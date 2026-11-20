<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\ServerCadangan;
use App\Libraries\Settings;

/**
 * Admin Tools → Server Cadangan: pengaturan ha.conf (utama di sekolah, cadangan di VPS), status
 * replikasi/salinan/backup, dan tombol "Aktifkan server ini" (ha-cadangan.sh pindah) di server cadangan.
 */
class ServerCadanganPanel extends PanelController
{
    public function index(): string
    {
        $status = null;
        $error  = '';
        try {
            $status = ServerCadangan::jalankan('status');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->render('server_cadangan/index', [
            'title'  => 'Server Cadangan',
            'status' => $status,
            'error'  => $error,
            'nilai'  => ServerCadangan::nilai(),
            'kode'   => ServerCadangan::kodeCadangan(),
        ]);
    }

    public function simpan()
    {
        $back = site_url('server_cadangan') . '#pengaturan';
        $in   = [];
        foreach (array_keys(ServerCadangan::CONF) as $k) {
            $in[$k] = trim((string) $this->request->getPost($k));
        }
        foreach (['ha_domain_utama', 'ha_domain_cadangan'] as $k) {
            if ($in[$k] !== '' && ! preg_match('/^[A-Za-z0-9.-]+$/', $in[$k])) {
                return redirect()->to($back)->with('error', 'Alamat hanya boleh domain atau IP (tanpa http:// dan tanpa garis miring).');
            }
        }
        foreach (['ha_utama_ip', 'ha_cadangan_ip'] as $k) {
            if ($in[$k] !== '' && ! filter_var($in[$k], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return redirect()->to($back)->with('error', 'IP server harus berupa IPv4, mis. 172.16.32.157.');
            }
        }
        if ($in['ha_secret'] === '' || $this->request->getPost('secret_baru') === '1') {
            $in['ha_secret'] = bin2hex(random_bytes(32));
        }
        if (strlen($in['ha_secret']) < 32 || ! ctype_alnum($in['ha_secret'])) {
            return redirect()->to($back)->with('error', 'Kunci rahasia minimal 32 huruf/angka. Centang "Buat kunci baru" untuk membuatnya otomatis.');
        }
        $in['ha_backup_hari'] = (string) max(1, min(90, (int) $in['ha_backup_hari'] ?: 7));
        if (! preg_match('/^[0-9]{1,2}(-[0-9]{1,2})?$/', $in['ha_backup_jam'])) {
            $in['ha_backup_jam'] = '6-17';
        }
        foreach ($in as $k => $v) {
            Settings::set($k, $v);
        }
        ActivityLog::add('server_cadangan', 'pengaturan disimpan: ' . $in['ha_domain_utama'] . ' / ' . $in['ha_domain_cadangan']);

        return redirect()->to($back)->with('success', 'Pengaturan server cadangan disimpan. Unduh ha.conf lalu salin ke kedua server.');
    }

    public function unduh()
    {
        $v = ServerCadangan::nilai();
        foreach (['ha_domain_utama', 'ha_domain_cadangan', 'ha_utama_ip', 'ha_cadangan_ip', 'ha_secret'] as $k) {
            if ($v[$k] === '') {
                return redirect()->to(site_url('server_cadangan') . '#pengaturan')->with('error', 'Lengkapi dan simpan pengaturan dulu sebelum mengunduh ha.conf.');
            }
        }
        ActivityLog::add('server_cadangan', 'unduh ha.conf');

        return $this->response->download('ha.conf', ServerCadangan::haConf());
    }

    /** Server cadangan → server utama (ha-cadangan.sh pindah, latar belakang). */
    public function pindah()
    {
        $back = site_url('server_cadangan');
        if (trim((string) $this->request->getPost('konfirmasi')) !== 'PINDAH') {
            return redirect()->to($back)->with('error', 'Ketik PINDAH (huruf kapital) untuk mengaktifkan server ini.');
        }
        try {
            ServerCadangan::jalankan('pindah');
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Tidak bisa memindahkan: ' . $e->getMessage());
        }
        ActivityLog::add('server_cadangan', 'PINDAH: server cadangan dijadikan utama');

        return redirect()->to($back . '#proses')->with('success', 'Proses pindah dimulai. Ikuti catatannya di bawah sampai selesai (biasanya 1–3 menit).');
    }

    /** Catatan proses pindah (JSON, untuk diperbarui berkala di halaman). */
    public function log()
    {
        try {
            return $this->response->setJSON(ServerCadangan::jalankan('log'));
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
