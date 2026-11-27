<?php

namespace App\Libraries;

/**
 * Sampel kondisi server tiap menit (dipanggil cron cbt:token) ke tabel `server_sampel`, bahan ringkasan server per
 * sesi (LaporanSesi). CPU dari selisih /proc/stat antar-menit, pekerja PHP-FPM dari /proc, koneksi dari MariaDB
 * Moodle, permintaan dan galat 5xx dari bagian baru log akses nginx sejak menit sebelumnya.
 */
class ServerSampel
{
    public const LOG_NGINX = '/var/log/nginx/access.log';

    /** Bagian log nginx yang dibaca per menit paling banyak (byte); sisanya dilewati. */
    private const MAKS_BACA = 64 * 1024 * 1024;

    public static function catat(): void
    {
        $kini = time();
        [$http, $galat] = self::nginx();
        $ram  = SysInfo::get();
        $ids  = [];
        try {
            $ids = Moodle::activeUserIds(2);
        } catch (\Throwable $e) {
            // DB Moodle tidak terbaca: sampel lain tetap dicatat.
        }
        db_connect()->table('server_sampel')->insert([
            'waktu'    => $kini,
            'cpu'      => self::cpu(),
            'ram'      => $ram['mem']['percent'],
            'disk'     => $ram['disk']['percent'],
            'beban'    => round((float) (sys_getloadavg()[0] ?? 0), 2),
            'fpm'      => self::pekerjaFpm(),
            'db'       => self::koneksiDb(),
            'http'     => $http,
            'galat'    => $galat,
            'aktif'    => count($ids),
            'pengguna' => implode(',', $ids),
        ]);
        if ((int) date('i', $kini) === 0) {
            db_connect()->table('server_sampel')->where('waktu <', $kini - 7 * 86400)->delete();
        }
    }

    /** Pemakaian CPU (%) sejak sampel sebelumnya, dari /proc/stat. */
    private static function cpu(): int
    {
        $baris = @file('/proc/stat', FILE_IGNORE_NEW_LINES);
        if (! $baris || ! preg_match('/^cpu\s+(.+)$/', $baris[0], $m)) {
            return 0;
        }
        $v     = array_map('intval', preg_split('/\s+/', trim($m[1])));
        $diam  = ($v[3] ?? 0) + ($v[4] ?? 0);
        $total = array_sum(array_slice($v, 0, 8));
        $lalu  = cache()->get('sampel_cpu');
        cache()->save('sampel_cpu', [$total, $diam], 600);
        if (! is_array($lalu) || $total <= $lalu[0]) {
            return 0;
        }

        return (int) round(100 * (1 - ($diam - $lalu[1]) / ($total - $lalu[0])));
    }

    /** Jumlah proses pekerja PHP-FPM (tanpa proses master). */
    private static function pekerjaFpm(): int
    {
        $n = 0;
        foreach (glob('/proc/[0-9]*/comm') ?: [] as $f) {
            if (str_starts_with((string) @file_get_contents($f), 'php-fpm')) {
                $n++;
            }
        }

        return max(0, $n - 1);
    }

    /** pm.max_children pool www (0 bila tidak terbaca). */
    public static function maksFpm(): int
    {
        foreach (glob('/etc/php/*/fpm/pool.d/www.conf') ?: [] as $f) {
            if (preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/m', (string) @file_get_contents($f), $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** max_connections MariaDB (0 bila tidak terbaca). */
    public static function maksDb(): int
    {
        try {
            return (int) (Moodle::db()->query("SHOW VARIABLES LIKE 'max_connections'")->getRowArray()['Value'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function koneksiDb(): int
    {
        try {
            return (int) (Moodle::db()->query("SHOW GLOBAL STATUS LIKE 'Threads_connected'")->getRowArray()['Value'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Permintaan dan jawaban 5xx di log nginx sejak menit sebelumnya. Posisi baca disimpan di cache; berkas diputar
     * (logrotate) atau lebih kecil → mulai dari awal berkas baru. Sampel pertama hanya menandai posisi.
     *
     * @return array{0:int, 1:int}
     */
    private static function nginx(): array
    {
        $f = self::LOG_NGINX;
        if (! is_readable($f)) {
            return [0, 0];
        }
        clearstatcache(true, $f);
        $ukuran = (int) filesize($f);
        $inode  = (int) fileinode($f);
        $lalu   = cache()->get('sampel_nginx');
        cache()->save('sampel_nginx', [$inode, $ukuran], 3600);
        if (! is_array($lalu)) {
            return [0, 0];
        }
        $dari = ($lalu[0] === $inode && $lalu[1] <= $ukuran) ? $lalu[1] : 0;
        $dari = max($dari, $ukuran - self::MAKS_BACA);
        if ($ukuran <= $dari) {
            return [0, 0];
        }
        $h = @fopen($f, 'rb');
        if (! $h) {
            return [0, 0];
        }
        fseek($h, $dari);
        $http = $galat = 0;
        while (($baris = fgets($h)) !== false) {
            if (preg_match('/" (\d{3}) /', $baris, $m)) {
                $http++;
                $galat += $m[1][0] === '5' ? 1 : 0;
            }
        }
        fclose($h);

        return [$http, $galat];
    }
}
