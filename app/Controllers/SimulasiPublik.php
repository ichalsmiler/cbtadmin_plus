<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Settings;
use App\Libraries\Tingkat;

/**
 * Dashboard Simulasi publik `/simulasi` (tanpa login): progres simulasi per kelas untuk wali kelas/guru.
 * Data sama dengan Dokumen → Rekap Simulasi, tetapi hanya nama siswa (tanpa username/no. peserta/ruang).
 * Dinyalakan/dimatikan admin (setelan `simulasi_publik`, bawaan aktif). Hasil di-cache 30 detik.
 */
class SimulasiPublik extends BaseController
{
    protected $helpers = ['url', 'form'];

    public static function aktif(): bool
    {
        return Settings::get('simulasi_publik', '1') === '1';
    }

    public function index()
    {
        $sekolah = Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', ''));
        if (! self::aktif()) {
            return $this->response->setStatusCode(404)->setBody(view('simulasi/publik', [
                'nonaktif' => true, 'sekolah' => $sekolah, 'version' => PanelController::VERSION,
            ]));
        }

        $kat     = max(0, (int) $this->request->getGet('kat'));
        $quiz    = max(0, (int) $this->request->getGet('quiz'));
        $tingkat = (string) $this->request->getGet('tingkat');
        $tingkat = in_array($tingkat, Tingkat::LIST, true) ? $tingkat : '';

        $kunci = 'simpub_' . $kat . '_' . $quiz . '_' . $tingkat;
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
                ], $d['kelas']),
                'diperbarui'   => time(),
            ];
            if ($data['error'] === '') {
                cache()->save($kunci, $data, 30);
            }
        }

        return view('simulasi/publik', $data + [
            'nonaktif' => false, 'sekolah' => $sekolah, 'tingkat' => $tingkat, 'version' => PanelController::VERSION,
        ]);
    }

    /** Admin: nyalakan/matikan dashboard publik (dari Dokumen → Rekap Simulasi). */
    public function setel()
    {
        $nyala = $this->request->getPost('aktif') === '1';
        Settings::set('simulasi_publik', $nyala ? '1' : '0');
        ActivityLog::add('simulasi_publik', $nyala ? 'dashboard simulasi publik diaktifkan' : 'dashboard simulasi publik dimatikan');

        return redirect()->to(site_url('dokumen/rekap_simulasi'))
            ->with('success', $nyala ? 'Dashboard simulasi publik aktif.' : 'Dashboard simulasi publik dimatikan.');
    }
}
