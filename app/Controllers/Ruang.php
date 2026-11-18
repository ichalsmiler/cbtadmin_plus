<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Pdf;
use App\Libraries\Pengawas;
use App\Libraries\Settings;

/**
 * Daftar Ruang (seperti AIOCBT): tiap ruang punya akun login pengawas (Kode Login + password),
 * nama ruang/lab, Tukar Akses (admin masuk sebagai pengawas ruang), Generate Kode, dan Cetak ID Login.
 */
class Ruang extends PanelController
{
    public function index(): string
    {
        $db = db_connect();
        Pengawas::sync();  // setiap ruang selalu punya akun pengawas
        if (($n = Pengawas::rapikanKode()) > 0) {
            ActivityLog::add('kode_login_ruang', $n . ' akun pengawas diubah ke format NPSN-kode');
        }

        return $this->render('ruang/index', [
            'title' => 'Daftar Ruang',
            'ruang' => $this->ruangRows(),
            'sesi'  => $db->table('sesi')->orderBy('nama')->get()->getResultArray(),
        ]);
    }

    /** Simpan nama ruang / lab semua ruang sekaligus. */
    public function lokasi()
    {
        $db    = db_connect();
        $count = 0;
        foreach ((array) $this->request->getPost('lokasi') as $id => $lokasi) {
            $count += $db->table('ruang')->where('id', (int) $id)->update(['lokasi' => mb_substr(trim((string) $lokasi), 0, 120)]) ? 1 : 0;
        }
        ActivityLog::add('nama_ruang', $count . ' ruang');

        return redirect()->to(site_url('daftar_ruang'))->with('success', 'Nama ruang disimpan.');
    }

    /** Generate Kode: password baru untuk akun pengawas ruang. */
    public function kode(int $id)
    {
        $akun = $this->akunPengawas($id);
        if (! $akun) {
            return redirect()->to(site_url('daftar_ruang'))->with('error', 'Ruang ini belum punya akun pengawas.');
        }
        $baru = Pengawas::kodeBaru((int) $akun['id']);
        ActivityLog::add('generate_kode_ruang', $akun['username'] . ' -> ' . $baru['username']);

        return redirect()->to(site_url('daftar_ruang'))->with('success', 'Kode login baru Ruang ' . $this->namaRuang($id) . ': ' . $baru['username'] . ' (password juga baru). Cetak ulang ID Login ruang ini.');
    }

    /** Tukar Akses: administrator masuk sebagai pengawas ruang; kembali lewat tombol di atas halaman. */
    public function tukar(int $id)
    {
        $akun = $this->akunPengawas($id);
        if (! $akun) {
            return redirect()->to(site_url('daftar_ruang'))->with('error', 'Ruang ini belum punya akun pengawas.');
        }
        $session = session();
        ActivityLog::add('tukar_akses', 'masuk sebagai ' . $akun['username']);
        $session->set([
            'tukar_dari' => (int) $session->get('admin_id'),
            'admin_id'   => (int) $akun['id'],
            'admin_user' => $akun['username'],
            'cred'       => \App\Filters\Guard::cap((string) $akun['password_hash']),
        ]);

        return redirect()->to(site_url('/'))->with('success', 'Anda sekarang masuk sebagai ' . $akun['nama'] . '.');
    }

    /** Kembali ke akun administrator setelah Tukar Akses. */
    public function kembali()
    {
        $session = session();
        $adminId = (int) $session->get('tukar_dari');
        $admin   = $adminId ? db_connect()->table('admins')->where('id', $adminId)->where('role', 'admin')->get()->getRowArray() : null;
        if (! $admin) {
            return redirect()->to(site_url('/'));
        }
        $session->remove('tukar_dari');
        $session->set(['admin_id' => (int) $admin['id'], 'admin_user' => $admin['username'], 'cred' => \App\Filters\Guard::cap((string) $admin['password_hash'])]);
        ActivityLog::add('tukar_akses', 'kembali ke ' . $admin['username']);

        return redirect()->to(site_url('daftar_ruang'));
    }

    /** Cetak ID Login semua ruang (PDF) untuk dibagikan ke pengawas. */
    public function cetak()
    {
        $rows = array_values(array_filter($this->ruangRows(), static fn ($r) => $r['username'] !== null));
        if ($rows === []) {
            return redirect()->to(site_url('daftar_ruang'))->with('error', 'Belum ada akun pengawas.');
        }
        ActivityLog::add('cetak_id_login', count($rows) . ' ruang');

        return Pdf::respond('pdf/id_login', ['rows' => $rows, 'url' => $this->alamatLogin()], 'id_login_pengawas.pdf');
    }

    /** Alamat login pengawas: dari Tools → Info Kegiatan (alamat server ujian), atau alamat panel ini. */
    private function alamatLogin(): string
    {
        $server = rtrim(trim(Settings::get('alamat_server')), '/');
        if ($server === '') {
            return site_url('login');
        }

        return str_contains($server, '/cbtadmin') ? $server . '/login' : $server . '/cbtadmin/login';
    }

    private function ruangRows(): array
    {
        return db_connect()->table('ruang r')
            ->select("r.*, s.nama AS sesi_nama, (SELECT COUNT(*) FROM peserta p WHERE p.ruang_id = r.id) AS jumlah_peserta,
                      a.id AS akun_id, a.username, a.password_plain, a.nama AS akun_nama, a.last_login", false)
            ->join('sesi s', 's.id = r.sesi_id', 'left')
            ->join('admins a', "a.ruang_id = r.id AND a.role = 'proktor'", 'left')
            ->orderBy('LENGTH(r.nama)', '', false)->orderBy('r.nama')
            ->get()->getResultArray();
    }

    private function namaRuang(int $ruangId): string
    {
        return (string) (db_connect()->table('ruang')->select('nama')->where('id', $ruangId)->get()->getRowArray()['nama'] ?? $ruangId);
    }

    private function akunPengawas(int $ruangId): ?array
    {
        return db_connect()->table('admins')->where('ruang_id', $ruangId)->where('role', 'proktor')->get()->getRowArray();
    }

    public function save()
    {
        $nama = trim((string) $this->request->getPost('nama'));
        if ($nama === '') {
            return redirect()->back()->with('error', 'Nama ruang wajib diisi.');
        }

        $sesiId = (int) $this->request->getPost('sesi_id');
        $data   = [
            'nama'      => mb_substr($nama, 0, 60),
            'kapasitas' => max(0, (int) $this->request->getPost('kapasitas')),
            'pengawas'  => mb_substr(trim((string) $this->request->getPost('pengawas')), 0, 120),
            'sesi_id'   => $sesiId > 0 ? $sesiId : null,
        ];

        $db = db_connect();
        $id = (int) $this->request->getPost('id');
        if ($id > 0) {
            $db->table('ruang')->where('id', $id)->update($data);
            ActivityLog::add('ubah_ruang', $data['nama']);
        } else {
            $db->table('ruang')->insert($data);
            ActivityLog::add('tambah_ruang', $data['nama']);
        }

        return redirect()->to(site_url('daftar_ruang'))->with('success', 'Ruang disimpan.');
    }

    public function delete(int $id)
    {
        $db  = db_connect();
        $row = $db->table('ruang')->where('id', $id)->get()->getRowArray();
        if ($row) {
            $db->table('peserta')->where('ruang_id', $id)->update(['ruang_id' => null]);
            // Akun pengawas ruang ini ikut dihapus.
            $db->table('admins')->where('ruang_id', $id)->where('role', 'proktor')->delete();
            $db->table('ruang')->where('id', $id)->delete();
            ActivityLog::add('hapus_ruang', $row['nama']);
        }

        return redirect()->to(site_url('daftar_ruang'))->with('success', 'Ruang dihapus.');
    }

    public function saveSesi()
    {
        $nama = trim((string) $this->request->getPost('nama'));
        if ($nama === '') {
            return redirect()->back()->with('error', 'Nama sesi wajib diisi.');
        }

        $time = static fn ($v) => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v . ':00' : null;
        db_connect()->table('sesi')->insert([
            'nama'        => mb_substr($nama, 0, 60),
            'jam_mulai'   => $time($this->request->getPost('jam_mulai')),
            'jam_selesai' => $time($this->request->getPost('jam_selesai')),
        ]);
        ActivityLog::add('tambah_sesi', $nama);

        return redirect()->to(site_url('daftar_ruang'))->with('success', 'Sesi ditambahkan.');
    }

    public function deleteSesi(int $id)
    {
        $db = db_connect();
        $db->table('ruang')->where('sesi_id', $id)->update(['sesi_id' => null]);
        $db->table('peserta')->where('sesi_id', $id)->update(['sesi_id' => null]);
        $db->table('sesi')->where('id', $id)->delete();
        ActivityLog::add('hapus_sesi', 'id ' . $id);

        return redirect()->to(site_url('daftar_ruang'))->with('success', 'Sesi dihapus.');
    }
}
