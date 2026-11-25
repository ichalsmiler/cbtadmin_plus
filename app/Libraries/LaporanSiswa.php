<?php

namespace App\Libraries;

/**
 * Laporan Siswa: siswa melapor kecurangan atau kendala teknis lewat tombol "Laporkan" di Moodle (plugin local_cbtujian),
 * langsung ke Proktor/admin (menu Laporan Siswa). Tombol hanya tampil saat fitur aktif + ada sesi aktif, dan
 * disembunyikan selama siswa mengerjakan (attempt inprogress). Moodle menandatangani tautan (HMAC username|waktu,
 * kunci `lapor_kunci` dikirim ke Moodle lewat bridge cbtujian_config), jadi siswa tidak login lagi di panel.
 */
class LaporanSiswa
{
    /** Menu di sidebar: false = fitur masih disembunyikan (halaman tetap bisa dibuka lewat alamat oleh admin). */
    public const TAMPIL = false;

    public const JENIS = [
        'curang'  => ['Buka HP / perangkat lain', 'Kerja sama / mencontek teman', 'Joki (dikerjakan orang lain)', 'Buka aplikasi / situs lain', 'Bocoran soal / kunci jawaban', 'Lainnya'],
        'kendala' => ['Soal tidak muncul / rusak', 'Gambar soal tidak tampil', 'Aplikasi / HP keluar sendiri', 'Tidak bisa login', 'Waktu / token bermasalah', 'Lainnya'],
    ];

    public const NAMA_JENIS = ['curang' => 'Kecurangan', 'kendala' => 'Kendala Teknis'];

    public const STATUS = ['baru' => 'Baru', 'tindak' => 'Ditindaklanjuti', 'tidak' => 'Tidak terbukti'];

    /** Batas laporan per siswa per sesi (hari ini). */
    public const MAKS_PER_SESI = 3;

    /** Masa berlaku tautan bertanda tangan dari Moodle (detik). */
    public const MASA_TAUTAN = 3 * 3600;

    /** Foto dihapus otomatis setelah sekian hari. */
    public const SIMPAN_FOTO_HARI = 30;

    public static function aktif(): bool
    {
        return Settings::get('lapor_aktif', '0') === '1';
    }

    /** Kunci HMAC bersama panel ↔ Moodle (dibuat sekali). */
    public static function kunci(): string
    {
        $k = Settings::get('lapor_kunci');
        if (strlen($k) < 32) {
            $k = bin2hex(random_bytes(24));
            Settings::set('lapor_kunci', $k);
        }

        return $k;
    }

    public static function tanda(string $username, int $waktu): string
    {
        return hash_hmac('sha256', $username . '|' . $waktu, self::kunci());
    }

    public static function cekTanda(string $username, int $waktu, string $tanda): bool
    {
        return $username !== '' && abs(time() - $waktu) <= self::MASA_TAUTAN
            && hash_equals(self::tanda($username, $waktu), $tanda);
    }

    /**
     * Kirim keadaan tombol ke Moodle: tampil bila fitur aktif DAN ada sesi aktif. Dipanggil saat sakelar diubah dan
     * saat sesi diaktifkan/dinonaktifkan. Alamat tanpa host (Moodle dan panel di host yang sama).
     */
    public static function sinkronMoodle(): void
    {
        $url = (string) parse_url(site_url('lapor'), PHP_URL_PATH);
        MoodleBridge::call('cbtujian_config', [
            'lapor'      => self::aktif() && ActiveTest::sesiId() !== 0 ? 1 : 0,
            'laporurl'   => $url,
            'laporkunci' => self::kunci(),
        ]);
    }

    /** Peserta + sesi efektifnya (sesi peserta, atau sesi ruangnya). */
    public static function peserta(string $username): ?array
    {
        $row = db_connect()->table('peserta p')
            ->select('p.id, p.nama, p.kelas, p.username, p.ruang_id, COALESCE(p.sesi_id, r.sesi_id) AS sesi_id, r.nama AS ruang_nama', false)
            ->join('ruang r', 'r.id = p.ruang_id', 'left')
            ->where('p.username', $username)->get()->getRowArray();

        return $row ?: null;
    }

    /** Alasan siswa belum/tidak bisa melapor sekarang; '' = boleh. */
    public static function alasanTutup(array $p): string
    {
        if (! self::aktif()) {
            return 'Fitur laporan sedang tidak dibuka.';
        }
        $sesi = ActiveTest::sesiId();
        if ($sesi === 0) {
            return 'Belum ada sesi ujian yang aktif.';
        }
        if ($sesi > 0 && (int) ($p['sesi_id'] ?? 0) !== $sesi) {
            return 'Laporan hanya bisa dikirim saat sesi ujian Anda berlangsung.';
        }
        $selesai = Settings::get('jam_' . Tingkat::of($p['kelas'] ?? '') . '_selesai');
        if (preg_match('/^\d{1,2}:\d{2}$/', $selesai) && time() > strtotime(date('Y-m-d') . ' ' . $selesai)) {
            return 'Sesi ujian Anda sudah berakhir (pukul ' . $selesai . ').';
        }

        return '';
    }

    /** Teman seruang di sesi aktif (untuk pilihan terlapor), tanpa dirinya sendiri. */
    public static function temanSeruang(array $p): array
    {
        if (empty($p['ruang_id'])) {
            return [];
        }
        $b = db_connect()->table('peserta p')->select('p.id, p.nama, p.kelas')
            ->join('ruang r', 'r.id = p.ruang_id', 'left')
            ->where('p.ruang_id', (int) $p['ruang_id'])->where('p.id !=', (int) $p['id']);
        if (ActiveTest::sesiFilter() !== null) {
            $b->where('COALESCE(p.sesi_id, r.sesi_id)', ActiveTest::sesiFilter(), false);
        }

        return $b->orderBy('p.nama')->get()->getResultArray();
    }

    /** Jumlah laporan siswa ini hari ini di sesi aktif. */
    public static function jumlahHariIni(int $pesertaId): int
    {
        return db_connect()->table('laporan_siswa')->where('pelapor_id', $pesertaId)
            ->where('sesi_id', ActiveTest::sesiId())->where('created_at >=', date('Y-m-d 00:00:00'))->countAllResults();
    }

    /** Laporan siswa ini hari ini (untuk "bukti terkirim" + status). */
    public static function milikHariIni(int $pesertaId): array
    {
        return db_connect()->table('laporan_siswa')->select('id, jenis, kategori, status, created_at')
            ->where('pelapor_id', $pesertaId)->where('created_at >=', date('Y-m-d 00:00:00'))
            ->orderBy('id', 'DESC')->get()->getResultArray();
    }

    public static function sudahMelaporkan(int $pelapor, int $terlapor): bool
    {
        return db_connect()->table('laporan_siswa')->where('pelapor_id', $pelapor)->where('terlapor_id', $terlapor)
            ->where('sesi_id', ActiveTest::sesiId())->where('created_at >=', date('Y-m-d 00:00:00'))->countAllResults() > 0;
    }

    public static function folderFoto(): string
    {
        return WRITEPATH . 'laporan/';
    }

    /**
     * Simpan foto: dikecilkan server (lebar maks 1000 px, JPEG <= 150 KB, GD) lewat SoalRtf::kecilkan; HP sudah
     * mengecilkan lebih dulu bila JavaScript-nya jalan. Mengembalikan nama berkas, null bila bukan gambar.
     */
    public static function simpanFoto(string $tmpPath): ?string
    {
        $data = (string) @file_get_contents($tmpPath);
        $info = $data !== '' ? @getimagesizefromstring($data) : false;
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        [$hasil, $ext] = SoalRtf::kecilkan($data, $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg'));
        if (! is_dir(self::folderFoto())) {
            mkdir(self::folderFoto(), 0750, true);
        }
        $nama = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        file_put_contents(self::folderFoto() . $nama, $hasil);

        return $nama;
    }

    /** Hapus foto yang lebih tua dari SIMPAN_FOTO_HARI (dipanggil saat menu dibuka). */
    public static function bersihkanFoto(): void
    {
        $batas = date('Y-m-d H:i:s', strtotime('-' . self::SIMPAN_FOTO_HARI . ' days'));
        $db    = db_connect();
        foreach ($db->table('laporan_siswa')->select('id, foto')->where('foto IS NOT NULL')->where('created_at <', $batas)->get()->getResultArray() as $r) {
            @unlink(self::folderFoto() . basename((string) $r['foto']));
            $db->table('laporan_siswa')->where('id', (int) $r['id'])->update(['foto' => null]);
        }
    }

    public static function jumlahBaru(): int
    {
        try {
            return db_connect()->table('laporan_siswa')->where('status', 'baru')->countAllResults();
        } catch (\Throwable $e) {
            return 0;  // tabel belum dimigrasi
        }
    }

    /** Pelapor yang laporannya sering "Tidak terbukti" (>= 2): ditandai di daftar. */
    public static function pelaporMeragukan(): array
    {
        $rows = db_connect()->table('laporan_siswa')->select('pelapor_id, COUNT(*) AS n')
            ->where('status', 'tidak')->groupBy('pelapor_id')->having('n >=', 2)->get()->getResultArray();

        return array_map('intval', array_column($rows, 'n', 'pelapor_id'));
    }
}
