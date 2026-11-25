<?php
/**
 * Kartu token untuk dashboard pengawas.
 * $token = ['mapel', 'enabled', 'menit', 'token', 'next', 'kunci'].
 */
?>
<?php if (! empty($token['ringkas'])): ?>
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3 py-2">
        <i class="bi bi-key fs-4 text-primary"></i>
        <div class="me-auto">
            <div class="small text-muted"><?= $token['mapel'] !== '' ? 'Token tes ' . esc($token['mapel']) : 'Belum ada tes aktif' ?></div>
            <div>
                <span class="font-monospace fw-semibold fs-4 token-ringkas" id="tokenValue" style="letter-spacing:.2em"><?= esc(! $token['enabled'] ? 'TANPA TOKEN' : ($token['token'] !== '' ? $token['token'] : (! empty($token['sesiNonaktif']) ? 'SESI NONAKTIF' : '–'))) ?></span>
                <?php if ($token['enabled'] && $token['next'] > 0): ?>
                    <span class="small text-muted ms-2">ganti pukul <strong id="tokenNext"><?= date('H:i', $token['next']) ?></strong> (<span id="tokenLeft">–</span>)</span>
                <?php endif ?>
            </div>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="<?= site_url('token_ujian') ?>"><i class="bi bi-key me-1"></i>Token</a>
    </div>
</div>
<?php else: ?>
<div class="card mb-4 token-card">
    <div class="card-body text-center py-4">
        <?php if (! empty($token['sesiBelum'])): ?>
            <?php // Sesi nonaktif: nama tes disembunyikan; pesan di atas ikon, ukuran sama dengan baris nama tes (tebal). ?>
            <div class="mb-2 fw-semibold text-warning-emphasis">Sesi belum diaktifkan oleh Proktor</div>
            <div class="token-display text-muted"><i class="bi bi-hourglass-split"></i></div>
            <p class="small text-muted mt-2 mb-0">Token akan muncul setelah proktor mengaktifkan sesi ujian. Halaman ini diperbarui otomatis.</p>
        <?php else: ?>
        <div class="text-muted mb-2">
            <?= $token['mapel'] !== '' ? 'Token tes <strong>' . esc($token['mapel']) . '</strong>' : 'Belum ada tes aktif' ?>
        </div>
        <?php endif ?>
        <?php if (! empty($token['sesiBelum'])): ?>
        <?php elseif ($token['kunci']): ?>
            <div class="token-display text-muted"><i class="bi bi-lock-fill"></i></div>
            <p class="mt-2 mb-3">Token muncul setelah absensi siswa ruang ini hari ini disimpan.</p>
            <a class="btn btn-primary" href="<?= site_url('kehadiran') ?>"><i class="bi bi-clipboard-check me-1"></i>Isi Laporan Kehadiran</a>
        <?php else: ?>
            <div class="token-display font-monospace fw-semibold" id="tokenValue">
                <?= esc(! $token['enabled'] ? 'TANPA TOKEN' : ($token['token'] !== '' ? $token['token'] : (! empty($token['sesiNonaktif']) ? 'SESI NONAKTIF' : '–'))) ?>
            </div>
            <p class="text-muted small mt-2 mb-0">
                <?php if (! $token['enabled']): ?>
                    Quiz bisa dimulai tanpa token.
                <?php elseif ($token['next'] > 0): ?>
                    Berganti otomatis setiap <?= (int) $token['menit'] ?> menit · berikutnya pukul
                    <strong id="tokenNext"><?= date('H:i', $token['next']) ?></strong> (<span id="tokenLeft">–</span> lagi)
                <?php else: ?>
                    Token belum dibuat. Hubungi administrator.
                <?php endif ?>
            </p>
        <?php endif ?>
    </div>
</div>
<?php endif ?>
<?php if (! empty($token['sesiBelum'])): ?>
<script>
(function () {
    'use strict';
    // Sesi belum aktif: muat ulang halaman begitu proktor mengaktifkan sesi.
    setInterval(function () {
        fetch('<?= site_url('status_tes/token') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && !d.sesiBelum) { window.location.reload(); } })
            .catch(function () { /* coba lagi di putaran berikutnya */ });
    }, 15000);
})();
</script>
<?php endif ?>
<?php if (! $token['kunci'] && $token['enabled']): ?>
<script>
(function () {
    'use strict';
    // Token berganti di server (cron); perbarui tampilan tanpa muat ulang halaman.
    var box = document.getElementById('tokenValue');
    var offset = 0, next = <?= (int) $token['next'] ?>;
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
                if (!d || !d.enabled || d.kunci) { return; }
                offset = d.now - Math.floor(Date.now() / 1000);
                if (d.token && box.textContent.trim() !== d.token) {
                    box.textContent = d.token;
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
<?php endif ?>
