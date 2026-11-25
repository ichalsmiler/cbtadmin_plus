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
        // Centang "Pakai HTTPS" = Cloudflare Proxied + sertifikat Origin; tanpa centang = http biasa (DNS only).
        $in['ha_https'] = $this->request->getPost('ha_https') === '1' ? 'ya' : 'tidak';
        // Pindah otomatis + DNS Cloudflare (bukan bagian ha.conf).
        $ipPublik = trim((string) $this->request->getPost('ha_ip_publik_utama'));
        $ipPublik = strtolower(preg_replace('~^https?://|/.*$~i', '', $ipPublik));
        if ($ipPublik !== '' && ! filter_var($ipPublik, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $ipPublik)) {
            return redirect()->to($back)->with('error', 'Tujuan server utama: IP publik (mis. 191.101.190.252) atau alamat tunnel (<id>.cfargotunnel.com).');
        }
        if ($ipPublik !== '' && $ipPublik === trim((string) $this->request->getPost('ha_cadangan_ip'))) {
            return redirect()->to($back)->with('error', 'Tujuan server utama tidak boleh sama dengan IP server cadangan (VPS).');
        }
        $token = trim((string) $this->request->getPost('ha_cf_token'));
        if ($token !== '' && ! preg_match('/^[A-Za-z0-9_-]{20,}$/', $token)) {
            return redirect()->to($back)->with('error', 'Token API Cloudflare tidak valid (huruf, angka, - dan _).');
        }
        $zone     = trim((string) $this->request->getPost('ha_cf_zone'));
        $otomatis = $this->request->getPost('ha_pindah_otomatis') === '1';
        if ($otomatis && ($ipPublik === '' || ($token === '' && Settings::get('ha_cf_token') === '' ) || $in['ha_cadangan_ip'] === '')) {
            return redirect()->to($back)->with('error', 'Pindah otomatis butuh IP publik server utama, IP server cadangan, dan token API Cloudflare.');
        }
        $in['ha_pindah_otomatis'] = $otomatis ? 'ya' : 'tidak';
        $in['ha_pindah_menit']    = (string) max(2, min(30, (int) $this->request->getPost('ha_pindah_menit') ?: 3));
        $in['ha_ip_publik_utama'] = $ipPublik;
        $in['ha_cf_zone']         = preg_match('/^[a-f0-9]{32}$/i', $zone) ? $zone : '';
        if ($token !== '') {
            $in['ha_cf_token'] = $token;
        } elseif ($this->request->getPost('hapus_cf_token') === '1') {
            $in['ha_cf_token'] = '';
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

    /** Server utama nonaktif → ambil data terbaru dari server cadangan lalu aktif lagi (ha-utama.sh kembalikan). */
    public function kembalikan()
    {
        $back = site_url('server_cadangan');
        if (trim((string) $this->request->getPost('konfirmasi')) !== 'KEMBALIKAN') {
            return redirect()->to($back)->with('error', 'Ketik KEMBALIKAN (huruf kapital) untuk mengaktifkan lagi server ini.');
        }
        try {
            ServerCadangan::jalankan('kembalikan');
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Tidak bisa mengembalikan: ' . $e->getMessage());
        }
        ActivityLog::add('server_cadangan', 'KEMBALIKAN: data diambil dari server cadangan, server utama aktif lagi');

        return redirect()->to($back . '#proses')->with('success', 'Proses kembalikan dimulai. Ikuti catatannya di bawah sampai selesai (beberapa menit).');
    }

    /** Server utama: mode sinkron = "cbt-ha utama" dari tombol (latar belakang, lewat jembatan root). */
    public function sinkron()
    {
        $back = site_url('server_cadangan');
        $kode = ServerCadangan::kodeCadangan();
        if ($kode === null) {
            return redirect()->to($back . '#pengaturan')->with('error', 'Lengkapi dan Simpan pengaturan dulu (IP server utama, IP server cadangan).');
        }
        try {
            ServerCadangan::jalankan('utama');
        } catch (\Throwable $e) {
            return redirect()->to($back . '#peranUtama')->with('error', 'Mode sinkron tidak bisa diaktifkan: ' . $e->getMessage());
        }
        ActivityLog::add('server_cadangan', 'mode sinkron diaktifkan (cbt-ha utama)');

        return redirect()->to($back . '#proses')->with('success', 'Mode sinkron sedang diaktifkan. Ikuti catatannya di bawah; setelah selesai, salin KODE ke panel server cadangan.');
    }

    /** Server cadangan: tempel KODE dari panel server utama → ha.conf + ha-cadangan.sh pasang (latar belakang). */
    public function jadiCadangan()
    {
        $back = site_url('server_cadangan');
        if (trim((string) $this->request->getPost('konfirmasi')) !== 'CADANGAN') {
            return redirect()->to($back . '#jadi-cadangan')->with('error', 'Ketik CADANGAN (huruf kapital) untuk menjadikan server ini cadangan.');
        }
        $kode = trim((string) $this->request->getPost('kode'));
        if ($kode === '') {
            return redirect()->to($back . '#jadi-cadangan')->with('error', 'Tempel KODE dari menu Server Cadangan di panel server utama.');
        }
        try {
            ServerCadangan::jalankan('cadangan', $kode);
        } catch (\Throwable $e) {
            return redirect()->to($back . '#jadi-cadangan')->with('error', 'Server ini tidak bisa dijadikan cadangan: ' . $e->getMessage());
        }
        ActivityLog::add('server_cadangan', 'server ini dipasang sebagai CADANGAN (KODE dari panel utama)');

        return redirect()->to($back . '#proses')->with('success', 'Pemasangan server cadangan dimulai (beberapa menit). Setelah selesai, login panel memakai akun admin server utama.');
    }

    /** Cek ke mana domain ujian diarahkan di Cloudflare (cbt-ha-dns cek). */
    public function dns()
    {
        $back = site_url('server_cadangan') . '#pengaturan';
        try {
            $hasil = (string) (ServerCadangan::jalankan('dns')['hasil'] ?? '');
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Cek DNS gagal: ' . $e->getMessage());
        }

        return redirect()->to($back)->with('success', 'DNS sekarang: ' . ($hasil !== '' ? str_replace("\n", '; ', $hasil) : '(tidak ada record)'));
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
