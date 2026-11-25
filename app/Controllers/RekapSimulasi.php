<?php

namespace App\Controllers;

use App\Libraries\ActiveTest;
use App\Libraries\ActivityLog;
use App\Libraries\Moodle;
use App\Libraries\Tingkat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Dokumen → Rekap Simulasi: per kelas, berapa siswa (Data CBTAdmin) yang sudah ikut simulasi, mengerjakan,
 * dan belum ikut sama sekali, untuk satu kategori ujian Moodle (mis. SIMULASI) atau satu mapel di dalamnya.
 */
class RekapSimulasi extends PanelController
{
    public function index(): string
    {
        $data = $this->data();

        return $this->render('dokumen/rekap_simulasi', ['title' => 'Rekap Simulasi'] + $data);
    }

    /** Excel: lembar Ringkasan per kelas + lembar Sudah ikut + lembar Belum ikut (nama per kelas). */
    public function export()
    {
        $d = $this->data();
        if ($d['kategori'] === null) {
            return redirect()->to(site_url('dokumen/rekap_simulasi'))->with('error', 'Pilih kategori ujian dulu.');
        }
        $book = new Spreadsheet();

        $ringkas = $book->getActiveSheet()->setTitle('Ringkasan');
        $baris   = [['Kelas', 'Jumlah siswa', 'Sudah ikut', 'Mengerjakan', 'Belum ikut', '% sudah ikut']];
        foreach ($d['kelas'] as $k) {
            $baris[] = [$k['kelas'], $k['total'], $k['selesai'], $k['proses'], $k['belum'], $k['persen'] . '%'];
        }
        $t       = $d['total'];
        $baris[] = ['TOTAL', $t['total'], $t['selesai'], $t['proses'], $t['belum'], $t['persen'] . '%'];
        self::isiLembar($ringkas, $baris);

        $sudah = $book->createSheet()->setTitle('Sudah ikut');
        $baris = [['No', 'Kelas', 'No. Peserta', 'Nama', 'Username', 'Ruang', 'Selesai pada']];
        $no    = 0;
        foreach ($d['kelas'] as $k) {
            foreach ($k['siswa'] as $s) {
                if ($s['status'] === 'selesai') {
                    $baris[] = [++$no, $k['kelas'], $s['nomor_peserta'], $s['nama'], $s['username'], $s['ruang'],
                        $s['selesai_pada'] ? date('d/m/Y H:i', $s['selesai_pada']) : ''];
                }
            }
        }
        self::isiLembar($sudah, $baris);

        $belum = $book->createSheet()->setTitle('Belum ikut');
        $baris = [['No', 'Kelas', 'No. Peserta', 'Nama', 'Username', 'Ruang', 'Keterangan']];
        $no    = 0;
        foreach ($d['kelas'] as $k) {
            foreach ($k['siswa'] as $s) {
                if ($s['status'] !== 'selesai') {
                    $baris[] = [++$no, $k['kelas'], $s['nomor_peserta'], $s['nama'], $s['username'], $s['ruang'], $s['keterangan']];
                }
            }
        }
        self::isiLembar($belum, $baris);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $isi = (string) ob_get_clean();

        $nama = preg_replace('/[^A-Za-z0-9]+/', '_', $d['quiz']['name'] ?? $d['kategori']['name']);
        ActivityLog::add('rekap_simulasi', ($d['quiz']['name'] ?? $d['kategori']['name']) . ': ' . $t['selesai'] . '/' . $t['total'] . ' sudah ikut');

        return $this->response->download('rekap_simulasi_' . trim($nama, '_') . '_' . ($d['tingkat'] !== '' ? 'kelas_' . $d['tingkat'] : 'semua_kelas') . '.xlsx', $isi);
    }

    /**
     * PDF siswa yang belum simulasi (belum ikut + mengerjakan), SATU berkas untuk semua angkatan (dipisah per angkatan X, XI, XII;
     * ?tingkat=X|XI|XII opsional). Tingkatan dari GET
     * (tidak mengubah filter tersimpan).
     */
    public function belum()
    {
        $tingkat = (string) $this->request->getGet('tingkat');
        $tingkat = in_array($tingkat, Tingkat::LIST, true) ? $tingkat : '';
        $d = self::kumpulkan((int) $this->request->getGet('kat'), (int) $this->request->getGet('quiz'), $tingkat);
        if ($d['kategori'] === null) {
            return redirect()->to(site_url('dokumen/rekap_simulasi'))->with('error', $d['error'] ?: 'Pilih kategori ujian dulu.');
        }
        $kelas = [];
        foreach ($d['kelas'] as $k) {
            $belum = array_values(array_filter($k['siswa'], static fn ($s) => $s['status'] !== 'selesai'));
            // Dikelompokkan per angkatan (bukan per kelas): satu tabel per angkatan, berkolom Kelas.
            $t = Tingkat::of($k['kelas']) ?: 'Lainnya';
            $kelas[$t] ??= ['kelas' => $t, 'total' => 0, 'siswa' => []];
            $kelas[$t]['total'] += $k['total'];
            foreach ($belum as $s) {
                $kelas[$t]['siswa'][] = ['kelas' => $k['kelas']] + $s;
            }
        }
        $urut  = array_flip(Tingkat::LIST);
        $kelas = array_filter($kelas, static fn ($a) => $a['siswa'] !== []);
        uksort($kelas, static fn ($a, $b) => ($urut[$a] ?? 9) <=> ($urut[$b] ?? 9));
        foreach ($kelas as &$a) {
            usort($a['siswa'], static fn ($x, $y) => strnatcasecmp((string) $x['kelas'], (string) $y['kelas']) ?: strnatcasecmp((string) $x['nama'], (string) $y['nama']));
        }
        unset($a);
        $kelas = array_values($kelas);
        $judul = $d['quiz']['name'] ?? $d['kategori']['name'];
        ActivityLog::add('rekap_simulasi_belum', $judul . ($tingkat !== '' ? ' kelas ' . $tingkat : ' semua angkatan') . ': ' . array_sum(array_map(static fn ($k) => count($k['siswa']), $kelas)) . ' siswa belum');

        return \App\Libraries\Pdf::respond('pdf/simulasi_belum', [
            'kelas'   => $kelas,
            'tingkat' => $tingkat,
            'judul'   => $judul . ($d['quiz'] === null ? ' (semua mapel)' : ''),
            'total'   => $d['total'],
        ], 'belum_simulasi_' . ($tingkat !== '' ? 'kelas_' . $tingkat : 'semua_angkatan') . '.pdf');
    }

    /** @param list<list<scalar|null>> $baris */
    private static function isiLembar(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $lembar, array $baris): void
    {
        foreach ($baris as $r => $row) {
            foreach (array_values($row) as $c => $v) {
                $sel = [$c + 1, $r + 1];
                if (is_int($v)) {
                    $lembar->setCellValue($sel, $v);
                } else {
                    $lembar->setCellValueExplicit($sel, (string) ($v ?? ''), DataType::TYPE_STRING);
                }
            }
        }
        $lembar->getStyle([1, 1, count($baris[0]), 1])->getFont()->setBold(true);
        foreach (range(1, count($baris[0])) as $c) {
            $lembar->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    /** Pilihan dari permintaan + rekap per kelas. */
    private function data(): array
    {
        return self::kumpulkan((int) $this->request->getGet('kat'), (int) $this->request->getGet('quiz'), Tingkat::dariRequest());
    }

    /**
     * Rekap untuk kategori/mapel/tingkatan tertentu (kat 0 = kategori bernama SIMULASI, lalu tes aktif; quiz 0 = semua mapel).
     * Dipakai juga oleh dashboard publik (SimulasiPublik).
     */
    public static function kumpulkan(int $katId, int $quizId, string $tingkat): array
    {
        $hasil    = ['tingkat' => $tingkat, 'kategoriList' => [], 'quizList' => [], 'kategori' => null, 'quiz' => null,
                     'kelas' => [], 'total' => self::hitung([]), 'error' => ''];

        try {
            // Kategori ujian tingkat atas (selain SESI NONAKTIF). Bawaan: yang bernama "SIMULASI…", lalu tes aktif.
            $kategori = array_values(array_filter(Moodle::categories(),
                static fn ($c) => (int) $c['parent'] === 0 && ! ActiveTest::isNonaktif($c['name'])));
            $hasil['kategoriList'] = $kategori;

            if ($katId === 0) {
                foreach ($kategori as $c) {
                    if (stripos((string) $c['name'], 'simulasi') !== false) {
                        $katId = (int) $c['id'];
                        break;
                    }
                }
            }
            $katId = $katId ?: ActiveTest::categoryId();
            foreach ($kategori as $c) {
                if ((int) $c['id'] === $katId) {
                    $hasil['kategori'] = $c;
                }
            }
            if ($hasil['kategori'] === null) {
                return $hasil;
            }

            $quizzes = Moodle::quizzes($katId);
            usort($quizzes, static fn ($a, $b) => strnatcasecmp((string) $a['course_short'], (string) $b['course_short']));
            $hasil['quizList'] = $quizzes;
            foreach ($quizzes as $q) {
                if ((int) $q['id'] === $quizId) {
                    $hasil['quiz'] = $q;
                }
            }
            $quizIds = $hasil['quiz'] !== null ? [(int) $hasil['quiz']['id']] : array_map('intval', array_column($quizzes, 'id'));

            $hasil['kelas'] = self::rekap($tingkat, $quizIds);
            $hasil['total'] = self::hitung(array_merge(...array_values(array_map(static fn ($k) => $k['siswa'], $hasil['kelas'])) ?: [[]]));
        } catch (\Throwable $e) {
            log_message('error', 'Rekap simulasi: ' . $e->getMessage());
            $hasil['error'] = 'Database Moodle tidak bisa dibaca.';
        }

        return $hasil;
    }

    /**
     * Siswa Data CBTAdmin (tingkatan terpilih) dikelompokkan per kelas. Status tiap siswa dari percobaan Moodle di
     * quiz terpilih: selesai (ada percobaan terkirim) > proses (sedang / tidak terkirim) > belum (tanpa percobaan).
     *
     * @param list<int> $quizIds
     */
    private static function rekap(string $tingkat, array $quizIds): array
    {
        $db = db_connect()->table('peserta p')
            ->select('p.nomor_peserta, p.nama, p.kelas, p.username, r.nama AS ruang')
            ->join('ruang r', 'r.id = p.ruang_id', 'left');
        if ($tingkat !== '') {
            $db->where('p.kelas REGEXP ' . db_connect()->escape(Tingkat::regexp($tingkat)), null, false);
        }
        $siswa = $db->get()->getResultArray();
        if ($siswa === []) {
            return [];
        }

        // Username → id Moodle (akun yang belum dibuat di Moodle tetap dihitung, sebagai "belum ikut").
        $mdl   = Moodle::db();
        $idMap = [];
        foreach (array_chunk(array_column($siswa, 'username'), 1000) as $potong) {
            foreach ($mdl->table(Moodle::t('user'))->select('id, username')->where('deleted', 0)->whereIn('username', $potong)->get()->getResultArray() as $u) {
                $idMap[$u['username']] = (int) $u['id'];
            }
        }

        $state = [];
        $waktu = [];   // waktu kirim percobaan terakhir yang selesai, per userid
        if ($quizIds !== [] && $idMap !== []) {
            $q = $mdl->table(Moodle::t('quiz_attempts'))->select('userid, state, timefinish')
                ->where('preview', 0)->whereIn('quiz', $quizIds);
            foreach (array_chunk(array_values($idMap), 1000) as $potong) {
                foreach ((clone $q)->whereIn('userid', $potong)->get()->getResultArray() as $a) {
                    $uid = (int) $a['userid'];
                    $s   = $a['state'] === 'finished' ? 'selesai' : 'proses';
                    if (($state[$uid] ?? '') !== 'selesai') {
                        $state[$uid] = $s;
                    }
                    if ($s === 'selesai') {
                        $waktu[$uid] = max($waktu[$uid] ?? 0, (int) $a['timefinish']);
                    }
                }
            }
        }

        $kelas = [];
        foreach ($siswa as $s) {
            $uid    = $idMap[$s['username']] ?? null;
            $status = $uid !== null ? ($state[$uid] ?? 'belum') : 'belum';
            $nama   = $s['kelas'] !== '' ? $s['kelas'] : '(tanpa kelas)';
            $kelas[$nama][] = [
                'nomor_peserta' => (string) $s['nomor_peserta'],
                'nama'          => (string) $s['nama'],
                'username'      => (string) $s['username'],
                'ruang'         => (string) ($s['ruang'] ?? ''),
                'status'        => $status,
                'selesai_pada'  => $uid !== null ? ($waktu[$uid] ?? 0) : 0,
                'keterangan'    => $uid === null ? 'Akun Moodle belum ada' : ($status === 'proses' ? 'Mengerjakan / belum terkirim' : ($status === 'belum' ? 'Belum ikut' : '')),
            ];
        }
        uksort($kelas, 'strnatcasecmp');

        $hasil = [];
        foreach ($kelas as $nama => $daftar) {
            usort($daftar, static fn ($a, $b) => strnatcasecmp($a['nama'], $b['nama']));
            $hasil[] = ['kelas' => $nama, 'siswa' => $daftar] + self::hitung($daftar);
        }

        return $hasil;
    }

    /** Jumlah per status + persentase sudah ikut. */
    private static function hitung(array $siswa): array
    {
        $n = ['total' => 0, 'selesai' => 0, 'proses' => 0, 'belum' => 0];
        foreach ($siswa as $s) {
            if (! isset($s['status'])) {
                continue;
            }
            $n['total']++;
            $n[$s['status']]++;
        }
        $n['persen'] = $n['total'] > 0 ? (int) round($n['selesai'] * 100 / $n['total']) : 0;

        return $n;
    }
}
