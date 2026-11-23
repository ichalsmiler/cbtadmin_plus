<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
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
    public function index(): string
    {
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
        $tab     = $this->request->getGet('tab') === 'edit' || ! $admin ? 'edit' : 'masuk';
        $dipilih = (int) $this->request->getGet('mapel');
        $daftar  = null;
        $gagal   = null;
        if ($tab === 'edit' && $dipilih > 1) {
            try {
                $daftar = MoodleBridge::call('soal_daftar', ['courseid' => $dipilih])[0] ?? null;
            } catch (\Throwable $e) {
                $gagal = $e->getMessage();
            }
        }

        return $this->render('soal/index', [
            'title'   => 'Soal',
            'tab'     => $tab,
            'admin'   => $admin,
            'mapel'   => $mapel,
            'error'   => $error,
            'dipilih' => $dipilih,
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
        $back     = site_url('soal') . '?tab=edit&mapel=' . $courseId . ($nomor > 0 ? '#soal-' . $nomor : '');
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
     * gambar yang masih data URI berarti tempelan baru (tidak punya berkas di Moodle), jadi dibuang, begitu juga skrip.
     */
    private static function bersihkanHtml(string $html): string
    {
        $html = (string) preg_replace('/<img\b[^>]*\bsrc="data:[^"]*"[^>]*>/i', '', $html);
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
        if ($courseId <= 1) {
            return redirect()->to($back)->with('error', 'Pilih mapel tujuan.');
        }
        if (! $file || ! $file->isValid() || ! in_array(strtolower($file->getClientExtension()), ['rtf', 'doc'], true)) {
            return redirect()->to($back . '?mapel=' . $courseId)->with('error', 'Pilih berkas soal .rtf (atau .rtf.doc) yang valid.');
        }
        try {
            $hasil = SoalRtf::urai((string) file_get_contents($file->getTempName()));
            $info  = $this->infoMapel($courseId);
        } catch (\Throwable $e) {
            return redirect()->to($back . '?mapel=' . $courseId)->with('error', 'Berkas tidak bisa dibaca: ' . $e->getMessage());
        }
        if ($info === null) {
            return redirect()->to($back)->with('error', 'Mapel tidak ditemukan di Moodle.');
        }

        $this->bersihkan();
        $token = bin2hex(random_bytes(16));
        $simpan = [
            'mapel'  => $courseId,
            'berkas' => $file->getClientName(),
            'oleh'   => (int) session()->get('admin_id'),
            'soal'   => array_map(static fn ($s) => ['gambar' => array_map(static fn ($g) => ['nama' => $g['nama'], 'data' => base64_encode($g['data'])], $s['gambar'])] + $s, $hasil['soal']),
        ];
        if (file_put_contents(self::DIR . $token . '.json', json_encode($simpan)) === false) {
            return redirect()->to($back)->with('error', 'Folder writable/soal tidak bisa ditulisi.');
        }

        return $this->render('soal/periksa', [
            'title'      => 'Soal · Periksa',
            'token'      => $token,
            'info'       => $info,
            'berkas'     => $file->getClientName(),
            'soal'       => $simpan['soal'],
            'peringatan' => $hasil['peringatan'],
            'gambar'     => $hasil['gambar'],
        ]);
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
