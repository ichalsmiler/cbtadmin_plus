<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php // Status Ujian → Token (dulu bagian atas Setting Ujian). ?>
<div class="card">
    <div class="card-header d-flex align-items-center">
        <span class="fw-semibold"><i class="bi bi-key me-1"></i>Token</span>
    </div>
    <div class="card-body">
        <p class="tes-ket">Dipasang sebagai password semua quiz di tes aktif<?= $tesAktif !== '' ? ' (<strong>' . esc($tesAktif) . '</strong>)' : '' ?>.</p>
        <form class="row g-2 mb-1" method="post" data-no-dirty action="<?= site_url('status_tes/release') ?>">
            <?= csrf_field() ?>
            <div class="col-md-9">
                <input class="form-control form-control-lg font-monospace fw-bold text-center token-box" id="tokenValue" readonly aria-label="Token"
                       value="<?= esc(! $tokenEnabled ? 'Token dinonaktifkan' : ($sesiAktif === 0 ? 'Sesi nonaktif' : ($token !== '' ? $token : 'Silakan klik tombol Release'))) ?>">
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-success btn-lg" type="submit" <?= $tokenEnabled && $catId && $sesiAktif > 0 ? '' : 'disabled' ?>>
                    <i class="bi bi-arrow-repeat me-1"></i>Release
                </button>
            </div>
        </form>
        <p class="small text-muted mb-0" id="tokenInfo">
            <?php if (! $tokenEnabled): ?>
                Quiz di tes ini bisa dimulai tanpa token.
            <?php elseif ($sesiAktif === 0): ?>
                Aktifkan sesi di <a href="<?= site_url('status_tes') ?>">Setting Ujian</a>; token dibuat otomatis.
            <?php elseif ($nextRelease > 0): ?>
                Token berganti otomatis setiap <?= (int) $tokenMinutes ?> menit. Berikutnya pukul
                <strong id="tokenNext"><?= date('H:i', $nextRelease) ?></strong> (<span id="tokenLeft">–</span> lagi).
            <?php else: ?>
                Pilih tes di <a href="<?= site_url('status_tes') ?>">Setting Ujian</a> lalu klik Simpan; token dibuat otomatis.
            <?php endif ?>
        </p>
    </div>
</div>

<?php // Pengaturan token langsung di halaman (dulu jendela dari tombol "Setting Token"). ?>
<form class="card mt-4" method="post" action="<?= site_url('status_tes/token_setting') ?>">
    <?= csrf_field() ?>
    <div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Pengaturan Token</div>
    <div class="card-body">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="f_aktif" name="aktif" value="1" <?= $tokenEnabled ? 'checked' : '' ?>>
            <label class="form-check-label" for="f_aktif">Gunakan token dengan release otomatis</label>
        </div>
        <label class="form-label" for="f_menit">Timer auto release token (menit)</label>
        <input class="form-control" type="number" id="f_menit" name="menit" min="1" max="1440" required value="<?= (int) $tokenMinutes ?>" style="max-width: 140px">
        <p class="small text-muted mt-2 mb-0">Token dipasang sebagai password di semua quiz pada tes aktif. Bila dimatikan, password quiz dikosongkan.</p>
    </div>
    <div class="card-footer text-end">
        <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Simpan</button>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    // Token berganti di server (cron); perbarui tampilan tanpa muat ulang halaman.
    var box = document.getElementById('tokenValue');
    var offset = 0, next = <?= (int) $nextRelease ?>;
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var tick = function () {
        var el = document.getElementById('tokenLeft');
        if (!el || !next) { return; }
        var left = next - (Math.floor(Date.now() / 1000) + offset);
        el.textContent = left > 0 ? Math.floor(left / 60) + ':' + pad(left % 60) : 'sebentar';
    };
    var poll = function () {
        fetch('<?= site_url('status_tes/token') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401) { window.location.reload(); return null; }
                return r.ok ? r.json() : null;
            })
            .then(function (d) {
                if (!d || !d.enabled) { return; }
                offset = d.now - Math.floor(Date.now() / 1000);
                if (d.token && box.value !== d.token) {
                    box.value = d.token;
                    box.classList.add('token-new');
                    setTimeout(function () { box.classList.remove('token-new'); }, 1500);
                }
                if (d.next) {
                    next = d.next;
                    var at = document.getElementById('tokenNext');
                    if (at) { var t = new Date(d.next * 1000); at.textContent = pad(t.getHours()) + ':' + pad(t.getMinutes()); }
                }
            })
            .catch(function () { /* coba lagi di putaran berikutnya */ });
    };
    tick();
    setInterval(tick, 1000);
    setInterval(poll, 15000);
})();
</script>
<?= $this->endSection() ?>
