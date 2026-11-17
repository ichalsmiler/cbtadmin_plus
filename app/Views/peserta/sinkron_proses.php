<?php // Kemajuan Sinkron ke Moodle: dikirim ulang otomatis sampai semua akun dan pendaftaran mapel selesai. ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sinkron ke Moodle · CBT Admin</title>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc(\App\Controllers\PanelController::VERSION) ?>">
</head>
<body class="login-page">
<main class="login-card" style="max-width:440px">
    <h1 class="h5 mb-3">Sinkron ke Moodle berjalan…</h1>
    <div class="d-flex align-items-center gap-2 mb-3" role="status">
        <div class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></div>
        <span>Jangan tutup halaman ini.</span>
    </div>
    <dl class="row small mb-3">
        <dt class="col-7 fw-normal text-body-secondary">Akun dibuat</dt><dd class="col-5 mb-1"><?= (int) $hasil['dibuat'] ?></dd>
        <dt class="col-7 fw-normal text-body-secondary">Akun lama ditautkan</dt><dd class="col-5 mb-1"><?= (int) $hasil['ditautkan'] ?></dd>
        <dt class="col-7 fw-normal text-body-secondary">Pendaftaran mapel baru</dt><dd class="col-5 mb-1"><?= (int) $hasil['daftar'] ?></dd>
        <dt class="col-7 fw-normal text-body-secondary">Sisa akun</dt><dd class="col-5 mb-1"><?= (int) $sisaAkun ?></dd>
        <dt class="col-7 fw-normal text-body-secondary">Sisa pendaftaran mapel</dt><dd class="col-5 mb-0"><?= $sisaAkun > 0 ? 'dihitung setelah akun selesai' : (int) $sisaDaftar ?></dd>
    </dl>
    <form method="post" action="<?= site_url('daftar_peserta/moodle/buat') ?>" id="lanjutForm">
        <?= csrf_field() ?>
        <input type="hidden" name="lanjut" value="1">
        <button class="btn btn-primary w-100" type="submit">Lanjutkan</button>
    </form>
</main>
<script>
    // Lanjut otomatis; tombol di atas hanya cadangan bila skrip diblokir.
    setTimeout(function () { document.getElementById('lanjutForm').submit(); }, 400);
</script>
</body>
</html>
