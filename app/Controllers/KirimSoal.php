<?php

namespace App\Controllers;

use App\Libraries\KirimSoal as KS;

/**
 * Halaman guru /kirim_soal (tanpa login, dibuka Guard): aplikasi Konver Soal dengan tombol Kirim. Pengganti layanan
 * email server konver-soal (server/kirim.js): endpoint api/guru, api/pengaturan, api/kirim/status, api/kirim
 * dengan bentuk jawaban yang sama, jadi aplikasinya tidak perlu diubah. Kiriman disimpan per TA/semester/tingkat
 * aktif (Libraries/KirimSoal) dan tampil di Soal → Kiriman Soal.
 */
class KirimSoal extends BaseController
{
    private const MAKS_BYTE = 80 * 1048576; // JSON setelah dibuka (RTF bergambar + Word asli)

    public function index()
    {
        return KS::sajikan($this->response, '', rtrim(site_url('kirim_soal'), '/') . '/');
    }

    public function berkas(string ...$bagian)
    {
        return KS::sajikan($this->response, implode('/', $bagian), rtrim(site_url('kirim_soal'), '/') . '/');
    }

    /**
     * Status pengumpulan tanpa login (/kirim_soal/status): periode aktif, rekap per kelas, guru + mapel sudah/belum
     * + waktu + tanda Di Moodle. Tanpa berkas, unduhan, IP, atau tombol aksi. Memuat ulang tiap menit.
     */
    public function statusPublik()
    {
        $aktif = KS::aktif();
        $rekap = $aktif ? KS::rekap(db_connect()->table('kirim_soal')->where($aktif)->get()->getResultArray()) : [];

        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('kiriman_soal/publik', [
            'aktif'   => $aktif,
            'rekap'   => $rekap,
            'ditutup' => KS::ditutup(),
            'tutup'   => KS::tutup(),
            'sekolah' => \App\Libraries\Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', '')),
            'version' => PanelController::VERSION,
        ]));
    }

    public function guru()
    {
        // dasar = daftar lengkap (menggantikan data-guru.js bawaan aplikasi).
        return $this->json(['ok' => true, 'guru' => [], 'koreksi' => [], 'dasar' => KS::guru(), 'impor' => new \stdClass()]);
    }

    public function pengaturan()
    {
        if ($this->request->is('post')) {
            return $this->json(['ok' => false, 'pesan' => 'Pengaturan diatur admin di panel CBT Admin (Soal → Kiriman Soal).'], 403);
        }
        $a   = KS::aktif();
        $t   = KS::tutup();
        $out = $a ? ['kunci' => true, 'semester' => $a['semester'], 'tahunAjaran' => $a['ta']] : ['kunci' => false];

        return $this->json(['ok' => true, 'adminAktif' => false] + $out + [
            'tutupKirim'   => $t !== null ? gmdate('Y-m-d\TH:i:s\Z', $t) : null,
            'kirimDitutup' => KS::ditutup(),
        ]);
    }

    public function status()
    {
        return $this->json(['ok' => true, 'siap' => KS::aktif() !== null, 'tujuan' => 'panel CBT Admin (Kurikulum)']);
    }

    public function kirim()
    {
        $tolak = fn (string $pesan, int $kode = 400, array $x = []) => $this->json(['ok' => false, 'pesan' => $pesan] + $x, $kode);
        $ip    = $this->request->getIPAddress();
        if (service('throttler')->check('kirimsoal_' . md5($ip), 30, HOUR) === false) {
            return $tolak('Terlalu banyak pengiriman dari perangkat ini. Coba lagi nanti.', 429);
        }
        $aktif = KS::aktif();
        if ($aktif === null) {
            return $tolak('Admin belum mengaktifkan tahun ajaran dan semester pengumpulan soal.', 403);
        }
        if (KS::ditutup()) {
            return $tolak('Pengiriman soal sudah ditutup. Hubungi admin bila perlu dibuka lagi.', 403, ['ditutup' => true]);
        }
        ini_set('memory_limit', '768M');
        $raw = (string) $this->request->getBody();
        if (stripos($this->request->getHeaderLine('Content-Encoding'), 'gzip') !== false) {
            $raw = @gzdecode($raw, self::MAKS_BYTE);
            if ($raw === false) {
                return $tolak('Data rusak atau terlalu besar.', 413);
            }
        }
        if (strlen($raw) > self::MAKS_BYTE) {
            return $tolak('File terlalu besar.', 413);
        }
        $b = json_decode($raw, true);
        unset($raw);
        if (! is_array($b)) {
            return $tolak('Data tidak valid.');
        }
        $nama  = trim((string) ($b['nama'] ?? ''));
        $mapel = trim((string) ($b['mapel'] ?? ''));
        $kelas = (string) ($b['kelas'] ?? '');
        if ((string) ($b['tahunAjaran'] ?? '') !== $aktif['ta'] || (string) ($b['semester'] ?? '') !== $aktif['semester']) {
            return $tolak('Pengumpulan soal yang dibuka: semester ' . $aktif['semester'] . ' ' . $aktif['ta'] . '. Muat ulang halaman.');
        }
        $ada = db_connect()->table('cek_guru')->where(['nama' => $nama, 'mapel' => $mapel, 'tingkat' => $kelas])->countAllResults();
        if (! in_array($kelas, KS::TINGKAT, true) || $ada === 0) {
            return $tolak('Nama guru, mata pelajaran, dan kelas tidak ada di daftar guru. Hubungi admin.');
        }
        $rtf = isset($b['rtf']) ? (string) $b['rtf'] : (string) base64_decode((string) ($b['rtfBase64'] ?? ''), true);
        if (! str_starts_with(ltrim($rtf), '{\rtf')) {
            return $tolak('File RTF tidak valid.');
        }
        $asli = isset($b['asliBase64']) ? base64_decode((string) $b['asliBase64'], true) : false;
        unset($b['rtf'], $b['rtfBase64'], $b['asliBase64']);

        $dir = KS::folder($aktif['ta'], $aktif['semester'], $kelas);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return $tolak('Folder penyimpanan tidak bisa dibuat di server.', 500);
        }
        $dasar = KS::aman($mapel) . ' - ' . KS::aman($nama);
        if (file_put_contents($dir . $dasar . '.rtf', $rtf, LOCK_EX) === false) {
            return $tolak('Gagal menyimpan file di server.', 500);
        }
        $ext       = strtolower(pathinfo((string) ($b['namaFileAsli'] ?? ''), PATHINFO_EXTENSION));
        $ext       = in_array($ext, ['docx', 'doc'], true) ? $ext : 'docx';
        $berkasAsl = null;
        foreach (glob($dir . $dasar . ' (asli).*') ?: [] as $lama) {
            @unlink($lama);
        }
        if (is_string($asli) && $asli !== '' && file_put_contents($dir . $dasar . ' (asli).' . $ext, $asli, LOCK_EX) !== false) {
            $berkasAsl = $dasar . ' (asli).' . $ext;
        }

        $db    = db_connect();
        $kunci = ['ta' => $aktif['ta'], 'semester' => $aktif['semester'], 'tingkat' => $kelas, 'guru' => $nama, 'mapel' => $mapel];
        $lama  = $db->table('kirim_soal')->where($kunci)->get()->getRowArray();
        $data  = [
            'berkas'      => $dasar . '.rtf',
            'asli'        => $berkasAsl,
            'nama_asli'   => mb_substr((string) ($b['namaFileAsli'] ?? ''), 0, 200),
            'jumlah_soal' => max(0, (int) ($b['jumlahSoal'] ?? 0)),
            'perlu_dicek' => max(0, (int) ($b['perluDicek'] ?? 0)),
            'ukuran'      => strlen($rtf),
            'ip'          => $ip,
            'masuk_at'    => null, // kiriman baru/ulang belum masuk Moodle
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        if ($lama) {
            $db->table('kirim_soal')->where('id', $lama['id'])->update($data + ['kali' => (int) $lama['kali'] + 1]);
        } else {
            $db->table('kirim_soal')->insert($kunci + $data + ['kali' => 1, 'created_at' => $data['updated_at']]);
        }

        return $this->json(['ok' => true, 'asli' => $berkasAsl !== null ? '+ file asli' : '']);
    }

    private function json(array $data, int $kode = 200)
    {
        return $this->response->setStatusCode($kode)->setHeader('Cache-Control', 'no-store')->setJSON($data);
    }
}
