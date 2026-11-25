<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\LaporanSiswa;
use App\Libraries\Settings;

/**
 * Menu Laporan Siswa (Proktor + admin): laporan kecurangan / kendala teknis dari siswa. Pengawas ruang tidak melihatnya.
 * Sakelar fitur (admin) mengirim keadaan tombol ke Moodle (LaporanSiswa::sinkronMoodle).
 */
class LaporanSiswaPanel extends PanelController
{
    public function index(): string
    {
        LaporanSiswa::bersihkanFoto();
        $jenis  = (string) $this->request->getGet('jenis');
        $jenis  = isset(LaporanSiswa::NAMA_JENIS[$jenis]) ? $jenis : 'curang';
        $status = (string) $this->request->getGet('status');
        $status = isset(LaporanSiswa::STATUS[$status]) ? $status : '';
        $tgl    = (string) $this->request->getGet('tgl');
        $tgl    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) ? $tgl : date('Y-m-d');

        $b = db_connect()->table('laporan_siswa l')
            ->select('l.*, pp.nama AS pelapor_nama, pp.kelas AS pelapor_kelas, pp.username AS pelapor_username,'
                . ' pt.nama AS terlapor_nama, pt.kelas AS terlapor_kelas, pt.username AS terlapor_username, r.nama AS ruang_nama, s.nama AS sesi_nama')
            ->join('peserta pp', 'pp.id = l.pelapor_id', 'left')
            ->join('peserta pt', 'pt.id = l.terlapor_id', 'left')
            ->join('ruang r', 'r.id = l.ruang_id', 'left')
            ->join('sesi s', 's.id = l.sesi_id', 'left')
            ->where('l.jenis', $jenis)
            ->where('l.created_at >=', $tgl . ' 00:00:00')->where('l.created_at <=', $tgl . ' 23:59:59');
        if ($status !== '') {
            $b->where('l.status', $status);
        }

        $baru = [];
        foreach (db_connect()->table('laporan_siswa')->select('jenis, COUNT(*) AS n')->where('status', 'baru')->groupBy('jenis')->get()->getResultArray() as $r) {
            $baru[$r['jenis']] = (int) $r['n'];
        }

        return $this->render('lapor/index', [
            'title'     => 'Laporan Siswa',
            'rows'      => $b->orderBy('l.id', 'DESC')->get()->getResultArray(),
            'jenis'     => $jenis,
            'status'    => $status,
            'tgl'       => $tgl,
            'baru'      => $baru,
            'meragukan' => LaporanSiswa::pelaporMeragukan(),
            'aktif'     => LaporanSiswa::aktif(),
        ]);
    }

    public function status()
    {
        $id     = (int) $this->request->getPost('id');
        $status = (string) $this->request->getPost('status');
        if (! isset(LaporanSiswa::STATUS[$status])) {
            return redirect()->back()->with('error', 'Status tidak dikenal.');
        }
        $db  = db_connect();
        $lap = $db->table('laporan_siswa')->where('id', $id)->get()->getRowArray();
        if (! $lap) {
            return redirect()->back()->with('error', 'Laporan tidak ditemukan.');
        }
        $db->table('laporan_siswa')->where('id', $id)->update([
            'status'         => $status,
            'catatan'        => mb_substr(trim((string) $this->request->getPost('catatan')), 0, 1000),
            'ditangani_oleh' => (string) session()->get('admin_user'),
            'ditangani_at'   => date('Y-m-d H:i:s'),
        ]);
        ActivityLog::add('laporan_siswa', '#' . $id . ' → ' . LaporanSiswa::STATUS[$status]);

        return redirect()->back()->with('success', 'Laporan #' . $id . ': ' . LaporanSiswa::STATUS[$status] . '.');
    }

    public function foto(int $id)
    {
        $foto = (string) db_connect()->table('laporan_siswa')->select('foto')->where('id', $id)->get()->getRow('foto');
        $path = LaporanSiswa::folderFoto() . basename($foto);
        if ($foto === '' || ! is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Foto tidak ada (sudah dihapus otomatis setelah ' . LaporanSiswa::SIMPAN_FOTO_HARI . ' hari).');
        }
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';

        return $this->response->setHeader('Content-Type', $mime)->setHeader('Cache-Control', 'private, max-age=3600')
            ->setBody((string) file_get_contents($path));
    }

    /** Jumlah laporan baru (badge / notifikasi). */
    public function jumlah()
    {
        return $this->response->setJSON(['baru' => LaporanSiswa::jumlahBaru()]);
    }

    /** Sakelar fitur: admin saja. */
    public function setelan()
    {
        if (session()->get('admin_role') !== 'admin') {
            return redirect()->back()->with('error', 'Hanya administrator yang bisa mengubah setelan ini.');
        }
        $aktif = $this->request->getPost('aktif') === '1';
        Settings::set('lapor_aktif', $aktif ? '1' : '0');
        try {
            LaporanSiswa::sinkronMoodle();
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Setelan disimpan, tetapi Moodle gagal diperbarui: ' . $e->getMessage());
        }
        ActivityLog::add('laporan_siswa_setelan', $aktif ? 'aktif' : 'nonaktif');

        return redirect()->back()->with('success', 'Tombol Laporkan di Moodle ' . ($aktif ? 'diaktifkan (tampil selama ada sesi aktif).' : 'dimatikan.'));
    }
}
