<?php

namespace App\Libraries;

/**
 * Nilai ujian: rekap semua mapel, hasil satu quiz, berkas Excel/ZIP. Dipakai menu Hasil Ujian dan bot Telegram
 * (/nilai, tanpa request/sesi: semua filter lewat parameter).
 */
class NilaiUjian
{
    /** Parameter bawaan "selesai terlalu cepat" (persen batas waktu, persen median, nilai minimal). */
    public const PARAM = ['pw' => 25, 'pm' => 50, 'pn' => 80];

    /**
     * Rekap nilai semua mapel: siswa panel (sesuai Tingkatan/Kelas) × quiz yang sudah punya nilai untuk siswa itu.
     * $kategori = id kategori Moodle (mis. hari ujian H1, termasuk subkategorinya) atau null = semua.
     * Quiz simulasi (kategori/kursus/quiz bernama "SIMULASI") tidak ikut.
     *
     * @return array{quiz: list<array>, rows: list<array>, siswa: int}
     */
    public static function rekap(string $kelas, string $tingkat, ?int $kategori = null): array
    {
        $peserta = array_values(array_filter(
            db_connect()->table('peserta')->select('nomor_peserta, nama, kelas, username')->orderBy('kelas')->orderBy('nama')->get()->getResultArray(),
            static fn ($p) => ($kelas === '' || $p['kelas'] === $kelas) && Tingkat::cocok($p['kelas'], $tingkat)
        ));
        if ($peserta === []) {
            return ['quiz' => [], 'rows' => [], 'siswa' => 0];
        }

        $mdb = Moodle::db();
        $ids = array_column($mdb->table(Moodle::t('user'))->select('id, username')->where('deleted', 0)
            ->whereIn('username', array_column($peserta, 'username'))->get()->getResultArray(), 'id', 'username');
        $nilai = [];
        if ($ids !== []) {
            foreach ($mdb->table(Moodle::t('quiz_grades'))->select('quiz, userid, grade')->whereIn('userid', array_values($ids))->get()->getResultArray() as $g) {
                $nilai[(int) $g['userid']][(int) $g['quiz']] = (float) $g['grade'];
            }
        }
        $adaNilai = [];
        foreach ($nilai as $per) {
            $adaNilai += array_fill_keys(array_keys($per), true);
        }
        $quiz = array_values(array_filter(Moodle::quizzes($kategori), static fn ($q) => isset($adaNilai[(int) $q['id']])
            && stripos($q['name'] . ' ' . $q['course_short'] . ' ' . $q['course_name'] . ' ' . $q['category_name'], 'simulasi') === false));
        usort($quiz, static fn ($a, $b) => [$a['course_short'], $a['name']] <=> [$b['course_short'], $b['name']]);
        $ikut = array_fill_keys(array_map('intval', array_column($quiz, 'id')), true);

        $rows = [];
        foreach ($peserta as $p) {
            $per        = $nilai[(int) ($ids[$p['username']] ?? 0)] ?? [];
            $ujian      = array_intersect_key($per, $ikut);
            $p['nilai'] = $ujian;
            $p['rata']  = $ujian ? array_sum($ujian) / count($ujian) : null;
            $rows[]     = $p;
        }

        return ['quiz' => $quiz, 'rows' => $rows, 'siswa' => count($peserta)];
    }

    /**
     * Hasil satu quiz: baris peserta (sesuai Kelas/Tingkatan) + nilai, durasi, tanda curiga, statistik.
     * Pengecualian dari Moodle diteruskan ke pemanggil.
     *
     * @return array{quiz: ?array, rows: list<array>, stats: ?array, cepat: list<array>, median: ?int}
     */
    public static function hasil(int $quizId, string $kelas = '', string $tingkat = '', string $ruangId = '', array $param = self::PARAM): array
    {
        $data = ['quiz' => $quizId > 0 ? QuizStatus::findQuiz($quizId) : null, 'rows' => [], 'stats' => null, 'cepat' => [], 'median' => null];
        if (! $data['quiz']) {
            return $data;
        }

        // Nilai akhir menurut metode penilaian quiz (tertinggi/rata-rata/dst.) dari gradebook quiz.
        $grades = array_column(Moodle::db()->table(Moodle::t('quiz_grades'))
            ->select('userid, grade')->where('quiz', $quizId)->get()->getResultArray(), 'grade', 'userid');

        $semua = QuizStatus::forQuiz($data['quiz']);
        // Median durasi dihitung dari semua peserta quiz ini yang sudah selesai (tidak terpengaruh filter).
        $durasiSemua = [];
        foreach ($semua as $s) {
            if ($s['status'] === 'finished' && $s['timefinish'] > $s['timestart']) {
                $durasiSemua[] = $s['timefinish'] - $s['timestart'];
            }
        }
        sort($durasiSemua);
        $n      = count($durasiSemua);
        $median = $n ? ($n % 2 ? $durasiSemua[intdiv($n, 2)] : intdiv($durasiSemua[$n / 2 - 1] + $durasiSemua[$n / 2], 2)) : null;
        $data['median'] = $median;
        $limit = (int) $data['quiz']['timelimit'];
        $skala = (float) $data['quiz']['grade'];

        $rows = QuizStatus::filter($semua, $ruangId, $kelas, $tingkat);
        foreach ($rows as &$r) {
            $r['nilai']  = isset($grades[$r['userid']]) ? (float) $grades[$r['userid']] : null;
            $r['durasi'] = $r['status'] === 'finished' && $r['timefinish'] > 0 ? $r['timefinish'] - $r['timestart'] : null;
            // Tanda curiga: durasi pendek (dibanding batas waktu atau median) dan/atau nilai tinggi.
            $r['curiga'] = null;
            if ($r['durasi'] !== null) {
                $cepat = ($limit > 0 && $r['durasi'] < $limit * $param['pw'] / 100)
                    || ($median && $n >= 5 && $r['durasi'] < $median * $param['pm'] / 100);
                $tinggi = $r['nilai'] !== null && $skala > 0 && $r['nilai'] >= $skala * $param['pn'] / 100;
                $r['curiga'] = $cepat ? ($tinggi ? 'tinggi' : 'sedang') : null;
            }
        }
        unset($r);
        $data['cepat'] = array_values(array_filter($rows, static fn ($r) => $r['curiga'] !== null));
        usort($data['cepat'], static fn ($a, $b) => [$a['curiga'] === 'tinggi' ? 0 : 1, $a['durasi']] <=> [$b['curiga'] === 'tinggi' ? 0 : 1, $b['durasi']]);
        usort($rows, static fn ($a, $b) => [$a['kelas'], $a['nama']] <=> [$b['kelas'], $b['nama']]);
        $data['rows'] = $rows;

        $nilai = array_values(array_filter(array_column($rows, 'nilai'), static fn ($v) => $v !== null));
        $data['stats'] = [
            'peserta' => count($rows),
            'selesai' => count(array_filter($rows, static fn ($r) => $r['status'] === 'finished')),
            'dinilai' => count($nilai),
            'rata'    => $nilai ? array_sum($nilai) / count($nilai) : null,
            'min'     => $nilai ? min($nilai) : null,
            'maks'    => $nilai ? max($nilai) : null,
            'skala'   => (float) $data['quiz']['grade'],
        ];

        return $data;
    }

    /**
     * Excel nilai satu quiz (baris dari hasil()). Dengan $quizId: lembar kedua "Analisis Soal" berisi tingkat
     * kesukaran tiap nomor (siswa yang sama dengan lembar nilai) dan ringkasan Mudah/Sedang/Sukar.
     */
    public static function excel(array $list, ?int $quizId = null): string
    {
        $rows = [];
        foreach ($list as $i => $r) {
            $rows[] = [
                $i + 1, $r['nomor_peserta'], $r['username'], $r['nama'], $r['kelas'], $r['ruang'] ?? '',
                self::label($r['status']), $r['nilai'] === null ? '' : number_format($r['nilai'], 2, ',', ''),
                $r['timestart'] ? date('d/m/Y H:i', $r['timestart']) : '', $r['timefinish'] ? date('d/m/Y H:i', $r['timefinish']) : '',
                $r['durasi'] !== null ? self::durasi($r['durasi']) : '',
            ];
        }

        $nilai = ['Nilai', ['no', 'nomor_peserta', 'username', 'nama', 'kelas', 'ruang', 'status', 'nilai', 'mulai', 'selesai', 'durasi'], $rows];
        if (! $quizId) {
            return Excel::buildSheets([$nilai]);
        }

        return Excel::buildSheets([$nilai, self::lembarAnalisis($quizId, array_column($list, 'userid'))]);
    }

    /** Lembar "Analisis Soal": indeks kesukaran (P) dan tingkat per nomor, lalu jumlah Mudah/Sedang/Sukar. */
    private static function lembarAnalisis(int $quizId, array $userIds): array
    {
        $an   = AnalisisSoal::analisis(AnalisisSoal::jawaban($quizId, $userIds));
        $rows = [];
        foreach ($an['butir'] as $b) {
            $teks   = trim(preg_replace('/\s+/u', ' ', (string) ($b['teks'] !== '' ? $b['teks'] : $b['nama'])));
            $rows[] = [
                $b['no'], mb_strlen($teks) > 150 ? mb_substr($teks, 0, 150) . '…' : $teks, $b['benar'], $b['salah'], $b['kosong'],
                $b['p'] === null ? '' : number_format($b['p'], 2, ',', ''), $b['tingkat'],
            ];
        }
        $rows[] = [];
        $rows[] = ['', 'Siswa dianalisis', $an['n']];
        foreach ($an['ringkas'] as $tingkat => $jumlah) {
            $rows[] = ['', 'Soal ' . $tingkat, $jumlah];
        }
        $rows[] = ['', 'Indeks kesukaran: ≤ 0,30 Sukar · 0,31–0,70 Sedang · > 0,70 Mudah'];

        return ['Analisis Soal', ['no', 'soal', 'benar', 'salah', 'kosong', 'indeks_kesukaran', 'tingkat'], $rows];
    }

    /**
     * ZIP nilai tiap quiz di $quiz (isi sama dengan unduhan satu quiz). Bawaan: PDF + Excel per quiz di akar ZIP.
     * $perTingkat (bot /nilai): Excel saja, dalam folder tingkatan course: "X/Bhs Indonesia.xlsx" (tanpa tingkatan
     * dikenali → folder LAINNYA; nama sama → ditambah nama quiz).
     *
     * @return array{isi: string, jumlah: int}
     */
    public static function zip(array $quiz, string $kelas, string $tingkat, bool $perTingkat = false): array
    {
        @set_time_limit(0);
        $tmp = tempnam(WRITEPATH . 'cache', 'nilai');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);

            throw new \RuntimeException('Berkas ZIP tidak bisa dibuat.');
        }
        $school = $perTingkat ? null : School::info();
        $bersih = static fn (string $s) => trim(preg_replace('/[^A-Za-z0-9]+/', $perTingkat ? ' ' : '_', $s), ' _');
        $jumlah = 0;
        $dipakai = [];
        foreach ($quiz as $q) {
            $d = self::hasil((int) $q['id'], $kelas, $tingkat);
            if (! $d['quiz'] || $d['rows'] === []) {
                continue;
            }
            if ($perTingkat) {
                $t     = CekSoal::tingkatCourse((string) $q['course_short'], (string) $q['course_name']);
                $mapel = $bersih((string) preg_replace('/\s+(XII|XI|X)\s*$/i', '', (string) $q['course_name'])) ?: $bersih($q['course_short']);
                $nama = ($t !== '' ? $t : 'LAINNYA') . '/' . $mapel;
                if (isset($dipakai[$nama])) {
                    $nama .= ' ' . $bersih($q['name']) . ' ' . $q['id'];
                }
                $dipakai[$nama] = true;
            } else {
                $nama = $bersih($q['course_short'] . ' ' . $q['name']);
                $zip->addFromString($nama . '.pdf', Pdf::render('pdf/hasil', $d + ['school' => $school]));
            }
            $zip->addFromString($nama . '.xlsx', self::excel($d['rows'], (int) $q['id']));
            $jumlah++;
        }
        $zip->close();
        $isi = (string) file_get_contents($tmp);
        @unlink($tmp);

        return ['isi' => $isi, 'jumlah' => $jumlah];
    }

    public static function label(string $status): string
    {
        return ['finished' => 'Selesai', 'inprogress' => 'Mengerjakan', 'notstarted' => 'Belum mulai'][$status];
    }

    public static function durasi(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
