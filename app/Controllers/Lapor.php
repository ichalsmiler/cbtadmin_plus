<?php

namespace App\Controllers;

use App\Libraries\ActiveTest;
use App\Libraries\LaporanSiswa;
use App\Libraries\Settings;

/**
 * Form laporan siswa `/lapor` (tanpa login panel). Dibuka dari tombol "Laporkan" di Moodle dengan tautan bertanda tangan
 * (?u=username&t=waktu&s=hmac); setelah dicek, identitas disimpan di sesi panel (`lapor_pid`) lalu dialihkan ke /lapor.
 */
class Lapor extends BaseController
{
    protected $helpers = ['url', 'form'];

    public function index()
    {
        $u = (string) $this->request->getGet('u');
        if ($u !== '') {
            if (! LaporanSiswa::cekTanda($u, (int) $this->request->getGet('t'), (string) $this->request->getGet('s'))) {
                return $this->halaman(null, 'Tautan laporan tidak sah atau sudah kedaluwarsa. Kembali ke ujian lalu tekan Laporkan lagi.', 403);
            }
            $p = LaporanSiswa::peserta($u);
            if ($p === null) {
                return $this->halaman(null, 'Akun Anda tidak terdaftar sebagai peserta ujian.', 403);
            }
            session()->set('lapor_pid', (int) $p['id']);
            session()->set('lapor_user', $u);

            return redirect()->to(site_url('lapor'));
        }

        $p = $this->pelapor();
        if ($p === null) {
            return $this->halaman(null, 'Buka form laporan lewat tombol Laporkan di halaman ujian.', 403);
        }

        return $this->halaman($p, LaporanSiswa::alasanTutup($p));
    }

    public function kirim()
    {
        $p = $this->pelapor();
        if ($p === null) {
            return redirect()->to(site_url('lapor'));
        }
        $tutup = LaporanSiswa::alasanTutup($p);
        if ($tutup !== '') {
            return redirect()->to(site_url('lapor'))->with('error', $tutup);
        }
        if (LaporanSiswa::jumlahHariIni((int) $p['id']) >= LaporanSiswa::MAKS_PER_SESI) {
            return redirect()->to(site_url('lapor'))->with('error', 'Anda sudah mengirim ' . LaporanSiswa::MAKS_PER_SESI . ' laporan di sesi ini. Bila mendesak, sampaikan langsung ke pengawas.');
        }

        $jenis    = (string) $this->request->getPost('jenis');
        $kategori = (string) $this->request->getPost('kategori');
        $ket      = trim((string) $this->request->getPost('keterangan'));
        if (! isset(LaporanSiswa::JENIS[$jenis]) || ! in_array($kategori, LaporanSiswa::JENIS[$jenis], true)) {
            return redirect()->to(site_url('lapor'))->with('error', 'Pilih jenis laporan.')->withInput();
        }
        if (mb_strlen($ket) < 5) {
            return redirect()->to(site_url('lapor'))->with('error', 'Tulis keterangan singkat (apa yang terjadi, kapan, di mana).')->withInput();
        }

        $terlapor = null;
        if ($jenis === 'curang' && (int) $this->request->getPost('terlapor_id') > 0) {
            $terlapor = (int) $this->request->getPost('terlapor_id');
            if (! in_array($terlapor, array_map('intval', array_column(LaporanSiswa::temanSeruang($p), 'id')), true)) {
                return redirect()->to(site_url('lapor'))->with('error', 'Siswa yang dilaporkan harus teman seruang Anda.')->withInput();
            }
            if (LaporanSiswa::sudahMelaporkan((int) $p['id'], $terlapor)) {
                return redirect()->to(site_url('lapor'))->with('error', 'Anda sudah melaporkan siswa itu di sesi ini.')->withInput();
            }
        }

        $foto = null;
        $f    = $this->request->getFile('foto');
        if ($f !== null && $f->getError() !== UPLOAD_ERR_NO_FILE) {
            if (! $f->isValid() || $f->getSize() > 12 * 1024 * 1024) {
                return redirect()->to(site_url('lapor'))->with('error', 'Foto gagal dikirim atau terlalu besar (maks. 12 MB).')->withInput();
            }
            $foto = LaporanSiswa::simpanFoto($f->getTempName());
            if ($foto === null) {
                return redirect()->to(site_url('lapor'))->with('error', 'Berkas foto harus JPG, PNG, atau WEBP.')->withInput();
            }
        }

        db_connect()->table('laporan_siswa')->insert([
            'jenis'       => $jenis,
            'kategori'    => $kategori,
            'pelapor_id'  => (int) $p['id'],
            'terlapor_id' => $terlapor,
            'ruang_id'    => $p['ruang_id'] !== null ? (int) $p['ruang_id'] : null,
            'sesi_id'     => ActiveTest::sesiId(),
            'keterangan'  => mb_substr($ket, 0, 1000),
            'foto'        => $foto,
            'status'      => 'baru',
            'ip'          => (string) $this->request->getIPAddress(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) db_connect()->insertID();

        return redirect()->to(site_url('lapor'))->with('success', 'Laporan #' . $id . ' terkirim ke Proktor. Terima kasih.');
    }

    private function pelapor(): ?array
    {
        $u = (string) session()->get('lapor_user');

        return $u === '' ? null : LaporanSiswa::peserta($u);
    }

    private function halaman(?array $p, string $tutup, int $kode = 200)
    {
        return $this->response->setStatusCode($kode)->setBody(view('lapor/siswa', [
            'p'       => $p,
            'tutup'   => $tutup,
            'teman'   => $p !== null && $tutup === '' ? LaporanSiswa::temanSeruang($p) : [],
            'riwayat' => $p !== null ? LaporanSiswa::milikHariIni((int) $p['id']) : [],
            'sisa'    => $p !== null ? max(0, LaporanSiswa::MAKS_PER_SESI - LaporanSiswa::jumlahHariIni((int) $p['id'])) : 0,
            'sekolah' => Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', '')),
            'version' => PanelController::VERSION,
        ]));
    }
}
