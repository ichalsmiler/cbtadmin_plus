<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Settings;
use App\Libraries\Tingkat;

/**
 * Dashboard Simulasi publik `/simulasi` (tanpa login): progres simulasi per kelas untuk wali kelas/guru.
 * Data sama dengan Dokumen → Rekap Simulasi, tetapi hanya nama siswa (tanpa username/no. peserta/ruang).
 * Setelan admin (Dokumen → Rekap Simulasi): `simulasi_publik` tampil/tidak (bawaan tampil), `simulasi_sembunyi_sudah` sembunyikan
 * nama siswa yang sudah ikut (bawaan ya; nama yang belum ikut selalu tampil), `simulasi_kat` kunci kategori (0 = bebas), `simulasi_quiz` kunci mapel
 * ('' = bebas, '0' = semua mapel, N = id quiz). Hasil di-cache 30 detik.
 */
class SimulasiPublik extends BaseController
{
    protected $helpers = ['url', 'form'];

    public static function aktif(): bool
    {
        return Settings::get('simulasi_publik', '1') === '1';
    }

    /** @return array{aktif: bool, sembunyiSudah: bool, kat: int, quiz: int|null} */
    public static function setelan(): array
    {
        $quiz = Settings::get('simulasi_quiz', '-');
        $kat  = (int) Settings::get('simulasi_kat', '0');

        return [
            'aktif' => self::aktif(),
            'sembunyiSudah' => Settings::get('simulasi_sembunyi_sudah', '1') === '1',
            'kat'   => $kat,
            'quiz'  => $kat > 0 && ctype_digit($quiz) ? (int) $quiz : null,
        ];
    }

    public function index()
    {
        $sekolah = Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', ''));
        if (! self::aktif()) {
            return $this->response->setStatusCode(404)->setBody(view('simulasi/publik', [
                'nonaktif' => true, 'sekolah' => $sekolah, 'version' => PanelController::VERSION,
            ]));
        }

        $sp      = self::setelan();
        $kat     = $sp['kat'] > 0 ? $sp['kat'] : max(0, (int) $this->request->getGet('kat'));
        $quiz    = $sp['quiz'] ?? max(0, (int) $this->request->getGet('quiz'));
        $tingkat = (string) $this->request->getGet('tingkat');
        $tingkat = in_array($tingkat, Tingkat::LIST, true) ? $tingkat : '';

        $kunci = 'simpub2_' . $kat . '_' . $quiz . '_' . $tingkat;
        $data  = cache($kunci);
        if (! is_array($data)) {
            $d    = RekapSimulasi::kumpulkan($kat, $quiz, $tingkat);
            $data = [
                'kategoriList' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name']], $d['kategoriList']),
                'quizList'     => array_map(static fn ($q) => ['id' => (int) $q['id'], 'name' => (string) $q['name'], 'course_short' => (string) $q['course_short']], $d['quizList']),
                'kategori'     => $d['kategori'] !== null ? ['id' => (int) $d['kategori']['id'], 'name' => (string) $d['kategori']['name']] : null,
                'quiz'         => $d['quiz'] !== null ? ['id' => (int) $d['quiz']['id'], 'name' => (string) $d['quiz']['name']] : null,
                'total'        => $d['total'],
                'error'        => $d['error'],
                'kelas'        => array_map(static fn ($k) => [
                    'kelas'   => $k['kelas'], 'total' => $k['total'], 'selesai' => $k['selesai'], 'proses' => $k['proses'],
                    'belum'   => $k['belum'], 'persen' => $k['persen'],
                    'kurang'  => array_values(array_map(static fn ($s) => ['nama' => $s['nama'], 'proses' => $s['status'] === 'proses'],
                        array_filter($k['siswa'], static fn ($s) => $s['status'] !== 'selesai'))),
                    'sudah'   => array_values(array_map(static fn ($s) => ['nama' => $s['nama'], 'waktu' => (int) $s['selesai_pada']],
                        array_filter($k['siswa'], static fn ($s) => $s['status'] === 'selesai'))),
                ], $d['kelas']),
                'diperbarui'   => time(),
            ];
            if ($data['error'] === '') {
                cache()->save($kunci, $data, 30);
            }
        }

        return view('simulasi/publik', $data + [
            'nonaktif' => false, 'sekolah' => $sekolah, 'tingkat' => $tingkat, 'version' => PanelController::VERSION,
            'kunciKat' => $sp['kat'] > 0, 'kunciQuiz' => $sp['quiz'] !== null, 'tampilSudah' => ! $sp['sembunyiSudah'],
        ]);
    }

    /** Admin: simpan setelan dashboard publik (dari Dokumen → Rekap Simulasi). */
    public function setel()
    {
        $post   = $this->request->getPost();
        $aktif  = ! empty($post['aktif']);
        $sembunyi = ! empty($post['sembunyi_sudah']);
        $kat    = max(0, (int) ($post['kat'] ?? 0));
        $quiz   = max(0, (int) ($post['quiz'] ?? 0));
        $kQuiz  = ! empty($post['kunci_quiz']) && $kat > 0;
        $kKat   = (! empty($post['kunci_kat']) || $kQuiz) && $kat > 0;

        Settings::set('simulasi_publik', $aktif ? '1' : '0');
        Settings::set('simulasi_sembunyi_sudah', $sembunyi ? '1' : '0');
        Settings::set('simulasi_kat', $kKat ? (string) $kat : '0');
        // Settings::get() menganggap '' sebagai kosong; '-' = mapel tidak dikunci.
        Settings::set('simulasi_quiz', $kQuiz ? (string) $quiz : '-');

        ActivityLog::add('simulasi_publik', ($aktif ? 'tampil' : 'tidak tampil') . '; sembunyikan yang sudah ' . ($sembunyi ? 'ya' : 'tidak')
            . '; kunci kategori ' . ($kKat ? '#' . $kat : 'tidak') . '; kunci mapel ' . ($kQuiz ? ($quiz ? '#' . $quiz : 'semua') : 'tidak'));

        parse_str((string) ($post['kembali'] ?? ''), $q);
        $kembali = http_build_query(array_filter([
            'kat' => (int) ($q['kat'] ?? 0) ?: '', 'quiz' => (int) ($q['quiz'] ?? 0) ?: '',
            'tingkat' => in_array($q['tingkat'] ?? '', Tingkat::LIST, true) ? $q['tingkat'] : '',
        ], static fn ($v) => $v !== ''));

        return redirect()->to(site_url('dokumen/rekap_simulasi') . ($kembali !== '' ? '?' . $kembali : ''))
            ->with('success', 'Setelan dashboard simulasi publik disimpan.');
    }
}
