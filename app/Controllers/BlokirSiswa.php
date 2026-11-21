<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Blokir;
use App\Libraries\Tingkat;

/**
 * Blokir Siswa: pengawas (untuk ruangnya) dan administrator memblokir siswa yang melanggar.
 * Siswa langsung dikeluarkan dari Moodle dan tidak bisa login sampai blokir dibuka.
 */
class BlokirSiswa extends PanelController
{
    public function index(): string
    {
        $isAdmin = \App\Filters\Guard::lihatSemua((string) session()->get('admin_role'));
        $ruangId = $this->myRuang();
        if ($ruangId === null && $isAdmin) {
            $get     = (string) $this->request->getGet('ruang');
            $ruangId = $get === '' ? null : (int) $get;
        }

        $tingkat = Tingkat::dariRequest();
        $rows    = ($ruangId === null && ! $isAdmin) ? [] : $this->saringSesi(Blokir::daftar($ruangId));
        $rows    = array_values(array_filter($rows, static fn ($r) => Tingkat::cocok($r['kelas'], $tingkat)));
        if ($this->request->getGet('aktif') === '1') {
            $rows = array_values(array_filter($rows, static fn ($r) => $r['diblokir_at'] !== null));
        }

        return $this->render('blokir/index', [
            'title'     => 'Blokir Siswa',
            'rows'      => $rows,
            'isAdmin'   => $isAdmin,
            'noRuang'   => ! $isAdmin && $this->myRuang() === null,
            'ruangList' => $this->ruangList(),
            'ruangId'   => $ruangId,
            'tingkat'   => $tingkat,
            'hanyaAktif' => $this->request->getGet('aktif') === '1',
            'jumlahAktif' => count(array_filter($this->saringSesi(Blokir::daftar($this->myRuang())), static fn ($r) => $r['diblokir_at'] !== null)),
        ]);
    }

    public function blokir()
    {
        $pid    = (int) $this->request->getPost('peserta_id');
        $alasan = trim((string) $this->request->getPost('alasan'));
        if (! $this->bolehMenindak($pid)) {
            return redirect()->back()->with('error', 'Siswa itu bukan peserta ruang Anda.');
        }
        if (mb_strlen($alasan) < 3) {
            return redirect()->back()->with('error', 'Tulis alasan blokir (mis. ribut, menyontek, membuka situs lain).');
        }

        try {
            Blokir::blokir($pid, $alasan, (string) session()->get('admin_user'));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Gagal memblokir: ' . $e->getMessage());
        }
        $p = $this->peserta($pid);
        ActivityLog::add('blokir_siswa', $p['username'] . ' (' . $p['nama'] . '). Alasan: ' . $alasan);

        return redirect()->back()->with('success', $p['nama'] . ' diblokir dan langsung dikeluarkan dari ujian.');
    }

    public function buka()
    {
        $pid = (int) $this->request->getPost('peserta_id');
        if (! $this->bolehMenindak($pid)) {
            return redirect()->back()->with('error', 'Siswa itu bukan peserta ruang Anda.');
        }

        try {
            $bisaLogin = Blokir::buka($pid, (string) session()->get('admin_user'));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Gagal membuka blokir: ' . $e->getMessage());
        }
        $p = $this->peserta($pid);
        ActivityLog::add('buka_blokir_siswa', $p['username'] . ' (' . $p['nama'] . ')');

        return redirect()->back()->with('success', $bisaLogin
            ? 'Blokir ' . $p['nama'] . ' dibuka; siswa bisa login lagi.'
            : 'Blokir pelanggaran ' . $p['nama'] . ' dibuka, tetapi siswa masih diblokir karena ditandai tidak hadir hari ini.');
    }

    /** Pengawas hanya boleh menindak siswa di ruangnya. */
    private function bolehMenindak(int $pid): bool
    {
        $p = $this->peserta($pid);
        if ($p === null) {
            return false;
        }

        // Pengawas: siswa ruangnya yang ikut sesi aktif.
        return $this->myRuang() === null
            ? \App\Filters\Guard::lihatSemua((string) session()->get('admin_role'))
            : in_array($p['username'] ?? '', $this->siswaSesi() ?? [], true);
    }

    private function peserta(int $pid): ?array
    {
        return db_connect()->table('peserta')->select('id, nama, username, ruang_id')->where('id', $pid)->get()->getRowArray();
    }
}
