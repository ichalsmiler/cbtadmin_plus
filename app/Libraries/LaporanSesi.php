<?php

namespace App\Libraries;

/**
 * Laporan evaluasi per sesi ujian ke Telegram admin, JEDA detik setelah sesi dimatikan (Nonaktifkan sesi atau pindah
 * ke sesi lain). Dipanggil cron cbt:token tiap menit: pantau() mencatat sesi yang sedang aktif (setelan lapor_sesi:
 * id, label, mulai, kategori) dan saat sesi berganti/mati memasukkannya ke antrean (lapor_antrian) untuk dikirim.
 * Isi: kehadiran (peserta sesi vs yang mengerjakan, tidak hadir dari absensi), per mapel (ikut, belum terkirim,
 * nilai rata-rata/min/maks, durasi, selesai terlalu cepat) dan soal yang kuncinya patut dicek.
 */
class LaporanSesi
{
    public const JEDA = 900;

    /** Selesai < persentase batas waktu ini dianggap terlalu cepat. */
    private const CEPAT = 0.25;

    public static function pantau(): void
    {
        $kini  = time();
        $id    = ActiveTest::sesiId();
        $catat = json_decode(Settings::get('lapor_sesi', '{}'), true) ?: [];

        if (isset($catat['id']) && (int) $catat['id'] !== $id) {
            $antri   = json_decode(Settings::get('lapor_antrian', '[]'), true) ?: [];
            $antri[] = $catat + ['selesai' => $kini, 'kirim' => $kini + self::JEDA];
            Settings::set('lapor_antrian', json_encode(array_slice($antri, -10)));
            Settings::set('lapor_sesi', '{}');
            $catat = [];
        }
        if ($id !== 0 && ! isset($catat['id'])) {
            $sesi = ActiveTest::sesiAktif();
            Settings::set('lapor_sesi', json_encode([
                'id' => $id, 'label' => $sesi['label'] ?? 'Sesi', 'mulai' => $kini, 'kategori' => ActiveTest::categoryId(),
            ]));
        }

        $antri = json_decode(Settings::get('lapor_antrian', '[]'), true) ?: [];
        $sisa  = [];
        foreach ($antri as $item) {
            if ((int) $item['kirim'] > $kini) {
                $sisa[] = $item;

                continue;
            }
            if (! Telegram::siap()) {
                continue;  // bot belum diatur: laporan dibuang, tidak menumpuk
            }
            try {
                $hasil = Telegram::kirimAdmin(self::susun($item));
                if (! ($hasil['ok'] ?? false) && (int) $item['kirim'] > $kini - 3600) {
                    $sisa[] = $item;  // Telegram gagal: coba lagi menit berikutnya, paling lama 1 jam
                }
            } catch (\Throwable $e) {
                log_message('error', 'Laporan sesi: ' . $e->getMessage());
            }
        }
        if (count($sisa) !== count($antri)) {
            Settings::set('lapor_antrian', json_encode($sisa));
        }
    }

    /** Teks laporan satu sesi. $item: id, label, mulai, selesai (timestamp). */
    public static function susun(array $item): string
    {
        $mulai   = (int) $item['mulai'];
        $selesai = (int) $item['selesai'];
        $db      = Moodle::db();
        $qa      = Moodle::t('quiz_attempts');
        $q       = Moodle::t('quiz');
        $c       = Moodle::t('course');
        $u       = Moodle::t('user');

        $teks = '📊 Evaluasi ' . $item['label'] . "\n" . CekSoal::tanggal(date('Y-m-d H:i:s', $mulai)) . '–' . date('H.i', $selesai) . ' WITA';

        // Attempt yang dimulai selama sesi (5 menit sebelum sesi tercatat ikut, karena pantau() berjalan per menit).
        $rows = $db->query(
            "SELECT a.id, a.quiz, a.userid, a.state, a.timestart, a.timefinish, a.sumgrades, q.sumgrades AS maks, q.grade,
                    q.timelimit, c.fullname, u.username
               FROM {$qa} a JOIN {$q} q ON q.id = a.quiz JOIN {$c} c ON c.id = q.course JOIN {$u} u ON u.id = a.userid
              WHERE a.preview = 0 AND a.timestart BETWEEN ? AND ?
              ORDER BY c.sortorder",
            [$mulai - 300, $selesai]
        )->getResultArray();

        // Kehadiran: peserta sesi ini (Semua Sesi = semua peserta) dibanding yang mengerjakan.
        $pdb = db_connect()->table('peserta p')->select('p.id, p.username')->join('ruang r', 'r.id = p.ruang_id', 'left');
        if ((int) $item['id'] > 0) {
            $pdb->where('COALESCE(p.sesi_id, r.sesi_id)', (int) $item['id'], false);
        }
        $peserta = $pdb->get()->getResultArray();
        $ikut    = array_flip(array_column($rows, 'username'));
        $belum   = array_filter($peserta, static fn ($p) => ! isset($ikut[$p['username']]));
        $tidak   = $peserta === [] ? 0 : db_connect()->table('absensi')->where('tanggal', date('Y-m-d', $mulai))
            ->where('status', Absensi::TIDAK_HADIR)->whereIn('peserta_id', array_column($peserta, 'id'))->countAllResults();
        $teks .= "\n\n👥 Peserta: " . count($peserta) . ' · mengerjakan ' . (count($peserta) - count($belum))
            . ' · tidak mengerjakan ' . count($belum) . ($tidak > 0 ? ' (' . $tidak . ' ditandai tidak hadir)' : '');

        if ($rows === []) {
            return $teks . "\n\nTidak ada attempt selama sesi ini.";
        }

        $perQuiz = [];
        foreach ($rows as $r) {
            $perQuiz[(int) $r['quiz']][] = $r;
        }
        foreach ($perQuiz as $quizId => $list) {
            $nilai = $durasi = [];
            $buka  = $cepat = 0;
            foreach ($list as $r) {
                if ($r['state'] !== 'finished') {
                    $buka++;

                    continue;
                }
                if ((float) $r['maks'] > 0 && $r['sumgrades'] !== null) {
                    $nilai[] = (float) $r['sumgrades'] / (float) $r['maks'] * 100;
                }
                $lama     = max(0, (int) $r['timefinish'] - (int) $r['timestart']);
                $durasi[] = $lama / 60;
                if ((int) $r['timelimit'] > 0 && $lama < (int) $r['timelimit'] * self::CEPAT) {
                    $cepat++;
                }
            }
            $teks .= "\n\n📚 " . $list[0]['fullname'] . ': ' . count($list) . ' attempt';
            if ($nilai !== []) {
                $teks .= "\n   Nilai rata-rata " . round(array_sum($nilai) / count($nilai)) . ' (min ' . round(min($nilai)) . ', maks ' . round(max($nilai)) . ')'
                    . ' · durasi ' . round(array_sum($durasi) / count($durasi)) . ' mnt';
            }
            if ($buka > 0) {
                $teks .= "\n   ⚠️ " . $buka . ' belum terkirim (dikirim otomatis saat waktu habis)';
            }
            if ($cepat > 0) {
                $teks .= "\n   ⚡ " . $cepat . ' selesai < ' . round(self::CEPAT * 100) . '% waktu';
            }
            foreach (self::kunciCuriga($quizId, array_map('intval', array_column($list, 'userid'))) as $baris) {
                $teks .= "\n   🔑 " . $baris;
            }
        }

        return mb_substr($teks, 0, 4000);
    }

    /**
     * Soal yang kuncinya patut dicek: daya beda negatif (siswa pintar lebih banyak salah), atau sangat sukar (P ≤ 0,25)
     * dengan satu jawaban salah dipilih ≥ 40% siswa. Maksimal 5 soal per mapel.
     *
     * @return list<string>
     */
    public static function kunciCuriga(int $quizId, array $userIds): array
    {
        $data = AnalisisSoal::jawaban($quizId, $userIds);
        if (count($data['jawaban']) < 10) {
            return [];  // terlalu sedikit siswa untuk disimpulkan
        }
        $hasil = [];
        foreach (AnalisisSoal::analisis($data)['butir'] as $b) {
            if ($b['p'] === null) {
                continue;
            }
            $salah = [];
            foreach ($data['jawaban'] as $per) {
                $j = $per[$b['slot']] ?? null;
                if ($j !== null && AnalisisSoal::kode($j) === 'S' && $j['respon'] !== '') {
                    $salah[$j['respon']] = ($salah[$j['respon']] ?? 0) + 1;
                }
            }
            arsort($salah);
            $populer = (string) array_key_first($salah);
            $porsi   = $salah === [] ? 0 : reset($salah) / max(1, count($data['jawaban']));
            if (($b['d'] !== null && $b['d'] < 0) || ($b['p'] <= 0.25 && $porsi >= 0.40)) {
                $hasil[] = 'No. ' . $b['slot'] . ': benar ' . round($b['p'] * 100) . '%'
                    . ($populer !== '' ? ', ' . round($porsi * 100) . '% memilih "' . mb_strimwidth(trim(strip_tags($populer)), 0, 40, '…') . '"' : '')
                    . ($b['d'] !== null && $b['d'] < 0 ? ', daya beda negatif' : '') . ' → cek kunci';
            }
        }

        return array_slice($hasil, 0, 5);
    }
}
