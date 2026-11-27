<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nama Pengawas · CBT Admin</title>
    <?= view('partials/favicon') ?>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc($version) ?>">
</head>
<body class="login-page">
<main class="login-card">
    <div class="login-brand">
        <div class="nama">Nama Pengawas</div>
        <div class="sekolah"><?= esc(trim(($ruang !== '' ? 'Ruang ' . $ruang : '') . ($sesi !== '' ? ' · ' . $sesi : ''), ' ·')) ?></div>
    </div>

    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger py-2" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
    <?php endif ?>

    <form method="post" action="<?= site_url('pengawas_nama') ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-2">
            <label class="form-label" for="nama">Nama lengkap pengawas (beserta gelar)</label>
            <input class="form-control" type="text" id="nama" name="nama" value="<?= esc($nama) ?>" minlength="3" maxlength="100" required autofocus>
        </div>
        <div class="form-text mb-4">Nama ini dicatat sebagai pengawas ruang ini untuk hari dan sesi sekarang, dan tampil di Rekap Kehadiran.</div>
        <button class="btn btn-primary w-100 py-2" type="submit">Lanjut</button>
    </form>
    <div class="text-center mt-3"><a class="small" href="<?= site_url('logout') ?>">Keluar</a></div>
</main>
<script src="<?= base_url('assets/vendor/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
