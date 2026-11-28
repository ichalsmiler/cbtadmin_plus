<?php

namespace App\Libraries;

/** Informasi server untuk panel System Information (khusus Linux). */
class SysInfo
{
    public static function get(): array
    {
        $cores = 1;
        $model = '-';
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            $cores = max(1, preg_match_all('/^processor\s*:/m', $cpuinfo));
            if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m)) {
                $model = trim($m[1]);
            }
        }

        $cpuPercent = self::cpuPersen();

        $memTotal = $memAvail = 0;
        $meminfo  = @file_get_contents('/proc/meminfo');
        if ($meminfo !== false) {
            if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m)) {
                $memTotal = (int) $m[1] * 1024;
            }
            if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $m)) {
                $memAvail = (int) $m[1] * 1024;
            }
        }
        $memUsed = max(0, $memTotal - $memAvail);

        $diskTotal = (float) @disk_total_space('/');
        $diskUsed  = max(0, $diskTotal - (float) @disk_free_space('/'));

        return [
            'cpu'  => ['percent' => $cpuPercent, 'cores' => $cores, 'model' => $model],
            'mem'  => self::usage($memUsed, $memTotal),
            'disk' => self::usage($diskUsed, $diskTotal),
        ];
    }

    /**
     * Pemakaian CPU nyata (%) dari selisih /proc/stat (bukan load average, yang di kontainer bisa
     * menempel di ~1 walau CPU diam). Selisih dihitung terhadap bacaan sebelumnya di cache; bila tidak
     * ada atau terlalu dekat/lama, dua bacaan berjarak 250 ms.
     */
    private static function cpuPersen(): int
    {
        $kini = self::bacaStat();
        if ($kini === null) {
            return 0;
        }
        $lalu = cache()->get('sysinfo_cpu');
        cache()->save('sysinfo_cpu', $kini, 600);
        $umur = is_array($lalu) ? microtime(true) - $lalu[2] : 0;
        if (! is_array($lalu) || $umur < 1 || $umur > 300 || $kini[0] <= $lalu[0]) {
            $lalu = $kini;
            usleep(250000);
            $kini = self::bacaStat() ?? $lalu;
            cache()->save('sysinfo_cpu', $kini, 600);
        }
        $total = $kini[0] - $lalu[0];
        if ($total <= 0) {
            return 0;
        }

        return (int) max(0, min(100, round(100 * (1 - ($kini[1] - $lalu[1]) / $total))));
    }

    /** [total jiffies, jiffies diam (idle+iowait), waktu baca] atau null. */
    private static function bacaStat(): ?array
    {
        $f = @fopen('/proc/stat', 'r');
        $b = $f ? fgets($f) : false;
        if ($f) {
            fclose($f);
        }
        if (! $b || ! preg_match('/^cpu\s+(.+)$/', trim($b), $m)) {
            return null;
        }
        $v = array_map('intval', preg_split('/\s+/', trim($m[1])));

        return [array_sum(array_slice($v, 0, 8)), ($v[3] ?? 0) + ($v[4] ?? 0), microtime(true)];
    }

    private static function usage(float $used, float $total): array
    {
        return [
            'percent' => $total > 0 ? (int) round($used / $total * 100) : 0,
            'text'    => self::bytes($used) . ' / ' . self::bytes($total),
        ];
    }

    private static function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
