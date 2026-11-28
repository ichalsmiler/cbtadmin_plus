<?php

namespace App\Libraries;

/**
 * Bandingkan soal kiriman guru (hasil SoalRtf::urai) dengan soal di quiz Moodle (bridge soal_semua).
 * Soal dipasangkan menurut kemiripan teks (bukan nomor), supaya tetap cocok bila soal ditambah, urutan berubah,
 * atau soal sudah diedit di Review & Edit Soal.
 */
class BandingSoal
{
    /** Kemiripan minimal (persen) agar dua soal dianggap pasangan. */
    private const MIRIP_MIN = 55.0;

    /**
     * @param list<array{teks:string,opsi:list<string>,kunci:int,gambar:array}> $kiriman
     * @param list<array{id:int,slot:int,teks:string,opsi:list<array{teks:string,kunci:bool}>}> $moodle
     *
     * @return array{baris: list<array>, ringkas: array<string,int>}
     */
    public static function bandingkan(array $kiriman, array $moodle): array
    {
        $k = array_map(static fn ($s) => [
            'teks' => self::polos($s['teks']),
            'opsi' => array_map([self::class, 'polos'], $s['opsi']),
            'kunci' => (int) $s['kunci'],
            'img'  => self::jumlahGambar($s['teks'] . implode('', $s['opsi'])),
        ], $kiriman);
        $m = array_map(static function ($s) {
            $kunci = -1;
            foreach ($s['opsi'] as $j => $o) {
                if ($o['kunci']) {
                    $kunci = $j;
                    break;
                }
            }

            return [
                'teks'  => self::polos($s['teks']),
                'opsi'  => array_map(static fn ($o) => self::polos($o['teks']), $s['opsi']),
                'kunci' => $kunci,
                'img'   => self::jumlahGambar($s['teks'] . implode('', array_column($s['opsi'], 'teks'))),
            ];
        }, $moodle);

        // Semua pasangan dengan kemiripan cukup, lalu dipilih rakus dari yang paling mirip.
        $calon = [];
        foreach ($k as $i => $a) {
            $ka = $a['teks'] . ' ' . implode(' ', $a['opsi']);
            foreach ($m as $j => $b) {
                if ($a['teks'] === $b['teks'] && $a['opsi'] === $b['opsi']) {
                    $calon[] = [101.0, $i, $j];  // sama persis: didahulukan
                    continue;
                }
                similar_text($ka, $b['teks'] . ' ' . implode(' ', $b['opsi']), $p);
                if ($p >= self::MIRIP_MIN) {
                    $calon[] = [$p, $i, $j];
                }
            }
        }
        usort($calon, static fn ($x, $y) => $y[0] <=> $x[0]);
        $pasK = $pasM = [];
        foreach ($calon as [, $i, $j]) {
            if (! isset($pasK[$i]) && ! isset($pasM[$j])) {
                $pasK[$i] = $j;
                $pasM[$j] = $i;
            }
        }

        $baris   = [];
        $ringkas = ['sama' => 0, 'beda' => 0, 'kunci' => 0, 'hilang' => 0, 'tambahan' => 0];
        foreach ($kiriman as $i => $s) {
            $j = $pasK[$i] ?? null;
            if ($j === null) {
                $baris[] = ['k' => $i, 'm' => null, 'status' => 'hilang', 'catatan' => ['Tidak ditemukan di quiz Moodle'], 'opsiBeda' => []];
                $ringkas['hilang']++;
                continue;
            }
            [$a, $b]  = [$k[$i], $m[$j]];
            $catatan  = [];
            $opsiBeda = [];
            if ($a['teks'] !== $b['teks']) {
                $catatan[] = 'Teks soal berbeda';
            }
            if (count($a['opsi']) !== count($b['opsi'])) {
                $catatan[] = 'Jumlah opsi berbeda (' . count($a['opsi']) . ' → ' . count($b['opsi']) . ')';
            }
            foreach ($a['opsi'] as $n => $o) {
                if (($b['opsi'][$n] ?? null) !== $o) {
                    $opsiBeda[] = $n;
                }
            }
            if ($opsiBeda !== []) {
                $catatan[] = 'Opsi ' . implode(', ', array_map(static fn ($n) => chr(65 + $n), $opsiBeda)) . ' berbeda';
            }
            // Kunci dibandingkan menurut isi opsinya (bukan huruf), lalu hurufnya.
            $kunciA = $a['opsi'][$a['kunci']] ?? null;
            $kunciB = $b['opsi'][$b['kunci']] ?? null;
            $kunciBeda = $kunciA !== $kunciB || $a['kunci'] !== $b['kunci'];
            if ($kunciBeda) {
                $catatan[] = 'Kunci berbeda (' . chr(65 + $a['kunci']) . ' → ' . ($b['kunci'] >= 0 ? chr(65 + $b['kunci']) : 'tidak ada') . ')';
            }
            if ($a['img'] !== $b['img']) {
                $catatan[] = 'Jumlah gambar berbeda (' . $a['img'] . ' → ' . $b['img'] . ')';
            }
            $status = $kunciBeda ? 'kunci' : ($catatan === [] ? 'sama' : 'beda');
            $ringkas[$status]++;
            $baris[] = ['k' => $i, 'm' => $j, 'status' => $status, 'catatan' => $catatan, 'opsiBeda' => $opsiBeda];
        }
        foreach ($moodle as $j => $s) {
            if (! isset($pasM[$j])) {
                $baris[] = ['k' => null, 'm' => $j, 'status' => 'tambahan', 'catatan' => ['Tidak ada di kiriman guru'], 'opsiBeda' => []];
                $ringkas['tambahan']++;
            }
        }

        return ['baris' => $baris, 'ringkas' => $ringkas];
    }

    /**
     * Teks polos untuk dibandingkan: tanpa tag/gambar, entitas diurai, huruf kecil, dan TANPA spasi sama sekali
     * (pemisah paragraf/baris RTF vs <p> Moodle tidak dianggap perbedaan).
     */
    public static function polos(string $html): string
    {
        $t = preg_replace('/<img\b[^>]*>/i', ' ', $html);
        $t = preg_replace('/<(br|\/p|\/div|\/li)\b[^>]*>/i', ' ', (string) $t);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{A0}", ' ', $t);

        return mb_strtolower((string) preg_replace('/\s+/u', '', $t));
    }

    private static function jumlahGambar(string $html): int
    {
        return preg_match_all('/<img\b/i', $html);
    }
}
