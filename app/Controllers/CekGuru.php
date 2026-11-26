<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\CekSoal;
use App\Libraries\MoodleBridge;
use App\Libraries\Settings;

/**
 * Halaman cek soal untuk guru `/cek/<token>` (tanpa login; Guard membuka cek/*). Tautan terkunci ke perangkat yang
 * pertama menekan "Buka soal" (POST cek/<token>/buka; cookie `cekp`, disimpan sebagai sha256 di cek_tugas.perangkat).
 * GET saja tidak mengunci dan tidak menampilkan soal: pratinjau tautan WhatsApp/Telegram (yang mengambil halaman lebih
 * dulu dari HP pengirim) tidak boleh mengunci tautan atau membaca isi soal.
 */
class CekGuru extends BaseController
{
    protected $helpers = ['url', 'form'];

    private const COOKIE = 'cekp';

    public function index(string $token)
    {
        [$t, $pesan] = $this->periksa($token, 'lihat');
        if ($t === null) {
            return $this->halaman(null, [], $pesan);
        }
        if ($pesan === 'pembuka') {
            return $this->halaman($t, [], '', 200, false, true);
        }
        $soal = (array) json_decode((string) $t['soal'], true);
        try {
            $isi = (array) (MoodleBridge::call('soal_semua', ['courseid' => (int) $t['courseid'], 'ids' => array_column($soal, 'id')])[0]['soal'] ?? []);
        } catch (\Throwable $e) {
            log_message('error', 'Cek soal: ' . $e->getMessage());

            return $this->halaman(null, [], 'Soal tidak bisa dimuat. Coba lagi beberapa saat lagi.');
        }

        return $this->halaman($t, $isi, '');
    }

    /** Tombol "Buka soal": mengunci tautan ke perangkat ini (bila belum terkunci), lalu ke halaman soal. */
    public function buka(string $token)
    {
        [$t, $pesan] = $this->periksa($token, 'buka');
        if ($t === null) {
            return $this->halaman(null, [], $pesan);
        }

        return redirect()->to(site_url('cek/' . $token));
    }

    public function kirim(string $token)
    {
        [$t, $pesan] = $this->periksa($token, 'kirim');
        if ($t === null || $pesan === 'pembuka') {
            return $t === null ? $this->halaman(null, [], $pesan) : redirect()->to(site_url('cek/' . $token));
        }
        if ($this->request->getPost('setuju') !== '1') {
            return redirect()->to(site_url('cek/' . $token))->withInput()->with('error', 'Centang pernyataan di bawah sebelum mengirim.');
        }
        $hasil = [
            'konfirmasi' => true,
            'pernyataan' => CekSoal::pernyataan($t),
            'catatan'    => trim(mb_substr((string) $this->request->getPost('catatan'), 0, 1000)),
        ];
        $t['hasil']      = json_encode($hasil);
        $t['selesai_at'] = date('Y-m-d H:i:s');
        db_connect()->table('cek_tugas')->where('id', $t['id'])->update(['hasil' => $t['hasil'], 'selesai_at' => $t['selesai_at']]);
        ActivityLog::add('cek_soal_selesai', $t['guru_nama'] . ' · ' . $t['mapel'], 'guru');
        CekSoal::kabarSelesai($t);

        return $this->halaman(null, [], 'Terima kasih, pernyataan Anda sudah terkirim ke admin. Tautan ini sekarang ditutup.', 200, true);
    }

    /**
     * Tugas yang masih boleh dibuka dari perangkat ini, atau [null, pesan]. Belum terkunci: mode 'buka' mengunci
     * perangkat ini; mode lain mengembalikan [tugas, 'pembuka'] (tampilkan halaman pembuka, tanpa soal).
     *
     * @param 'lihat'|'buka'|'kirim' $mode
     *
     * @return array{0: ?array, 1: string}
     */
    private function periksa(string $token, string $mode): array
    {
        if (! preg_match('/^[0-9a-f]{32}$/', $token)) {
            return [null, 'Tautan tidak valid.'];
        }
        $db = db_connect();
        $t  = $db->table('cek_tugas')->where('token', $token)->get()->getRowArray();
        if ($t === null) {
            return [null, 'Tautan tidak valid.'];
        }
        $status = CekSoal::status($t);
        if ($status === 'selesai') {
            return [null, 'Cek soal ini sudah dikirim. Terima kasih.'];
        }
        if ($status === 'dicabut') {
            return [null, 'Tautan ini sudah dicabut admin.'];
        }
        if ($status === 'kedaluwarsa') {
            return [null, 'Batas waktu cek soal sudah lewat. Hubungi admin bila perlu tautan baru.'];
        }
        $tutup = CekSoal::alasanMapelTutup((int) $t['courseid']);
        if ($tutup !== '') {
            return [null, $tutup];
        }

        $kuki = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (! preg_match('/^[0-9a-f]{40}$/', $kuki)) {
            $kuki = '';
        }
        $ip = $this->request->getIPAddress();
        if ($t['perangkat'] === null && $mode !== 'buka') {
            return [$t, 'pembuka'];
        }
        if ($t['perangkat'] === null) {
            if ($kuki === '') {
                $kuki = bin2hex(random_bytes(20));
                $path = (string) (parse_url(site_url('/'), PHP_URL_PATH) ?: '/');
                setcookie(self::COOKIE, $kuki, ['expires' => time() + 86400 * 365, 'path' => $path, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $this->request->isSecure()]);
            }
            $t['perangkat'] = hash('sha256', $kuki);
            $t['dibuka_at'] = date('Y-m-d H:i:s');
            $t['ip']        = $ip;
            $db->table('cek_tugas')->where('id', $t['id'])->where('perangkat', null)
                ->update(['perangkat' => $t['perangkat'], 'dibuka_at' => $t['dibuka_at'], 'ip' => $ip]);
            // Dua perangkat membuka bersamaan: yang kalah ditolak.
            $kini = $db->table('cek_tugas')->select('perangkat')->where('id', $t['id'])->get()->getRowArray();
            if (($kini['perangkat'] ?? '') !== $t['perangkat']) {
                return [null, 'Tautan ini sudah dibuka di perangkat lain.'];
            }
        } elseif ($kuki === '' || ! hash_equals((string) $t['perangkat'], hash('sha256', $kuki))) {
            $db->table('cek_tugas')->where('id', $t['id'])->set('ditolak', 'ditolak + 1', false)->update();

            return [null, 'Tautan ini sudah dibuka di perangkat atau browser lain. Buka dengan browser yang sama seperti pertama kali, atau minta admin melepas kunci perangkat.'];
        }
        if ($mode === 'lihat') {
            $db->table('cek_tugas')->where('id', $t['id'])->set('akses', 'akses + 1', false)->update();
        }

        return [$t, ''];
    }

    private function halaman(?array $t, array $isi, string $pesan, int $kode = 403, bool $sukses = false, bool $pembuka = false)
    {
        return $this->response->setStatusCode($t === null && ! $sukses ? $kode : 200)->setBody(view('cek_soal/guru', [
            't'       => $t,
            'pembuka' => $pembuka,
            'isi'     => $isi,
            'pesan'   => $pesan,
            'sukses'  => $sukses,
            'sekolah' => Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', '')),
            'ip'      => $this->request->getIPAddress(),
            'version' => PanelController::VERSION,
        ]));
    }
}
