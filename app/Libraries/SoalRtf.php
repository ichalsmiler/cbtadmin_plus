<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Membaca berkas soal RTF (format ekspor bank soal seperti contoh "Bhs. Inggris-X-Ganjil-….rtf"):
 * - soal = paragraf biasa (teks bacaan + pertanyaan, boleh bergambar), lalu
 * - opsi = paragraf daftar berhuruf (a, b, c, …), lalu
 * - kunci = baris "ANS: X".
 * Judul bagian ("Multiple choice") dan baris info (PTS:, DIF:, REF:, …) diabaikan; bagian selain pilihan ganda
 * (essay, true/false, …) dilewati dengan peringatan. Hasilnya dijadikan Moodle XML untuk jembatan import_soal.
 */
class SoalRtf
{
    private const TUJUAN_LEWAT = [
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'listtable', 'listoverridetable', 'object', 'themedata', 'datastore',
        'latentstyles', 'generator', 'xmlnstbl', 'rsidtbl', 'header', 'headerl', 'headerr', 'headerf', 'footer', 'footerl',
        'footerr', 'footerf', 'nonshppict', 'listtext', 'pntext', 'pntxta', 'pntxtb', 'fldinst', 'bkmkstart', 'bkmkend',
        'field', 'footnote', 'annotation', 'comment', 'pgdsctbl', 'revtbl', 'mmathPr', 'colorschememapping', 'filetbl',
    ];

    /** Judul bagian di berkas; selain pilihan ganda dilewati. */
    private const BAGIAN = [
        'multiple choice' => true, 'pilihan ganda' => true,
        'true/false' => false, 'modified true/false' => false, 'completion' => false, 'matching' => false,
        'short answer' => false, 'problem' => false, 'essay' => false, 'uraian' => false, 'isian' => false,
        'numeric response' => false, 'other' => false,
    ];

    /** Gambar dikecilkan: lebar maksimal (px) dan ukuran sasaran per gambar (byte). */
    public const LEBAR_MAKS = 1000;
    public const UKURAN_MAKS = 150 * 1024;

    /** Ringkasan pengecilan gambar selama urai() (byte asli, byte hasil, jumlah gambar yang diubah). */
    private static array $ukuran = ['asli' => 0, 'jadi' => 0, 'jumlah' => 0];

    /** Gambar WMF (rumus/diagram dari Equation Editor atau ekspor bank soal) yang gagal diubah ke PNG selama urai(). */
    private static int $wmfGagal = 0;

    /** Pengubah WMF → PNG (paket Debian libwmf-bin). */
    public const WMF2GD = '/usr/bin/wmf2gd';

    /**
     * @return array{soal: list<array{teks: string, opsi: list<string>, kunci: int, gambar: list<array{nama: string, data: string}>}>, peringatan: list<string>, gambar: array{asli: int, jadi: int, jumlah: int}}
     */
    public static function urai(string $rtf): array
    {
        self::$ukuran   = ['asli' => 0, 'jadi' => 0, 'jumlah' => 0];
        self::$wmfGagal = 0;
        if (! str_starts_with(ltrim($rtf), '{\\rtf')) {
            throw new RuntimeException('Berkas bukan RTF. Simpan soal sebagai Rich Text Format (.rtf).');
        }
        [$paragraf, $hurufList] = self::paragraf($rtf);

        $soal       = [];
        $peringatan = [];
        $dilewati   = 0;
        $pilgan     = true;
        $batang     = [];  // paragraf soal yang sedang dikumpulkan
        $opsi       = [];
        $nomorSoal  = 0;
        foreach ($paragraf as $p) {
            $teks  = trim(self::teksPolos($p['runs']));
            $kecil = strtolower(trim($teks, " \t:."));
            if ($p['gambar'] === [] && isset(self::BAGIAN[$kecil])) {
                $pilgan = self::BAGIAN[$kecil];
                $batang = $opsi = [];

                continue;
            }
            if (preg_match('/^(PTS|DIF|REF|OBJ|TOP|KEY|MSC|NAT|STA|LOC|BLM|RAT|FEEDBACK)\s*:/i', $teks)) {
                continue;
            }
            if (preg_match('/^ANS\s*:\s*(.*)$/i', $teks, $m)) {
                $nomorSoal++;
                if (! $pilgan) {
                    $dilewati++;
                } elseif (count($opsi) < 2) {
                    $peringatan[] = "Soal $nomorSoal dilewati: opsi jawaban kurang dari 2.";
                } elseif (! preg_match('/^([A-Z])\b/i', trim($m[1]), $k) || ($idx = ord(strtoupper($k[1])) - 65) >= count($opsi)) {
                    $peringatan[] = "Soal $nomorSoal dilewati: kunci \"" . trim($m[1]) . '" tidak sesuai dengan ' . count($opsi) . ' opsi.';
                } elseif ($batang === []) {
                    $peringatan[] = "Soal $nomorSoal dilewati: teks soal kosong.";
                } else {
                    $soal[] = self::bangunSoal($batang, $opsi, $idx);
                }
                $batang = $opsi = [];

                continue;
            }
            $opsiHuruf = $p['ls'] !== null && ($hurufList[$p['ls']] ?? false);
            if ($opsiHuruf) {
                $opsi[] = $p;
            } elseif ($opsi !== [] && preg_match('/^[A-Ea-e][.)]\s/', $teks)) {
                $opsi[] = $p;  // opsi diketik manual "A. …" setelah opsi daftar
            } elseif ($opsi === [] && $batang !== [] && preg_match('/^[Aa][.)]\s/', $teks)) {
                $opsi[] = $p;  // opsi diketik manual, dimulai "A. …"
            } else {
                if ($opsi !== []) {
                    // Paragraf biasa setelah opsi tanpa ANS: soal sebelumnya tidak punya kunci.
                    $peringatan[] = 'Soal setelah nomor ' . $nomorSoal . ' tidak punya baris "ANS:", dilewati.';
                    $batang = $opsi = [];
                }
                $batang[] = $p;
            }
        }
        if ($dilewati > 0) {
            $peringatan[] = "$dilewati soal bukan pilihan ganda (essay/benar-salah/dll.) dilewati; masukkan manual di Moodle.";
        }
        if (self::$wmfGagal > 0) {
            $peringatan[] = self::$wmfGagal . ' gambar WMF (rumus/diagram) tidak bisa dibaca'
                . (is_executable(self::WMF2GD) ? '.' : ': pengubah WMF belum terpasang di server (apt install libwmf-bin, atau deploy ulang panel).')
                . ' Periksa soal yang rumus/gambarnya hilang.';
        }
        if ($soal === []) {
            throw new RuntimeException('Tidak ada soal pilihan ganda yang terbaca. Pastikan opsi berupa daftar a/b/c dan ada baris "ANS: X" di bawah setiap soal.');
        }

        return ['soal' => $soal, 'peringatan' => $peringatan, 'gambar' => self::$ukuran];
    }

    /**
     * Kecilkan gambar soal supaya ringan saat ratusan siswa membuka soal bersamaan: lebar paling besar LEBAR_MAKS px,
     * disimpan JPEG (kualitas diturunkan bertahap sampai <= UKURAN_MAKS). Gambar yang sudah kecil, atau PNG kecil
     * (mis. diagram/teks yang lebih tajam sebagai PNG), dibiarkan. Tanpa ekstensi GD gambar tidak diubah.
     *
     * @return array{0: string, 1: string} [data, ekstensi]
     */
    public static function kecilkan(string $data, string $ext): array
    {
        if (! function_exists('imagecreatefromstring') || $data === '') {
            return [$data, $ext];
        }
        $info = @getimagesizefromstring($data);
        if (! $info) {
            return [$data, $ext];
        }
        [$w, $h] = $info;
        if ($w <= self::LEBAR_MAKS && strlen($data) <= self::UKURAN_MAKS) {
            return [$data, $ext];
        }
        $img = @imagecreatefromstring($data);
        if (! $img) {
            return [$data, $ext];
        }
        $lebar  = min($w, self::LEBAR_MAKS);
        $tinggi = max(1, (int) round($h * $lebar / $w));
        $kanvas = imagecreatetruecolor($lebar, $tinggi);
        // PNG transparan diberi latar putih (JPEG tidak punya transparansi).
        imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $img, 0, 0, 0, 0, $lebar, $tinggi, $w, $h);
        imagedestroy($img);
        $hasil = '';
        foreach ([82, 75, 68, 60, 52] as $kualitas) {
            ob_start();
            imagejpeg($kanvas, null, $kualitas);
            $hasil = (string) ob_get_clean();
            if (strlen($hasil) <= self::UKURAN_MAKS) {
                break;
            }
        }
        imagedestroy($kanvas);

        // Hasil tidak lebih kecil (mis. PNG kecil yang sedikit lebih lebar): pakai yang asli.
        return $hasil !== '' && strlen($hasil) < strlen($data) ? [$hasil, 'jpg'] : [$data, $ext];
    }

    /**
     * Gambar WMF dari RTF (\wmetafile, mis. rumus Equation Editor/MathType atau diagram hasil ekspor bank soal) → PNG
     * lewat wmf2gd (libwmf-bin). WMF di RTF tidak membawa ukuran, jadi diberi header "placeable" dari SetWindowOrg/
     * SetWindowExt metafile + \picwgoal (twips); tanpa itu libwmf menggambar persegi (gepeng). Dirender 2x lebih rapat
     * (maks. LEBAR_MAKS px), lebar tampil = ukuran di dokumen (twips / 15 = px pada 96 dpi).
     *
     * @param array{picwgoal: int, pichgoal: int, picw: int, pich: int} $ukuran
     *
     * @return array{ext: string, data: string, lebar: int}|null
     */
    private static function wmfKePng(string $wmf, array $ukuran): ?array
    {
        $gw = $ukuran['picwgoal'] > 0 ? $ukuran['picwgoal'] : (int) round($ukuran['picw'] * 1440 / 2540);
        $gh = $ukuran['pichgoal'] > 0 ? $ukuran['pichgoal'] : (int) round($ukuran['pich'] * 1440 / 2540);
        if (! is_executable(self::WMF2GD) || strlen($wmf) < 18 || $gw <= 0 || $gh <= 0) {
            self::$wmfGagal++;

            return null;
        }
        $lebar = max(1, (int) round($gw / 15));
        $w     = min(self::LEBAR_MAKS, $lebar * 2);
        $h     = max(1, (int) round($w * $gh / $gw));
        $masuk = tempnam(sys_get_temp_dir(), 'wmf');
        $keluar = $masuk . '.png';
        file_put_contents($masuk, self::wmfPlaceable($wmf, $gw));
        exec(escapeshellarg(self::WMF2GD) . ' -t png --maxwidth=' . $w . ' --maxheight=' . $h . ' --maxpect -o '
            . escapeshellarg($keluar) . ' ' . escapeshellarg($masuk) . ' >/dev/null 2>&1');
        $png = is_file($keluar) ? (string) file_get_contents($keluar) : '';
        @unlink($masuk);
        @unlink($keluar);
        if ($png === '' || ! @getimagesizefromstring($png)) {
            self::$wmfGagal++;

            return null;
        }

        return ['ext' => 'png', 'data' => $png, 'lebar' => $lebar];
    }

    /** Tambah header placeable (22 byte) dari SetWindowOrg/SetWindowExt; WMF tanpa SetWindowExt dikembalikan apa adanya. */
    private static function wmfPlaceable(string $wmf, int $lebarTwips): string
    {
        if (substr($wmf, 0, 4) === "\xD7\xCD\xC6\x9A") {
            return $wmf;  // sudah placeable
        }
        $pos = unpack('v', substr($wmf, 2, 2))[1] * 2;  // panjang header metafile (word)
        $org = [0, 0];
        $ext = null;
        $n   = strlen($wmf);
        for ($r = 0; $r < 200 && $pos + 6 <= $n; $r++) {
            $size = unpack('V', substr($wmf, $pos, 4))[1];
            $fn   = unpack('v', substr($wmf, $pos + 4, 2))[1];
            if ($size < 3) {
                break;
            }
            if (($fn === 0x020B || $fn === 0x020C) && $pos + 10 <= $n) {
                $p  = unpack('s2', substr($wmf, $pos + 6, 4));  // parameter tersimpan terbalik: y, x
                $xy = [$p[2], $p[1]];
                if ($fn === 0x020B) {
                    $org = $xy;
                } else {
                    $ext = $xy;
                }
            }
            $pos += $size * 2;
        }
        if ($ext === null || $ext[0] === 0 || $ext[1] === 0) {
            return $wmf;
        }
        $inch = max(1, min(65535, (int) round(abs($ext[0]) * 1440 / max(1, $lebarTwips))));
        $kata = [0xCDD7, 0x9AC6, 0, $org[0] & 0xFFFF, $org[1] & 0xFFFF, ($org[0] + $ext[0]) & 0xFFFF, ($org[1] + $ext[1]) & 0xFFFF, $inch, 0, 0];
        $cek  = 0;
        foreach ($kata as $k) {
            $cek ^= $k;
        }
        $kata[] = $cek;

        return pack('v*', ...$kata) . $wmf;
    }

    /** Moodle XML untuk jembatan import_soal. */
    public static function keXml(array $soal, string $awalanNama = 'Soal'): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";
        foreach ($soal as $i => $s) {
            $nama  = $awalanNama . ' ' . ($i + 1);
            $x    .= '<question type="multichoice"><name><text>' . htmlspecialchars($nama, ENT_XML1) . '</text></name>'
                . '<questiontext format="html"><text><![CDATA[' . self::cdata($s['teks']) . ']]></text>';
            $x .= self::berkasXml($s['teks'], $s['gambar']);
            $x .= '</questiontext><generalfeedback format="html"><text></text></generalfeedback>'
                . '<defaultgrade>1</defaultgrade><penalty>0</penalty><hidden>0</hidden>'
                . '<single>true</single><shuffleanswers>true</shuffleanswers><answernumbering>ABCD</answernumbering>'
                . '<showstandardinstruction>0</showstandardinstruction>';
            foreach ($s['opsi'] as $j => $o) {
                $x .= '<answer fraction="' . ($j === $s['kunci'] ? '100' : '0') . '" format="html"><text><![CDATA[' . self::cdata($o) . ']]></text>'
                    . self::berkasXml($o, $s['gambar'])
                    . '<feedback format="html"><text></text></feedback></answer>';
            }
            $x .= "</question>\n";
        }

        return $x . '</quiz>';
    }

    /** Elemen <file> untuk gambar yang dipakai $html (teks soal atau satu opsi). */
    private static function berkasXml(string $html, array $gambar): string
    {
        $x = '';
        foreach ($gambar as $g) {
            if (str_contains($html, '@@PLUGINFILE@@/' . $g['nama'] . '"')) {
                $x .= '<file name="' . htmlspecialchars($g['nama'], ENT_XML1) . '" path="/" encoding="base64">' . base64_encode($g['data']) . '</file>';
            }
        }

        return $x;
    }

    // ------------------------------------------------------------------ penyusun soal

    private static function bangunSoal(array $batang, array $opsi, int $kunci): array
    {
        $gambar = [];
        $html   = [];
        $akhir  = count($batang) - 1;
        foreach ($batang as $i => $p) {
            $runs = $p['runs'];
            if ($i === $akhir) {
                // Nomor soal yang diketik manual ("7.<tab>…") dibuang; Moodle memberi nomor sendiri.
                $runs = self::buangAwalan($runs, '/^\s*\d{1,3}\s*[.)]\s*/');
            }
            $isi = self::pasangGambar(self::html($runs), $p['gambar'], $gambar);
            if ($isi !== '') {
                $html[] = '<p>' . $isi . '</p>';
            }
        }
        $teksOpsi = [];
        foreach ($opsi as $o) {
            // Opsi diketik manual "A. …": hurufnya dibuang (Moodle menampilkan A/B/C sendiri). Gambar opsi (mis. rumus)
            // disimpan di daftar gambar soal; keXml() memasang berkasnya pada jawaban yang memakainya.
            $teksOpsi[] = self::pasangGambar(self::html(self::buangAwalan($o['runs'], '/^\s*[A-Ea-e][.)]\s*/')), $o['gambar'], $gambar);
        }

        return ['teks' => implode('', $html), 'opsi' => $teksOpsi, 'kunci' => $kunci, 'gambar' => $gambar];
    }

    /**
     * Ganti penanda gambar {{Gn}} (posisi gambar di paragraf) dengan <img>, berkasnya ditambahkan ke $gambar.
     * Gambar dari WMF dibuat 2x lebih rapat; lebar tampil (atribut width) tetap ukuran aslinya di dokumen.
     */
    private static function pasangGambar(string $isi, array $gambarParagraf, array &$gambar): string
    {
        return (string) preg_replace_callback('/\{\{G(\d+)\}\}/', static function (array $m) use ($gambarParagraf, &$gambar): string {
            $g = $gambarParagraf[(int) $m[1]] ?? null;
            if ($g === null) {
                return '';
            }
            [$data, $ext] = self::kecilkan($g['data'], $g['ext']);
            self::$ukuran['asli'] += strlen($g['data']);
            self::$ukuran['jadi'] += strlen($data);
            self::$ukuran['jumlah'] += $data !== $g['data'] ? 1 : 0;
            $nama     = 'gambar-' . (count($gambar) + 1) . '.' . $ext;
            $gambar[] = ['nama' => $nama, 'data' => $data];
            $lebar    = ! empty($g['lebar']) ? ' width="' . (int) $g['lebar'] . '"' : '';

            // Sama dengan pratinjau konver-soal: lebar = picwgoal / 15 px; rumus diturunkan/dinaikkan sesuai \dn/\up,
            // gambar lain di tengah baris (konver-soal: .edit img { vertical-align: middle }).
            $geser = ';vertical-align:' . (! empty($g['turun']) ? -(int) round($g['turun'] * 10 / 15) . 'px' : 'middle');

            return '<img src="@@PLUGINFILE@@/' . $nama . '" alt=""' . $lebar . ' style="max-width:100%;height:auto' . $geser . '">';
        }, $isi);
    }

    private static function buangAwalan(array $runs, string $pola): array
    {
        foreach ($runs as $i => $r) {
            if (trim($r['t']) === '') {
                continue;
            }
            $runs[$i]['t'] = (string) preg_replace($pola, '', $r['t'], 1);
            break;
        }

        return $runs;
    }

    private static function teksPolos(array $runs): string
    {
        return implode('', array_column($runs, 't'));
    }

    private static function html(array $runs): string
    {
        $h = '';
        foreach ($runs as $r) {
            if (isset($r['img'])) {
                $h .= '{{G' . (int) $r['img'] . '}}';  // diganti <img> oleh pasangGambar()

                continue;
            }
            $t = str_replace(["\t", "\n"], [' ', '<br>'], htmlspecialchars($r['t'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            foreach (['sub' => 'sub', 'sup' => 'sup', 'u' => 'u', 'i' => 'i', 'b' => 'b'] as $k => $tag) {
                if (! empty($r[$k]) && trim($r['t']) !== '') {
                    $t = "<$tag>$t</$tag>";
                }
            }
            $h .= $t;
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', $h));
    }

    private static function cdata(string $s): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $s);
    }

    // ------------------------------------------------------------------ pembaca RTF

    /**
     * Paragraf berisi run teks berformat, nomor daftar (\ls), dan gambar (JPEG/PNG dari \shppict).
     *
     * @return array{0: list<array{ls: ?int, runs: list<array>, gambar: list<array{ext: string, data: string}>}>, 1: array<int, bool>}
     */
    private static function paragraf(string $rtf): array
    {
        $hurufList = self::daftarBerhuruf($rtf);
        $n         = strlen($rtf);
        $i         = 0;
        $tumpukan  = [];
        $st        = ['lewat' => false, 'pict' => false, 'b' => false, 'i' => false, 'u' => false, 'sup' => false, 'sub' => false, 'uc' => 1, 'dn' => 0];
        $pict      = null;  // ['ext' => …, 'hex' => …, 'kedalaman' => …]
        $ukuranPict = ['picwgoal' => 0, 'pichgoal' => 0, 'picw' => 0, 'pich' => 0, 'picscalex' => 100, 'picscaley' => 100];  // twips / satuan metafile
        $hasil     = [];
        $cur       = ['ls' => null, 'runs' => [], 'gambar' => []];
        $lewatChar = 0;

        $tambah = static function (string $t) use (&$cur, &$st): void {
            if ($st['lewat'] || $t === '') {
                return;
            }
            $f    = ['b' => $st['b'], 'i' => $st['i'], 'u' => $st['u'], 'sup' => $st['sup'], 'sub' => $st['sub']];
            $last = array_key_last($cur['runs']);
            if ($last !== null && array_diff_key($cur['runs'][$last], ['t' => 1]) === $f) {
                $cur['runs'][$last]['t'] .= $t;
            } else {
                $cur['runs'][] = ['t' => $t] + $f;
            }
        };
        $tutup = static function () use (&$cur, &$hasil): void {
            if (trim(implode('', array_column($cur['runs'], 't'))) !== '' || $cur['gambar'] !== []) {
                $hasil[] = $cur;
            }
            $cur = ['ls' => $cur['ls'], 'runs' => [], 'gambar' => []];
        };

        while ($i < $n) {
            $c = $rtf[$i];
            if ($pict !== null && $st['pict'] && ! $st['lewat'] && ctype_xdigit($c)) {
                $len           = strspn($rtf, "0123456789abcdefABCDEF\r\n", $i);
                $pict['hex']  .= str_replace(["\r", "\n"], '', substr($rtf, $i, $len));
                $i            += $len;

                continue;
            }
            if ($c === '{') {
                $tumpukan[] = $st;
                $i++;
                if (preg_match('/\G\\\\(\*\\\\)?([a-zA-Z]+)/', $rtf, $m, 0, $i)) {
                    $kata = $m[2];
                    if ($kata === 'shppict' || $kata === 'pict') {
                        // Gambar: diambil dari \pict (JPEG/PNG, atau WMF yang diubah ke PNG); cadangan WMF di \nonshppict
                        // dilewati karena gambar yang sama sudah ada di \shppict.
                        if (! $st['lewat']) {
                            $st['pict'] = $kata === 'pict';
                            if ($kata === 'pict') {
                                $ukuranPict = ['picwgoal' => 0, 'pichgoal' => 0, 'picw' => 0, 'pich' => 0, 'picscalex' => 100, 'picscaley' => 100];
                            }
                        }
                    } elseif (in_array($kata, self::TUJUAN_LEWAT, true) || $m[1] !== '') {
                        $st['lewat'] = true;
                    }
                }

                continue;
            }
            if ($c === '}') {
                if ($pict !== null && $st['pict'] && ($tumpukan === [] || ! end($tumpukan)['pict'])) {
                    if ($pict['ext'] !== '' && $pict['hex'] !== '' && strlen($pict['hex']) % 2 === 0) {
                        // Lebar tampil = ukuran di dokumen (\picwgoal twips × \picscalex %, / 15 = px pada 96 dpi), bukan
                        // ukuran piksel gambar: rumus dari Word/ekspor bank soal sering disimpan 2x lebih rapat.
                        $skala = max(1, $ukuranPict['picscalex']) / 100;
                        $g     = ['ext' => $pict['ext'], 'data' => (string) hex2bin($pict['hex']),
                            'lebar' => $ukuranPict['picwgoal'] > 0 ? max(1, (int) round($ukuranPict['picwgoal'] * $skala / 15)) : null];
                        if ($g['ext'] === 'wmf') {
                            $ukuranPict['picwgoal'] = (int) round($ukuranPict['picwgoal'] * $skala);
                            $ukuranPict['pichgoal'] = (int) round($ukuranPict['pichgoal'] * max(1, $ukuranPict['picscaley']) / 100);
                            $g = self::wmfKePng($g['data'], $ukuranPict);
                        }
                        if ($g !== null) {
                            // \dnN / \upN (setengah poin) di sekitar gambar = rumus diturunkan/dinaikkan dari garis dasar
                            // (konver-soal menulisnya dari kedalaman rumus Word). 1 setengah poin = 10 twips = 2/3 px.
                            $g['turun']      = (int) $st['dn'];
                            $cur['runs'][]   = ['t' => '', 'img' => count($cur['gambar'])];
                            $cur['gambar'][] = $g;
                        }
                    }
                    $pict = null;
                }
                $st = $tumpukan !== [] ? array_pop($tumpukan) : $st;
                $i++;

                continue;
            }
            if ($c === '\\') {
                if (preg_match('/\G\\\\([a-zA-Z]+)(-?\d+)? ?|\G\\\\\'([0-9a-fA-F]{2})|\G\\\\(.)/s', $rtf, $m, 0, $i) !== 1) {
                    $i++;

                    continue;
                }
                $i += strlen($m[0]);
                if (($m[3] ?? '') !== '') {
                    if ($lewatChar > 0) {
                        $lewatChar--;

                        continue;
                    }
                    $tambah(mb_convert_encoding(chr(hexdec($m[3])), 'UTF-8', 'Windows-1252'));

                    continue;
                }
                if (($m[4] ?? '') !== '') {
                    $ch = $m[4];
                    if ($ch === '\\' || $ch === '{' || $ch === '}') {
                        $tambah($ch);
                    } elseif ($ch === '~') {
                        $tambah(' ');
                    } elseif ($ch === '_') {
                        $tambah('-');
                    } elseif ($ch === "\n" || $ch === "\r") {
                        $tutup();
                    }

                    continue;
                }
                $kata = $m[1];
                $arg  = $m[2] ?? '';
                $nol  = $arg === '0';
                switch ($kata) {
                    case 'par':
                        if (! $st['lewat']) {
                            $tutup();
                        }
                        break;
                    case 'line':
                        $tambah("\n");
                        break;
                    case 'tab':
                        $tambah("\t");
                        break;
                    case 'cell':
                        $tambah(' | ');
                        break;
                    case 'row':
                        if (! $st['lewat']) {
                            $tutup();
                        }
                        break;
                    case 'u':
                        $kode = (int) $arg;
                        $tambah(mb_chr($kode < 0 ? $kode + 65536 : $kode, 'UTF-8') ?: '');
                        $lewatChar = $st['uc'];
                        continue 2;
                    case 'uc':
                        $st['uc'] = (int) $arg;
                        break;
                    case 'b':
                    case 'i':
                        $st[$kata] = ! $nol;
                        break;
                    case 'ul':
                        $st['u'] = ! $nol;
                        break;
                    case 'ulnone':
                        $st['u'] = false;
                        break;
                    case 'super':
                        $st['sup'] = true;
                        break;
                    case 'sub':
                        $st['sub'] = true;
                        break;
                    case 'nosupersub':
                        $st['sup'] = $st['sub'] = false;
                        break;
                    case 'dn':
                    case 'up':
                        $setengahPoin = $arg === '' ? 6 : (int) $arg;  // bawaan RTF: 3 pt
                        $st['dn']     = $kata === 'dn' ? $setengahPoin : -$setengahPoin;
                        break;
                    case 'plain':
                        $st = ['b' => false, 'i' => false, 'u' => false, 'sup' => false, 'sub' => false, 'dn' => 0] + $st;
                        break;
                    case 'pard':
                        if (! $st['lewat']) {
                            $cur['ls'] = null;
                        }
                        break;
                    case 'ls':
                        if (! $st['lewat']) {
                            $cur['ls'] = (int) $arg;
                        }
                        break;
                    case 'jpegblip':
                    case 'pngblip':
                        if ($st['pict'] && ! $st['lewat']) {
                            $pict = ['ext' => $kata === 'jpegblip' ? 'jpg' : 'png', 'hex' => ''];
                        }
                        break;
                    case 'picwgoal':
                    case 'pichgoal':
                    case 'picw':
                    case 'pich':
                    case 'picscalex':
                    case 'picscaley':
                        if ($st['pict']) {
                            $ukuranPict[$kata] = (int) $arg;
                        }
                        break;
                    case 'wmetafile':
                        if ($st['pict'] && ! $st['lewat']) {
                            $pict = ['ext' => 'wmf', 'hex' => ''];
                        }
                        break;
                    case 'emfblip':
                    case 'macpict':
                    case 'dibitmap':
                    case 'wbitmap':
                        if ($st['pict']) {
                            $pict = ['ext' => '', 'hex' => ''];  // format tidak didukung browser: dilewati
                        }
                        break;
                    case 'bin':
                        $i += max(0, (int) $arg);
                        break;
                }
                $lewatChar = 0;

                continue;
            }
            if ($c === "\r" || $c === "\n") {
                $i++;

                continue;
            }
            if ($lewatChar > 0) {
                $lewatChar--;
                $i++;

                continue;
            }
            // Teks biasa: ambil sekaligus sampai karakter khusus berikutnya.
            $len = strcspn($rtf, "\\{}\r\n", $i);
            if ($st['pict']) {
                $i += max(1, $len);

                continue;
            }
            $tambah(mb_convert_encoding(substr($rtf, $i, $len), 'UTF-8', 'Windows-1252'));
            $i += max(1, $len);
        }
        $tutup();

        return [$hasil, $hurufList];
    }

    /**
     * Nomor daftar (\lsN) yang penomorannya huruf (\levelnfc3 A,B,C atau \levelnfc4 a,b,c) = daftar opsi jawaban.
     *
     * @return array<int, bool>
     */
    private static function daftarBerhuruf(string $rtf): array
    {
        $jenis = [];
        if (preg_match_all('/\{\\\\list\\\\listtemplateid-?\d+.*?\\\\levelnfc(\d+).*?\\\\listid(-?\d+)\}/s', $rtf, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $jenis[(int) $x[2]] = in_array((int) $x[1], [3, 4], true);
            }
        }
        $hasil = [];
        if (preg_match_all('/\\\\listoverride\\\\listid(-?\d+).*?\\\\ls(\d+)\}/s', $rtf, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $hasil[(int) $x[2]] = $jenis[(int) $x[1]] ?? false;
            }
        }

        return $hasil;
    }
}
