<?php

namespace App\Libraries;

/**
 * Penghitung per attempt di plugin local_cbtujian (preferensi Moodle `<awalan><attempt>` + `_waktu` terakhir,
 * `_total` kelebihan yang sudah dibuka pengawas). Dua jenis:
 *  - 'keluar' (Anti Newtab): keluar dari aplikasi/halaman ujian, `cbtujian_keluar_`, batas anti_newtab_maks;
 *  - 'login' (Batas login per mapel): login selama ujian berjalan, `cbtujian_login_` (tidak ada = 1), batas login_maks.
 * Lebih dari batas = terkunci sampai dibuka lewat Lock Peserta → Buka kunci.
 */
class KeluarAplikasi
{
    public const AWALAN = ['keluar' => 'cbtujian_keluar_', 'login' => 'cbtujian_login_'];

    public static function maks(string $jenis = 'keluar'): int
    {
        return $jenis === 'login'
            ? max(0, (int) Settings::get('login_maks', '0'))
            : max(0, (int) Settings::get('anti_newtab_maks', '3'));
    }

    /** Fitur jenis ini aktif? (login: batas > 0) */
    public static function aktif(string $jenis = 'keluar'): bool
    {
        return $jenis === 'login' ? self::maks('login') > 0 : Settings::get('anti_newtab', '0') === '1';
    }

    /**
     * Attempt yang tercatat. $attemptIds = null: hanya attempt yang masih berjalan.
     * Kunci `keluar` = jumlah sekarang (untuk jenis login: jumlah login pada mapel itu).
     *
     * @return list<array{userid:int, attempt:int, username:string, nama:string, keluar:int, total:int, waktu:int, kunci:bool, state:string}>
     */
    public static function daftar(?array $attemptIds = null, string $jenis = 'keluar'): array
    {
        $awalan = self::AWALAN[$jenis] ?? self::AWALAN['keluar'];
        $p  = Moodle::t('user_preferences');
        $a  = Moodle::t('quiz_attempts');
        $u  = Moodle::t('user');
        $db = Moodle::db();
        $where = $attemptIds === null ? "a.state = 'inprogress'" : ($attemptIds === [] ? '1 = 0' : 'a.id IN (' . implode(',', array_map('intval', $attemptIds)) . ')');
        $rows  = $db->query(
            "SELECT p.userid, p.name, p.value, a.id AS attempt, a.state, u.username, u.firstname, u.lastname
               FROM {$p} p
               JOIN {$a} a ON a.userid = p.userid AND p.name IN (CONCAT('" . $awalan . "', a.id), CONCAT('" . $awalan . "', a.id, '_waktu'), CONCAT('" . $awalan . "', a.id, '_total'))
               JOIN {$u} u ON u.id = p.userid
              WHERE p.name LIKE '" . $awalan . "%' AND {$where}"
        )->getResultArray();
        $maks  = self::maks($jenis);
        $hasil = [];
        foreach ($rows as $r) {
            $k = (int) $r['attempt'];
            $hasil[$k] ??= [
                'userid' => (int) $r['userid'], 'attempt' => $k, 'username' => $r['username'],
                'nama' => trim($r['firstname'] . ' ' . $r['lastname']), 'keluar' => $jenis === 'login' ? 1 : 0, 'total' => 0, 'waktu' => 0, 'state' => $r['state'],
            ];
            $akhir = substr($r['name'], strlen($awalan . $k));
            if ($akhir === '') {
                $hasil[$k]['keluar'] = (int) $r['value'];
            } elseif ($akhir === '_waktu') {
                $hasil[$k]['waktu'] = (int) $r['value'];
            } elseif ($akhir === '_total') {
                $hasil[$k]['total'] = (int) $r['value'];
            }
        }
        foreach ($hasil as &$h) {
            $h['total'] += $h['keluar'];
            $h['kunci']  = $h['state'] === 'inprogress' && $h['keluar'] > $maks;
        }
        unset($h);
        $hasil = array_values(array_filter($hasil, static fn ($h) => $h['total'] > 0));
        usort($hasil, static fn ($x, $y) => [$y['kunci'], $y['total']] <=> [$x['kunci'], $x['total']]);

        return $hasil;
    }
}
