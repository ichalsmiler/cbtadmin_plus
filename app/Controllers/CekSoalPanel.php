<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\CekSoal;
use App\Libraries\Excel;
use App\Libraries\MoodleBridge;
use App\Libraries\Settings;
use App\Libraries\Telegram;
use App\Libraries\Tingkat;

/**
 * Menu Cek Soal (admin + kurikulum): data guru per mapel (unggah Excel), buat tautan cek untuk guru
 * (dikirim ke Telegram admin untuk diteruskan ke WA guru), daftar tautan + hasil. Logika di Libraries/CekSoal;
 * halaman guru di Controllers/CekGuru; bot /ceksoal di Commands/TelegramBot.
 */
class CekSoalPanel extends PanelController
{
    private const KOLOM = ['Nama Guru', 'Mapel', 'Tingkat'];

    public function index(): string
    {
        // Alamat tautan bawaan = alamat panel yang sedang dibuka admin (bot di CLI tidak tahu alamat ini).
        if (Settings::get('cek_soal_alamat') === '') {
            Settings::set('cek_soal_alamat', (string) CekSoal::asal(site_url('/')));
        }
        $tab   = $this->request->getGet('tab') === 'guru' ? 'guru' : 'tugas';
        $mapel = [];
        $error = null;
        try {
            $mapel = CekSoal::mapel();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $db    = db_connect();
        $guru  = $db->table('cek_guru')->orderBy('nama')->orderBy('tingkat')->get()->getResultArray();
        // Guru pengampu tiap course (untuk centang otomatis) + baris data guru yang tidak cocok dengan mapel mana pun.
        $cocok = CekSoal::pasangkan($guru, $mapel);
        $peta  = [];
        foreach ($cocok as $gid => $cids) {
            foreach ($cids as $cid) {
                $peta[$cid][] = $gid;
            }
        }
        $tugas = $db->table('cek_tugas')->where('dicabut_at', null)->orderBy('id', 'DESC')->limit(300)->get()->getResultArray();

        return $this->render('cek_soal/index', [
            'title'    => 'Cek Soal',
            'tab'      => $tab,
            'mapel'    => $mapel,
            'error'    => $error,
            'guru'     => $guru,
            'peta'     => $peta,
            'cocok'    => $cocok,
            'tugas'    => $tugas,
            'telegram' => Telegram::siap(),
            'alamat'   => CekSoal::alamat(),
            'baru'     => (array) session()->getFlashdata('cek_baru'),
        ]);
    }

    /** Buat tautan cek untuk guru yang dicentang (+ nama tambahan), lalu kirim pesan per guru ke Telegram admin. */
    public function buat()
    {
        $back     = site_url('cek_soal');
        $courseId = (int) $this->request->getPost('mapel');
        $ids      = array_map('intval', (array) $this->request->getPost('guru'));
        $guru     = [];
        if ($ids !== []) {
            foreach (db_connect()->table('cek_guru')->whereIn('id', $ids)->get()->getResultArray() as $g) {
                $guru[] = ['id' => (int) $g['id'], 'nama' => $g['nama']];
            }
        }
        foreach (explode(',', (string) $this->request->getPost('nama_lain')) as $n) {
            if (trim($n) !== '') {
                $guru[] = ['id' => null, 'nama' => trim($n)];
            }
        }
        if ($courseId <= 1 || $guru === []) {
            return redirect()->to($back)->with('error', 'Pilih mapel dan minimal satu guru.');
        }
        try {
            $dibuat = CekSoal::buat($courseId, $guru, (int) $this->request->getPost('hari'), (string) session('admin_user'));
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', $e->getMessage());
        }
        $terkirim = 0;
        if (Telegram::siap()) {
            foreach ($dibuat as $t) {
                $terkirim += (int) (bool) (Telegram::kirimAdmin(CekSoal::pesan($t))['ok'] ?? false);
            }
        }

        return redirect()->to($back)->with('cek_baru', array_column($dibuat, 'id'))->with('success', count($dibuat) . ' tautan cek dibuat'
            . (Telegram::siap() ? ', ' . $terkirim . ' pesan terkirim ke Telegram admin (tinggal diteruskan ke WA guru).' : '. Bot Telegram belum diatur: salin pesan dari tabel di bawah.'));
    }

    /** Aksi per tautan: cabut/hapus (baris dihapus), kirim ulang ke Telegram, lepas kunci perangkat. */
    public function aksi(int $id)
    {
        $back = site_url('cek_soal');
        $db   = db_connect();
        $t    = $db->table('cek_tugas')->where('id', $id)->get()->getRowArray();
        if ($t === null) {
            return redirect()->to($back)->with('error', 'Tautan tidak ditemukan.');
        }
        $aksi = (string) $this->request->getPost('aksi');
        // Cabut (tautan aktif) dan Hapus (selesai/kedaluwarsa) sama-sama menghapus barisnya: tautan langsung mati dan
        // tidak tampil lagi, sehingga bisa dibuat ulang (panel atau /ceksoal).
        if ($aksi === 'cabut' || $aksi === 'hapus') {
            $db->table('cek_tugas')->where('id', $id)->delete();
            ActivityLog::add('cek_soal_' . $aksi, $t['guru_nama'] . ' · ' . $t['mapel'] . ' (' . CekSoal::status($t) . ')');

            return redirect()->to($back)->with('success', 'Tautan ' . $t['guru_nama'] . ' (' . $t['mapel'] . ') ' . ($aksi === 'cabut' ? 'dicabut' : 'dihapus') . '. Buat ulang lewat form di atas atau /ceksoal di Telegram bila perlu.');
        }
        if ($aksi === 'lepas') {
            $db->table('cek_tugas')->where('id', $id)->update(['perangkat' => null]);
            ActivityLog::add('cek_soal_lepas', $t['guru_nama'] . ' · ' . $t['mapel']);

            return redirect()->to($back)->with('success', 'Kunci perangkat dilepas: tautan ' . $t['guru_nama'] . ' bisa dibuka lagi di perangkat baru (yang pertama membuka).');
        }
        if ($aksi === 'kirim') {
            $r = Telegram::kirimAdmin(CekSoal::pesan($t));

            return redirect()->to($back)->with(($r['ok'] ?? false) ? 'success' : 'error', ($r['ok'] ?? false) ? 'Pesan dikirim ulang ke Telegram admin.' : 'Gagal kirim Telegram: ' . ($r['description'] ?? 'tidak terhubung'));
        }

        return redirect()->to($back);
    }

    /** Hasil cek satu tautan: soal lengkap + tanda guru + catatan. */
    public function hasil(int $id): string
    {
        $t = db_connect()->table('cek_tugas')->where('id', $id)->get()->getRowArray();
        if ($t === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $soal  = (array) json_decode((string) $t['soal'], true);
        $isi   = [];
        $gagal = null;
        try {
            $isi = (array) (MoodleBridge::call('soal_semua', ['courseid' => (int) $t['courseid'], 'ids' => array_column($soal, 'id')])[0]['soal'] ?? []);
        } catch (\Throwable $e) {
            $gagal = $e->getMessage();
        }

        return $this->render('cek_soal/hasil', [
            'title' => 'Cek Soal · Hasil',
            't'     => $t,
            'isi'   => $isi,
            'hasil' => (array) json_decode((string) ($t['hasil'] ?? ''), true),
            'gagal' => $gagal,
        ]);
    }

    /** Pengaturan: jumlah soal per tautan (5…30 / semua) dan alamat tautan. */
    public function setelan()
    {
        $a = CekSoal::asal((string) $this->request->getPost('alamat'));
        if ($a === null) {
            return redirect()->to(site_url('cek_soal'))->with('error', 'Alamat tidak valid (contoh: https://cbt.sekolah.sch.id).');
        }
        $n = (int) $this->request->getPost('jumlah');
        if (! in_array($n, CekSoal::PILIHAN_JUMLAH, true)) {
            return redirect()->to(site_url('cek_soal'))->with('error', 'Pilihan jumlah soal tidak valid.');
        }
        Settings::set('cek_soal_alamat', $a);
        Settings::set('cek_soal_jumlah', (string) $n);
        ActivityLog::add('cek_soal_setelan', 'Jumlah ' . CekSoal::namaJumlah($n) . ', alamat ' . $a);

        return redirect()->to(site_url('cek_soal'))->with('success', 'Pengaturan disimpan: ' . CekSoal::namaJumlah($n) . ' per guru.');
    }

    // ---------- Data guru ----------

    public function template()
    {
        $contoh = [['Sari Dewi, S.Pd.', 'Matematika', 'X'], ['Sari Dewi, S.Pd.', 'Matematika', 'XI'], ['Andi Rahman, S.Pd.', 'Fisika', 'XII']];

        return $this->response->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="template_guru_mapel.xlsx"')
            ->setBody(Excel::build(self::KOLOM, $contoh));
    }

    /** Unggah Excel data guru (Nama Guru, Mapel, Tingkat). Mode ganti = hapus data lama dulu. */
    public function unggah()
    {
        $back = site_url('cek_soal') . '?tab=guru';
        $file = $this->request->getFile('berkas');
        if (! $file || ! $file->isValid() || ! in_array(strtolower($file->getClientExtension()), ['xlsx', 'xls', 'csv'], true)) {
            return redirect()->to($back)->with('error', 'Pilih berkas Excel (.xlsx) yang valid.');
        }
        try {
            $matriks = self::bacaMatriks($file->getTempName());
            $rows    = $matriks ?? Excel::read($file->getTempName(), ['nama_guru']);
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Berkas tidak bisa dibaca: ' . $e->getMessage());
        }
        $data  = [];
        $salah = [];
        foreach ($rows as $i => $r) {
            $nama    = trim((string) ($r['nama_guru'] ?? $r['nama'] ?? ''));
            $mapel   = trim((string) ($r['mapel'] ?? $r['mata_pelajaran'] ?? ''));
            $tingkat = strtoupper(trim((string) ($r['tingkat'] ?? $r['kelas'] ?? '')));
            $tingkat = Tingkat::of($tingkat) ?: $tingkat;
            if ($nama === '' || $mapel === '' || ! in_array($tingkat, Tingkat::LIST, true)) {
                $salah[] = 'baris ' . ($i + 2);

                continue;
            }
            $data[] = ['nama' => mb_substr($nama, 0, 120), 'mapel' => mb_substr($mapel, 0, 120), 'tingkat' => $tingkat, 'created_at' => date('Y-m-d H:i:s')];
        }
        if ($data === []) {
            return redirect()->to($back)->with('error', 'Tidak ada baris yang valid. Kolom wajib: Nama Guru, Mapel, Tingkat (X/XI/XII).');
        }
        $db = db_connect();
        if ($this->request->getPost('ganti') === '1') {
            $db->table('cek_guru')->truncate();
        }
        $db->table('cek_guru')->insertBatch($data);
        Settings::set('cek_guru_diubah', date('Y-m-d H:i:s'));
        ActivityLog::add('cek_soal_guru', count($data) . ' baris data guru diunggah' . ($this->request->getPost('ganti') === '1' ? ' (ganti)' : ''));

        return redirect()->to($back)->with('success', count($data) . ' baris data guru disimpan.'
            . ($salah !== [] ? ' Dilewati (kolom kosong/tingkat salah): ' . implode(', ', array_slice($salah, 0, 15)) . (count($salah) > 15 ? ', …' : '') . '.' : '')
            . ' Periksa kolom "Cocok dengan" di tabel.');
    }

    /**
     * Format lampiran SK penulis soal: kolom "Mata Pelajaran" lalu kolom guru per kelas (judul XII / XI / X di baris
     * judul atau baris bawahnya). Sel gabungan (satu guru untuk beberapa kelas) diisikan ke semua kelasnya.
     * Data berhenti di baris pertama yang kolom mapelnya kosong. null = bukan format ini.
     *
     * @return list<array{nama_guru: string, mapel: string, tingkat: string}>|null
     */
    private static function bacaMatriks(string $path): ?array
    {
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        $raw   = $sheet->toArray(null, true, false, false);
        foreach ($sheet->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($range);
            $nilai = $raw[$r1 - 1][$c1 - 1] ?? null;
            for ($r = $r1; $r <= $r2; $r++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    $raw[$r - 1][$c - 1] = $nilai;
                }
            }
        }
        $teks = static fn ($v): string => trim((string) preg_replace('/\s+/', ' ', (string) ($v ?? '')));
        foreach (array_slice($raw, 0, 30, true) as $i => $baris) {
            $kolMapel = null;
            foreach ($baris as $c => $v) {
                if (in_array(strtoupper($teks($v)), ['MATA PELAJARAN', 'MAPEL', 'MATA PELAJARAN (MAPEL)'], true)) {
                    $kolMapel = $c;
                    break;
                }
            }
            if ($kolMapel === null) {
                continue;
            }
            // Judul kelas di baris yang sama atau satu/dua baris di bawahnya.
            $kolT  = [];
            $mulai = $i + 1;
            foreach ([$i, $i + 1, $i + 2] as $j) {
                foreach ($raw[$j] ?? [] as $c => $v) {
                    $t = Tingkat::of('' . $teks($v));
                    if ($c !== $kolMapel && $t !== '' && strtoupper($teks($v)) === $t && ! isset($kolT[$c])) {
                        $kolT[$c] = $t;
                        $mulai    = max($mulai, $j + 1);
                    }
                }
            }
            if ($kolT === []) {
                continue;
            }
            $hasil = [];
            for ($r = $mulai; $r < count($raw); $r++) {
                $mapel = $teks($raw[$r][$kolMapel] ?? '');
                if ($mapel === '') {
                    break;
                }
                foreach ($kolT as $c => $t) {
                    $nama = $teks($raw[$r][$c] ?? '');
                    if ($nama !== '' && $nama !== '-') {
                        $hasil[] = ['nama_guru' => $nama, 'mapel' => $mapel, 'tingkat' => $t];
                    }
                }
            }

            return $hasil;
        }

        return null;
    }

    public function simpanGuru()
    {
        $back    = site_url('cek_soal') . '?tab=guru';
        $nama    = trim((string) $this->request->getPost('nama'));
        $mapel   = trim((string) $this->request->getPost('mapel'));
        $tingkat = (string) $this->request->getPost('tingkat');
        if ($nama === '' || $mapel === '' || ! in_array($tingkat, Tingkat::LIST, true)) {
            return redirect()->to($back)->with('error', 'Isi nama guru, mapel, dan tingkat.');
        }
        $row = ['nama' => mb_substr($nama, 0, 120), 'mapel' => mb_substr($mapel, 0, 120), 'tingkat' => $tingkat];
        $id  = (int) $this->request->getPost('id');
        $db  = db_connect();
        if ($id > 0) {
            $db->table('cek_guru')->where('id', $id)->update($row);
        } else {
            $db->table('cek_guru')->insert($row + ['created_at' => date('Y-m-d H:i:s')]);
        }
        Settings::set('cek_guru_diubah', date('Y-m-d H:i:s'));

        return redirect()->to($back)->with('success', 'Data guru disimpan.');
    }

    public function hapusGuru(int $id)
    {
        db_connect()->table('cek_guru')->where('id', $id)->delete();
        Settings::set('cek_guru_diubah', date('Y-m-d H:i:s'));

        return redirect()->to(site_url('cek_soal') . '?tab=guru')->with('success', 'Baris data guru dihapus.');
    }
}
