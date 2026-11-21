<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
// [kunci statistik, label, warna, ikon, tautan]; warna = arti status (panel.css): netral, proses, ok (sudah), awas (perlu perhatian), redup (belum).
$cards = $isAdmin ? [
    ['ruang', 'Total Ruang', 'netral', 'door-open', 'daftar_ruang'],
    ['users', 'Total User', 'netral', 'people', 'daftar_peserta/moodle'],
    ['aktif', 'User Aktif', 'netral', 'person-check', 'reset_login'],
    ['belum', 'Belum Submit', 'proses', 'hourglass-split', 'status_peserta/inprogress'],
    ['sudah', 'Sudah Submit', 'ok', 'check2-circle', 'status_peserta/finished'],
] : [
    // Pengawas: sesi yang sedang ujian (mis. "Sesi Aktif (Kelas X)") dan jumlah siswa ruangnya di sesi itu.
    ['ruang', $myRuang !== null ? 'Sesi Aktif' . ($sesiAktif !== null && $sesiAktif['tingkat'] !== '' ? ' (Kelas ' . $sesiAktif['tingkat'] . ')' : '') : 'Total Ruang', 'netral', 'calendar-check', null],
    ['users', $myRuang !== null ? ($sesiAktif !== null ? 'Siswa di Ruang (sesi aktif)' : 'Siswa di Ruang') : 'Total Siswa', 'netral', 'people', 'peserta_ruang'],
    // Urutan pantauan: belum login → belum mulai → mengerjakan → sudah submit (tautan ke daftar nama di bawah).
    ['belumLogin', 'Belum Login', 'redup', 'person-x', '#pantau-belumLogin'],
    ['belumMulai', 'Belum Mulai', 'awas', 'hourglass', '#pantau-belumMulai'],
    ['belum', 'Mengerjakan', 'proses', 'pencil-square', '#pantau-mengerjakan'],
    ['sudah', 'Sudah Submit', 'ok', 'check2-circle', '#pantau-sudah'],
];
$pantauKolom = [
    'belumLogin'  => ['Belum login', 'redup', 'Belum masuk Moodle hari ini'],
    'belumMulai'  => ['Belum mulai', 'awas', 'Sudah login, belum membuka soal'],
    'mengerjakan' => ['Mengerjakan', 'proses', 'Sedang mengerjakan soal'],
    'sudah'       => ['Sudah submit', 'ok', 'Ujian hari ini sudah selesai'],
];
$bars = $isAdmin ? [
    ['cpu', 'CPU', $stats['sys']['cpu']['cores'] . ' core · ' . $stats['sys']['cpu']['model']],
    ['mem', 'Memory', $stats['sys']['mem']['text']],
    ['disk', 'Disk', $stats['sys']['disk']['text']],
] : [];
?>

<?php if ($isAdmin && $namaKegiatan === ''): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        Nama Kegiatan belum diatur (<a class="alert-link" href="<?= \App\Controllers\Tools::halaman('kegiatan') ?>">Tools → Info Sekolah → Info Kegiatan</a>).
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
<?php endif ?>

<?php if ($sesiAktif !== null): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2" role="status">
        <i class="bi bi-calendar-check"></i>
        <span>Sesi aktif: <strong><?= esc($sesiAktif['label']) ?></strong></span>
        <?php if ($isAdmin): ?><a class="alert-link ms-auto small" href="<?= site_url('status_tes') ?>">Ganti sesi</a><?php endif ?>
    </div>
<?php else: ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2" role="status">
        <i class="bi bi-exclamation-triangle"></i>
        <span>Belum ada sesi yang aktif</span>
        <?php if ($isAdmin): ?><a class="alert-link ms-auto small" href="<?= site_url('status_tes') ?>">Aktifkan di Setting Ujian</a><?php endif ?>
    </div>
<?php endif ?>

<?php if ($token !== null): ?>
    <?= view('partials/token_card', ['token' => $token]) ?>
<?php endif ?>

<div class="alert alert-danger <?= $stats['moodleError'] ? '' : 'd-none' ?>" id="moodleError" role="alert">
    Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.
</div>

<div class="row g-3 mb-4 row-cols-2 <?= count($cards) > 5 ? 'row-cols-md-3 row-cols-xxl-6' : (count($cards) > 4 ? 'row-cols-md-3 row-cols-xl-5' : 'row-cols-xl-4') ?>">
    <?php foreach ($cards as [$key, $label, $color, $icon, $link]): ?>
        <div class="col">
            <div class="stat-card stat-<?= $color ?>">
                <div class="stat-ribbon"><i class="bi bi-<?= $icon ?>"></i></div>
                <div class="stat-value" data-stat="<?= $key ?>"><?= $stats[$key] === null ? '–' : (is_int($stats[$key]) ? number_format($stats[$key], 0, ',', '.') : esc($stats[$key])) ?></div>
                <div class="stat-label"><?= esc($label) ?></div>
                <?php if ($link !== null): ?>
                    <a class="stat-link" href="<?= $link[0] === '#' ? $link : site_url($link) ?>" aria-label="Buka <?= esc($label) ?>">Lihat</a>
                <?php endif ?>
            </div>
        </div>
    <?php endforeach ?>
</div>

<?php if (! $isAdmin && $myRuang !== null): ?>
<?php // Pantauan siswa ruang: nama per kelompok, diperbarui bersama statistik (CbtDashboard → data.pantau). ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center">
        <span class="fw-semibold"><i class="bi bi-binoculars me-1"></i>Pantauan Siswa</span>
        <span class="small text-muted ms-2 d-none d-sm-inline">diperbarui otomatis tiap <?= (int) $refresh ?> detik</span>
    </div>
    <div class="card-body">
        <div class="row g-3 row-cols-1 row-cols-md-2 row-cols-xl-4">
            <?php foreach ($pantauKolom as $k => [$judul, $warna, $ket]): ?>
                <div class="col" id="pantau-<?= $k ?>">
                    <div class="pantau-kolom stat-<?= $warna ?>">
                        <div class="pantau-judul"><?= esc($judul) ?> <span class="badge pantau-jumlah" data-pantau-jumlah="<?= $k ?>"><?= count($stats['pantau'][$k] ?? []) ?></span></div>
                        <div class="small text-muted mb-2"><?= esc($ket) ?></div>
                        <ol class="pantau-daftar" data-pantau="<?= $k ?>">
                            <?php foreach ($stats['pantau'][$k] ?? [] as $s): ?>
                                <li><span><?= esc($s['nama']) ?> <span class="text-muted">· <?= esc($s['kelas']) ?></span></span><?php if ($s['info'] !== ''): ?><small><?= esc($s['info']) ?></small><?php endif ?></li>
                            <?php endforeach ?>
                        </ol>
                        <?php if (($stats['pantau'][$k] ?? []) === []): ?><div class="small text-muted pantau-kosong" data-pantau-kosong="<?= $k ?>">Tidak ada</div><?php else: ?><div class="small text-muted pantau-kosong d-none" data-pantau-kosong="<?= $k ?>">Tidak ada</div><?php endif ?>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</div>
<?php endif ?>

<?php if ($isAdmin): ?>
<div class="card">
    <div class="card-header d-flex align-items-center">
        <span class="fw-semibold">System Information</span>
        <?php if ($webminUrl !== ''): ?>
            <a class="ms-auto small" href="<?= esc($webminUrl, 'attr') ?>" target="_blank" rel="noopener">Open Webmin <i class="bi bi-box-arrow-up-right"></i></a>
        <?php endif ?>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-5">
                <dl class="row mb-0 sysinfo">
                    <dt class="col-4">Name</dt><dd class="col-8"><?= esc($namaSekolah !== '' ? $namaSekolah : '-') ?></dd>
                    <dt class="col-4">ID</dt><dd class="col-8"><?= esc($npsn !== '' ? $npsn : '-') ?></dd>
                    <dt class="col-4">Address</dt><dd class="col-8"><?= esc($serverIp) ?></dd>
                </dl>
            </div>
            <div class="col-lg-7">
                <?php foreach ($bars as [$key, $label, $text]): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold"><?= $label ?></span>
                            <span class="text-muted text-truncate ms-3" data-sys-text="<?= $key ?>"><?= esc($text) ?></span>
                        </div>
                        <div class="progress" role="progressbar" aria-label="<?= $label ?>">
                            <div class="progress-bar" data-sys-bar="<?= $key ?>" style="width: <?= (int) $stats['sys'][$key]['percent'] ?>%"><?= (int) $stats['sys'][$key]['percent'] ?>%</div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    CbtDashboard.start('<?= site_url('dashboard/stats') ?>', <?= (int) $refresh ?>);
</script>
<?= $this->endSection() ?>
