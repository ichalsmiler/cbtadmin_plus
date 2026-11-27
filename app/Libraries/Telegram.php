<?php

namespace App\Libraries;

/**
 * Panggilan API bot Telegram (token ha_tg_token, chat admin ha_tg_chat; diatur di menu Server Cadangan).
 * Dipakai bot perintah (Commands/TelegramBot) dan pemberitahuan dari panel (mis. Cek Soal).
 */
class Telegram
{
    /** Satu sambungan dipakai ulang selama proses berjalan (tanpa jabat tangan TLS dan pencarian DNS tiap pesan). */
    private static $ch = null;

    private const BERKAS_IP = WRITEPATH . 'cache/telegram_ip';

    public static function token(): string
    {
        $t = Settings::get('ha_tg_token');

        return preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $t) ? $t : '';
    }

    /** Bot siap mengirim ke chat admin. */
    public static function siap(): bool
    {
        return self::token() !== '' && Settings::get('ha_tg_chat') !== '';
    }

    /**
     * Kirim pesan ke chat admin. $tombol = inline keyboard (array baris tombol) atau null.
     *
     * @return array<string, mixed>
     */
    public static function kirimAdmin(string $teks, ?array $tombol = null): array
    {
        if (! self::siap()) {
            return ['ok' => false, 'description' => 'Bot Telegram belum diatur (menu Server Cadangan).'];
        }
        $isi = ['chat_id' => Settings::get('ha_tg_chat'), 'text' => $teks, 'disable_web_page_preview' => 'true'];
        if ($tombol !== null) {
            $isi['reply_markup'] = json_encode(['inline_keyboard' => $tombol]);
        }

        return self::api(self::token(), 'sendMessage', $isi, 15);
    }

    /**
     * Kirim berkas (isi di memori) sebagai dokumen. Batas Telegram 50 MB per berkas.
     *
     * @return array<string, mixed>
     */
    public static function kirimBerkas(string $token, string $chat, string $nama, string $isi, string $keterangan = ''): array
    {
        if (strlen($isi) > 49 * 1024 * 1024) {
            return ['ok' => false, 'description' => 'Berkas ' . $nama . ' lebih dari 49 MB, unduh lewat panel.'];
        }

        return self::api($token, 'sendDocument', [
            'chat_id' => $chat, 'caption' => mb_substr($keterangan, 0, 1000),
            'document' => new \CURLStringFile($isi, $nama, 'application/octet-stream'),
        ], 180);
    }

    /** @return array<string, mixed> */
    public static function api(string $token, string $metode, array $isi, int $batas): array
    {
        if (self::$ch === null) {
            self::$ch = curl_init();
        } else {
            curl_reset(self::$ch);
        }
        $opsi = [
            CURLOPT_URL => 'https://api.telegram.org/bot' . $token . '/' . $metode, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $isi,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $batas, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ];
        $ip = self::ipTelegram();
        if ($ip !== '') {
            $opsi[CURLOPT_RESOLVE] = ['api.telegram.org:443:' . $ip];
        }
        curl_setopt_array(self::$ch, $opsi);
        $jawab = curl_exec(self::$ch);
        if ($jawab === false && $ip !== '') {
            @unlink(self::BERKAS_IP);  // alamat lama mungkin sudah berubah: cari ulang di putaran berikutnya
        }

        return json_decode((string) $jawab, true) ?: [];
    }

    /**
     * Alamat IPv4 api.telegram.org, disimpan sehari. DNS jaringan sekolah kadang lambat (5–15 dtk) atau gagal, dan
     * itu membuat balasan bot terlambat; dengan alamat tersimpan, pencarian DNS hanya sekali sehari. Bila DNS gagal,
     * alamat lama tetap dipakai.
     */
    private static function ipTelegram(): string
    {
        static $ip = null;
        if ($ip !== null) {
            return $ip;
        }
        $lama = is_file(self::BERKAS_IP) ? trim((string) @file_get_contents(self::BERKAS_IP)) : '';
        if ($lama !== '' && filemtime(self::BERKAS_IP) > time() - 86400) {
            return $ip = $lama;
        }
        $baru = gethostbyname('api.telegram.org');
        if (filter_var($baru, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            @file_put_contents(self::BERKAS_IP, $baru);

            return $ip = $baru;
        }

        return $ip = filter_var($lama, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $lama : '';
    }
}
