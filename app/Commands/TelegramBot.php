<?php

namespace App\Commands;

use App\Libraries\ActiveTest;
use App\Libraries\ActivityLog;
use App\Libraries\CekSoal;
use App\Libraries\Moodle;
use App\Libraries\Settings;
use App\Libraries\SysInfo;
use App\Libraries\Telegram;
use App\Libraries\Tingkat;
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
            self::pasangMenu($token);
            $tunggu = max(1, min(25, self::DURASI - (time() - $mulai)));
            $upd    = self::api($token, 'getUpdates', [
                'offset'          => (int) Settings::get('ha_tg_offset', '0'),
                'timeout'         => $tunggu,
                'allowed_updates' => '["message","callback_query"]',
            ], $tunggu + 10);
            if (! ($upd['ok'] ?? false)) {
                sleep(5);

                continue;
            }
            foreach ($upd['result'] ?? [] as $u) {
                Settings::set('ha_tg_offset', (string) ((int) $u['update_id'] + 1));
                // Tombol pilihan (Cek Soal): hanya dari pesan di chat admin.
                $cq = $u['callback_query'] ?? null;
                if (is_array($cq)) {
                    if ((string) ($cq['message']['chat']['id'] ?? '') === $chat) {
                        try {
                            $this->tombol($token, $chat, $cq);
                        } catch (\Throwable $e) {
                            log_message('error', 'cbt:telegram tombol: ' . $e->getMessage());
                            self::api($token, 'answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => 'Gagal: ' . mb_substr($e->getMessage(), 0, 150), 'show_alert' => 'true'], 15);
                        }
                    }

                    continue;
                }
                $m = $u['message'] ?? null;
                if (! is_array($m) || (string) ($m['chat']['id'] ?? '') !== $chat || time() - (int) ($m['date'] ?? 0) > self::BASI) {
                    continue;
                }
                $teks = trim((string) ($m['text'] ?? ''));
                if ($teks === '' || $teks[0] !== '/') {
                    continue;
                }
                $perintah = strtolower(preg_replace('/@\S+$/', '', strtok($teks, ' ')));
                if ($perintah === '/ceksoal' || $perintah === '/cekstatus') {
                    try {
                        $perintah === '/ceksoal' ? $this->cekMulai($token, $chat) : $this->cekStatus($token, $chat);
                    } catch (\Throwable $e) {
                        log_message('error', 'cbt:telegram: ' . $e->getMessage());
                        self::api($token, 'sendMessage', ['chat_id' => $chat, 'text' => '⚠️ Gagal: ' . $e->getMessage()], 15);
                    }

                    continue;
                }
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

    /** Daftar perintah: sumber teks /bantuan dan menu perintah Telegram (tombol Menu / ketik "/"). */
    private const PERINTAH = [
        'status'    => 'server, sesi, siswa mengerjakan/selesai, beban server',
        'token'     => 'token yang berlaku dan sisa waktunya',
        'absensi'   => 'ruang yang belum lengkap absensinya',
        'ceksoal'   => 'buat tautan cek soal untuk guru',
        'cekstatus' => 'tautan cek soal yang belum selesai',
        'bantuan'   => 'daftar perintah ini',
    ];

    private static function bantuan(): string
    {
        $baris = [];
        foreach (self::PERINTAH as $p => $ket) {
            $baris[] = '/' . $p . ' – ' . $ket;
        }

        return "Perintah CBT:\n" . implode("\n", $baris);
    }

    /**
     * Pasang menu perintah bot (setMyCommands) bila daftar PERINTAH berubah atau token bot diganti. Tanda daftar yang
     * terpasang disimpan di setelan tg_menu, jadi panggilan ke Telegram hanya terjadi sekali per perubahan.
     */
    private static function pasangMenu(string $token): void
    {
        $daftar = array_map(static fn ($p, $ket) => ['command' => $p, 'description' => $ket], array_keys(self::PERINTAH), self::PERINTAH);
        $tanda  = md5($token . json_encode($daftar));
        if (Settings::get('tg_menu') === $tanda) {
            return;
        }
        if ((self::api($token, 'setMyCommands', ['commands' => json_encode($daftar)], 15)['ok'] ?? false)) {
            Settings::set('tg_menu', $tanda);
        }
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

    // ---------- Cek Soal (/ceksoal, /cekstatus; tombol berawalan "cs:") ----------
    // Pilihan berjalan disimpan di setelan cek_tg_state (satu menu aktif): {msg, cid, guru: [id], semua: [id]}.

    private function cekMulai(string $token, string $chat): void
    {
        $ada = [];
        foreach (CekSoal::mapel('', true) as $m) {
            if ($m['tingkat'] !== '') {
                $ada[$m['tingkat']] = true;
            }
        }
        $baris = [array_values(array_map(static fn ($t) => ['text' => 'Kelas ' . $t, 'callback_data' => 'cs:t:' . $t], array_filter(Tingkat::LIST, static fn ($t) => isset($ada[$t]))))];
        if ($baris[0] === []) {
            self::api($token, 'sendMessage', ['chat_id' => $chat, 'text' => 'Tidak ada mapel yang bisa dicek (belum berisi soal, sudah dikerjakan, atau sedang diujikan).'], 15);

            return;
        }
        $r = self::api($token, 'sendMessage', ['chat_id' => $chat, 'text' => '📋 Cek soal guru – pilih tingkat:', 'reply_markup' => json_encode(['inline_keyboard' => $baris])], 15);
        Settings::set('cek_tg_state', json_encode(['msg' => (int) ($r['result']['message_id'] ?? 0)]));
    }

    private function tombol(string $token, string $chat, array $cq): void
    {
        $data  = (string) ($cq['data'] ?? '');
        $msgId = (int) ($cq['message']['message_id'] ?? 0);
        $jawab = static fn (string $teks = '', bool $alert = false) => self::api($token, 'answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $teks, 'show_alert' => $alert ? 'true' : 'false'], 15);
        $ubah  = static fn (string $teks, array $tombol = []) => self::api($token, 'editMessageText', ['chat_id' => $chat, 'message_id' => $msgId, 'text' => $teks, 'disable_web_page_preview' => 'true']
            + ($tombol !== [] ? ['reply_markup' => json_encode(['inline_keyboard' => $tombol])] : []), 15);
        $parts = explode(':', $data, 3);
        if (($parts[0] ?? '') !== 'cs') {
            $jawab();

            return;
        }
        $aksi = $parts[1] ?? '';
        $arg  = $parts[2] ?? '';

        // Cabut dari /cekstatus: tidak memakai menu berjalan.
        if ($aksi === 'c') {
            $db = db_connect();
            $t  = $db->table('cek_tugas')->where('id', (int) $arg)->get()->getRowArray();
            if ($t !== null && in_array(CekSoal::status($t), ['belum', 'dibuka'], true)) {
                $db->table('cek_tugas')->where('id', (int) $arg)->delete();
                ActivityLog::add('cek_soal_cabut', $t['guru_nama'] . ' · ' . $t['mapel'], 'telegram');
                $jawab('Tautan ' . $t['guru_nama'] . ' dicabut.');
            } else {
                $jawab('Tautan sudah tidak aktif.');
            }
            [$teks, $tombol] = $this->cekStatusIsi();
            $ubah($teks, $tombol);

            return;
        }

        $st = json_decode(Settings::get('cek_tg_state', '{}'), true) ?: [];
        if ((int) ($st['msg'] ?? 0) !== $msgId) {
            $jawab('Menu ini sudah kedaluwarsa. Ketik /ceksoal lagi.', true);

            return;
        }
        $simpan = static fn (array $s) => Settings::set('cek_tg_state', json_encode($s));

        if ($aksi === 't' && in_array($arg, Tingkat::LIST, true)) {
            $list  = CekSoal::mapel($arg, true);
            $nama  = array_count_values(array_column($list, 'fullname'));
            $btn   = array_map(static fn ($m) => ['text' => $m['nama'] . ($nama[$m['fullname']] > 1 ? ' · ' . $m['kategori'] : ''), 'callback_data' => 'cs:m:' . $m['id']], $list);
            $baris = array_chunk($btn, 2);
            $baris[] = [['text' => '« Tingkat', 'callback_data' => 'cs:awal']];
            $jawab();
            $ubah('📋 Kelas ' . $arg . ' – pilih mapel:', $baris);

            return;
        }
        if ($aksi === 'awal') {
            $jawab();
            self::api($token, 'deleteMessage', ['chat_id' => $chat, 'message_id' => $msgId], 15);
            $this->cekMulai($token, $chat);

            return;
        }
        if ($aksi === 'm') {
            $course = CekSoal::course((int) $arg);
            if ($course === null) {
                $jawab('Mapel tidak ditemukan.', true);

                return;
            }
            $tutup = CekSoal::alasanMapelTutup((int) $course['id']);
            if ($tutup !== '') {
                $jawab($tutup, true);

                return;
            }
            $guru = CekSoal::guruUntuk($course);
            if ($guru === []) {
                $jawab();
                $ubah('⚠️ Belum ada data guru untuk ' . $course['fullname'] . ".\nUnggah di panel: menu Cek Soal → Data Guru, lalu /ceksoal lagi.");
                Settings::set('cek_tg_state', '{}');

                return;
            }
            $ids = array_map(static fn ($g) => (int) $g['id'], $guru);
            $st  = ['msg' => $msgId, 'cid' => (int) $course['id'], 'guru' => $ids, 'semua' => $ids];
            $simpan($st);
            $jawab();
            $ubah(...$this->cekGuruIsi($course, $guru, $ids));

            return;
        }
        $course = isset($st['cid']) ? CekSoal::course((int) $st['cid']) : null;
        if ($course === null) {
            $jawab('Menu ini sudah kedaluwarsa. Ketik /ceksoal lagi.', true);

            return;
        }
        $semua = array_values(array_filter(CekSoal::guruUntuk($course), static fn ($g) => in_array((int) $g['id'], $st['semua'] ?? [], true)));
        if ($aksi === 'g') {
            $id  = (int) $arg;
            $sel = array_map('intval', $st['guru'] ?? []);
            $st['guru'] = in_array($id, $sel, true) ? array_values(array_diff($sel, [$id])) : array_merge($sel, [$id]);
            $simpan($st);
            $jawab();
            $ubah(...$this->cekGuruIsi($course, $semua, $st['guru']));

            return;
        }
        $dipilih = array_values(array_filter($semua, static fn ($g) => in_array((int) $g['id'], $st['guru'] ?? [], true)));
        // Buat: batas waktu lewat bot selalu BATAS_BOT hari (tanpa langkah pilih batas).
        if ($aksi === 'l') {
            if ($dipilih === []) {
                $jawab('Pilih minimal satu guru.', true);

                return;
            }
            $dibuat = CekSoal::buat((int) $course['id'], array_map(static fn ($g) => ['id' => (int) $g['id'], 'nama' => $g['nama']], $dipilih), self::BATAS_BOT, 'telegram');
            Settings::set('cek_tg_state', '{}');
            $jawab('Tautan dibuat.');
            $ubah('✅ ' . count($dibuat) . ' tautan cek ' . $course['fullname'] . ' dibuat (batas ' . CekSoal::tanggal((string) $dibuat[0]['batas']) . ").\nSalin pesan di bawah lalu teruskan ke WA guru:");
            foreach ($dibuat as $t) {
                self::api($token, 'sendMessage', ['chat_id' => $chat, 'text' => CekSoal::pesan($t), 'disable_web_page_preview' => 'true'], 15);
            }

            return;
        }
        $jawab();
    }

    /** @return array{0: string, 1: array} */
    private function cekGuruIsi(array $course, array $guru, array $dipilih): array
    {
        $btn = array_map(static fn ($g) => ['text' => (in_array((int) $g['id'], $dipilih, true) ? '☑ ' : '☐ ') . $g['nama'], 'callback_data' => 'cs:g:' . $g['id']], $guru);
        $baris   = array_chunk($btn, 2);
        $baris[] = [['text' => '« Mapel', 'callback_data' => 'cs:t:' . $course['tingkat']], ['text' => 'Buat tautan ✓', 'callback_data' => 'cs:l']];

        return ['📋 ' . $course['fullname'] . ' (' . $course['soal'] . " soal)\n"
            . (CekSoal::jumlah() === 0 ? 'Semua soal' : CekSoal::jumlah() . ' soal acak') . ' per guru, batas ' . self::BATAS_BOT . " hari.\n"
            . 'Guru (ketuk untuk centang/hapus centang):', $baris];
    }

    /** Batas waktu tautan yang dibuat lewat bot (hari). */
    private const BATAS_BOT = 1;

    private function cekStatus(string $token, string $chat): void
    {
        [$teks, $tombol] = $this->cekStatusIsi();
        self::api($token, 'sendMessage', ['chat_id' => $chat, 'text' => $teks, 'disable_web_page_preview' => 'true']
            + ($tombol !== [] ? ['reply_markup' => json_encode(['inline_keyboard' => $tombol])] : []), 15);
    }

    /** @return array{0: string, 1: array} */
    private function cekStatusIsi(): array
    {
        $rows = db_connect()->table('cek_tugas')->where('created_at >=', date('Y-m-d H:i:s', strtotime('-14 days')))->orderBy('id', 'DESC')->get()->getResultArray();
        $aktif = $selesai = [];
        foreach ($rows as $t) {
            $s = CekSoal::status($t);
            if ($s === 'belum' || $s === 'dibuka') {
                $aktif[] = $t;
            } elseif ($s === 'selesai') {
                $selesai[] = $t;
            }
        }
        if ($aktif === [] && $selesai === []) {
            return ['📋 Belum ada tautan cek soal dalam 14 hari terakhir.', []];
        }
        $teks = '📋 Cek soal (14 hari)';
        if ($aktif !== []) {
            $teks .= "\n\n⏳ Belum selesai (" . count($aktif) . '):';
            foreach ($aktif as $t) {
                $teks .= "\n• " . $t['guru_nama'] . ' – ' . $t['mapel'] . ' (' . ($t['dibuka_at'] !== null ? 'sudah dibuka' : 'belum dibuka') . ', batas ' . date('d/m H:i', strtotime((string) $t['batas'])) . ')';
            }
        }
        if ($selesai !== []) {
            $teks .= "\n\n✅ Sudah menyatakan benar (" . count($selesai) . '):';
            foreach (array_slice($selesai, 0, 20) as $t) {
                $cat   = CekSoal::catatan($t);
                $teks .= "\n• " . $t['guru_nama'] . ' – ' . $t['mapel'] . ($cat !== '' ? ' (catatan: ' . mb_strimwidth($cat, 0, 80, '…') . ')' : '');
            }
        }
        $tombol = array_map(static fn ($t) => [['text' => 'Cabut: ' . $t['guru_nama'] . ' · ' . $t['mapel'], 'callback_data' => 'cs:c:' . $t['id']]], array_slice($aktif, 0, 10));

        return [mb_substr($teks, 0, 4000), $tombol];
    }

    private static function api(string $token, string $metode, array $isi, int $batas): array
    {
        return Telegram::api($token, $metode, $isi, $batas);
    }
}
