<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\AnalisisSoal;
use App\Libraries\Excel;
use App\Libraries\Moodle;
use App\Libraries\NilaiUjian;
use App\Libraries\Pdf;
use App\Libraries\Tingkat;

class HasilUjian extends PanelController
{
    public function index(): string
    {
        $data = $this->collect();
        $data['analisis'] = null;
        if ($data['tab'] === 'analisis' && $data['quiz']) {
            try {
                $data['analisis'] = AnalisisSoal::analisis(AnalisisSoal::jawaban((int) $data['quiz']['id'], array_column($data['rows'], 'userid')));
            } catch (\Throwable $e) {
                $data['error'] = true;
                log_message('error', 'Analisis soal: ' . $e->getMessage());
            }
        }

        $data['rekap'] = null;
        if (! $data['quiz'] && ! $data['error']) {
            try {
                $data['rekap'] = $this->rekap($data['kelas'], $data['tingkat']);
            } catch (\Throwable $e) {
                $data['error'] = true;
                log_message('error', 'Rekap nilai: ' . $e->getMessage());
            }
        }

        return $this->render('hasil/index', $data + [
            'title'     => 'Hasil Ujian',
            'kelasList' => array_column(db_connect()->table('peserta')->distinct()->select('kelas')->where('kelas !=', '')->orderBy('kelas')->get()->getResultArray(), 'kelas'),
        ]);
    }

    public function export()
    {
        $data = $this->collect();
        if (! $data['quiz']) {
            return redirect()->to(site_url('hasil_ujian'))->with('error', 'Pilih quiz dulu.');
        }
        ActivityLog::add('export_hasil', $data['quiz']['name'] . ' (excel)');

        return $this->response->download(
            'hasil_' . preg_replace('/[^A-Za-z0-9]+/', '_', $data['quiz']['name']) . '_' . date('Ymd_His') . '.xlsx',
            NilaiUjian::excel($data['rows'], (int) $data['quiz']['id']) // + lembar Analisis Soal (tingkat kesukaran)
        );
    }

    /**
     * Tanpa memilih quiz satu per satu: ZIP berisi PDF + Excel nilai tiap mapel (quiz yang sudah ada nilainya
     * untuk siswa sesuai Tingkatan/Kelas), isi tiap berkas sama dengan unduhan saat satu quiz dipilih.
     */
    public function zip()
    {
        $filter = $this->collect();
        $quiz   = $this->rekap($filter['kelas'], $filter['tingkat'])['quiz'];
        if ($quiz === []) {
            return redirect()->to(site_url('hasil_ujian'))->with('error', 'Belum ada nilai untuk diunduh (sesuai filter).');
        }

        try {
            ['isi' => $isi, 'jumlah' => $jumlah] = NilaiUjian::zip($quiz, $filter['kelas'], $filter['tingkat']);
        } catch (\Throwable $e) {
            log_message('error', 'ZIP nilai: ' . $e->getMessage());

            return redirect()->to(site_url('hasil_ujian'))->with('error', 'Berkas ZIP tidak bisa dibuat.');
        }
        ActivityLog::add('export_hasil', 'ZIP ' . $jumlah . ' mapel');

        $label = $filter['kelas'] !== '' ? $filter['kelas'] : ($filter['tingkat'] !== '' ? 'kelas_' . $filter['tingkat'] : 'semua_kelas');

        return $this->response->download('nilai_per_mapel_' . preg_replace('/[^A-Za-z0-9]+/', '_', $label) . '_' . date('Ymd_His') . '.zip', $isi);
    }

    /** Excel jawaban per nomor soal: B benar, S salah, – kosong, ? belum dinilai (sesuai filter halaman). */
    public function exportSoal()
    {
        $data = $this->collect();
        if (! $data['quiz']) {
            return redirect()->to(site_url('hasil_ujian'))->with('error', 'Pilih quiz dulu.');
        }
        $soal = AnalisisSoal::jawaban((int) $data['quiz']['id'], array_column($data['rows'], 'userid'));

        $headers = ['no', 'nomor_peserta', 'nama', 'kelas', 'ruang', 'status', 'nilai', 'benar', 'salah', 'kosong'];
        foreach ($soal['soal'] as $s) {
            $headers[] = (string) $s['no'];
        }
        $rows = [];
        foreach ($data['rows'] as $i => $r) {
            $per   = $soal['jawaban'][$r['userid']] ?? null;
            $kode  = array_map(static fn ($s) => $per === null ? '' : AnalisisSoal::kode($per[$s['slot']] ?? null), $soal['soal']);
            $count = array_count_values($kode);
            $rows[] = array_merge([
                $i + 1, $r['nomor_peserta'], $r['nama'], $r['kelas'], $r['ruang'] ?? '', self::label($r['status']),
                $r['nilai'] === null ? '' : number_format($r['nilai'], 2, ',', ''),
                $per === null ? '' : ($count['B'] ?? 0), $per === null ? '' : ($count['S'] ?? 0), $per === null ? '' : ($count['–'] ?? 0),
            ], $kode);
        }
        ActivityLog::add('export_hasil', $data['quiz']['name'] . ' (jawaban per soal)');

        return $this->response->download(
            'jawaban_' . preg_replace('/[^A-Za-z0-9]+/', '_', $data['quiz']['name']) . '_' . date('Ymd_His') . '.xlsx',
            Excel::build($headers, $rows)
        );
    }

    /** Excel analisis butir soal (indeks kesukaran dan daya beda). */
    public function exportAnalisis()
    {
        $data = $this->collect();
        if (! $data['quiz']) {
            return redirect()->to(site_url('hasil_ujian'))->with('error', 'Pilih quiz dulu.');
        }
        $an   = AnalisisSoal::analisis(AnalisisSoal::jawaban((int) $data['quiz']['id'], array_column($data['rows'], 'userid')));
        $rows = [];
        foreach ($an['butir'] as $b) {
            $rows[] = [
                $b['no'], $b['teks'] !== '' ? $b['teks'] : $b['nama'], $b['benar'], $b['salah'], $b['kosong'],
                $b['p'] === null ? '' : number_format($b['p'], 2, ',', ''), $b['tingkat'],
                $b['d'] === null ? '' : number_format($b['d'], 2, ',', ''), $b['kualitas'],
            ];
        }
        ActivityLog::add('export_hasil', $data['quiz']['name'] . ' (analisis soal)');

        return $this->response->download(
            'analisis_' . preg_replace('/[^A-Za-z0-9]+/', '_', $data['quiz']['name']) . '_' . date('Ymd_His') . '.xlsx',
            Excel::build(['no', 'soal', 'benar', 'salah', 'kosong', 'indeks_kesukaran', 'tingkat', 'daya_beda', 'kualitas'], $rows)
        );
    }

    public function pdf()
    {
        $data = $this->collect();
        if (! $data['quiz']) {
            return redirect()->to(site_url('hasil_ujian'))->with('error', 'Pilih quiz dulu.');
        }
        ActivityLog::add('export_hasil', $data['quiz']['name'] . ' (pdf)');

        return Pdf::respond('pdf/hasil', $data, 'hasil_' . $data['quiz']['name'] . '.pdf');
    }

    public static function label(string $status): string
    {
        return NilaiUjian::label($status);
    }

    public static function durasi(int $seconds): string
    {
        return NilaiUjian::durasi($seconds);
    }

    /** @return array{quiz: list<array>, rows: list<array>, siswa: int} */
    private function rekap(string $kelas, string $tingkat): array
    {
        return NilaiUjian::rekap($kelas, $tingkat);
    }

    private function collect(?int $quizId = null): array
    {
        $quizId ??= (int) $this->request->getGet('quiz');
        $ruangId = '';
        $kelas   = (string) $this->request->getGet('kelas');
        // Parameter "selesai terlalu cepat" (persen): batas waktu quiz, median durasi, nilai minimal.
        $param = [
            'pw' => max(1, min(100, (int) ($this->request->getGet('pw') ?: 25))),
            'pm' => max(1, min(100, (int) ($this->request->getGet('pm') ?: 50))),
            'pn' => max(0, min(100, (int) ($this->request->getGet('pn') ?? 80) ?: 80)),
        ];
        $data    = [
            'quiz' => null, 'quizzes' => [], 'rows' => [], 'stats' => null, 'error' => false,
            'quizId' => $quizId, 'ruangId' => $ruangId, 'kelas' => $kelas, 'tingkat' => Tingkat::dariRequest(),
            'tab' => in_array($this->request->getGet('tab'), ['cepat', 'analisis'], true) ? $this->request->getGet('tab') : 'semua', 'param' => $param, 'cepat' => [], 'median' => null,
        ];

        try {
            $data['quizzes'] = Moodle::quizzes();
            $data = NilaiUjian::hasil($quizId, $kelas, $data['tingkat'], $ruangId, $param) + $data;
        } catch (\Throwable $e) {
            $data['error'] = true;
            log_message('error', 'Hasil Ujian: ' . $e->getMessage());
        }

        return $data;
    }
}
