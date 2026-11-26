<?php

namespace App\Libraries;

/**
 * Cek Soal oleh guru: admin membuat tautan cek per guru (panel atau bot Telegram /ceksoal), guru membuka tautan
 * tanpa login, membaca sejumlah soal acak (setelan cek_soal_jumlah) lalu menyatakan benar; hasilnya masuk rekap panel + kabar Telegram.
 * Pengaman: tautan terkunci ke perangkat pertama (cookie), kedaluwarsa (batas), tertutup bila mapel sedang diujikan
 * atau sudah ada attempt siswa, tertutup setelah dikirim, bisa dicabut; halaman diberi watermark nama guru.
 */
class CekSoal
{
    /** Pilihan jumlah soal per tautan (0 = semua soal). */
    public const PILIHAN_JUMLAH = [5, 10, 15, 20, 25, 30, 0];

    /** Jumlah soal per tautan dari setelan cek_soal_jumlah (bawaan 5; 0 = semua soal). */
    public static function jumlah(): int
    {
        $n = (int) Settings::get('cek_soal_jumlah', '5');

        return in_array($n, self::PILIHAN_JUMLAH, true) ? $n : 5;
    }

    public static function namaJumlah(?int $n = null): string
    {
        $n ??= self::jumlah();

        return $n === 0 ? 'semua soal' : $n . ' soal';
    }

    /** Pilihan batas waktu (hari). */
    public const BATAS_HARI = [1, 2, 3, 7];

    /** Huruf besar tanpa spasi/tanda baca, untuk mencocokkan nama mapel. */
    public static function normal(string $s): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($s));
    }

    /** Tingkatan course dari shortname "USBK-XI-MATEMATIKA" atau fullname "MATEMATIKA XI". */
    public static function tingkatCourse(string $shortname, string $fullname): string
    {
        if (preg_match('/(?:^|[-_ ])(XII|XI|X)(?:[-_ ]|$)/i', $shortname, $m) || preg_match('/\b(XII|XI|X)\s*$/i', $fullname, $m)) {
            return strtoupper($m[1]);
        }

        return '';
    }

    /**
     * Course mapel di Moodle (tanpa kategori SESI NONAKTIF) beserta tingkatan, nama mapel tanpa tingkatan,
     * kode dari shortname, jumlah soal di quiz-nya, dan `tutup` (alasan tidak bisa dicek: sudah dikerjakan siswa /
     * sedang diujikan, '' = bisa). $tingkat = '' untuk semua; $hanyaBisa = hanya yang bersoal dan tidak tertutup.
     *
     * @return list<array<string, mixed>>
     */
    public static function mapel(string $tingkat = '', bool $hanyaBisa = false): array
    {
        $c  = Moodle::t('course');
        $cc = Moodle::t('course_categories');
        $q  = Moodle::t('quiz');
        $s  = Moodle::t('quiz_slots');
        $a  = Moodle::t('quiz_attempts');
        $rows = Moodle::db()->query(
            "SELECT c.id, c.fullname, c.shortname, c.visible, c.category, cc.name AS kategori,
                    (SELECT COUNT(*) FROM {$s} sl JOIN {$q} qq ON qq.id = sl.quizid WHERE qq.course = c.id) AS soal,
                    EXISTS (SELECT 1 FROM {$a} at JOIN {$q} qq ON qq.id = at.quiz WHERE qq.course = c.id AND at.preview = 0) AS dikerjakan
               FROM {$c} c JOIN {$cc} cc ON cc.id = c.category
              WHERE c.id > 1 ORDER BY cc.sortorder, c.sortorder"
        )->getResultArray();
        $aktif = ActiveTest::sesiId() !== 0 ? ActiveTest::categoryId() : -1;

        $hasil = [];
        foreach ($rows as $r) {
            if (ActiveTest::isNonaktif($r['kategori'])) {
                continue;
            }
            $t = self::tingkatCourse($r['shortname'], $r['fullname']);
            $r['tutup'] = (int) $r['dikerjakan'] > 0 ? 'sudah dikerjakan'
                : ((int) $r['category'] === $aktif && (int) $r['visible'] === 1 ? 'sedang diujikan' : '');
            if (($tingkat !== '' && $t !== $tingkat) || ($hanyaBisa && ((int) $r['soal'] === 0 || $r['tutup'] !== ''))) {
                continue;
            }
            $kode         = preg_match('/(?:^|[-_ ])(?:XII|XI|X)[-_ ](.+)$/i', $r['shortname'], $m) ? trim($m[1]) : $r['shortname'];
            $r['tingkat'] = $t;
            $r['nama']    = trim((string) preg_replace('/\s+(XII|XI|X)\s*$/i', '', $r['fullname']));
            $r['kode']    = $kode;
            $hasil[]      = $r;
        }

        return $hasil;
    }

    /** Satu course mapel (lihat mapel()) atau null. */
    public static function course(int $courseId): ?array
    {
        foreach (self::mapel() as $m) {
            if ((int) $m['id'] === $courseId) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Kunci nama mapel untuk dicocokkan: huruf besar tanpa spasi/tanda baca, dengan penulisan yang sering berbeda
     * disamakan ("Pend. Agama Islam" = "AGAMA ISLAM", "Bahasa" = "BHS", "Lanjutan"/"LANJT" = "LANJUT").
     */
    public static function kunci(string $s): string
    {
        $s = ' ' . trim((string) preg_replace('/[^A-Z0-9]+/', ' ', mb_strtoupper($s))) . ' ';
        $s = (string) preg_replace('/^ (PEND|PENDIDIKAN) AGAMA /', ' AGAMA ', $s);
        $s = str_replace([' BAHASA ', ' LANJUTAN ', ' LANJT '], [' BHS ', ' LANJUT ', ' LANJUT '], $s);

        return str_replace(' ', '', $s);
    }

    /** Kunci-kunci sebuah course mapel. */
    private static function kunciCourse(array $c): array
    {
        return array_unique([self::kunci($c['nama']), self::kunci($c['kode']), self::kunci($c['fullname']), self::kunci($c['shortname'])]);
    }

    /**
     * Pasangkan data guru dengan course mapel: [guru_id => [course_id, ...]]. Nama mapel dicoba utuh lalu bagian
     * sebelum koma ("Seni Budaya, Musik, Tari" → Seni Budaya); bila tetap tidak ada course yang cocok di tingkat itu,
     * "Lanjut" dibuang (Fisika Lanjutan → FISIKA, karena hanya sebagian mapel punya course "LANJUT").
     *
     * @return array<int, list<int>>
     */
    public static function pasangkan(array $guru, array $mapel): array
    {
        $hasil = [];
        foreach ($guru as $g) {
            $teks  = (string) $g['mapel'];
            $coba  = array_unique(array_filter([self::kunci($teks), self::kunci(explode(',', $teks)[0])]));
            $calon = array_values(array_filter($mapel, static fn ($m) => $m['tingkat'] === $g['tingkat']));
            $kena  = [];
            foreach ([$coba, array_map(static fn ($k) => (string) preg_replace('/LANJUT$/', '', $k), $coba)] as $daftar) {
                foreach ($calon as $m) {
                    if (array_intersect($daftar, self::kunciCourse($m)) !== []) {
                        $kena[] = (int) $m['id'];
                    }
                }
                if ($kena !== []) {
                    break;
                }
            }
            $hasil[(int) $g['id']] = $kena;
        }

        return $hasil;
    }

    /** @return list<array<string, mixed>> guru pengampu course (dari tabel cek_guru) */
    public static function guruUntuk(array $course): array
    {
        $semua = db_connect()->table('cek_guru')->where('tingkat', $course['tingkat'])->orderBy('nama')->get()->getResultArray();
        $peta  = self::pasangkan($semua, self::mapel($course['tingkat']));

        return array_values(array_filter($semua, static fn ($g) => in_array((int) $course['id'], $peta[(int) $g['id']] ?? [], true)));
    }

    /** Alamat awal tautan cek (setelan cek_soal_alamat; diisi dari alamat panel yang dibuka admin). */
    public static function alamat(): string
    {
        $asal = self::asal(Settings::get('cek_soal_alamat')) ?? self::asal((string) env('app.baseURL', '')) ?? '';
        $path = '/' . trim((string) (parse_url((string) env('app.baseURL', '/cbtadmin/'), PHP_URL_PATH) ?: '/cbtadmin/'), '/') . '/';

        return $asal . $path;
    }

    /** "https://domain[:port]" dari sebuah alamat (path diabaikan, mis. salinan ".../cbtadmin/login/"); null bila tidak sah. */
    public static function asal(string $url): ?string
    {
        $p = parse_url(trim($url));
        if (! is_array($p) || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }

        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function url(string $token): string
    {
        return self::alamat() . 'cek/' . $token;
    }

    /**
     * Buat tautan cek untuk tiap guru. Soal dipilih acak, diutamakan yang belum pernah dibagikan untuk course ini,
     * dan tiap guru dalam satu kali buat mendapat soal berbeda bila jumlah soal cukup.
     *
     * @param list<array{id: int|null, nama: string}> $guru
     *
     * @return list<array<string, mixed>> baris cek_tugas yang dibuat
     */
    public static function buat(int $courseId, array $guru, int $hari, string $oleh): array
    {
        $course = self::course($courseId);
        if ($course === null) {
            throw new \RuntimeException('Mapel tidak ditemukan di Moodle.');
        }
        $daftar = MoodleBridge::call('soal_daftar', ['courseid' => $courseId])[0] ?? null;
        $soal   = [];
        foreach ((array) ($daftar['soal'] ?? []) as $s) {
            $soal[(int) $s['id']] = (int) $s['slot'];
        }
        if ($soal === []) {
            throw new \RuntimeException('Quiz ' . $course['fullname'] . ' belum berisi soal.');
        }
        $tutup = self::alasanMapelTutup($courseId);
        if ($tutup !== '') {
            throw new \RuntimeException($tutup);
        }

        $db     = db_connect();
        $pernah = [];
        foreach ($db->table('cek_tugas')->select('soal')->where('courseid', $courseId)->where('dicabut_at', null)->get()->getResultArray() as $r) {
            foreach ((array) json_decode((string) $r['soal'], true) as $s) {
                $pernah[(int) $s['id']] = true;
            }
        }
        $hari  = in_array($hari, self::BATAS_HARI, true) ? $hari : 1;
        $batas = date('Y-m-d H:i:s', strtotime('+' . $hari . ' days'));
        $dipakai = [];
        $dibuat  = [];
        foreach ($guru as $g) {
            $ids    = array_keys($soal);
            shuffle($ids);
            // Urutan prioritas: belum pernah dibagikan & belum dipakai di kiriman ini, lalu belum dipakai di kiriman ini, lalu sisanya.
            usort($ids, static fn ($a, $b) => (isset($dipakai[$a]) <=> isset($dipakai[$b])) ?: (isset($pernah[$a]) <=> isset($pernah[$b])));
            $pilih = self::jumlah() === 0 ? $ids : array_slice($ids, 0, self::jumlah());
            usort($pilih, static fn ($a, $b) => $soal[$a] <=> $soal[$b]);
            foreach ($pilih as $id) {
                $dipakai[$id] = true;
            }
            $baris = [
                'token'       => bin2hex(random_bytes(16)),
                'guru_id'     => $g['id'] ?? null,
                'guru_nama'   => mb_substr(trim((string) $g['nama']), 0, 120),
                'courseid'    => $courseId,
                'mapel'       => $course['fullname'],
                'soal'        => json_encode(array_map(static fn ($id) => ['id' => $id, 'slot' => $soal[$id]], $pilih)),
                'batas'       => $batas,
                'dibuat_oleh' => mb_substr($oleh, 0, 100),
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            $db->table('cek_tugas')->insert($baris);
            $baris['id'] = (int) $db->insertID();
            $dibuat[]    = $baris;
        }
        ActivityLog::add('cek_soal_buat', $course['fullname'] . ' → ' . implode(', ', array_column($dibuat, 'guru_nama')) . ' (batas ' . $hari . ' hari)');

        return $dibuat;
    }

    /** Pesan siap-teruskan ke WA guru. */
    public static function pesan(array $t): string
    {
        return 'Yth. ' . $t['guru_nama'] . ",\n"
            . 'mohon cek ' . count((array) json_decode((string) $t['soal'], true)) . ' soal ' . $t['mapel'] . ".\n"
            . 'Batas: ' . self::tanggal((string) $t['batas']) . "\n"
            . self::url((string) $t['token']) . "\n\n"
            . 'Tautan hanya bisa dibuka di 1 perangkat. Mohon tidak disebarkan.';
    }

    public static function tanggal(string $dt): string
    {
        $hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $w     = strtotime($dt);

        return $hari[(int) date('w', $w)] . ' ' . date('j', $w) . ' ' . $bulan[(int) date('n', $w)] . ' ' . date('Y, H.i', $w);
    }

    /** Status tautan: selesai | dicabut | kedaluwarsa | dibuka | belum. */
    public static function status(array $t): string
    {
        if ($t['selesai_at'] !== null) {
            return 'selesai';
        }
        if ($t['dicabut_at'] !== null) {
            return 'dicabut';
        }
        if (strtotime((string) $t['batas']) < time()) {
            return 'kedaluwarsa';
        }

        return $t['dibuka_at'] !== null ? 'dibuka' : 'belum';
    }

    public const NAMA_STATUS = ['selesai' => 'Selesai', 'dicabut' => 'Dicabut', 'kedaluwarsa' => 'Kedaluwarsa', 'dibuka' => 'Sedang dicek', 'belum' => 'Belum dibuka'];

    /**
     * Mapel tidak boleh dicek lagi bila sedang diujikan (course tampil di tes aktif saat ada sesi aktif)
     * atau siswa sudah mulai mengerjakan quiz-nya. '' = boleh.
     */
    public static function alasanMapelTutup(int $courseId): string
    {
        $c = Moodle::db()->table(Moodle::t('course'))->select('visible, category')->where('id', $courseId)->get()->getRowArray();
        if ($c === null) {
            return 'Mapel sudah tidak ada di Moodle.';
        }
        if (ActiveTest::sesiId() !== 0 && (int) $c['category'] === ActiveTest::categoryId() && (int) $c['visible'] === 1) {
            return 'Mapel ini sedang diujikan, jadi soalnya tidak bisa dicek lagi.';
        }
        $q = Moodle::t('quiz');
        $a = Moodle::t('quiz_attempts');
        $n = (int) (Moodle::db()->query("SELECT COUNT(*) AS n FROM {$a} at JOIN {$q} qq ON qq.id = at.quiz WHERE qq.course = ? AND at.preview = 0", [$courseId])->getRowArray()['n'] ?? 0);

        return $n > 0 ? 'Siswa sudah mengerjakan mapel ini, jadi soalnya tidak bisa dicek lagi.' : '';
    }

    /** Pernyataan guru yang tampil di halaman cek dan disimpan bersama hasil. */
    public static function pernyataan(array $t): string
    {
        return 'Dengan ini saya, ' . $t['guru_nama'] . ', menyatakan bahwa soal di atas adalah benar soal ' . $t['mapel'] . ' yang saya kirim.';
    }

    /** Catatan guru saat mengirim pernyataan ('' bila tanpa catatan). Hasil: JSON {konfirmasi, catatan}. */
    public static function catatan(array $t): string
    {
        return trim((string) (((array) json_decode((string) ($t['hasil'] ?? ''), true))['catatan'] ?? ''));
    }

    /** Kabar ke Telegram admin saat guru selesai. */
    public static function kabarSelesai(array $t): void
    {
        $catatan = self::catatan($t);
        $teks    = '✅ ' . $t['guru_nama'] . ' menyatakan soal ' . $t['mapel'] . ' benar.'
            . ($catatan !== '' ? "\n📝 Catatan: " . $catatan : '');
        try {
            Telegram::kirimAdmin($teks);
        } catch (\Throwable $e) {
            log_message('error', 'Cek Soal Telegram: ' . $e->getMessage());
        }
    }
}
