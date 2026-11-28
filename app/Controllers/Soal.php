<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Ai;
use App\Libraries\CekSoal;
use App\Libraries\KirimSoal;
use App\Libraries\Settings;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\SoalRtf;

/**
 * Menu Soal → Masukkan Soal: unggah soal RTF (pilihan ganda, kunci "ANS: X"), periksa, lalu masukkan langsung ke
 * quiz mapel di Moodle (jembatan import_soal; quiz dibuat bila belum ada). Hasil periksa disimpan sementara di
 * writable/soal/<token>.json sampai dikirim (dibersihkan setelah 1 hari).
 * Tab Edit Soal: ubah teks/opsi/kunci soal di quiz mapel lewat jembatan soal_daftar/soal_baca/soal_ubah.
 */
class Soal extends PanelController
{
    private const DIR = WRITEPATH . 'soal/';

    /** Menu Soal: tab Masukkan Soal (unggah RTF) dan Edit Soal (perbaiki naskah/kunci soal yang sudah di Moodle). */
    public function index(string $bagian = '')
    {
        // Alamat lama ?tab=edit|review (sebelum submenu) → soal/edit|review, supaya submenu yang benar tersorot.
        $lama = (string) $this->request->getGet('tab');
        // Review Soal digabung ke Review & Edit (soal/edit): alamat lamanya dialihkan.
        if (($bagian === '' && in_array($lama, ['edit', 'review'], true)) || $bagian === 'review') {
            $mapel = (int) $this->request->getGet('mapel');

            return redirect()->to(site_url('soal/edit') . ($mapel > 1 ? '?mapel=' . $mapel : ''));
        }
        $mapel = [];
        $error = false;
        try {
            $mapel = $this->daftarMapel();
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Soal: ' . $e->getMessage());
        }
        // Proktor hanya boleh Edit Soal (unggah soal baru khusus admin).
        $admin   = session('admin_role') === 'admin';
        if ($bagian === '' && ! $admin) {
            return redirect()->to(site_url('soal/edit') . ($this->request->getGet('mapel') ? '?mapel=' . (int) $this->request->getGet('mapel') : ''));
        }
        $tab     = $bagian !== '' ? $bagian : 'masuk';
        $dipilih = (int) $this->request->getGet('mapel');
        // Kiriman guru (Kirim Soal) periode aktif: pilihan pengganti unggah berkas; ?kiriman=ID dari Kiriman Soal.
        $kiriman   = $tab === 'masuk' ? KirimSoal::untukMasukkan() : [];
        $kirimanId = (int) $this->request->getGet('kiriman');
        foreach ($kiriman as $k) {
            if ((int) $k['id'] === $kirimanId && $dipilih <= 1) {
                $dipilih = (int) $k['course'];
            }
        }
        $daftar  = null;
        $gagal   = null;
        if ($tab !== 'masuk' && $dipilih > 1) {
            // Review & Edit: semua soal lengkap (opsi, kunci, gambar) + jumlah attempt sedang/selesai.
            try {
                $daftar = MoodleBridge::call('soal_semua', ['courseid' => $dipilih])[0] ?? null;
            } catch (\Throwable $e) {
                $gagal = $e->getMessage();
            }
        }

        return $this->render('soal/index', [
            'title'   => $tab === 'masuk' ? 'Masukkan Soal' : 'Review & Edit Soal',
            'tab'     => $tab,
            'admin'   => $admin,
            'mapel'   => $mapel,
            'error'   => $error,
            'dipilih' => $dipilih,
            'kiriman' => $kiriman,
            'kirimanId' => $kirimanId,
            'daftar'  => $daftar,
            'gagal'   => $gagal,
        ]);
    }

    /** Edit Soal: isi satu soal (JSON) untuk editor. */
    public function baca()
    {
        try {
            $item = MoodleBridge::call('soal_baca', [
                'courseid'   => (int) $this->request->getGet('mapel'),
                'questionid' => (int) $this->request->getGet('id'),
            ])[0] ?? null;
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }

        return $this->response->setJSON($item);
    }

    /** Edit Soal: simpan teks soal, teks opsi, dan kunci (di tempat; siswa yang sedang mengerjakan ikut melihatnya). */
    public function ubah()
    {
        $courseId = (int) $this->request->getPost('mapel');
        $nomor    = (int) $this->request->getPost('nomor');
        $back     = site_url('soal/edit') . '?mapel=' . $courseId . ($nomor > 0 ? '#soal-' . $nomor : '');
        $opsi     = [];
        foreach ((array) $this->request->getPost('opsi') as $id => $teks) {
            $opsi[] = ['id' => (int) $id, 'teks' => self::bersihkanHtml((string) $teks)];
        }
        try {
            $item = MoodleBridge::call('soal_ubah', [
                'courseid'    => $courseId,
                'questionid'  => (int) $this->request->getPost('id'),
                'teks'        => self::bersihkanHtml((string) $this->request->getPost('teks')),
                'opsi'        => $opsi,
                'kunci'       => (int) $this->request->getPost('kunci'),
                'nilai_ulang' => $this->request->getPost('nilai_ulang') === '1',
            ])[0] ?? [];
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Soal nomor ' . $nomor . ' gagal disimpan: ' . $e->getMessage());
        }
        ActivityLog::add('edit_soal', 'Mapel ' . $courseId . ', soal nomor ' . $nomor . (! empty($item['kunci_berubah']) ? ' (kunci diubah, ' . (int) ($item['dinilai_ulang'] ?? 0) . ' attempt dinilai ulang)' : ''));

        $pesan = 'Soal nomor ' . $nomor . ' disimpan.';
        if (! empty($item['kunci_berubah'])) {
            $pesan .= ' Kunci berubah; ' . (int) ($item['dinilai_ulang'] ?? 0) . ' jawaban siswa yang sudah selesai dinilai ulang.';
        }
        if ((int) ($item['sedang'] ?? 0) > 0) {
            $pesan .= ' ' . (int) $item['sedang'] . ' siswa yang sedang mengerjakan melihat perbaikan saat pindah nomor atau memuat ulang halaman.';
        }

        return redirect()->to($back)->with('success', $pesan);
    }

    /**
     * HTML dari editor panel. Gambar asli sudah dikembalikan ke @@PLUGINFILE@@ oleh JS (atribut data-berkas);
     * gambar yang masih data URI = tempelan baru: dikecilkan (SoalRtf::kecilkan) lalu disimpan sebagai berkas soal
     * oleh jembatan (soal_ubah). Data URI selain gambar PNG/JPEG/GIF/WEBP (atau > 8 MB) dibuang, begitu juga skrip.
     */
    private static function bersihkanHtml(string $html): string
    {
        $html = (string) preg_replace_callback('/<img\b[^>]*\bsrc="data:image\/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+\/=\s]+)"[^>]*>/i', static function ($m) {
            $data = base64_decode((string) preg_replace('/\s+/', '', $m[2]), true);
            if ($data === false || strlen($data) > 8 * 1024 * 1024 || ! @getimagesizefromstring($data)) {
                return '';
            }
            $jenis = strtolower($m[1]);
            [$data, $ext] = SoalRtf::kecilkan($data, $jenis === 'jpeg' ? 'jpg' : $jenis);
            $mime = ['jpg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'][$ext] ?? 'png';

            return '<img src="data:image/' . $mime . ';base64,' . base64_encode($data) . '" alt="" style="max-width:100%">';
        }, $html);
        $html = (string) preg_replace('/<img\b[^>]*\bsrc="data:(?!image\/(png|jpeg|gif|webp);base64,)[^"]*"[^>]*>/i', '', $html);
        $html = (string) preg_replace('#<(script|style|iframe)\b.*?</\1>#is', '', $html);
        $html = (string) preg_replace('/\son\w+="[^"]*"/i', '', $html);

        return trim($html);
    }

    /** Langkah 1: baca berkas RTF lalu tampilkan pratinjau soal dan kuncinya. */
    public function periksa()
    {
        $back     = site_url('soal');
        $courseId = (int) $this->request->getPost('mapel');
        $file     = $this->request->getFile('berkas');
        $kirimId  = (int) $this->request->getPost('kiriman');
        if ($courseId <= 1) {
            return redirect()->to($back . ($kirimId ? '?kiriman=' . $kirimId : ''))->with('error', 'Pilih mapel tujuan.');
        }
        if ($kirimId > 0) {
            // Soal kiriman guru (Kirim Soal): RTF diambil dari folder kiriman.
            $k   = db_connect()->table('kirim_soal')->where('id', $kirimId)->get()->getRowArray();
            $rtf = $k ? KirimSoal::bacaRtf($k) : null;
            if ($rtf === null) {
                return redirect()->to($back . '?mapel=' . $courseId)->with('error', 'Berkas kiriman guru tidak ditemukan.');
            }
            $namaBerkas = $k['berkas'] . ' (kiriman ' . $k['guru'] . ')';
        } elseif (! $file || ! $file->isValid() || ! in_array(strtolower($file->getClientExtension()), ['rtf', 'doc'], true)) {
            return redirect()->to($back . '?mapel=' . $courseId)->with('error', 'Pilih kiriman guru atau berkas soal .rtf (atau .rtf.doc) yang valid.');
        } else {
            $rtf        = (string) file_get_contents($file->getTempName());
            $namaBerkas = $file->getClientName();
        }
        try {
            $hasil = SoalRtf::urai($rtf);
            $info  = $this->infoMapel($courseId);
        } catch (\Throwable $e) {
            return redirect()->to($back . '?mapel=' . $courseId)->with('error', 'Berkas tidak bisa dibaca: ' . $e->getMessage());
        }
        if ($info === null) {
            return redirect()->to($back)->with('error', 'Mapel tidak ditemukan di Moodle.');
        }
        // Pengaman kiriman guru: kelas harus sama; pasangan mapel yang belum pernah dipastikan wajib dikonfirmasi.
        $cek = null;
        if ($kirimId > 0) {
            $tc = CekSoal::tingkatCourse((string) $info['shortname'], (string) $info['fullname']);
            if ($tc !== '' && $tc !== $k['tingkat']) {
                return redirect()->to($back . '?kiriman=' . $kirimId)->with('error', 'Kiriman ' . $k['mapel'] . ' kelas ' . $k['tingkat']
                    . ' tidak boleh masuk ke ' . $info['fullname'] . ' (kelas ' . $tc . '). Pilih mapel kelas ' . $k['tingkat'] . '.');
            }
            $kunciPeta = KirimSoal::kunciPeta($k['tingkat'], $k['mapel']);
            $petaLama  = KirimSoal::peta()[$kunciPeta] ?? null;
            $cek       = [
                'guru'      => $k['guru'],
                'mapel'     => $k['mapel'],
                'tingkat'   => $k['tingkat'],
                'pasti'     => $petaLama === $info['shortname'],
                'petaLama'  => $petaLama,
                'tanpaKls'  => $tc === '',
                'bedaJml'   => (int) $k['jumlah_soal'] > 0 && (int) $k['jumlah_soal'] !== count($hasil['soal']) ? (int) $k['jumlah_soal'] : 0,
                'kunciPeta' => $kunciPeta,
            ];
        }

        $this->bersihkan();
        $token = bin2hex(random_bytes(16));
        $simpan = [
            'mapel'   => $courseId,
            'berkas'  => $namaBerkas,
            'kiriman' => $kirimId,
            'cek'     => $cek === null ? null : ['wajib' => ! $cek['pasti'], 'kunciPeta' => $cek['kunciPeta'], 'shortname' => $info['shortname']],
            'oleh'    => (int) session()->get('admin_id'),
            'soal'   => array_map(static fn ($s) => ['gambar' => array_map(static fn ($g) => ['nama' => $g['nama'], 'data' => base64_encode($g['data'])], $s['gambar'])] + $s, $hasil['soal']),
        ];
        if (file_put_contents(self::DIR . $token . '.json', json_encode($simpan)) === false) {
            return redirect()->to($back)->with('error', 'Folder writable/soal tidak bisa ditulisi.');
        }

        return $this->render('soal/periksa', [
            'title'      => 'Soal · Periksa',
            'token'      => $token,
            'info'       => $info,
            'berkas'     => $namaBerkas,
            'soal'       => $simpan['soal'],
            'peringatan' => $hasil['peringatan'],
            'gambar'     => $hasil['gambar'],
            'cek'        => $cek,
            'aiSiap'     => Ai::siap(),
        ]);
    }

    /** Kartu "Cek soal dengan AI": simpan/hapus API key, pilih model, tes koneksi (admin). */
    public function aiSetelan()
    {
        if (! Ai::tampil()) {
            return redirect()->to(site_url('soal'))->with('error', 'Fitur cek soal dengan AI sedang disembunyikan.');
        }
        $aksi = (string) $this->request->getPost('aksi');
        if ($aksi === 'hapus') {
            Ai::simpanKey(null);
            ActivityLog::add('ai', 'Hapus API key AI');

            return redirect()->to(site_url('soal'))->with('success', 'API key AI dihapus.');
        }
        $provider = (string) $this->request->getPost('provider');
        if (isset(Ai::PROVIDER[$provider])) {
            Settings::set('ai_provider', $provider);
        }
        $url = trim((string) $this->request->getPost('url'));
        if ($url !== '' && ! preg_match('~^https?://[^\s]+$~i', $url)) {
            return redirect()->to(site_url('soal'))->with('error', 'Alamat API harus diawali http:// atau https://.');
        }
        Settings::set('ai_url', rtrim($url, '/'));
        Settings::set('ai_model_bebas', mb_substr(trim((string) $this->request->getPost('model_bebas')), 0, 120));
        Settings::set('ai_gambar', $this->request->getPost('gambar') === '1' ? '1' : '0');
        $model = (string) $this->request->getPost('model');
        if (isset(Ai::MODEL[$model])) {
            Settings::set('ai_model', $model);
        }
        $key = trim((string) $this->request->getPost('key'));
        if ($key !== '') {
            if (! preg_match('/^\S{16,300}$/', $key)) {
                return redirect()->to(site_url('soal'))->with('error', 'API key tidak valid (minimal 16 karakter, tanpa spasi).');
            }
            Ai::simpanKey($key);
            ActivityLog::add('ai', 'Simpan API key AI');
        }
        if (Ai::ada() && Ai::model() !== '') {
            try {
                $balas = Ai::tes();

                return redirect()->to(site_url('soal'))->with('success', 'Koneksi AI berhasil (' . Ai::namaModel() . ' membalas: "' . mb_substr(trim($balas), 0, 30) . '").');
            } catch (\Throwable $e) {
                return redirect()->to(site_url('soal'))->with('error', 'Tes AI gagal: ' . $e->getMessage());
            }
        }

        return redirect()->to(site_url('soal'))->with('success', 'Setelan AI disimpan.');
    }

    /** Halaman Periksa → tombol "Cek dengan AI": telaah soal hasil periksa (JSON, dipanggil fetch). */
    public function aiCek()
    {
        $token = (string) $this->request->getPost('token');
        $path  = self::DIR . $token . '.json';
        $balas = fn (array $d, int $kode = 200) => $this->response->setStatusCode($kode)
            ->setJSON($d + ['csrf' => csrf_hash()]);
        if (! Ai::siap()) {
            return $balas(['ok' => false, 'pesan' => 'Fitur cek soal dengan AI tidak aktif.'], 403);
        }
        if (! preg_match('/^[0-9a-f]{32}$/', $token) || ! is_file($path)) {
            return $balas(['ok' => false, 'pesan' => 'Data periksa sudah kedaluwarsa. Ulangi Periksa.'], 404);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || (int) ($data['oleh'] ?? 0) !== (int) session()->get('admin_id')) {
            return $balas(['ok' => false, 'pesan' => 'Data periksa tidak valid.'], 403);
        }
        $info = $this->infoMapel((int) $data['mapel']);
        try {
            $hasil = Ai::cekSoal($data['soal'], (string) ($info['fullname'] ?? ''));
        } catch (\Throwable $e) {
            return $balas(['ok' => false, 'pesan' => $e->getMessage()], 502);
        }
        ActivityLog::add('ai', 'Cek soal ' . ($data['berkas'] ?? '') . ': ' . count($hasil['temuan']) . ' temuan');

        return $balas(['ok' => true, 'model' => Ai::namaModel()] + $hasil);
    }

    /** Langkah 2: masukkan soal yang sudah diperiksa ke quiz mapel. */
    public function kirim()
    {
        $back  = site_url('soal');
        $token = (string) $this->request->getPost('token');
        $path  = self::DIR . $token . '.json';
        if (! preg_match('/^[0-9a-f]{32}$/', $token) || ! is_file($path)) {
            return redirect()->to($back)->with('error', 'Data periksa sudah kedaluwarsa. Unggah berkasnya lagi.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || (int) ($data['oleh'] ?? 0) !== (int) session()->get('admin_id')) {
            return redirect()->to($back)->with('error', 'Data periksa tidak valid. Unggah berkasnya lagi.');
        }
        $soal = array_map(static fn ($s) => ['gambar' => array_map(static fn ($g) => ['nama' => $g['nama'], 'data' => (string) base64_decode($g['data'], true)], $s['gambar'])] + $s, $data['soal']);
        $ganti = $this->request->getPost('mode') === 'ganti';
        if (! empty($data['cek']['wajib']) && $this->request->getPost('yakin') !== '1') {
            return redirect()->to($back . '?kiriman=' . (int) $data['kiriman'])->with('error', 'Centang "Mapel tujuan sudah benar" sebelum memasukkan soal kiriman. Ulangi Periksa.');
        }
        try {
            $item = MoodleBridge::call('import_soal', [
                'courseid' => (int) $data['mapel'],
                'xml'      => SoalRtf::keXml($soal),
                'ganti'    => $ganti,
                'timelimit' => Tools::durasiDetik(),
            ])[0] ?? [];
        } catch (\Throwable $e) {
            return redirect()->to($back . '?mapel=' . (int) $data['mapel'])->with('error', 'Gagal memasukkan soal: ' . $e->getMessage());
        }
        @unlink($path);
        if ((int) ($data['kiriman'] ?? 0) > 0) {
            db_connect()->table('kirim_soal')->where('id', (int) $data['kiriman'])
                ->update(['masuk_at' => date('Y-m-d H:i:s'), 'courseid' => (int) $data['mapel']]);
            if (! empty($data['cek']['kunciPeta'])) {
                // Pasangan mapel ini sudah dipastikan: kiriman berikutnya (juga semester lain) memakainya.
                KirimSoal::simpanPeta($data['cek']['kunciPeta'], (string) $data['cek']['shortname']);
            }
        }
        ActivityLog::add('masukkan_soal', ($data['berkas'] ?? '') . ' → ' . ($item['quiz'] ?? '?') . ': ' . (int) ($item['ditambah'] ?? 0) . ' soal' . ($ganti ? ' (ganti)' : ''));

        $pesan = (int) ($item['ditambah'] ?? 0) . ' soal masuk ke quiz "' . ($item['quiz'] ?? '') . '"'
            . (! empty($item['quiz_baru']) ? ' (quiz baru dibuat)' : '') . '; quiz kini berisi ' . (int) ($item['total'] ?? 0) . ' soal, nilai maksimal 100.';

        return redirect()->to($back . '?mapel=' . (int) $data['mapel'])->with('success', $pesan);
    }

    /** Mapel Moodle (tanpa halaman depan) beserta kategori, jumlah soal di quiz, dan jumlah attempt. */
    private function daftarMapel(): array
    {
        $c  = Moodle::t('course');
        $cc = Moodle::t('course_categories');
        $q  = Moodle::t('quiz');
        $s  = Moodle::t('quiz_slots');
        $a  = Moodle::t('quiz_attempts');

        return Moodle::db()->query(
            "SELECT c.id, c.fullname, c.shortname, cc.name AS kategori,
                    (SELECT COUNT(*) FROM {$s} sl JOIN {$q} qq ON qq.id = sl.quizid WHERE qq.course = c.id) AS soal,
                    (SELECT COUNT(*) FROM {$a} at JOIN {$q} qq ON qq.id = at.quiz WHERE qq.course = c.id AND at.preview = 0) AS attempt
               FROM {$c} c JOIN {$cc} cc ON cc.id = c.category
              WHERE c.id > 1 ORDER BY cc.sortorder, c.sortorder"
        )->getResultArray();
    }

    private function infoMapel(int $courseId): ?array
    {
        foreach ($this->daftarMapel() as $m) {
            if ((int) $m['id'] === $courseId) {
                return $m;
            }
        }

        return null;
    }

    /** Siapkan folder dan hapus hasil periksa lebih dari 1 hari. */
    private function bersihkan(): void
    {
        if (! is_dir(self::DIR)) {
            mkdir(self::DIR, 0750, true);
        }
        foreach (glob(self::DIR . '*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
