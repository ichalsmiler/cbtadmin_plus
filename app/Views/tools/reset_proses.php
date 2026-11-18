<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<div class="card">
    <div class="card-body d-flex align-items-center gap-3">
        <div class="spinner-border text-danger" role="status" aria-hidden="true"></div>
        <div>
            <div class="fw-semibold">Reset Moodle sedang berjalan</div>
            <div class="small text-body-secondary" role="status"><?= esc($pesan) ?></div>
            <div class="small text-body-secondary">Halaman ini melanjutkan otomatis. Jangan ditutup sampai selesai.</div>
        </div>
    </div>
    <form method="post" action="<?= site_url('tools/test_manage/reset/lanjut') ?>" id="lanjutReset" class="card-footer">
        <?= csrf_field() ?>
        <button class="btn btn-outline-secondary btn-sm" type="submit">Lanjutkan sekarang</button>
    </form>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>setTimeout(function () { document.getElementById('lanjutReset').submit(); }, 500);</script>
<?= $this->endSection() ?>
