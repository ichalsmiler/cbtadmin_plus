<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$tgl = static fn (string $d): string => date('d/m/Y', strtotime($d));
$sudah = count(array_filter($rows, static fn ($r) => $r['status'] !== null));
?>
<?php if (! $ruang): ?>
    <div class="alert alert-warning">Akun ini belum terhubung ke ruang. Hubungi administrator.</div>
<?php else: ?>
<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold">Ruang <?= esc($ruang['nama']) ?> · <?= $tgl($tanggal) ?></span>
        <span class="text-muted small"><?= count($rows) ?> siswa · <?= $sudah ?> sudah diabsen</span>
        <?php if ($isAdmin): ?>
            <a class="ms-auto small" href="<?= site_url('dokumen/laporan_kehadiran') ?>?tab=pengawas&amp;tanggal=<?= esc($tanggal, 'url') ?>">&laquo; Rekap semua ruang</a>
        <?php endif ?>
    </div>
    <div class="card-body">
        <?php if ($sesi !== null): ?>
            <div class="mb-2"><span class="badge text-bg-info fw-normal"><i class="bi bi-calendar-check me-1"></i>Sesi aktif: <?= esc($sesi['label']) ?></span></div>
        <?php endif ?>
        <form class="d-flex flex-wrap gap-3 align-items-end mb-3 dt-toolbar" method="get" action="<?= site_url('kehadiran') ?>" id="absenFilter">
            <?php if ($isAdmin): ?>
                <input type="hidden" name="ruang" value="<?= (int) $ruang['id'] ?>">
                <input type="hidden" name="tanggal" value="<?= esc($tanggal, 'attr') ?>">
            <?php endif ?>
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
        </form>
        <?php if ($sesi === null && ! $isAdmin): ?>
            <div class="alert alert-warning mb-0">Sesi belum diaktifkan oleh proktor. Daftar siswa muncul setelah sesi ujian diaktifkan.</div>
        <?php elseif ($rows === []): ?>
            <p class="text-muted mb-0">Tidak ada siswa<?= $sesi !== null ? ' ' . esc($sesi['label']) : '' ?><?= $tingkat !== '' ? ' kelas ' . esc($tingkat) : '' ?> di ruang ini.</p>
        <?php else: ?>
            <p class="small text-muted">
                Tandai setiap siswa lalu klik <strong>Simpan</strong>. Siswa yang <strong>tidak hadir</strong> langsung diblokir
                di Moodle sampai hari ini berakhir; bila ternyata datang, ubah menjadi Hadir dan simpan lagi.
            </p>
            <form method="post" action="<?= site_url('kehadiran/simpan') ?>" id="absenForm">
                <?= csrf_field() ?>
                <input type="hidden" name="tingkat" value="<?= esc($tingkat, 'attr') ?>">
                <?php if ($isAdmin): ?>
                    <input type="hidden" name="ruang_id" value="<?= (int) $ruang['id'] ?>">
                    <input type="hidden" name="tanggal" value="<?= esc($tanggal, 'attr') ?>">
                <?php endif ?>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button class="btn btn-outline-success btn-sm" type="button" id="semuaHadir"><i class="bi bi-check2-all me-1"></i>Tandai semua hadir</button>
                    <span class="small text-muted align-self-center" id="ringkas"></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 datatable tabel-hp" id="absenTable" data-toolbar="#absenFilter" data-order-col="0">
                        <thead><tr><th>No. Peserta</th><th>Nama</th><th>Kelas</th><th class="text-center">Kehadiran</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php $pid = (int) $r['id']; ?>
                            <tr>
                                <td><?= esc($r['nomor_peserta'] ?: '–') ?></td>
                                <td><?= esc($r['nama']) ?></td>
                                <td><?= esc($r['kelas']) ?></td>
                                <td class="text-center text-nowrap">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Kehadiran <?= esc($r['nama'], 'attr') ?>">
                                        <input type="radio" class="btn-check" name="status[<?= $pid ?>]" id="h<?= $pid ?>" value="hadir" <?= $r['status'] === 'hadir' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-success" for="h<?= $pid ?>">Hadir</label>
                                        <input type="radio" class="btn-check" name="status[<?= $pid ?>]" id="t<?= $pid ?>" value="tidak_hadir" <?= $r['status'] === 'tidak_hadir' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-danger" for="t<?= $pid ?>">Tidak hadir</label>
                                    </div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ((int) $r['diblokir']): ?>
                                        <span class="badge text-bg-danger">Diblokir</span>
                                        <?php if ($isAdmin): ?>
                                            <button class="btn btn-link btn-sm p-0 ms-1" type="button"
                                                    data-post="<?= site_url('dokumen/absensi/buka') ?>"
                                                    data-fields="<?= esc(json_encode(['peserta_id' => $pid, 'tanggal' => $tanggal]), 'attr') ?>"
                                                    data-confirm-text="Buka blokir <?= esc($r['nama'], 'attr') ?>? Status tetap tidak hadir.">Buka blokir</button>
                                        <?php endif ?>
                                    <?php elseif ($r['status'] === null): ?>
                                        <span class="badge text-bg-light">Belum diabsen</span>
                                    <?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-save me-1"></i>Simpan</button>
            </form>
        <?php endif ?>
    </div>
</div>
<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    var form = document.getElementById('absenForm');
    if (!form) { return; }
    // Tabel memakai DataTables (halaman & pencarian): baris di halaman lain tidak ada di DOM,
    // jadi semua radio dibaca lewat API tabel dan yang tersembunyi dikirim sebagai input tambahan.
    var el = document.getElementById('absenTable');
    var dt = (el && typeof DataTable !== 'undefined') ? new DataTable(el, { retrieve: true }) : null;
    var all = function (sel) { return dt ? dt.$(sel).toArray() : Array.prototype.slice.call(form.querySelectorAll(sel)); };
    var update = function () {
        var h = all('input[value=hadir]:checked').length;
        var t = all('input[value=tidak_hadir]:checked').length;
        var total = all('input[value=hadir]').length;
        document.getElementById('ringkas').textContent = h + ' hadir · ' + t + ' tidak hadir · ' + (total - h - t) + ' belum ditandai';
    };
    form.addEventListener('change', function (e) { if (e.target.type === 'radio') { update(); } });
    document.getElementById('semuaHadir').addEventListener('click', function () {
        all('input[value=hadir]').forEach(function (r) {
            var other = r.closest('td').querySelector('input[value=tidak_hadir]');
            if (!other.checked) { r.checked = true; }  // yang sudah ditandai tidak hadir dibiarkan
        });
        update();
    });
    form.addEventListener('submit', function (event) {
        form.querySelectorAll('.absen-extra').forEach(function (x) { x.remove(); });
        all('input[type=radio]:checked').forEach(function (r) {
            if (document.body.contains(r)) { return; }
            var hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = r.name; hidden.value = r.value; hidden.className = 'absen-extra';
            form.appendChild(hidden);
        });
        var t = all('input[value=tidak_hadir]:checked').length;
        if (t > 0 && !form.dataset.ok) {
            event.preventDefault();
            var go = window.confirm(t + ' siswa ditandai TIDAK HADIR dan akan diblokir di Moodle hari ini. Lanjutkan?');
            if (go) { form.dataset.ok = '1'; form.submit(); }
        }
    });
    update();
})();
</script>
<?= $this->endSection() ?>
