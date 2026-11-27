<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;

/**
 * Soal → Acak Soal (khusus administrator): atur acak urutan soal dan acak opsi jawaban per quiz Moodle.
 * Perubahan hanya berlaku untuk attempt yang dimulai sesudahnya.
 */
class AcakSoal extends PanelController
{
    public function index(): string
    {
        $katId    = (int) $this->request->getGet('kat');
        $kategori = [];
        $rows     = [];
        $error    = false;
        try {
            $kategori = array_values(array_filter(Moodle::categories(), static fn ($c) => (int) $c['parent'] === 0));
            $rows     = Moodle::quizzes($katId > 0 ? $katId : null);
            $ids      = array_map('intval', array_column($rows, 'id'));
            if ($ids !== []) {
                $in    = implode(',', $ids);
                $db    = Moodle::db();
                $acak  = array_column($db->query('SELECT quizid, MAX(shufflequestions) AS v FROM ' . Moodle::t('quiz_sections') . " WHERE quizid IN ({$in}) GROUP BY quizid")->getResultArray(), 'v', 'quizid');
                $opsi  = array_column($db->query('SELECT id, shuffleanswers AS v FROM ' . Moodle::t('quiz') . " WHERE id IN ({$in})")->getResultArray(), 'v', 'id');
                $soal  = array_column($db->query('SELECT quizid, COUNT(*) AS n FROM ' . Moodle::t('quiz_slots') . " WHERE quizid IN ({$in}) GROUP BY quizid")->getResultArray(), 'n', 'quizid');
                $jalan = array_column($db->query('SELECT quiz, COUNT(*) AS n FROM ' . Moodle::t('quiz_attempts') . " WHERE quiz IN ({$in}) AND preview = 0 AND state IN ('inprogress', 'overdue') GROUP BY quiz")->getResultArray(), 'n', 'quiz');
                foreach ($rows as &$r) {
                    $r['acak_soal'] = (int) ($acak[$r['id']] ?? 0) === 1;
                    $r['acak_opsi'] = (int) ($opsi[$r['id']] ?? 0) === 1;
                    $r['jml_soal']  = (int) ($soal[$r['id']] ?? 0);
                    $r['berjalan']  = (int) ($jalan[$r['id']] ?? 0);
                }
                unset($r);
                usort($rows, static fn ($a, $b) => [$a['category_name'], $a['course_short']] <=> [$b['category_name'], $b['course_short']]);
            }
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Acak Soal: ' . $e->getMessage());
        }

        return $this->render('soal/acak', [
            'title'    => 'Acak Soal',
            'kategori' => $kategori,
            'katId'    => $katId,
            'rows'     => $rows,
            'error'    => $error,
        ]);
    }

    public function simpan()
    {
        $back = site_url('acak_soal') . ((int) $this->request->getPost('kat') > 0 ? '?kat=' . (int) $this->request->getPost('kat') : '');
        $ids  = array_values(array_filter(array_map('intval', (array) $this->request->getPost('quiz'))));
        $pil  = static fn ($v): ?int => in_array($v, ['0', '1'], true) ? (int) $v : null;
        $soal = $pil($this->request->getPost('soal'));
        $opsi = $pil($this->request->getPost('opsi'));
        if ($ids === []) {
            return redirect()->to($back)->with('error', 'Centang minimal satu mapel.');
        }
        if ($soal === null && $opsi === null) {
            return redirect()->to($back)->with('error', 'Pilih pengaturan Acak soal atau Acak opsi.');
        }
        try {
            $r = MoodleBridge::call('quiz_shuffle', ['quizids' => $ids, 'soal' => $soal, 'opsi' => $opsi])[0] ?? [];
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal menyimpan: ' . $e->getMessage());
        }
        $label = static fn (?int $v): string => $v === null ? 'tetap' : ($v ? 'acak' : 'urut');
        ActivityLog::add('acak_soal', count($ids) . ' quiz, soal ' . $label($soal) . ', opsi ' . $label($opsi));

        return redirect()->to($back)->with('success', (int) ($r['diubah'] ?? 0) . ' dari ' . count($ids) . ' mapel diubah. Berlaku untuk siswa yang mulai mengerjakan sesudah ini.');
    }
}
