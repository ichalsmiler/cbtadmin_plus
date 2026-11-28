<?php

namespace App\Libraries;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Cek soal dengan AI. Provider (setelan `ai_provider`):
 * - openrouter / deepseek / groq / openai_lain: API kompatibel OpenAI (POST <alamat>/chat/completions, Guzzle),
 *   alamat `ai_url`, model bebas `ai_model_bebas`, gambar dikirim hanya bila `ai_gambar` = 1 (tidak semua model bisa);
 * - anthropic: SDK resmi anthropic-ai/sdk (model `ai_model`).
 * API key disimpan terenkripsi (sodium secretbox) di setelan `ai_key`; kunci enkripsinya writable/ai.key (0600).
 * Key tidak pernah dikirim ke browser: yang tampil hanya 4 karakter terakhir.
 */
class Ai
{
    /** Provider => [nama, alamat API bawaan, contoh model]. */
    public const PROVIDER = [
        'openrouter'  => ['OpenRouter', 'https://openrouter.ai/api/v1', 'deepseek/deepseek-chat'],
        'deepseek'    => ['DeepSeek', 'https://api.deepseek.com', 'deepseek-chat'],
        'groq'        => ['Groq', 'https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile'],
        'openai_lain' => ['Lainnya (kompatibel OpenAI)', '', ''],
        'anthropic'   => ['Anthropic (Claude)', 'https://api.anthropic.com', 'claude-opus-5-5'],
    ];

    /** Model Anthropic yang bisa dipilih (provider anthropic). */
    public const MODEL = [
        'claude-opus-5-5'   => 'Claude Opus 5.5 (paling teliti)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (lebih cepat & murah)',
        'claude-haiku-5-5'  => 'Claude Haiku 5.5 (paling murah)',
    ];

    private const BERKAS_KUNCI = WRITEPATH . 'ai.key';
    private const MAKS_GAMBAR  = 40;
    private const TIMEOUT      = 240.0;

    private const SISTEM = <<<'TXT'
        Anda penelaah soal ujian pilihan ganda tingkat SMA di Indonesia. Tugas Anda menemukan masalah yang membuat
        soal tidak layak diujikan, sebelum soal dimasukkan ke ujian berbasis komputer.

        Periksa setiap soal:
        - kunci: kunci jawaban salah, atau jawaban yang benar ada di opsi lain;
        - opsi: lebih dari satu opsi benar, tidak ada opsi yang benar, opsi kembar atau hampir sama, opsi kosong;
        - soal: kalimat soal ambigu, data kurang sehingga tidak bisa dijawab, angka/satuan keliru;
        - ejaan: salah ketik yang mengubah makna atau membingungkan (abaikan salah ketik kecil yang tidak mengganggu);
        - gambar: soal merujuk gambar/tabel/grafik yang tidak ada, atau isi gambar bertentangan dengan soal.

        Laporkan hanya masalah nyata yang Anda yakini, bukan selera gaya bahasa. Bila ragu apakah kunci salah, tetap
        laporkan dengan tingkat "saran" dan jelaskan alasannya. "penting" = soal sebaiknya diperbaiki sebelum dipakai.
        Isi saran_kunci dengan huruf opsi yang menurut Anda benar bila jenisnya "kunci", selain itu string kosong.
        Nomor = nomor soal sesuai urutan. Tulis pesan dalam bahasa Indonesia, singkat dan jelas (maksimal 2 kalimat).
        Ringkasan = 1-2 kalimat kesimpulan seluruh naskah. Bila tidak ada masalah, temuan berupa daftar kosong.
        TXT;

    private const FORMAT_JSON = <<<'TXT'

        Jawab HANYA dengan satu objek JSON (tanpa teks lain, tanpa ```), bentuknya:
        {"ringkasan": "...", "temuan": [{"nomor": 1, "jenis": "kunci|opsi|soal|ejaan|gambar|lain", "tingkat": "penting|saran", "pesan": "...", "saran_kunci": ""}]}
        TXT;

    public static function provider(): string
    {
        $p = Settings::get('ai_provider');

        return isset(self::PROVIDER[$p]) ? $p : 'openrouter';
    }

    public static function alamat(): string
    {
        $u = rtrim(Settings::get('ai_url'), '/');

        return $u !== '' ? $u : self::PROVIDER[self::provider()][1];
    }

    /** Model yang dipakai: pilihan Claude untuk anthropic, isian bebas untuk provider lain. */
    public static function model(): string
    {
        if (self::provider() === 'anthropic') {
            $m = Settings::get('ai_model');

            return isset(self::MODEL[$m]) ? $m : 'claude-opus-5-5';
        }

        return Settings::get('ai_model_bebas');
    }

    public static function namaModel(): string
    {
        return self::PROVIDER[self::provider()][0] . ' · ' . (self::MODEL[self::model()] ?? self::model());
    }

    public static function kirimGambar(): bool
    {
        return self::provider() === 'anthropic' || Settings::get('ai_gambar') === '1';
    }

    /** Pustaka yang dibutuhkan provider terpasang (Guzzle untuk kompatibel OpenAI, SDK untuk Anthropic). */
    public static function tersedia(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && (self::provider() === 'anthropic' ? class_exists(Client::class) : class_exists(\GuzzleHttp\Client::class));
    }

    /**
     * Fitur AI ditampilkan? Disembunyikan bawaan (28 Nov 2026: pengecekan isi soal diserahkan ke guru saat kirim);
     * tampilkan lagi dengan setelan `ai_tampil` = 1.
     */
    public static function tampil(): bool
    {
        return Settings::get('ai_tampil') === '1';
    }

    /** Siap dipakai: fitur ditampilkan, pustaka ada, key ada, model + alamat terisi. */
    public static function siap(): bool
    {
        return self::tampil() && self::tersedia() && self::ada() && self::model() !== '' && self::alamat() !== '';
    }

    public static function ada(): bool
    {
        return self::key() !== null;
    }

    /** 4 karakter terakhir key untuk ditampilkan ("" bila belum ada). */
    public static function ekor(): string
    {
        $k = self::key();

        return $k !== null ? substr($k, -4) : '';
    }

    public static function simpanKey(?string $key): void
    {
        if ($key === null || $key === '') {
            Settings::set('ai_key', '');

            return;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        Settings::set('ai_key', base64_encode($nonce . sodium_crypto_secretbox($key, $nonce, self::kunci())));
    }

    private static function key(): ?string
    {
        $raw = base64_decode(Settings::get('ai_key'), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || ! function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }
        $isi = @sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::kunci());

        return $isi === false || $isi === '' ? null : $isi;
    }

    private static function kunci(): string
    {
        if (! is_file(self::BERKAS_KUNCI)) {
            file_put_contents(self::BERKAS_KUNCI, sodium_crypto_secretbox_keygen(), LOCK_EX);
            @chmod(self::BERKAS_KUNCI, 0600);
        }

        return (string) file_get_contents(self::BERKAS_KUNCI);
    }

    /** Uji key + alamat + model dengan permintaan sangat kecil; mengembalikan balasan model. */
    public static function tes(): string
    {
        if (self::provider() === 'anthropic') {
            return self::anthropic([['type' => 'text', 'text' => 'Balas satu kata: siap']], null, 1024, 'low');
        }

        return self::chat([['role' => 'user', 'content' => 'Balas satu kata: siap']], 50, false);
    }

    /**
     * Telaah soal pilihan ganda (hasil SoalRtf, gambar base64) untuk mapel tertentu.
     *
     * @return array{ringkasan: string, temuan: list<array{nomor: int, jenis: string, tingkat: string, pesan: string, saran_kunci: string}>}
     */
    public static function cekSoal(array $soal, string $mapel): array
    {
        // Bagian isi: teks per soal, gambar (bila provider/model mendukung) tepat setelah soalnya.
        $bagian  = [['teks' => 'Mata pelajaran: ' . $mapel . "\nJumlah soal: " . count($soal) . "\n\n"]];
        $gambar  = self::kirimGambar();
        $nGambar = 0;
        $huruf   = range('A', 'Z');
        foreach ($soal as $i => $s) {
            $teks = 'Soal ' . ($i + 1) . ":\n" . self::polos($s['teks']) . "\n";
            foreach ($s['opsi'] as $j => $o) {
                $teks .= ($huruf[$j] ?? '?') . '. ' . self::polos($o) . "\n";
            }
            $bagian[] = ['teks' => $teks . 'Kunci: ' . ($huruf[$s['kunci']] ?? '?') . "\n\n"];
            foreach ($gambar ? $s['gambar'] : [] as $g) {
                $mime = str_ends_with(strtolower($g['nama']), '.png') ? 'image/png' : 'image/jpeg';
                if ($nGambar++ < self::MAKS_GAMBAR) {
                    $bagian[] = ['teks' => '[Gambar ' . $g['nama'] . ' milik soal ' . ($i + 1) . ']'];
                    $bagian[] = ['mime' => $mime, 'data' => $g['data']];
                }
            }
        }
        $penutup = $gambar ? 'Telaah semua soal di atas sesuai petunjuk.'
            : 'Telaah semua soal di atas sesuai petunjuk. Gambar soal tidak ikut dikirim; jangan laporkan soal hanya karena gambarnya tidak terlihat.';
        $bagian[] = ['teks' => $penutup];

        if (self::provider() === 'anthropic') {
            $konten = array_map(static fn ($b) => isset($b['teks']) ? ['type' => 'text', 'text' => $b['teks']]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $b['mime'], 'data' => $b['data']]], $bagian);
            $hasil = self::anthropic($konten, self::SISTEM, 16000, 'medium', self::schema());
        } else {
            $isi = $gambar
                ? array_map(static fn ($b) => isset($b['teks']) ? ['type' => 'text', 'text' => $b['teks']]
                    : ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $b['mime'] . ';base64,' . $b['data']]], $bagian)
                : implode('', array_column($bagian, 'teks'));
            $hasil = self::chat([
                ['role' => 'system', 'content' => self::SISTEM . self::FORMAT_JSON],
                ['role' => 'user', 'content' => $isi],
            ], 8000, true);
        }

        return self::rapikan($hasil);
    }

    // ------------------------------------------------------------------ provider kompatibel OpenAI

    /** POST <alamat>/chat/completions; $json = minta keluaran objek JSON (response_format json_object). */
    private static function chat(array $messages, int $maxTokens, bool $json): string
    {
        if (! class_exists(\GuzzleHttp\Client::class)) {
            throw new \RuntimeException('Pustaka Guzzle belum terpasang di server (deploy ulang).');
        }
        $key = self::key();
        if ($key === null || self::model() === '' || self::alamat() === '') {
            throw new \RuntimeException('API key, alamat API, atau model AI belum diisi.');
        }
        @set_time_limit(280);
        $body = ['model' => self::model(), 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => 0.2];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        $http = new \GuzzleHttp\Client(['timeout' => self::TIMEOUT, 'connect_timeout' => 15, 'http_errors' => false]);
        $kirim = static fn (array $b) => $http->post(self::alamat() . '/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'X-Title' => 'CBT Admin'],
            'body'    => json_encode($b, JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $res = $kirim($body);
            // Sebagian model tidak menerima response_format: ulangi sekali tanpa itu (format tetap diminta di prompt).
            if ($json && $res->getStatusCode() === 400 && stripos((string) $res->getBody(), 'response_format') !== false) {
                unset($body['response_format']);
                $res = $kirim($body);
            }
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new \RuntimeException('Tidak bisa terhubung ke ' . self::alamat() . '. Periksa alamat API dan koneksi internet server.');
        }
        $kode = $res->getStatusCode();
        $d    = json_decode((string) $res->getBody(), true);
        if ($kode !== 200) {
            $pesan = is_array($d) ? (string) ($d['error']['message'] ?? $d['message'] ?? '') : '';
            throw new \RuntimeException(match (true) {
                $kode === 401 || $kode === 403 => 'API key ditolak provider (' . $kode . ').',
                $kode === 402                  => 'Saldo/kredit akun provider habis (402).',
                $kode === 429                  => 'Batas pemakaian API tercapai (429). Coba lagi beberapa saat lagi.',
                $kode === 404                  => 'Model atau alamat API tidak ditemukan (404). Periksa nama model.',
                default                        => 'Provider membalas galat ' . $kode . '.',
            } . ($pesan !== '' ? ' ' . mb_substr($pesan, 0, 200) : ''));
        }
        $isi = $d['choices'][0]['message']['content'] ?? null;
        if (($d['choices'][0]['finish_reason'] ?? '') === 'length') {
            throw new \RuntimeException('Jawaban AI terpotong (naskah terlalu panjang). Coba bagi naskah menjadi lebih kecil.');
        }
        if (! is_string($isi) || trim($isi) === '') {
            throw new \RuntimeException('Jawaban AI kosong.');
        }

        return $isi;
    }

    // ------------------------------------------------------------------ Anthropic (SDK resmi)

    private static function anthropic(array $konten, ?string $sistem, int $maxTokens, string $effort, ?array $schema = null): string
    {
        if (! class_exists(Client::class)) {
            throw new \RuntimeException('Pustaka Anthropic SDK belum terpasang di server (deploy ulang).');
        }
        $key = self::key();
        if ($key === null) {
            throw new \RuntimeException('API key AI belum diisi.');
        }
        @set_time_limit(280);
        $output = ['effort' => $effort] + ($schema !== null ? ['format' => ['type' => 'json_schema', 'schema' => $schema]] : []);
        try {
            $c   = new Client(apiKey: $key, requestOptions: ['timeout' => self::TIMEOUT, 'maxRetries' => 1]);
            $msg = $sistem !== null
                ? $c->messages->create(model: self::model(), maxTokens: $maxTokens, system: $sistem, outputConfig: $output, messages: [['role' => 'user', 'content' => $konten]])
                : $c->messages->create(model: self::model(), maxTokens: $maxTokens, outputConfig: $output, messages: [['role' => 'user', 'content' => $konten]]);
        } catch (AuthenticationException) {
            throw new \RuntimeException('API key ditolak. Periksa key di kartu Cek soal dengan AI.');
        } catch (RateLimitException) {
            throw new \RuntimeException('Batas pemakaian API tercapai. Coba lagi beberapa saat lagi.');
        } catch (APIStatusException $e) {
            throw new \RuntimeException('Layanan AI menolak permintaan (' . ($e->type?->value ?? 'galat') . '): ' . $e->getMessage());
        } catch (APIConnectionException) {
            throw new \RuntimeException('Tidak bisa terhubung ke layanan AI. Periksa koneksi internet server.');
        }
        if ($msg->stopReason === 'refusal') {
            throw new \RuntimeException('AI menolak menelaah naskah ini.');
        }
        if ($msg->stopReason === 'max_tokens') {
            throw new \RuntimeException('Jawaban AI terpotong (naskah terlalu panjang). Coba bagi naskah menjadi lebih kecil.');
        }
        foreach ($msg->content as $b) {
            if ($b->type === 'text') {
                return $b->text;
            }
        }

        return '';
    }

    private static function schema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'ringkasan' => ['type' => 'string'],
                'temuan'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'properties'           => [
                            'nomor'       => ['type' => 'integer'],
                            'jenis'       => ['type' => 'string', 'enum' => ['kunci', 'opsi', 'soal', 'ejaan', 'gambar', 'lain']],
                            'tingkat'     => ['type' => 'string', 'enum' => ['penting', 'saran']],
                            'pesan'       => ['type' => 'string'],
                            'saran_kunci' => ['type' => 'string'],
                        ],
                        'required'             => ['nomor', 'jenis', 'tingkat', 'pesan', 'saran_kunci'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['ringkasan', 'temuan'],
            'additionalProperties' => false,
        ];
    }

    // ------------------------------------------------------------------ bantuan

    /** Teks jawaban → struktur temuan yang rapi (JSON bisa dibungkus ``` atau diawali teks oleh sebagian model). */
    private static function rapikan(string $teks): array
    {
        $d = json_decode($teks, true);
        if (! is_array($d) && preg_match('~\{.*\}~s', $teks, $m)) {
            $d = json_decode($m[0], true);
        }
        if (! is_array($d) || ! isset($d['temuan']) || ! is_array($d['temuan'])) {
            throw new \RuntimeException('Jawaban AI tidak berformat yang diminta. Coba lagi atau ganti model.');
        }
        $temuan = [];
        foreach ($d['temuan'] as $t) {
            if (! is_array($t) || (int) ($t['nomor'] ?? 0) < 1) {
                continue;
            }
            $temuan[] = [
                'nomor'       => (int) $t['nomor'],
                'jenis'       => in_array($t['jenis'] ?? '', ['kunci', 'opsi', 'soal', 'ejaan', 'gambar', 'lain'], true) ? $t['jenis'] : 'lain',
                'tingkat'     => ($t['tingkat'] ?? '') === 'penting' ? 'penting' : 'saran',
                'pesan'       => mb_substr((string) ($t['pesan'] ?? ''), 0, 500),
                'saran_kunci' => mb_substr((string) ($t['saran_kunci'] ?? ''), 0, 3),
            ];
        }

        return ['ringkasan' => mb_substr((string) ($d['ringkasan'] ?? ''), 0, 600), 'temuan' => $temuan];
    }

    /** HTML soal → teks polos (gambar jadi penanda nama berkas). */
    private static function polos(string $html): string
    {
        $html = (string) preg_replace('~<img\b[^>]*src="@@PLUGINFILE@@/([^"]+)"[^>]*>~i', ' [Gambar $1] ', $html);
        $html = (string) preg_replace('~<sup>(.*?)</sup>~is', '^($1)', $html);
        $html = (string) preg_replace('~<sub>(.*?)</sub>~is', '_($1)', $html);
        $html = (string) preg_replace('~<(br|/p)\b[^>]*>~i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
