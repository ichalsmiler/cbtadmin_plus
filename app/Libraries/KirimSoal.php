<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Kirim Soal (guru, tanpa login) + Kiriman Soal (dashboard panel). Aplikasi Konver Soal (app/KonverSoal/, salinan
 * repo konver-soal) hanya disajikan di halaman guru
 * /kirim_soal (Kirim aktif, ke panel ini, bukan email). Kiriman disimpan per TA aktif:
 * writable/kirim_soal/<2026-2027>/<Ganjil|Genap>/<X|XI|XII>/<Mapel> - <Guru>.rtf (+ " (asli).docx"),
 * dicatat di tabel kirim_soal (satu baris per TA+semester+tingkat+guru+mapel; kirim ulang = ganti berkas, kali+1).
 */
class KirimSoal
{
    public const TINGKAT  = ['X', 'XI', 'XII'];
    public const SEMESTER = ['Ganjil', 'Genap'];
    private const APP     = APPPATH . 'KonverSoal/';
    private const JENIS   = ['html' => 'text/html; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8'];

    /** TA + semester aktif (diatur di Kiriman Soal), null bila belum diaktifkan. */
    public static function aktif(): ?array
    {
        $ta  = Settings::get('kirim_soal_ta');
        $sem = Settings::get('kirim_soal_semester');

        return self::taSah($ta) && in_array($sem, self::SEMESTER, true) ? ['ta' => $ta, 'semester' => $sem] : null;
    }

    public static function taSah(string $ta): bool
    {
        return preg_match('~^(\d{4})/(\d{4})$~', $ta, $m) === 1 && (int) $m[2] === (int) $m[1] + 1;
    }

    /**
     * Daftar tahun ajaran pilihan (setelan `kirim_soal_ta_daftar`, JSON), urut naik. Bawaan bila belum diatur:
     * TA berjalan dan berikutnya. TA aktif selalu ikut.
     */
    public static function daftarTa(): array
    {
        $d = json_decode(Settings::get('kirim_soal_ta_daftar'), true);
        if (! is_array($d)) {
            $y = (int) date('Y') - ((int) date('n') < 7 ? 1 : 0);
            $d = [$y . '/' . ($y + 1), ($y + 1) . '/' . ($y + 2)];
        }
        $aktif = Settings::get('kirim_soal_ta');
        if (self::taSah($aktif)) {
            $d[] = $aktif;
        }
        $d = array_values(array_unique(array_filter($d, static fn ($t) => is_string($t) && self::taSah($t))));
        sort($d);

        return $d;
    }

    public static function simpanDaftarTa(array $daftar): void
    {
        $d = array_values(array_unique(array_filter($daftar, static fn ($t) => is_string($t) && self::taSah($t))));
        sort($d);
        Settings::set('kirim_soal_ta_daftar', json_encode($d));
    }

    /**
     * Kiriman periode aktif untuk Masukkan Soal, masing-masing dengan `course` = tebakan course Moodle
     * (pencocokan nama mapel per tingkat seperti Cek Soal, CekSoal::pasangkan); 0 bila tidak ketemu.
     */
    public static function untukMasukkan(): array
    {
        $a = self::aktif();
        if ($a === null) {
            return [];
        }
        $rows  = db_connect()->table('kirim_soal')->where($a)->orderBy('tingkat')->orderBy('mapel')->orderBy('guru')->get()->getResultArray();
        $mapel = [];
        $peta  = self::peta();
        foreach ($rows as &$r) {
            $r['pasti'] = false;
            try {
                $mapel[$r['tingkat']] ??= CekSoal::mapel($r['tingkat']);
                // Pasangan tersimpan (shortname) didahulukan; bila course-nya tidak ada lagi, tebak dari nama.
                $sn = $peta[self::kunciPeta($r['tingkat'], $r['mapel'])] ?? null;
                foreach ($sn !== null ? $mapel[$r['tingkat']] : [] as $m) {
                    if ($m['shortname'] === $sn) {
                        $r['course'] = (int) $m['id'];
                        $r['pasti']  = true;
                    }
                }
                $kena = $r['pasti'] ? [] : (CekSoal::pasangkan([['id' => 0, 'mapel' => $r['mapel'], 'tingkat' => $r['tingkat']]], $mapel[$r['tingkat']])[0] ?? []);
            } catch (\Throwable $e) {
                $kena = [];
            }
            if (! $r['pasti']) {
                $r['course'] = (int) ($kena[0] ?? 0);
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Rekap per tingkat: baris Data Guru (guru + mapel) dipasangkan dengan kirimannya (null = belum);
     * kiriman yang guru/mapelnya tidak ada lagi di Data Guru ikut dengan 'lain' => true. Belum kirim di bawah.
     *
     * @return array<string, list<array{guru: string, mapel: string, kirim: ?array}>>
     */
    public static function rekap(array $kiriman): array
    {
        $peta = [];
        foreach ($kiriman as $k) {
            $peta[$k['tingkat'] . "\0" . $k['guru'] . "\0" . $k['mapel']] = $k;
        }
        $rekap = array_fill_keys(self::TINGKAT, []);
        foreach (self::guru() as $g) {
            $kunci                = $g['kelas'] . "\0" . $g['nama'] . "\0" . $g['mapel'];
            $rekap[$g['kelas']][] = ['guru' => $g['nama'], 'mapel' => $g['mapel'], 'kirim' => $peta[$kunci] ?? null];
            unset($peta[$kunci]);
        }
        foreach ($peta as $k) {
            $rekap[$k['tingkat']][] = ['guru' => $k['guru'], 'mapel' => $k['mapel'], 'kirim' => $k, 'lain' => true];
        }
        foreach ($rekap as &$list) {
            usort($list, static fn ($a, $b) => [$a['kirim'] === null ? 1 : 0, $a['mapel'], $a['guru']] <=> [$b['kirim'] === null ? 1 : 0, $b['mapel'], $b['guru']]);
        }
        unset($list);

        return $rekap;
    }

    /**
     * Pasangan mapel kiriman → course Moodle yang sudah dipastikan admin (tersimpan saat soal kiriman berhasil
     * dimasukkan): setelan `kirim_soal_peta` JSON {"X|Biologi": "USBK-X-BIOLOGI"}. Shortname dipakai (bukan id)
     * supaya tetap berlaku setelah Reset Moodle / semester berikutnya.
     */
    public static function peta(): array
    {
        $p = json_decode(Settings::get('kirim_soal_peta'), true);

        return is_array($p) ? $p : [];
    }

    public static function kunciPeta(string $tingkat, string $mapel): string
    {
        return $tingkat . '|' . $mapel;
    }

    public static function simpanPeta(string $kunci, ?string $shortname): void
    {
        $p = self::peta();
        if ($shortname === null) {
            unset($p[$kunci]);
        } else {
            $p[$kunci] = $shortname;
        }
        ksort($p);
        Settings::set('kirim_soal_peta', json_encode($p, JSON_UNESCAPED_UNICODE));
    }

    /** Isi RTF satu kiriman, null bila tidak ada. */
    public static function bacaRtf(array $k): ?string
    {
        $p = self::folder($k['ta'], $k['semester'], $k['tingkat']) . $k['berkas'];

        return is_file($p) ? (string) file_get_contents($p) : null;
    }

    /** Waktu (timestamp) pengiriman ditutup; null = terbuka. Bisa di masa depan (jadwal tutup). */
    public static function tutup(): ?int
    {
        $t = Settings::get('kirim_soal_tutup');

        return $t !== '' && ($ts = strtotime($t)) !== false ? $ts : null;
    }

    public static function ditutup(): bool
    {
        $t = self::tutup();

        return $t !== null && time() >= $t;
    }

    public static function folder(string $ta, string $sem, string $tingkat = ''): string
    {
        return WRITEPATH . 'kirim_soal/' . str_replace('/', '-', $ta) . '/' . $sem . '/' . ($tingkat !== '' ? $tingkat . '/' : '');
    }

    /** Potongan nama berkas yang aman (tanpa / \ : * ? " < > | dan karakter kendali). */
    public static function aman(string $s): string
    {
        $s = trim(preg_replace('~\s+~u', ' ', preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~u', ' ', $s)));

        return mb_substr($s, 0, 90);
    }

    /** Guru dari Data Guru (Cek Soal) dalam bentuk daftar aplikasi: [{nama, mapel, kelas}]. */
    public static function guru(): array
    {
        $rows = db_connect()->table('cek_guru')->select('nama, mapel, tingkat')->orderBy('nama')->get()->getResultArray();
        $out  = [];
        foreach ($rows as $r) {
            if (in_array($r['tingkat'], self::TINGKAT, true)) {
                $out[$r['nama'] . "\0" . $r['mapel'] . "\0" . $r['tingkat']] = ['nama' => $r['nama'], 'mapel' => $r['mapel'], 'kelas' => $r['tingkat']];
            }
        }

        return array_values($out);
    }

    /**
     * Sajikan berkas aplikasi Konver Soal untuk halaman guru. $base = alamat folder aplikasi (berakhiran /).
     * Pengaturan admin bawaan aplikasi disembunyikan:
     * TA/semester dan buka/tutup diatur di Kiriman Soal.
     */
    public static function sajikan(ResponseInterface $res, string $path, string $base): ResponseInterface
    {
        if ($path === '') {
            $path = 'index.html';
        }
        if (! preg_match('~^(index\.html|styles\.css|(js|lib)/[A-Za-z0-9._-]+\.js)$~', $path) || ! is_file(self::APP . $path)) {
            return $res->setStatusCode(404)->setBody('Tidak ada.');
        }
        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        $body = (string) file_get_contents(self::APP . $path);
        if ($path === 'index.html') {
            $sembunyi = '.admin-link,a[href="#admin"],#keKonvertUser';
            $body     = preg_replace('~<head>~i', '<head><base href="' . esc($base, 'attr') . '">', $body, 1);
            $body     = preg_replace('~</head>~i', '<style>' . $sembunyi . '{display:none!important}'
                . '.cbt-status-link{position:fixed;right:16px;bottom:16px;z-index:50;padding:8px 14px;border-radius:999px;background:#fff;'
                . 'border:1px solid rgba(0,0,0,.15);box-shadow:0 2px 8px rgba(0,0,0,.12);font:500 13px system-ui,sans-serif;color:#0b5c4e;text-decoration:none}'
                . '</style></head>', $body, 1);
            // Tautan ke status pengumpulan (tanpa login) di pojok kanan bawah.
            $body = preg_replace('~</body>~i', '<a class="cbt-status-link" href="status" target="_blank" rel="noopener">Lihat status pengumpulan</a></body>', $body, 1);
        }

        return $res->setHeader('Content-Type', self::JENIS[$ext])
            ->setHeader('Cache-Control', $ext === 'html' ? 'no-store' : 'public, max-age=3600')
            ->setHeader('X-Frame-Options', 'SAMEORIGIN')
            ->setBody($body);
    }
}
