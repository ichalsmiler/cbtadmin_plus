<?php

namespace App\Controllers;

use App\Libraries\Absensi;
use App\Libraries\ActivityLog;
use App\Libraries\Excel;
use App\Libraries\ActiveTest;
use App\Libraries\Tingkat;

/**
 * Laporan Kehadiran: pengawas mengabsen siswa ruangnya hari ini (siswa tidak hadir
 * diblokir di Moodle); administrator melihat rekap semua ruang dan membuka blokir.
 */
class Kehadiran extends PanelController
{
    // ------------------------------------------------------------------ Absensi ruang

    public function index()
    {
        $isAdmin = \App\Filters\Guard::lihatSemua((string) session()->get('admin_role'));
        $ruangId = $this->myRuang() ?? (int) $this->request->getGet('ruang');
        $tanggal = $isAdmin ? $this->tanggal() : date('Y-m-d');

        if ($ruangId === 0) {
            if ($isAdmin) {
                return redirect()->to(site_url('dokumen/laporan_kehadiran') . '?tab=pengawas');
            }

            return $this->render('kehadiran/index', ['title' => 'Laporan Kehadiran', 'ruang' => null, 'rows' => [], 'tanggal' => $tanggal, 'isAdmin' => false]);
        }

        $ruang = db_connect()->table('ruang')->where('id', $ruangId)->get()->getRowArray();
        // Hanya siswa sesi aktif (Setting Ujian). Tanpa sesi aktif: pengawas tidak melihat siswa,
        // admin memakai filter tingkatan. Simpan hanya mengubah siswa yang tampil.
        // Filter tingkatan tetap ada; saat sesi aktif, bawaannya tingkatan sesi itu (mis. sesi Kelas X → X).
        $sesi = ActiveTest::sesiAktif();
        if ($sesi !== null && $this->request->getGet('tingkat') === null) {
            $tingkat = in_array($sesi['tingkat'], Tingkat::LIST, true) ? $sesi['tingkat'] : '';
        } else {
            $tingkat = Tingkat::dariRequest();
        }
        $rows    = [];
        if ($ruang && ($sesi !== null || $isAdmin)) {
            $rows = array_values(array_filter(Absensi::forRuang($ruangId, $tanggal, ActiveTest::sesiFilter()), static fn ($r) => Tingkat::cocok((string) $r['kelas'], $tingkat)));
        }

        return $this->render('kehadiran/index', [
            'title'   => 'Laporan Kehadiran',
            'ruang'   => $ruang,
            'rows'    => $rows,
            'tanggal' => $tanggal,
            'tingkat' => $tingkat,
            'sesi'    => $sesi,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function simpan()
    {
        $isAdmin = \App\Filters\Guard::lihatSemua((string) session()->get('admin_role'));
        $ruangId = $this->myRuang() ?? ($isAdmin ? (int) $this->request->getPost('ruang_id') : 0);
        $tanggal = $isAdmin ? $this->tanggal((string) $this->request->getPost('tanggal')) : date('Y-m-d');
        $tingkat = in_array($this->request->getPost('tingkat'), Tingkat::LIST, true) ? (string) $this->request->getPost('tingkat') : '';
        $back    = site_url('kehadiran') . '?' . http_build_query(array_filter(['ruang' => $isAdmin ? $ruangId : '', 'tanggal' => $isAdmin ? $tanggal : '', 'tingkat' => $tingkat]));
        if ($ruangId === 0) {
            return redirect()->to($back)->with('error', 'Akun ini belum terhubung ke ruang.');
        }

        $statuses = array_filter((array) $this->request->getPost('status'), static fn ($s) => in_array($s, [Absensi::HADIR, Absensi::TIDAK_HADIR], true));
        // Pengawas: hanya siswa ruangnya di sesi aktif (yang tampil di halaman); tanpa sesi aktif tidak bisa
        // menyimpan. Tanpa ini siswa sesi lain bisa ikut ditandai tidak hadir lalu di-suspend di Moodle.
        if ($this->myRuang() !== null) {
            $boleh = $this->siswaSesi() ?? [];
            if ($boleh === []) {
                return redirect()->to($back)->with('error', 'Sesi belum diaktifkan oleh Proktor. Absensi belum bisa disimpan.');
            }
            $idBoleh  = array_flip(array_map('intval', array_column(db_connect()->table('peserta')->select('id')
                ->where('ruang_id', $ruangId)->whereIn('username', $boleh)->get()->getResultArray(), 'id')));
            // Siswa yang tampil di halaman (filter tingkatan ikut), untuk syarat "semua sudah ditandai".
            $idTampil = array_map('intval', array_column(array_filter(db_connect()->table('peserta')->select('id, kelas')
                ->where('ruang_id', $ruangId)->whereIn('username', $boleh)->get()->getResultArray(),
                static fn ($p) => Tingkat::cocok((string) $p['kelas'], $tingkat)), 'id'));
            $statuses = array_filter($statuses, static fn ($pid) => isset($idBoleh[(int) $pid]), ARRAY_FILTER_USE_KEY);
            // Pengawas wajib menandai semua siswa ruangnya (tombol Simpan juga terkunci di halaman sampai lengkap).
            $kurang = count(array_diff($idTampil, array_map('intval', array_keys($statuses))));
            if ($statuses !== [] && $kurang > 0) {
                return redirect()->to($back)->with('error', 'Absensi belum disimpan: masih ada ' . $kurang . ' siswa yang belum ditandai Hadir atau Tidak hadir.');
            }
        }
        if ($statuses === []) {
            return redirect()->to($back)->with('error', 'Belum ada siswa yang ditandai.');
        }

        try {
            $r = Absensi::save($ruangId, $tanggal, $statuses, (string) session()->get('admin_user'));
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Absensi gagal disimpan: ' . $e->getMessage());
        }
        $ruang = db_connect()->table('ruang')->select('nama')->where('id', $ruangId)->get()->getRowArray();
        ActivityLog::add('absensi', 'ruang ' . ($ruang['nama'] ?? $ruangId) . " $tanggal: {$r['hadir']} hadir, {$r['tidak']} tidak hadir, {$r['diblokir']} diblokir, {$r['dibuka']} dibuka");

        $message = "Absensi disimpan: {$r['hadir']} hadir, {$r['tidak']} tidak hadir.";
        if ($r['diblokir'] > 0) {
            $message .= " {$r['diblokir']} siswa diblokir di Moodle hari ini.";
        }
        if ($r['dibuka'] > 0) {
            $message .= " Blokir {$r['dibuka']} siswa dibuka.";
        }
        if ($r['gagal'] !== []) {
            $message .= ' Gagal: ' . implode('; ', array_slice($r['gagal'], 0, 5));
        }

        return redirect()->to($back)->with($r['gagal'] === [] ? 'success' : 'error', $message);
    }

    // ------------------------------------------------------------------ Rekap admin

    /** Alamat lama "Absensi Ruang": kini tab Absensi Pengawas di Laporan Kehadiran. */
    public function rekap()
    {
        return redirect()->to(site_url('dokumen/laporan_kehadiran') . '?' . http_build_query(['tab' => 'pengawas', 'tanggal' => $this->tanggal()]));
    }

    public function buka()
    {
        $pid     = (int) $this->request->getPost('peserta_id');
        $tanggal = $this->tanggal((string) $this->request->getPost('tanggal'));
        try {
            $ok = Absensi::unblock($pid, $tanggal);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Gagal membuka blokir: ' . $e->getMessage());
        }
        $p = db_connect()->table('peserta')->select('username')->where('id', $pid)->get()->getRowArray();
        ActivityLog::add('buka_blokir', ($p['username'] ?? $pid) . ' ' . $tanggal);

        return redirect()->back()->with($ok ? 'success' : 'error', $ok
            ? 'Blokir ' . ($p['username'] ?? '') . ' dibuka; siswa bisa login lagi.'
            : 'Blokir tidak bisa dibuka (siswa belum ada di Moodle?).');
    }

    public function export()
    {
        $tanggal = $this->tanggal();
        $tingkat = Tingkat::dariRequest();
        $rows    = [];
        foreach (Absensi::rekap($tanggal, $tingkat) as $r) {
            foreach (Absensi::forRuang((int) $r['id'], $tanggal) as $p) {
                if (! Tingkat::cocok((string) $p['kelas'], $tingkat)) {
                    continue;
                }
                $rows[] = [
                    $r['nama'], $p['nomor_peserta'], $p['username'], $p['nama'], $p['kelas'],
                    match ($p['status']) { Absensi::HADIR => 'Hadir', Absensi::TIDAK_HADIR => 'Tidak hadir', default => 'Belum diabsen' },
                    (int) $p['diblokir'] ? 'Ya' : '', $p['dicatat_oleh'] ?? '',
                ];
            }
        }
        ActivityLog::add('export_absensi', $tanggal);

        return $this->response->download(
            'absensi_' . $tanggal . '.xlsx',
            Excel::build(['Ruang', 'Nomor Peserta', 'Username', 'Nama', 'Kelas', 'Kehadiran', 'Diblokir', 'Dicatat oleh'], $rows)
        );
    }

    private function tanggal(?string $value = null): string
    {
        $value ??= (string) $this->request->getGet('tanggal');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : date('Y-m-d');
    }
}
