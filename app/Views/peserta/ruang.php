<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<div class="card">
    <div class="card-header d-flex align-items-center">
        <span class="fw-semibold"><i class="bi bi-person me-1"></i><?= $ruang ? 'Ruang ' . esc($ruang['nama']) . (trim((string) $ruang['lokasi']) !== '' && strcasecmp(trim($ruang['lokasi']), 'Ruang ' . $ruang['nama']) !== 0 ? ' · ' . esc($ruang['lokasi']) : '') : 'Daftar Peserta' ?></span>
        <span class="badge text-bg-primary ms-auto"><?= count($rows) ?> siswa</span>
    </div>
    <div class="card-body">
        <form class="d-flex flex-wrap gap-3 align-items-end mb-3 dt-toolbar" id="pesertaToolbar" method="get" action="<?= site_url('peserta_ruang') ?>">
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
            <?php if (! $lockRuang): ?>
                <div>
                    <label class="form-label small mb-1" for="fRuang">Ruang</label>
                    <select class="form-select form-select-sm" id="fRuang" name="ruang" onchange="this.form.submit()">
                        <option value="">Pilih ruang</option>
                        <?php foreach ($ruangList as $r): ?>
                            <option value="<?= (int) $r['id'] ?>" data-kelas="<?= esc((string) $r['kelas'], 'attr') ?>" <?= (string) $r['id'] === $ruangId ? 'selected' : '' ?>><?= esc($r['nama']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            <?php endif ?>
        </form>
        <?php if (! $ruang): ?>
            <p class="text-muted mb-0">Pilih ruang untuk melihat pesertanya.</p>
        <?php else: ?>
            <table class="table table-hover align-middle w-100 datatable tabel-hp" data-order-col="0" data-toolbar="#pesertaToolbar">
                <thead><tr><th style="width:3rem">No</th><th>No. Peserta</th><th>Nama</th><th>Kelas</th><th>Username</th><th>Password</th><th>Sesi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $p): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><?= esc($p['nomor_peserta'] !== '' ? $p['nomor_peserta'] : $p['username']) ?></td>
                        <td><?= esc($p['nama']) ?></td>
                        <td><?= esc($p['kelas']) ?></td>
                        <td><code><?= esc($p['username']) ?></code></td>
                        <td><code><?= esc($p['password_plain'] ?? '') ?></code></td>
                        <td><?= esc($p['sesi_nama'] ?? '-') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>
