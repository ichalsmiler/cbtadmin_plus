<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (! $nonaktif): ?><meta http-equiv="refresh" content="60"><?php endif ?>
    <title>Dashboard Simulasi<?= $sekolah !== '' ? ' · ' . esc($sekolah) : '' ?></title>
    <?= view('partials/favicon') ?>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc($version) ?>">
</head>
<body class="publik-page">
<main class="container-xl py-4">
    <header class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Dashboard Simulasi</h1>
            <?php if ($sekolah !== ''): ?><div class="text-muted"><?= esc($sekolah) ?></div><?php endif ?>
        </div>
        <?php if (! $nonaktif): ?>
            <div class="small text-muted">Diperbarui <?= date('H:i:s', $diperbarui) ?> · halaman memuat ulang tiap 1 menit</div>
        <?php endif ?>
    </header>

<?php if ($nonaktif): ?>
    <div class="alert alert-info">Dashboard simulasi sedang tidak dibuka.</div>
<?php else: ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">Data belum bisa dibaca. Coba lagi sebentar lagi.</div>
    <?php endif ?>

    <?php
    $katId  = $kategori !== null ? (int) $kategori['id'] : 0;
    $quizId = $quiz !== null ? (int) $quiz['id'] : 0;
    ?>
    <form class="card card-body mb-3 d-flex flex-row flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('simulasi') ?>">
        <?php if ($kunciKat): ?>
            <div style="min-width: 200px">
                <div class="form-label small mb-1">Kategori ujian</div>
                <div class="fw-semibold py-1"><i class="bi bi-lock me-1 text-muted" aria-hidden="true"></i><?= esc($kategori['name'] ?? '-') ?></div>
            </div>
        <?php else: ?>
            <div style="min-width: 200px">
                <label class="form-label small mb-1" for="fKat">Kategori ujian</label>
                <select class="form-select form-select-sm" id="fKat" name="kat" onchange="if(this.form.quiz)this.form.quiz.value='';this.form.submit()">
                    <?php if ($kategori === null): ?><option value="">Pilih kategori</option><?php endif ?>
                    <?php foreach ($kategoriList as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id'] === $katId ? 'selected' : '' ?>><?= esc($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endif ?>
        <?php if ($kunciQuiz): ?>
            <div class="flex-grow-1" style="min-width: 220px">
                <div class="form-label small mb-1">Mapel</div>
                <div class="fw-semibold py-1"><i class="bi bi-lock me-1 text-muted" aria-hidden="true"></i><?= esc($quiz !== null ? $quiz['name'] : 'Semua mapel') ?></div>
            </div>
        <?php else: ?>
            <div class="flex-grow-1" style="min-width: 220px">
                <label class="form-label small mb-1" for="fQuiz">Mapel</label>
                <select class="form-select form-select-sm" id="fQuiz" name="quiz" onchange="this.form.submit()">
                    <option value="">Semua mapel</option>
                    <?php foreach ($quizList as $q): ?>
                        <option value="<?= $q['id'] ?>" <?= $q['id'] === $quizId ? 'selected' : '' ?>><?= esc($q['course_short'] . ' · ' . $q['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endif ?>
        <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
    </form>

    <?php if ($kategori === null): ?>
        <div class="alert alert-info">Belum ada kategori ujian simulasi.</div>
    <?php elseif ($kelas === []): ?>
        <div class="alert alert-info">Belum ada data siswa untuk tingkatan ini.</div>
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
                        <th class="text-center">Belum selesai</th><th class="text-center">Belum ikut</th><th style="min-width:9rem">Persentase</th><?php if ($tampilSudah): ?><th>Yang sudah</th><?php endif ?><th>Yang belum</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($kelas as $k): ?>
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
                            <?php if ($tampilSudah): ?><td class="small">
                                <?php if ($k['sudah'] === []): ?>
                                    <span class="text-muted">–</span>
                                <?php else: ?>
                                    <details>
                                        <summary class="text-success"><?= count($k['sudah']) ?> siswa</summary>
                                        <ol class="mb-0 mt-1 ps-3">
                                            <?php foreach ($k['sudah'] as $s): ?>
                                                <li><?= esc($s['nama']) ?><?php if ($s['waktu']): ?> <span class="text-muted">(<?= date('d/m H:i', $s['waktu']) ?>)</span><?php endif ?></li>
                                            <?php endforeach ?>
                                        </ol>
                                    </details>
                                <?php endif ?>
                            </td><?php endif ?>
                            <td class="small">
                                <?php if ($k['kurang'] === []): ?>
                                    <span class="text-success"><i class="bi bi-check2-all me-1"></i>Semua sudah ikut</span>
                                <?php else: ?>
                                    <details>
                                        <summary><?= count($k['kurang']) ?> siswa</summary>
                                        <ol class="mb-0 mt-1 ps-3">
                                            <?php foreach ($k['kurang'] as $s): ?>
                                                <li><?= esc($s['nama']) ?><?php if ($s['proses']): ?> <span class="badge text-bg-warning ms-1">belum selesai</span><?php endif ?></li>
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
                        <td class="text-center"><?= $total['proses'] ?></td><td class="text-center"><?= $total['belum'] ?></td><td><?= $total['persen'] ?>%</td><?php if ($tampilSudah): ?><td></td><?php endif ?><td></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
        <p class="small text-muted mt-2 mb-0"><strong>Sudah ikut</strong> = ada percobaan yang terkirim; <strong>Belum selesai</strong> = mulai tetapi belum/tidak terkirim;
            <strong>Belum ikut</strong> = belum mulai. Dengan "Semua mapel", siswa dihitung sudah ikut bila mengerjakan salah satu mapel.</p>
    <?php endif ?>
<?php endif ?>
</main>
</body>
</html>
