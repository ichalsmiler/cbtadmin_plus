<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$query = http_build_query(array_filter(['tab' => 'ujian', 'quiz' => $quizId ?: '', 'ruang' => $ruangId, 'tingkat' => $tingkat], static fn ($v) => $v !== ''));
$total = array_sum(array_column($ruang, 'total'));
$hadir = array_sum(array_column($ruang, 'hadir'));
?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<?= view('partials/tab_kehadiran', ['tab' => 'ujian']) ?>
<div class="card mb-3">
    <div class="card-body">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('dokumen/laporan_kehadiran') ?>">
            <input type="hidden" name="tab" value="ujian">
            <div class="flex-grow-1" style="min-width: 240px">
                <label class="form-label small mb-1" for="fQuiz">Mata pelajaran (quiz)</label>
                <select class="form-select form-select-sm" id="fQuiz" name="quiz" required onchange="this.form.submit()">
                    <option value="">Pilih quiz</option>
                    <?php foreach ($quizzes as $q): ?>
                        <option value="<?= (int) $q['id'] ?>" <?= (int) $q['id'] === $quizId ? 'selected' : '' ?>><?= esc($q['course_short'] . ' · ' . $q['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
            <div>
                <label class="form-label small mb-1" for="fRuang">Ruang</label>
                <select class="form-select form-select-sm" id="fRuang" name="ruang" onchange="this.form.submit()">
                    <option value="">Semua ruang</option>
                    <option value="0" <?= $ruangId === '0' ? 'selected' : '' ?>>(tanpa ruang)</option>
                    <?php foreach ($ruangList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= isset($r['kelas']) ? 'data-kelas="' . esc((string) $r['kelas'], 'attr') . '"' : '' ?> <?= (string) $r['id'] === $ruangId ? 'selected' : '' ?>><?= esc($r['nama']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($quiz): ?>
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold"><?= esc($quiz['name']) ?></span>
            <span class="small text-muted">Hadir <?= $hadir ?> dari <?= $total ?> peserta</span>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('dokumen/laporan_kehadiran/export') . '?' . $query ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('dokumen/laporan_kehadiran/berita_acara') . '?' . $query ?>" target="_blank" data-pdf><i class="bi bi-file-earmark-pdf me-1"></i>Berita Acara PDF</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Ruang</th><th>Sesi</th><th>Pengawas</th><th class="text-center">Terdaftar</th><th class="text-center">Hadir</th><th class="text-center">Tidak hadir</th><th class="text-end">Kehadiran</th><th>Tidak hadir</th></tr></thead>
                <tbody>
                <?php if ($ruang === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada peserta terdaftar untuk quiz ini.</td></tr>
                <?php endif ?>
                <?php foreach ($ruang as $g): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($g['nama']) ?></td>
                        <td><?= esc($g['sesi'] !== '' ? $g['sesi'] : '-') ?></td>
                        <td><?= esc($g['pengawas'] !== '' ? $g['pengawas'] : '-') ?></td>
                        <td class="text-center"><?= $g['total'] ?></td>
                        <td class="text-center"><?= $g['hadir'] ?></td>
                        <td class="text-center"><?= count($g['absen']) ?></td>
                        <td class="text-end"><?= $g['total'] > 0 ? round($g['hadir'] / $g['total'] * 100) : 0 ?>%</td>
                        <td class="small"><?= esc(implode(', ', array_map(static fn ($r) => $r['nama'], $g['absen']))) ?: '–' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">Hadir = peserta punya attempt di quiz ini (sedang mengerjakan atau selesai). Ruang diambil dari Data CBTAdmin.</p>
<?php else: ?>
    <div class="card"><div class="card-body text-muted">Pilih mata pelajaran (quiz) untuk melihat rekap kehadiran per ruang.</div></div>
<?php endif ?>
<?= $this->endSection() ?>
