<?php

namespace App\Controllers;

use App\Libraries\ActiveTest;
use App\Libraries\ActivityLog;
use App\Libraries\PengawasHadir;

/**
 * Isi nama pengawas setelah login (Guard mengarahkan ke sini sampai nama diisi, dan lagi saat hari/sesi berganti).
 * Nama tampil di kolom Pengawas Rekap Kehadiran.
 */
class PengawasNama extends PanelController
{
    public function index()
    {
        if (! PengawasHadir::perlu()) {
            return redirect()->to(site_url('/'));
        }
        $ruangId = (int) $this->myRuang();
        $ruang   = db_connect()->table('ruang')->select('nama')->where('id', $ruangId)->get()->getRowArray();
        $sesi    = ActiveTest::sesiAktif();

        return view('auth/pengawas_nama', [
            'version' => self::VERSION,
            'ruang'   => $ruang['nama'] ?? '',
            'sesi'    => $sesi && $sesi['id'] > 0 ? $sesi['label'] : '',
            'nama'    => (string) (old('nama') ?? session()->get('pengawas_nama') ?? ''),
        ]);
    }

    public function simpan()
    {
        $nama = trim(preg_replace('/\s+/u', ' ', (string) $this->request->getPost('nama')));
        if (mb_strlen($nama) < 3 || mb_strlen($nama) > 100) {
            return redirect()->back()->withInput()->with('error', 'Isi nama lengkap pengawas (3–100 huruf).');
        }
        try {
            PengawasHadir::simpan($nama, (int) $this->myRuang(), (int) session()->get('admin_id'));
        } catch (\Throwable $e) {
            log_message('error', 'Nama pengawas: ' . $e->getMessage());

            return redirect()->back()->withInput()->with('error', 'Nama gagal disimpan. Coba lagi atau hubungi admin.');
        }
        ActivityLog::add('nama_pengawas', $nama);

        return redirect()->to(site_url('/'));
    }
}
