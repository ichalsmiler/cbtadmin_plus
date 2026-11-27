<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\KeluarAplikasi;
use App\Libraries\MoodleBridge;
use App\Libraries\Tingkat;

/**
 * Lock Peserta: siswa yang terkunci karena Batas login per mapel (atau Anti Newtab bila masih aktif), dibuka
 * pengawas. Terpisah dari Reset Login. Pengawas hanya melihat/membuka siswa ruangnya di sesi aktif.
 */
class LockPeserta extends PanelController
{
    public function index(): string
    {
        $ruangId    = $this->myRuang() !== null ? (string) $this->myRuang() : (string) $this->request->getGet('ruang');
        $tingkat    = Tingkat::dariRequest();
        $jenisAktif = array_values(array_filter(['login', 'keluar'], static fn ($j) => KeluarAplikasi::aktif($j)));
        $rows       = [];
        $error      = false;

        try {
            $ruangSiswa = array_column(db_connect()->table('peserta p')->select('p.username, p.kelas, p.ruang_id, r.nama AS ruang')
                ->join('ruang r', 'r.id = p.ruang_id', 'left')->get()->getResultArray(), null, 'username');
            foreach ($jenisAktif as $jenis) {
                $catat = [];
                foreach (KeluarAplikasi::daftar(null, $jenis) as $k) {
                    $info = $ruangSiswa[$k['username']] ?? null;
                    if ($ruangId === '0' ? ($info['ruang_id'] ?? null) !== null : ($ruangId !== '' && (string) ($info['ruang_id'] ?? '') !== $ruangId)) {
                        continue;
                    }
                    if (! Tingkat::cocok($info['kelas'] ?? '', $tingkat) || isset($catat[$k['userid']])) {
                        continue; // daftar() sudah urut terkunci/terbanyak dulu: ambil attempt pertama per siswa
                    }
                    $catat[$k['userid']] = $k + ['jenis' => $jenis, 'kelas' => $info['kelas'] ?? '', 'ruang' => $info['ruang'] ?? null];
                }
                $rows = array_merge($rows, $this->saringSesi(array_values($catat)));
            }
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Lock Peserta: ' . $e->getMessage());
        }
        usort($rows, static fn ($x, $y) => [$y['kunci'], $y['waktu']] <=> [$x['kunci'], $x['waktu']]);

        return $this->render('lock_peserta/index', [
            'title'      => 'Lock Peserta',
            'rows'       => $rows,
            'ruangId'    => $ruangId,
            'tingkat'    => $tingkat,
            'ruangList'  => $this->ruangList(),
            'lockRuang'  => $this->myRuang() !== null,
            'jenisAktif' => $jenisAktif,
            'maks'       => ['login' => KeluarAplikasi::maks('login'), 'keluar' => KeluarAplikasi::maks('keluar')],
            'error'      => $error,
        ]);
    }

    /**
     * Buka kunci. jenis=login: siswa boleh login sekali lagi; jenis=keluar (Anti Newtab): boleh melanjutkan,
     * keluar sekali lagi langsung terkunci lagi.
     */
    public function buka()
    {
        $userid  = (int) $this->request->getPost('userid');
        $attempt = (int) $this->request->getPost('attempt');
        $jenis   = $this->request->getPost('jenis') === 'keluar' ? 'keluar' : 'login';
        $siswa   = null;
        foreach (KeluarAplikasi::daftar(null, $jenis) as $k) {
            if ($k['userid'] === $userid && $k['attempt'] === $attempt && $k['kunci']) {
                $siswa = $k;
            }
        }
        // Pengawas hanya boleh membuka siswa ruangnya di sesi aktif.
        if ($siswa === null || $this->saringSesi([$siswa]) === []) {
            return redirect()->back()->with('error', 'Siswa itu tidak sedang terkunci (atau bukan siswa ruang Anda).');
        }
        try {
            MoodleBridge::call('antinewtab_buka', ['jenis' => $jenis, 'items' => [['userid' => $userid, 'attempt' => $attempt]]]);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Gagal membuka kunci: ' . $e->getMessage());
        }
        ActivityLog::add('buka_kunci_' . $jenis, $siswa['username'] . ' ' . $siswa['nama'] . ', ' . $jenis . ' ' . $siswa['total'] . ' kali');

        return redirect()->back()->with('success', 'Kunci ' . $siswa['nama'] . ' dibuka. ' . ($jenis === 'login'
            ? 'Siswa bisa login sekali lagi untuk melanjutkan ujian; login berikutnya langsung terkunci lagi.'
            : 'Minta siswa menekan tombol lanjutkan ujian. Bila keluar sekali lagi, ujiannya langsung terkunci lagi.'));
    }
}
