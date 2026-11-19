<?php

namespace App\Libraries;

/** Tingkatan kelas (X, XI, XII) dibaca dari awal nama kelas, mis. "XI-3" → XI. */
class Tingkat
{
    public const LIST = ['X', 'XI', 'XII'];

    public static function of(?string $kelas): string
    {
        return preg_match('/^\s*(XII|XI|X)(?![A-Z])/i', (string) $kelas, $m) ? strtoupper($m[1]) : '';
    }

    /**
     * Jam ujian tingkatan (Admin Tools → Sesi; setting jam_<X|XI|XII>_mulai/selesai), mis. "08:00 - 10:00";
     * '' bila belum diisi. Dipakai kartu peserta, daftar hadir, berita acara, dan log telat mulai.
     */
    public static function jam(string $tingkat, string $pemisah = ' - '): string
    {
        if (! in_array($tingkat, self::LIST, true)) {
            return '';
        }
        $mulai   = Settings::get('jam_' . $tingkat . '_mulai');
        $selesai = Settings::get('jam_' . $tingkat . '_selesai');

        return $mulai !== '' ? $mulai . ($selesai !== '' ? $pemisah . $selesai : '') : '';
    }

    /** Apakah kelas termasuk tingkatan itu ('' = semua tingkatan). */
    public static function cocok(?string $kelas, string $tingkat): bool
    {
        return $tingkat === '' || self::of($kelas) === $tingkat;
    }

    /** Tingkatan bawaan bila belum pernah dipilih. */
    public const BAWAAN = 'X';

    /**
     * Tingkatan terpilih: dari ?tingkat= (''/semua = semua tingkatan) lalu diingat di sesi, sehingga
     * menu lain dan ekspor (Excel/PDF) yang tidak membawa ?tingkat= memakai pilihan terakhir.
     * Belum pernah memilih → Kelas X.
     */
    public static function dariRequest(): string
    {
        $raw = service('request')->getGet('tingkat');
        if ($raw === null && session()->get('admin_ruang') !== null) {
            // Pengawas ruang: bawaan mengikuti tingkatan sesi aktif (mis. sesi Kelas XII → XII).
            $sesi = ActiveTest::sesiAktif();
            if ($sesi !== null && in_array($sesi['tingkat'], self::LIST, true)) {
                return $sesi['tingkat'];
            }
        }
        if ($raw === null) {
            $simpan = session()->get('filter_tingkat');

            return is_string($simpan) && ($simpan === '' || in_array($simpan, self::LIST, true)) ? $simpan : self::BAWAAN;
        }
        $t = strtoupper(trim((string) $raw));
        $t = in_array($t, self::LIST, true) ? $t : '';
        session()->set('filter_tingkat', $t);

        return $t;
    }

    /** Pola SQL REGEXP untuk kolom kelas, mis. '^XI([^A-Z]|$)'. */
    public static function regexp(string $tingkat): string
    {
        return '^' . $tingkat . '([^A-Za-z]|$)';
    }
}
