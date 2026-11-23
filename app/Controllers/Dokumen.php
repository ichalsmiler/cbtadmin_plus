<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Excel;
use App\Libraries\Moodle;
use App\Libraries\Pdf;
use App\Libraries\QuizStatus;
use App\Libraries\Settings;
use App\Libraries\Tingkat;

class Dokumen extends PanelController
{
    /** Kartu maksimal dalam satu PDF; lebih dari itu dipecah per ruang/kelas. */
    private const MAKS_KARTU = 400;

    // ------------------------------------------------------------------ Kartu / Daftar Hadir

    public function cetak(): string
    {
        $db      = db_connect();
        $quizzes = [];

        try {
            $quizzes = Moodle::quizzes();
        } catch (\Throwable $e) {
            log_message('error', 'Dokumen: ' . $e->getMessage());
        }

        return $this->render('dokumen/cetak', [
            'title'     => 'Kartu / Daftar Hadir',
            'ruangList' => $db->query('SELECT r.id, r.nama, (SELECT COUNT(*) FROM peserta p WHERE p.ruang_id = r.id) AS jumlah, (SELECT GROUP_CONCAT(DISTINCT p.kelas ORDER BY p.kelas SEPARATOR \'|\') FROM peserta p WHERE p.ruang_id = r.id) AS kelas FROM ruang r ORDER BY LENGTH(r.nama), r.nama')->getResultArray(),
            'kelasList' => $db->query("SELECT kelas, COUNT(*) AS jumlah FROM peserta GROUP BY kelas ORDER BY kelas")->getResultArray(),
            'sesiList'  => $db->table('sesi')->orderBy('nama')->get()->getResultArray(),
            'quizzes'   => $quizzes,
            'total'     => $db->table('peserta')->countAllResults(),
        ]);
    }

    public function kartu()
    {
        $rows = $this->pesertaCetak();
        if ($rows === []) {
            return redirect()->to(site_url('dokumen/cetak_doc'))->with('error', 'Tidak ada peserta untuk pilihan ini.');
        }

        // Per ruang atau per kelas: tiap ruang/kelas mulai di halaman baru, berlabel di atasnya.
        $perKelas = $this->request->getGet('per') === 'kelas';
        $groups   = [];
        foreach ($rows as $r) {
            $label            = $perKelas ? 'Kelas ' . ($r['kelas'] !== '' ? $r['kelas'] : '(tanpa kelas)') : 'Ruang ' . ($r['ruang_nama'] ?? '(tanpa ruang)');
            $groups[$label][] = $r;
        }
        uksort($groups, 'strnatcasecmp');

        // Terlalu banyak untuk satu PDF (dompdf kehabisan memori di server 1 GB): tampilkan tautan per kelompok.
        // Cetak lewat browser (?cetak=1) tidak memakai dompdf, jadi semua kartu boleh sekaligus.
        if (! $this->request->getGet('cetak') && count($rows) > self::MAKS_KARTU && count($groups) > 1) {
            $query = $this->request->getGet();
            unset($query['unduh']);
            $links = [];
            foreach ($groups as $label => $anggota) {
                $q = $perKelas ? ['kelas' => $anggota[0]['kelas']] : ['ruang' => $anggota[0]['ruang_id'] === null ? '0' : (string) $anggota[0]['ruang_id']];
                $links[] = ['label' => $label, 'jumlah' => count($anggota), 'url' => site_url('dokumen/cetak_doc/kartu') . '?' . http_build_query($q + $query)];
            }

            return view('dokumen/kartu_bagian', ['links' => $links, 'total' => count($rows), 'maks' => self::MAKS_KARTU]);
        }
        ActivityLog::add('cetak_kartu', count($rows) . ' kartu, per ' . ($perKelas ? 'kelas' : 'ruang') . ', ' . count($groups) . ' kelompok');

        return Pdf::respond('pdf/kartu', [
            'groups'  => $this->pasangJam($groups),
            'catatan' => Settings::get('catatan_kartu', 'Kartu peserta wajib dibawa selama ujian berlangsung.'),
        ], 'kartu_peserta_per_' . ($perKelas ? 'kelas' : 'ruang') . '.pdf');
    }

    /** Kartu dalam satu ZIP: satu PDF per kelas (Per Kelas) atau per ruang (Per Ruang). */
    public function kartuZip()
    {
        $rows = $this->pesertaCetak();
        if ($rows === []) {
            return redirect()->to(site_url('dokumen/cetak_doc'))->with('error', 'Tidak ada peserta untuk pilihan ini.');
        }
        $perKelas = $this->request->getGet('per') === 'kelas';
        $groups   = [];
        foreach ($rows as $r) {
            $label            = $perKelas ? 'Kelas ' . ($r['kelas'] !== '' ? $r['kelas'] : '(tanpa kelas)') : 'Ruang ' . ($r['ruang_nama'] ?? '(tanpa ruang)');
            $groups[$label][] = $r;
        }
        uksort($groups, 'strnatcasecmp');
        $groups = $this->pasangJam($groups);

        @set_time_limit(0);
        $catatan = Settings::get('catatan_kartu', 'Kartu peserta wajib dibawa selama ujian berlangsung.');
        $tmp     = tempnam(WRITEPATH . 'cache', 'kartu');
        $zip     = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return redirect()->to(site_url('dokumen/cetak_doc'))->with('error', 'Berkas ZIP tidak bisa dibuat.');
        }
        foreach ($groups as $label => $anggota) {
            $nama = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'Kartu ' . $label) . '.pdf';
            $zip->addFromString($nama, Pdf::render('pdf/kartu', ['groups' => [$label => $anggota], 'catatan' => $catatan, 'school' => \App\Libraries\School::info()]));
        }
        $zip->close();
        ActivityLog::add('cetak_kartu', count($rows) . ' kartu, ZIP per ' . ($perKelas ? 'kelas' : 'ruang') . ', ' . count($groups) . ' berkas');

        $isi = (string) file_get_contents($tmp);
        @unlink($tmp);

        // Tanda selesai untuk tombol di halaman (lihat dokumen/cetak.php), dibaca JS jadi tanpa HttpOnly.
        $token = (string) $this->request->getGet('token_unduh');
        if (ctype_digit($token)) {
            header('Set-Cookie: kartu_zip=' . $token . '; Path=/; Max-Age=120; SameSite=Lax', false);
        }

        return $this->response->download('kartu_peserta_per_' . ($perKelas ? 'kelas' : 'ruang') . '.zip', $isi);
    }

    /** Jam ujian per tingkatan (Admin Tools → Sesi) untuk tiap kartu. */
    private function pasangJam(array $groups): array
    {
        $jam = [];
        foreach (Tingkat::LIST as $t) {
            $jam[$t] = Tingkat::jam($t);
        }
        foreach ($groups as &$anggota) {
            foreach ($anggota as &$r) {
                $r['jam'] = $jam[Tingkat::of($r['kelas'])] ?? '';
            }
            unset($r);
        }
        unset($anggota);

        return $groups;
    }

    public function daftarHadir()
    {
        $rows = $this->pesertaCetak();
        if ($rows === []) {
            return redirect()->to(site_url('dokumen/cetak_doc'))->with('error', 'Tidak ada peserta untuk pilihan ruang/sesi ini.');
        }

        $mapel  = '';
        $quizId = (int) $this->request->getGet('quiz');
        if ($quizId > 0) {
            try {
                $mapel = (string) (QuizStatus::findQuiz($quizId)['name'] ?? '');
            } catch (\Throwable $e) {
                log_message('error', 'Daftar hadir: ' . $e->getMessage());
            }
        }

        $groups = [];
        foreach ($rows as $r) {
            $key            = ($r['ruang_nama'] ?? '(tanpa ruang)') . '|' . ($r['sesi_nama'] ?? '');
            $groups[$key][] = $r;
        }
        ActivityLog::add('cetak_daftar_hadir', count($groups) . ' ruang');

        return Pdf::respond('pdf/daftar_hadir', ['groups' => $groups, 'mapel' => $mapel], 'daftar_hadir.pdf');
    }

    /** Peserta Data CBTAdmin menurut ruang/kelas dan sesi, dengan sesi ruang sebagai cadangan. */
    private function pesertaCetak(): array
    {
        $perKelas = $this->request->getGet('per') === 'kelas';
        $ruangId  = $perKelas ? '' : (string) $this->request->getGet('ruang');
        $kelas    = $perKelas ? $this->request->getGet('kelas') : null;
        $sesiId   = (string) $this->request->getGet('sesi');

        $builder = db_connect()->table('peserta p')
            ->select('p.*, r.nama AS ruang_nama, r.pengawas, s.nama AS sesi_nama, s.jam_mulai, s.jam_selesai')
            ->join('ruang r', 'r.id = p.ruang_id', 'left')
            ->join('sesi s', 's.id = COALESCE(p.sesi_id, r.sesi_id)', 'left', false);
        if ($ruangId === '0') {
            $builder->where('p.ruang_id', null);
        } elseif ($ruangId !== '') {
            $builder->where('p.ruang_id', (int) $ruangId);
        }
        if ($kelas !== null && $kelas !== '__semua') {
            $builder->where('p.kelas', (string) $kelas);
        }
        $tingkat = Tingkat::dariRequest();
        if ($tingkat !== '') {
            $builder->where('p.kelas REGEXP ' . db_connect()->escape(Tingkat::regexp($tingkat)), null, false);
        }
        if ($sesiId !== '') {
            $builder->where('COALESCE(p.sesi_id, r.sesi_id)', (int) $sesiId, false);
        }
        if ($perKelas) {
            $builder->orderBy('p.kelas')->orderBy('p.nama');
        } else {
            $builder->orderBy('LENGTH(r.nama)', '', false)->orderBy('r.nama')->orderBy('s.nama')->orderBy('p.nomor_peserta')->orderBy('p.nama');
        }

        return $builder->get()->getResultArray();
    }

    // ------------------------------------------------------------------ Laporan Kehadiran

    /**
     * Laporan Kehadiran, dua tab: "pengawas" (absensi yang diisi pengawas tiap ruang, per tanggal)
     * dan "ujian" (hadir = punya attempt di quiz, dengan Berita Acara).
     */
    public function kehadiran(): string
    {
        if ($this->request->getGet('tab') === 'ujian') {
            return $this->render('dokumen/kehadiran', $this->rekap() + ['title' => 'Laporan Kehadiran', 'tab' => 'ujian']);
        }

        $tanggal = (string) $this->request->getGet('tanggal');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || ! strtotime($tanggal)) {
            $tanggal = date('Y-m-d');
        }
        $tingkat = Tingkat::dariRequest();

        return $this->render('dokumen/absensi', [
            'title'   => 'Laporan Kehadiran',
            'tab'     => 'pengawas',
            'tanggal' => $tanggal,
            'tingkat' => $tingkat,
            'rows'    => \App\Libraries\Absensi::rekap($tanggal, $tingkat),
        ]);
    }

    public function kehadiranExport()
    {
        $data = $this->rekap();
        if (! $data['quiz']) {
            return redirect()->to(site_url('dokumen/laporan_kehadiran'))->with('error', 'Pilih mata pelajaran (quiz) dulu.');
        }

        $rows = [];
        foreach ($data['ruang'] as $g) {
            foreach ($g['peserta'] as $r) {
                $rows[] = [$g['nama'], $r['nomor_peserta'], $r['username'], $r['nama'], $r['kelas'], $r['status'] === 'notstarted' ? 'Tidak hadir' : 'Hadir'];
            }
        }
        ActivityLog::add('export_kehadiran', $data['quiz']['name']);

        return $this->response->download(
            'kehadiran_' . preg_replace('/[^A-Za-z0-9]+/', '_', $data['quiz']['name']) . '_' . date('Ymd_His') . '.xlsx',
            Excel::build(['ruang', 'nomor_peserta', 'username', 'nama', 'kelas', 'kehadiran'], $rows)
        );
    }

    public function beritaAcara()
    {
        $data = $this->rekap();
        if (! $data['quiz']) {
            return redirect()->to(site_url('dokumen/laporan_kehadiran'))->with('error', 'Pilih mata pelajaran (quiz) dulu.');
        }
        ActivityLog::add('cetak_berita_acara', $data['quiz']['name']);

        return Pdf::respond('pdf/berita_acara', $data, 'berita_acara_' . $data['quiz']['name'] . '.pdf');
    }

    /** Hadir = punya attempt di quiz itu. Dikelompokkan per ruang Data CBTAdmin. */
    private function rekap(): array
    {
        $quizId  = (int) $this->request->getGet('quiz');
        $ruangId = (string) $this->request->getGet('ruang');
        $tingkat = Tingkat::dariRequest();
        $data    = ['quiz' => null, 'quizzes' => [], 'ruang' => [], 'error' => false, 'quizId' => $quizId, 'ruangId' => $ruangId, 'tingkat' => $tingkat];

        $ruangInfo = array_column(db_connect()->table('ruang r')
            ->select('r.id, r.nama, r.pengawas, s.nama AS sesi_nama, s.jam_mulai, s.jam_selesai')
            ->join('sesi s', 's.id = r.sesi_id', 'left')
            ->get()->getResultArray(), null, 'id');
        $data['ruangList'] = array_values($ruangInfo);

        try {
            $data['quizzes'] = Moodle::quizzes();
            $data['quiz']    = $quizId > 0 ? QuizStatus::findQuiz($quizId) : null;
            if (! $data['quiz']) {
                return $data;
            }

            $groups = [];
            foreach (QuizStatus::filter(QuizStatus::forQuiz($data['quiz']), $ruangId, '', $tingkat) as $r) {
                $key = $r['ruang_id'] ?? 0;
                $groups[$key] ??= [
                    'nama'     => $r['ruang'] ?? '(tanpa ruang)',
                    'pengawas' => $ruangInfo[$key]['pengawas'] ?? '',
                    'sesi'     => $ruangInfo[$key]['sesi_nama'] ?? '',
                    'jam'      => Tingkat::jam(Tingkat::of($r['kelas'] ?? ''), '–'),  // jam ujian tingkatan (Admin Tools → Sesi)
                    'peserta'  => [],
                    'hadir'    => 0,
                ];
                $groups[$key]['peserta'][] = $r;
                if ($r['status'] !== 'notstarted') {
                    $groups[$key]['hadir']++;
                }
            }
            foreach ($groups as &$g) {
                usort($g['peserta'], static fn ($a, $b) => [$a['nomor_peserta'], $a['nama']] <=> [$b['nomor_peserta'], $b['nama']]);
                $g['total'] = count($g['peserta']);
                $g['absen'] = array_values(array_filter($g['peserta'], static fn ($r) => $r['status'] === 'notstarted'));
            }
            unset($g);
            uasort($groups, static fn ($a, $b) => strnatcasecmp($a['nama'], $b['nama']));
            $data['ruang'] = array_values($groups);
        } catch (\Throwable $e) {
            $data['error'] = true;
            log_message('error', 'Laporan kehadiran: ' . $e->getMessage());
        }

        return $data;
    }
}
