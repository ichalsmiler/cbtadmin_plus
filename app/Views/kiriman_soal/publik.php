<?php
$total = 0;
$sudah = 0;
foreach ($rekap as $list) {
    foreach ($list as $r) {
        $total++;
        $sudah += $r['kirim'] !== null ? 1 : 0;
    }
}
$persen = $total > 0 ? (int) floor($sudah * 100 / $total) : 0;
$warna  = static fn (int $p): string => $p >= 100 ? 'bg-success' : ($p >= 70 ? 'bg-warning' : 'bg-danger');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <title>Status Pengumpulan Soal<?= $sekolah !== '' ? ' · ' . esc($sekolah) : '' ?></title>
    <?= view('partials/favicon') ?>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc($version) ?>">
</head>
<body class="publik-page">
<main class="container-xl py-3 py-md-4">
    <header class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Status Pengumpulan Soal</h1>
            <div class="text-muted"><?= $aktif ? 'Semester ' . esc($aktif['semester']) . ' ' . esc($aktif['ta']) : '' ?><?= $aktif && $sekolah !== '' ? ' · ' : '' ?><?= esc($sekolah) ?></div>
        </div>
        <div class="text-end small text-muted">
            <a class="btn btn-primary btn-sm mb-1" href="<?= site_url('kirim_soal/') ?>"><i class="bi bi-send me-1"></i>Kirim soal</a><br>
            Diperbarui <?= date('H:i') ?> · memuat ulang tiap 1 menit
        </div>
    </header>

<?php if (! $aktif): ?>
    <div class="alert alert-info">Pengumpulan soal belum dibuka.</div>
<?php else: ?>
    <?php if ($ditutup): ?>
        <div class="alert alert-secondary py-2">Pengiriman soal ditutup sejak <?= date('d-m-Y H:i', $tutup) ?>.</div>
    <?php elseif ($tutup !== null): ?>
        <div class="alert alert-warning py-2">Batas pengiriman soal: <b><?= date('d-m-Y H:i', $tutup) ?></b>.</div>
    <?php endif ?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between fw-semibold mb-1"><span>Semua kelas</span><span><?= $sudah ?> / <?= $total ?> sudah kirim (<?= $persen ?>%)</span></div>
            <div class="progress mb-3" role="progressbar" aria-valuenow="<?= $persen ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $warna($persen) ?>" style="width: <?= $persen ?>%"></div></div>
            <div class="row g-3 align-items-start">
                <?php foreach ($rekap as $t => $list): $n = count(array_filter($list, static fn ($r) => $r['kirim'] !== null)); $p = $list ? (int) floor($n * 100 / count($list)) : 0; ?>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between small"><span>Kelas <?= $t ?></span><span><?= $n ?> / <?= count($list) ?></span></div>
                        <div class="progress" style="height: 6px"><div class="progress-bar <?= $warna($p) ?>" style="width: <?= $p ?>%"></div></div>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-start">
        <?php foreach ($rekap as $t => $list): ?>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header fw-semibold">Kelas <?= $t ?></div>
                    <ul class="list-group list-group-flush small">
                        <?php if (! $list): ?><li class="list-group-item text-muted">Belum ada data guru.</li><?php endif ?>
                        <?php foreach ($list as $r): $k = $r['kirim']; ?>
                            <li class="list-group-item d-flex gap-2 align-items-start">
                                <i class="bi <?= $k ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?> mt-1"></i>
                                <div class="me-auto">
                                    <div class="fw-semibold"><?= esc($r['mapel']) ?></div>
                                    <div class="text-muted"><?= esc($r['guru']) ?></div>
                                </div>
                                <div class="text-end text-nowrap">
                                    <?php if ($k): ?>
                                        <div><?= esc(date('d-m H:i', strtotime($k['updated_at']))) ?></div>
                                        <?php if (! empty($k['masuk_at'])): ?><span class="badge text-bg-primary">Di Moodle</span><?php endif ?>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning">Belum</span>
                                    <?php endif ?>
                                </div>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
</main>
</body>
</html>
