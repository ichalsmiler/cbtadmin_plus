<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · CBT Admin</title>
    <?= view('partials/favicon') ?>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc($version) ?>">
</head>
<body class="login-page">
<main class="login-card">
    <div class="login-brand">
        <div class="nama">CBT Admin</div>
        <?php $sekolah = \App\Libraries\Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', '')); ?>
        <?php if ($sekolah !== ''): ?><div class="sekolah"><?= esc($sekolah) ?></div><?php endif ?>
    </div>

    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger py-2" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
    <?php endif ?>

    <form method="post" action="<?= site_url('login') ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="username">Username / kode login</label>
            <input class="form-control" type="text" id="username" name="username" value="<?= esc(old('username') ?? '') ?>" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <input class="form-control" type="password" id="password" name="password" required>
                <button class="btn btn-outline-secondary" type="button" id="lihatPassword" title="Tampilkan password" aria-label="Tampilkan password" aria-pressed="false">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <button class="btn btn-primary w-100 py-2" type="submit">Masuk ke panel</button>
    </form>
</main>
<script>
(function () {
    var btn = document.getElementById('lihatPassword');
    var inp = document.getElementById('password');
    if (!btn || !inp) return;
    btn.addEventListener('click', function () {
        var tampil = inp.type === 'password';
        inp.type = tampil ? 'text' : 'password';
        var teks = tampil ? 'Sembunyikan password' : 'Tampilkan password';
        btn.title = teks;
        btn.setAttribute('aria-label', teks);
        btn.setAttribute('aria-pressed', tampil ? 'true' : 'false');
        btn.querySelector('i').className = tampil ? 'bi bi-eye-slash' : 'bi bi-eye';
        inp.focus();
    });
})();
</script>
</body>
</html>
