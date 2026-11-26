<?php

namespace App\Commands;

use App\Libraries\ActiveTest;
use App\Libraries\Moodle;
use App\Libraries\Settings;
use App\Libraries\SysInfo;
use CodeIgniter\CLI\BaseCommand;

/**
 * Bot Telegram: menjawab perintah dari chat admin (ha_tg_token + ha_tg_chat, menu Server Cadangan).
 * Dijalankan cron tiap menit (/etc/cron.d/cbtadmin, ikut mati di server yang tidak aktif); tiap jalan
 * membaca pesan lewat getUpdates (long polling) selama ±55 detik, jadi tidak perlu webhook atau port terbuka.
 * Pesan dari chat lain diabaikan. Posisi pesan terakhir disimpan di setelan ha_tg_offset.
 */
class TelegramBot extends BaseCommand
{
    protected $group       = 'CBT';
    protected $name        = 'cbt:telegram';
    protected $description = 'Jawab perintah Telegram admin (/status, /token, /absensi, /bantuan).';

    private const DURASI = 55;

    /** Pesan lebih lama dari ini (server sempat mati) tidak dijawab lagi. */
    private const BASI = 600;

    public function run(array $params)
    {
        $peran = trim((string) @file_get_contents('/etc/cbt-ha-peran'));
        if (in_array($peran, ['cadangan', 'nonaktif', 'kembali'], true)) {
            return EXIT_SUCCESS;
        }

        $mulai = time();
        while (time() - $mulai < self::DURASI) {
            Settings::segarkan();
            $token = Settings::get('ha_tg_token');
            $chat  = Settings::get('ha_tg_chat');
            // Tanpa chat terdaftar bot diam (getUpdates dipakai tombol "Kirim pesan uji" untuk mengambil chat).
            if (! preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token) || $chat === '') {
                return EXIT_SUCCESS;
            }
            $tunggu = max(1, min(25, self::DURASI - (time() - $mulai)));
            $upd    = self::api($token, 'getUpdates', [
                'offset'          => (int) Settings::get('ha_tg_offset', '0'),
                'timeout'         => $tunggu,
                'allowed_updates' => '["message"]',
            ], $tunggu + 10);
            if (! ($upd['ok'] ?? false)) {
                sleep(5);

                continue;
            }
            foreach ($upd['result'] ?? [] as $u) {
                Settings::set('ha_tg_offset', (string) ((int) $u['update_id'] + 1));
                $m = $u['message'] ?? null;
                if (! is_array($m) || (string) ($m['chat']['id'] ?? '') !== $chat || time() - (int) ($m['date'] ?? 0) > self::BASI) {
                    continue;
                }
                $teks = trim((string) ($m['text'] ?? ''));
                if ($teks === '' || $teks[0] !== '/') {
                    continue;
                }
                $perintah = strtolower(preg_replace('/@\S+$/', '', strtok($teks, ' ')));
                try {
                    $balas = $this->jawab($perintah);
                } catch (\Throwable $e) {
                    log_message('error', 'cbt:telegram: ' . $e->getMessage());
                    $balas = '⚠️ Gagal mengambil data: ' . $e->getMessage();
                }
                self::api($token, 'sendMessage', ['chat_id' => $chat, 'text' => $balas], 15);
            }
        }

        return EXIT_SUCCESS;
    }

    private function jawab(string $perintah): string
    {
        return match ($perintah) {
            '/status'          => $this->status(),
            '/token'           => $this->token(),
            '/absensi'         => $this->absensi(),
            '/start', '/bantuan', '/help' => self::bantuan(),
            default            => "Perintah tidak dikenal.\n\n" . self::bantuan(),
        };
    }

    private static function bantuan(): string
    {
        return "Perintah CBT:\n"
            . "/status – server, sesi, siswa mengerjakan/selesai, beban server\n"
            . "/token – token yang berlaku dan sisa waktunya\n"
            . "/absensi – ruang yang belum lengkap absensinya\n"
            . '/bantuan – daftar perintah ini';
    }

    private static function sesiTeks(): string
    {
        $sesi = ActiveTest::sesiAktif();
        if ($sesi === null) {
            return 'Tidak ada sesi aktif';
        }

        return $sesi['id'] === ActiveTest::SEMUA ? $sesi['label'] : 'Sesi ' . $sesi['nama'];
    }

    private function status(): string
    {
        $baris = [];
        $ha    = self::ha();
        $server = gethostname() . ($ha !== null ? ' (' . ($ha['peran'] ?? '?') . ')' : '');
        if ($ha !== null && ($ha['terpasang'] ?? false)) {
            $server .= ($ha['terhubung'] ?? false)
                ? ' · pasangan terhubung (' . (int) ($ha['kontak_detik'] ?? 0) . ' dtk lalu)'
                : ' · ⚠️ pasangan TIDAK terhubung';
        }
        $baris[] = '🖥 ' . $server;
        $baris[] = '📋 ' . self::sesiTeks();
        $mapel   = ActiveTest::mapelAktif();
        if ($mapel !== '') {
            $baris[] = '📚 ' . $mapel;
        }

        $cat     = ActiveTest::categoryId() ?: null;
        $menit   = max(1, (int) Settings::get('active_minutes', '5'));
        $baris[] = '👥 Online ' . $menit . ' mnt terakhir: ' . Moodle::activeUsers($menit) . ' dari ' . Moodle::totalUsers();
        $baris[] = '✍️ Mengerjakan: ' . Moodle::unsubmittedAttempts($cat) . ' · Selesai: ' . Moodle::finishedAttempts($cat);

        $sys     = SysInfo::get();
        $baris[] = '⚙️ CPU ' . $sys['cpu']['percent'] . '% · RAM ' . $sys['mem']['percent'] . '% · Disk ' . $sys['disk']['percent'] . '%';

        return implode("\n", $baris);
    }

    private function token(): string
    {
        if (! ActiveTest::tokenEnabled()) {
            return '🔑 Token tidak dipakai (Setting Ujian).';
        }
        $token = ActiveTest::token();
        if ($token === '') {
            return '🔑 Belum ada token: ' . self::sesiTeks() . '.';
        }
        $sisa = ActiveTest::nextReleaseAt() - time();

        return '🔑 Token: ' . $token . "\n" . self::sesiTeks()
            . ($sisa > 0 ? "\nBerganti dalam " . (int) ceil($sisa / 60) . ' menit' : "\nSegera berganti");
    }

    private function absensi(): string
    {
        $db     = db_connect();
        $sesiId = ActiveTest::sesiFilter();
        $sesi   = $sesiId !== null ? ' AND COALESCE(p.sesi_id, r.sesi_id) = ' . (int) $sesiId : '';
        $rows   = $db->query(
            "SELECT r.nama, COUNT(p.id) AS total, COUNT(a.id) AS ditandai
               FROM ruang r
               JOIN peserta p ON p.ruang_id = r.id{$sesi}
               LEFT JOIN absensi a ON a.peserta_id = p.id AND a.tanggal = ?
              GROUP BY r.id, r.nama
              ORDER BY LENGTH(r.nama), r.nama",
            [date('Y-m-d')]
        )->getResultArray();

        $kurang = [];
        foreach ($rows as $r) {
            $belum = (int) $r['total'] - (int) $r['ditandai'];
            if ($belum > 0) {
                $kurang[] = '• ' . $r['nama'] . ': ' . ((int) $r['ditandai'] === 0 ? 'belum absen' : $belum . ' siswa belum') . ' (' . $r['total'] . ' siswa)';
            }
        }
        $kepala = '📝 Absensi ' . date('d-m-Y') . ' · ' . self::sesiTeks();
        if ($rows === []) {
            return $kepala . "\nTidak ada siswa.";
        }

        return $kepala . "\n" . ($kurang === []
            ? '✅ Semua ' . count($rows) . ' ruang sudah lengkap.'
            : count($kurang) . ' dari ' . count($rows) . " ruang belum lengkap:\n" . implode("\n", $kurang));
    }

    /** Keadaan HA dari jembatan root panel (sudoers dipasang install.sh); null bila tidak ada. */
    private static function ha(): ?array
    {
        if (! is_file('/usr/local/sbin/cbt-ha-panel')) {
            return null;
        }
        $out = shell_exec('timeout 20 sudo -n /usr/local/sbin/cbt-ha-panel status 2>/dev/null');
        $ha  = json_decode((string) $out, true);

        return is_array($ha) ? $ha : null;
    }

    /** @return array<string, mixed> */
    private static function api(string $token, string $metode, array $isi, int $batas): array
    {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $metode);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $isi, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $batas, CURLOPT_CONNECTTIMEOUT => 10]);
        $jawab = curl_exec($ch);
        curl_close($ch);

        return json_decode((string) $jawab, true) ?: [];
    }
}
