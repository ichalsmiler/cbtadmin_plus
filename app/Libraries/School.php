<?php

namespace App\Libraries;

/** Identitas sekolah untuk kop dan tanda tangan dokumen. */
class School
{
    public const UPLOAD_DIR = WRITEPATH . 'uploads/';

    private const HARI  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function info(): array
    {
        $dataUri = static fn (?string $p): ?string => $p ? 'data:' . mime_content_type($p) . ';base64,' . base64_encode((string) file_get_contents($p)) : null;

        return [
            'nama_sekolah'   => Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', '')),
            'npsn'           => Settings::get('npsn', (string) env('cbt.npsn', '')),
            'alamat'         => Settings::get('alamat'),
            'kota'           => Settings::get('kota'),
            // Pimpinan: jabatan (Kepala Sekolah / Kepala Madrasah), nama, jenis nomor (NIP/NIY) dan nomornya.
            'jabatan'        => Settings::get('jabatan_pimpinan', 'Kepala Sekolah') ?: 'Kepala Sekolah',
            'kepala_sekolah' => Settings::get('kepala_sekolah'),
            'jenis_nomor'    => Settings::get('jenis_nomor_pimpinan', 'NIP') ?: 'NIP',
            'nip_kepala'     => Settings::get('nip_kepala'),
            'nama_kegiatan'  => Settings::get('nama_kegiatan'),
            'tahun_ajaran'   => Settings::get('tahun_ajaran'),
            'alamat_server'  => Settings::get('alamat_server'),
            'logo'           => $dataUri(self::logoPath()),
            'logo2'          => $dataUri(self::logo2Path()),
            'ttd'            => $dataUri(self::ttdPath()),
        ];
    }

    /**
     * Gaya <img> agar gambar masuk kotak persegi $kotakMm × $kotakMm dengan proporsi tetap
     * (logo kiri/kanan tampak sama besar walau berkas unggahan beda ukuran). dompdf tidak mendukung object-fit,
     * jadi lebar/tinggi dihitung dari ukuran asli gambar.
     */
    public static function gayaLogo(?string $path, float $kotakMm = 11): string
    {
        $ukuran = $path ? @getimagesize($path) : false;
        if (! $ukuran || $ukuran[0] <= 0 || $ukuran[1] <= 0) {
            return 'height:' . $kotakMm . 'mm;width:auto';
        }
        $skala = $kotakMm / max($ukuran[0], $ukuran[1]);

        return sprintf('width:%.2fmm;height:%.2fmm', $ukuran[0] * $skala, $ukuran[1] * $skala);
    }

    public static function logoPath(): ?string
    {
        return self::gambar('logo_file');
    }

    /** Logo kanan kartu peserta (mis. logo pemerintah daerah / dinas). */
    public static function logo2Path(): ?string
    {
        return self::gambar('logo2_file');
    }

    /** Logo halaman login ujian (salinan panel; yang dipakai Moodle ada di core_admin/logo). */
    public static function logoLoginPath(): ?string
    {
        return self::gambar('logologin_file');
    }

    /** Tanda tangan pimpinan (untuk dokumen). */
    public static function ttdPath(): ?string
    {
        return self::gambar('ttd_file');
    }

    public static function removeLogo(): void
    {
        self::hapus('logo');
    }

    public static function removeTtd(): void
    {
        self::hapus('ttd');
    }

    /** Hapus gambar logo / logo2 / ttd. */
    public static function hapusGambar(string $jenis): void
    {
        self::hapus($jenis);
    }

    private static function gambar(string $key): ?string
    {
        $name = basename(Settings::get($key));
        $path = self::UPLOAD_DIR . $name;

        return $name !== '' && is_file($path) ? $path : null;
    }

    private static function hapus(string $jenis): void
    {
        // "logo.*" tidak boleh ikut menghapus "logo2.*", jadi cocokkan nama persis.
        foreach (glob(self::UPLOAD_DIR . $jenis . '.{png,jpg}', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        Settings::set($jenis . '_file', '');
    }

    /** "Jumat, 13 November 2026" */
    public static function tanggal(int $ts, bool $withDay = true): string
    {
        $text = (int) date('j', $ts) . ' ' . self::BULAN[(int) date('n', $ts)] . ' ' . date('Y', $ts);

        return $withDay ? self::HARI[(int) date('w', $ts)] . ', ' . $text : $text;
    }
}
