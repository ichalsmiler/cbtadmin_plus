<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\BandingSoal;
use App\Libraries\CekSoal;
use App\Libraries\KirimSoal;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\Settings;
use App\Libraries\SoalRtf;

/**
 * Soal → Kiriman Soal (admin + kurikulum): aktifkan TA + semester pengumpulan (folder X/XI/XII dibuat), buka/tutup
 * pengiriman, tautan guru /kirim_soal, dan rekap sudah/belum per guru–mapel–tingkat (Data Guru di Cek Soal)
 * beserta unduhan RTF/Word asli dan ZIP per tingkat.
 */
class KirimanSoal extends PanelController
{
    public function index(): string
    {
        $aktif = KirimSoal::aktif();
        $ta    = (string) ($this->request->getGet('ta') ?? '');
        $sem   = (string) ($this->request->getGet('semester') ?? '');
        $lihat = KirimSoal::taSah($ta) && in_array($sem, KirimSoal::SEMESTER, true) ? ['ta' => $ta, 'semester' => $sem] : $aktif;

        $db      = db_connect();
        $kiriman = $lihat ? $db->table('kirim_soal')->where($lihat)->orderBy('guru')->get()->getResultArray() : [];
        $periode = $db->table('kirim_soal')->select('ta, semester, COUNT(*) AS n')->groupBy('ta, semester')
            ->orderBy('ta', 'DESC')->orderBy('semester')->get()->getResultArray();
        $rekap = KirimSoal::rekap($kiriman);

        return $this->render('kiriman_soal/index', [
            'title'   => 'Kiriman Soal',
            'aktif'   => $aktif,
            'lihat'   => $lihat,
            'rekap'   => $rekap,
            'periode' => $periode,
            'tutup'   => KirimSoal::tutup(),
            'tautan'  => CekSoal::alamat() . 'kirim_soal/',
            'pilihTa'    => KirimSoal::daftarTa(),
            'peta'       => KirimSoal::peta(),
            'taTerpakai' =>array_values(array_unique(array_column($periode, 'ta'))),
            'adaApp'  => is_file(APPPATH . 'KonverSoal/index.html'),
            'dataGuru'   => $this->dataGuru(),
        ]);
    }

    /** Ringkasan Data Guru (Cek Soal) untuk konfirmasi saat pindah periode: jumlah per tingkat + waktu terakhir diubah. */
    private function dataGuru(): array
    {
        $db    = db_connect();
        $per   = array_fill_keys(KirimSoal::TINGKAT, 0);
        foreach ($db->table('cek_guru')->select('tingkat, COUNT(*) AS n')->groupBy('tingkat')->get()->getResultArray() as $r) {
            $per[$r['tingkat']] = (int) $r['n'];
        }
        $nama   = $db->table('cek_guru')->select('COUNT(DISTINCT nama) AS n')->get()->getRow()->n ?? 0;
        $diubah = Settings::get('cek_guru_diubah');
        if ($diubah === '') {
            $diubah = (string) ($db->table('cek_guru')->selectMax('created_at', 'm')->get()->getRow()->m ?? '');
        }

        return ['guru' => (int) $nama, 'per' => $per, 'diubah' => $diubah];
    }

    /** Aktifkan TA + semester: kiriman guru masuk ke folder ini; folder X, XI, XII dibuat. */
    public function aktifkan()
    {
        $ta  = (string) $this->request->getPost('ta');
        $sem = (string) $this->request->getPost('semester');
        if (! KirimSoal::taSah($ta) || ! in_array($sem, KirimSoal::SEMESTER, true)) {
            return redirect()->back()->with('error', 'Pilih tahun ajaran dan semester.');
        }
        $lama = KirimSoal::aktif();
        $baru = ! $lama || $lama['ta'] !== $ta || $lama['semester'] !== $sem;
        if ($baru && $this->request->getPost('guru_sesuai') !== '1') {
            return redirect()->back()->with('error', 'Pastikan dulu Data Guru di Cek Soal sudah sesuai untuk periode ' . $sem . ' ' . $ta . ', lalu centang konfirmasinya.');
        }
        foreach (KirimSoal::TINGKAT as $t) {
            $dir = KirimSoal::folder($ta, $sem, $t);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                return redirect()->back()->with('error', 'Folder ' . $dir . ' tidak bisa dibuat.');
            }
        }
        Settings::set('kirim_soal_ta', $ta);
        Settings::set('kirim_soal_semester', $sem);
        ActivityLog::add('Kiriman Soal', 'Aktifkan ' . $sem . ' ' . $ta);

        return redirect()->to(site_url('kiriman_soal'))->with('success', 'Pengumpulan soal semester ' . $sem . ' ' . $ta . ' aktif. Folder X, XI, XII siap.');
    }

    /** Tambah / hapus tahun ajaran di daftar pilihan (yang aktif atau sudah ada kirimannya tidak bisa dihapus). */
    public function ta()
    {
        $ta     = trim((string) $this->request->getPost('ta'));
        $daftar = KirimSoal::daftarTa();
        if ($this->request->getPost('aksi') === 'hapus') {
            $dipakai = db_connect()->table('kirim_soal')->where('ta', $ta)->countAllResults() > 0;
            if (($ta === (KirimSoal::aktif()['ta'] ?? '')) || $dipakai) {
                return redirect()->back()->with('error', 'Tahun ajaran ' . $ta . ' sedang aktif atau sudah punya kiriman.');
            }
            KirimSoal::simpanDaftarTa(array_diff($daftar, [$ta]));

            return redirect()->to(site_url('kiriman_soal'))->with('success', 'Tahun ajaran ' . $ta . ' dihapus dari daftar.');
        }
        if (! KirimSoal::taSah($ta)) {
            return redirect()->back()->with('error', 'Tulis tahun ajaran seperti 2027/2028 (tahun kedua = tahun pertama + 1).');
        }
        if (in_array($ta, $daftar, true)) {
            return redirect()->back()->with('error', 'Tahun ajaran ' . $ta . ' sudah ada.');
        }
        KirimSoal::simpanDaftarTa([...$daftar, $ta]);

        return redirect()->to(site_url('kiriman_soal'))->with('success', 'Tahun ajaran ' . $ta . ' ditambahkan.');
    }

    /** Hapus satu pasangan mapel tersimpan (kiriman berikutnya kembali ditebak dan wajib dikonfirmasi). */
    public function hapusPeta()
    {
        $kunci = (string) $this->request->getPost('kunci');
        if (! array_key_exists($kunci, KirimSoal::peta())) {
            return redirect()->back()->with('error', 'Pasangan tidak ditemukan.');
        }
        KirimSoal::simpanPeta($kunci, null);
        ActivityLog::add('Kiriman Soal', 'Hapus pasangan mapel ' . $kunci);

        return redirect()->to(site_url('kiriman_soal') . '#peta')->with('success', 'Pasangan ' . str_replace('|', ' · ', $kunci) . ' dihapus.');
    }

    /** Buka / tutup sekarang / jadwalkan tutup pengiriman. */
    public function tutup()
    {
        $aksi = (string) $this->request->getPost('aksi');
        if ($aksi === 'buka') {
            Settings::set('kirim_soal_tutup', '');
            $pesan = 'Pengiriman soal dibuka.';
        } elseif ($aksi === 'sekarang') {
            Settings::set('kirim_soal_tutup', date('Y-m-d H:i:s'));
            $pesan = 'Pengiriman soal ditutup.';
        } else {
            $w = strtotime((string) $this->request->getPost('waktu'));
            if ($w === false) {
                return redirect()->back()->with('error', 'Isi tanggal dan jam tutup.');
            }
            Settings::set('kirim_soal_tutup', date('Y-m-d H:i:s', $w));
            $pesan = 'Pengiriman soal ditutup mulai ' . date('d-m-Y H:i', $w) . '.';
        }
        ActivityLog::add('Kiriman Soal', $pesan);

        return redirect()->to(site_url('kiriman_soal'))->with('success', $pesan);
    }

    /** Unduh RTF (jenis rtf) atau Word asli (jenis asli) satu kiriman. */
    public function unduh(int $id, string $jenis)
    {
        $k = db_connect()->table('kirim_soal')->where('id', $id)->get()->getRowArray();
        $f = $k ? ($jenis === 'asli' ? $k['asli'] : $k['berkas']) : null;
        $p = $f ? KirimSoal::folder($k['ta'], $k['semester'], $k['tingkat']) . $f : '';
        if ($p === '' || ! is_file($p)) {
            return redirect()->back()->with('error', 'Berkas tidak ditemukan.');
        }

        return $this->response->download($p, null)->setFileName($f);
    }

    /** Bandingkan soal kiriman guru (RTF tersimpan) dengan soal di quiz Moodle course tujuan, berdampingan per soal. */
    public function bandingkan(int $id)
    {
        $back = site_url('kiriman_soal');
        $k    = db_connect()->table('kirim_soal')->where('id', $id)->get()->getRowArray();
        if (! $k || empty($k['courseid'])) {
            return redirect()->to($back)->with('error', 'Kiriman ini belum pernah dimasukkan ke Moodle.');
        }
        $rtf = KirimSoal::bacaRtf($k);
        if ($rtf === null) {
            return redirect()->to($back)->with('error', 'Berkas RTF kiriman tidak ditemukan.');
        }
        try {
            $kiriman = SoalRtf::urai($rtf)['soal'];
            $moodle  = MoodleBridge::call('soal_semua', ['courseid' => (int) $k['courseid']])[0] ?? [];
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal membaca soal: ' . $e->getMessage());
        }
        if (empty($moodle['quiz'])) {
            return redirect()->to($back)->with('error', 'Quiz mapel tujuan tidak ditemukan di Moodle (mungkin sudah direset).');
        }
        $course = Moodle::db()->query('SELECT fullname, shortname FROM ' . Moodle::t('course') . ' WHERE id = ?', [(int) $k['courseid']])->getRowArray();

        return $this->render('kiriman_soal/bandingkan', [
            'title'   => 'Bandingkan Soal',
            'k'       => $k,
            'course'  => $course,
            'quiz'    => $moodle['quiz'],
            'kiriman' => $kiriman,
            'moodle'  => (array) $moodle['soal'],
            'hasil'   => BandingSoal::bandingkan($kiriman, (array) $moodle['soal']),
        ]);
    }

    /** ZIP semua kiriman satu tingkat (atau semua tingkat bila kosong) untuk TA + semester. */
    public function zip()
    {
        $ta  = (string) $this->request->getGet('ta');
        $sem = (string) $this->request->getGet('semester');
        $t   = (string) $this->request->getGet('tingkat');
        if (! KirimSoal::taSah($ta) || ! in_array($sem, KirimSoal::SEMESTER, true) || ($t !== '' && ! in_array($t, KirimSoal::TINGKAT, true))) {
            return redirect()->back()->with('error', 'Pilihan tidak sah.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ks');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $n = 0;
        foreach ($t !== '' ? [$t] : KirimSoal::TINGKAT as $tk) {
            foreach (glob(KirimSoal::folder($ta, $sem, $tk) . '*') ?: [] as $f) {
                if (is_file($f)) {
                    $zip->addFile($f, ($t !== '' ? '' : $tk . '/') . basename($f));
                    $n++;
                }
            }
        }
        $zip->close();
        if ($n === 0) {
            @unlink($tmp);

            return redirect()->back()->with('error', 'Belum ada kiriman.');
        }
        $nama = 'Soal ' . $sem . ' ' . str_replace('/', '-', $ta) . ($t !== '' ? ' Kelas ' . $t : '') . '.zip';
        $isi  = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $this->response->download($nama, $isi);
    }

    /**
     * Lihat folder soal: isi writable/kirim_soal/<p> (folder + berkas), unduh berkas (?p=…&unduh=1) atau
     * folder sebagai ZIP (?p=…&zip=1). Path dibatasi di dalam kirim_soal (tanpa ..).
     */
    public function folder()
    {
        $akar = realpath(WRITEPATH . 'kirim_soal') ?: '';
        $p    = trim(str_replace('\\', '/', (string) ($this->request->getGet('p') ?? '')), '/');
        $full = $akar !== '' ? realpath($akar . ($p !== '' ? '/' . $p : '')) : false;
        if ($full === false || ($full !== $akar && ! str_starts_with($full, $akar . DIRECTORY_SEPARATOR))) {
            if ($akar === '' && $p === '') {
                return $this->render('kiriman_soal/folder', ['title' => 'Folder Soal', 'p' => '', 'isi' => []]);
            }

            return redirect()->to(site_url('kiriman_soal/folder'))->with('error', 'Folder tidak ditemukan.');
        }
        if (is_file($full)) {
            return $this->response->download($full, null)->setFileName(basename($full));
        }
        if ($this->request->getGet('zip')) {
            $tmp = tempnam(sys_get_temp_dir(), 'ks');
            $zip = new \ZipArchive();
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $zip->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($full) + 1)));
                }
            }
            $n = $zip->numFiles;
            $zip->close();
            if ($n === 0) {
                @unlink($tmp);

                return redirect()->back()->with('error', 'Folder ini masih kosong.');
            }
            $isi = (string) file_get_contents($tmp);
            @unlink($tmp);

            return $this->response->download('Soal ' . ($p !== '' ? str_replace('/', ' ', $p) : 'semua') . '.zip', $isi);
        }
        $isi = [];
        foreach (scandir($full) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $f     = $full . '/' . $n;
            $dir   = is_dir($f);
            $isi[] = [
                'nama'   => $n,
                'dir'    => $dir,
                'jumlah' => $dir ? count(array_diff(scandir($f) ?: [], ['.', '..'])) : 0,
                'ukuran' => $dir ? 0 : (int) filesize($f),
                'waktu'  => (int) filemtime($f),
            ];
        }
        usort($isi, static fn ($a, $b) => [! $a['dir'], $a['nama']] <=> [! $b['dir'], $b['nama']]);

        return $this->render('kiriman_soal/folder', ['title' => 'Folder Soal', 'p' => $p, 'isi' => $isi]);
    }

    /** Hapus satu kiriman (mis. salah kirim): baris + berkasnya. */
    public function hapus(int $id)
    {
        $db = db_connect();
        $k  = $db->table('kirim_soal')->where('id', $id)->get()->getRowArray();
        if (! $k) {
            return redirect()->back()->with('error', 'Kiriman tidak ditemukan.');
        }
        $dir = KirimSoal::folder($k['ta'], $k['semester'], $k['tingkat']);
        foreach (array_filter([$k['berkas'], $k['asli']]) as $f) {
            @unlink($dir . $f);
        }
        $db->table('kirim_soal')->where('id', $id)->delete();
        ActivityLog::add('Kiriman Soal', 'Hapus kiriman ' . $k['mapel'] . ' - ' . $k['guru'] . ' (' . $k['tingkat'] . ')');

        return redirect()->back()->with('success', 'Kiriman ' . $k['mapel'] . ' – ' . $k['guru'] . ' dihapus.');
    }
}
