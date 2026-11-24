<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$katId  = $kategori !== null ? (int) $kategori['id'] : 0;
$quizId = $quiz !== null ? (int) $quiz['id'] : 0;
$query  = http_build_query(array_filter(['kat' => $katId ?: '', 'quiz' => $quizId ?: '', 'tingkat' => $tingkat], static fn ($v) => $v !== ''));
?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= esc($error) ?></div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-body">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('dokumen/rekap_simulasi') ?>">
            <div style="min-width: 200px">
                <label class="form-label small mb-1" for="fKat">Kategori ujian</label>
                <select class="form-select form-select-sm" id="fKat" name="kat" onchange="this.form.quiz.value='';this.form.submit()">
                    <?php if ($kategori === null): ?><option value="">Pilih kategori</option><?php endif ?>
                    <?php foreach ($kategoriList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $katId ? 'selected' : '' ?>><?= esc($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="flex-grow-1" style="min-width: 220px">
                <label class="form-label small mb-1" for="fQuiz">Mapel</label>
                <select class="form-select form-select-sm" id="fQuiz" name="quiz">
                    <option value="">Semua mapel di kategori ini</option>
                    <?php foreach ($quizList as $q): ?>
                        <option value="<?= (int) $q['id'] ?>" <?= (int) $q['id'] === $quizId ? 'selected' : '' ?>><?= esc($q['course_short'] . ' · ' . $q['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat, 'autoSubmit' => false]) ?>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Tampilkan</button>
            <?php if ($kategori !== null && $kelas !== []): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('dokumen/rekap_simulasi/export') . '?' . $query ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel</a>
            <?php endif ?>
        </form>
        <p class="small text-muted mb-0 mt-2">Siswa diambil dari Data CBTAdmin. <strong>Sudah ikut</strong> = ada percobaan yang terkirim;
            <strong>Belum selesai</strong> = mulai tetapi belum/tidak terkirim; <strong>Belum ikut</strong> = belum mulai sama sekali
            (termasuk siswa yang akun Moodle-nya belum ada). Dengan "Semua mapel", siswa dihitung sudah ikut bila mengerjakan salah satu mapel di kategori ini.</p>
    </div>
</div>

<?php if ($kategori === null): ?>
    <div class="alert alert-info">Belum ada kategori ujian. Buat kategori simulasi (mis. <strong>SIMULASI</strong>) di Admin Tools → Kategori Mapel.</div>
<?php elseif ($kelas === []): ?>
    <div class="alert alert-info">Tidak ada siswa di Data CBTAdmin untuk tingkatan ini.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Jumlah siswa', $total['total'], 'stat-netral'], ['Sudah ikut', $total['selesai'] . ' (' . $total['persen'] . '%)', 'stat-ok'],
                        ['Belum selesai', $total['proses'], 'stat-proses'], ['Belum ikut', $total['belum'], 'stat-awas']] as [$lbl, $nilai, $kls]): ?>
            <div class="col-6 col-lg-3">
                <div class="stat-card <?= $kls ?>">
                    <div class="stat-label"><?= $lbl ?></div>
                    <div class="stat-value"><?= esc((string) $nilai) ?></div>
                </div>
            </div>
        <?php endforeach ?>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">
            <?= esc($quiz !== null ? $quiz['name'] : $kategori['name'] . ' (semua mapel)') ?>
            <span class="small text-muted fw-normal ms-1"><?= $tingkat !== '' ? 'Kelas ' . esc($tingkat) : 'Semua tingkatan' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>Kelas</th><th class="text-center">Jumlah</th><th class="text-center">Sudah ikut</th>
                    <th class="text-center">Belum selesai</th><th class="text-center">Belum ikut</th><th style="min-width:9rem">Persentase</th><th>Yang sudah</th><th>Yang belum</th>
                </tr></thead>
                <tbody>
                <?php foreach ($kelas as $k): ?>
                    <?php $kurang = array_values(array_filter($k['siswa'], static fn ($s) => $s['status'] !== 'selesai')); ?>
                    <?php $sudah = array_values(array_filter($k['siswa'], static fn ($s) => $s['status'] === 'selesai')); ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($k['kelas']) ?></td>
                        <td class="text-center"><?= $k['total'] ?></td>
                        <td class="text-center text-success fw-semibold"><?= $k['selesai'] ?></td>
                        <td class="text-center"><?= $k['proses'] ?: '–' ?></td>
                        <td class="text-center <?= $k['belum'] ? 'text-danger fw-semibold' : '' ?>"><?= $k['belum'] ?: '–' ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:.5rem" role="progressbar" aria-label="Sudah ikut kelas <?= esc($k['kelas'], 'attr') ?>" aria-valuenow="<?= $k['persen'] ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar <?= $k['persen'] === 100 ? 'bg-success' : '' ?>" style="width: <?= $k['persen'] ?>%"></div>
                                </div>
                                <span class="small"><?= $k['persen'] ?>%</span>
                            </div>
                        </td>
                        <td class="small">
                            <?php if ($sudah === []): ?>
                                <span class="text-muted">–</span>
                            <?php else: ?>
                                <details>
                                    <summary class="text-success"><?= count($sudah) ?> siswa</summary>
                                    <ol class="mb-0 mt-1 ps-3">
                                        <?php foreach ($sudah as $s): ?>
                                            <li><?= esc($s['nama']) ?> <span class="text-muted">(<?= esc($s['nomor_peserta'] ?: $s['username']) ?><?= $s['ruang'] !== '' ? ', ' . esc($s['ruang']) : '' ?><?= $s['selesai_pada'] ? ', ' . date('d/m H:i', $s['selesai_pada']) : '' ?>)</span></li>
                                        <?php endforeach ?>
                                    </ol>
                                </details>
                            <?php endif ?>
                        </td>
                        <td class="small">
                            <?php if ($kurang === []): ?>
                                <span class="text-success"><i class="bi bi-check2-all me-1"></i>Semua sudah ikut</span>
                            <?php else: ?>
                                <details>
                                    <summary><?= count($kurang) ?> siswa</summary>
                                    <ol class="mb-0 mt-1 ps-3">
                                        <?php foreach ($kurang as $s): ?>
                                            <li><?= esc($s['nama']) ?> <span class="text-muted">(<?= esc($s['nomor_peserta'] ?: $s['username']) ?><?= $s['ruang'] !== '' ? ', ' . esc($s['ruang']) : '' ?>)</span>
                                                <?php if ($s['status'] === 'proses'): ?><span class="badge text-bg-warning ms-1">belum selesai</span><?php endif ?>
                                                <?php if ($s['keterangan'] === 'Akun Moodle belum ada'): ?><span class="badge text-bg-secondary ms-1">akun Moodle belum ada</span><?php endif ?>
                                            </li>
                                        <?php endforeach ?>
                                    </ol>
                                </details>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot><tr class="fw-semibold">
                    <td>Total</td><td class="text-center"><?= $total['total'] ?></td><td class="text-center text-success"><?= $total['selesai'] ?></td>
                    <td class="text-center"><?= $total['proses'] ?></td><td class="text-center"><?= $total['belum'] ?></td><td><?= $total['persen'] ?>%</td><td></td><td></td>
                </tr></tfoot>
            </table>
        </div>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
