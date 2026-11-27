<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php $fmt = static fn ($ts) => $ts > 0 ? date('d/m H:i', $ts) : '–'; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Data login Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<?php $nTolak = count(array_filter($rows, static fn ($r) => $r['tolak'] !== null)); ?>
<?php if ($nTolak > 0): ?>
    <div class="alert alert-danger d-flex flex-wrap gap-2 align-items-center" role="status">
        <i class="bi bi-phone-flip"></i>
        <span><strong><?= $nTolak ?> peserta</strong> mencoba login di <strong>perangkat lain</strong> dan ditolak (baris merah). Pastikan memang siswa itu, lalu Reset Login supaya ia bisa masuk di perangkat barunya.</span>
    </div>
<?php endif ?>
<?php if ($menit > 0): ?>
    <div class="alert alert-info d-flex flex-wrap gap-2 align-items-center" role="status">
        <span>Menampilkan <strong>user aktif <?= (int) $menit ?> menit terakhir</strong> (sama dengan kartu User Aktif di Dashboard).</span>
        <a class="alert-link ms-auto" href="<?= site_url('reset_login') ?>">Tampilkan semua yang masih login</a>
    </div>
<?php endif ?>
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-3 align-items-end">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('reset_login') ?>">
            <?php if ($menit > 0): ?><input type="hidden" name="aktif" value="1"><?php endif ?>
            <div>
                <label class="form-label small mb-1" for="fCari">Cari peserta</label>
                <input class="form-control form-control-sm" id="fCari" name="cari" value="<?= esc($cari) ?>" placeholder="username atau nama, lalu Enter">
            </div>
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
            <div>
                <label class="form-label small mb-1" for="fRuang">Ruang</label>
                <select class="form-select form-select-sm" id="fRuang" name="ruang" onchange="this.form.submit()" <?= $lockRuang ? 'disabled' : '' ?>>
                    <?php if (! $lockRuang): ?>
                        <option value="">Semua ruang</option>
                        <option value="0" <?= $ruangId === '0' ? 'selected' : '' ?>>(tanpa ruang)</option>
                    <?php endif ?>
                    <?php foreach ($ruangList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" data-kelas="<?= esc((string) $r['kelas'], 'attr') ?>" <?= (string) $r['id'] === $ruangId ? 'selected' : '' ?>><?= esc($r['nama']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <button class="visually-hidden" type="submit" tabindex="-1">Cari</button>
            <?php if ($cari !== '' || $tingkat !== '' || ($ruangId !== '' && ! $lockRuang)): ?>
                <a class="btn btn-link btn-sm" href="<?= site_url('reset_login') ?>">Hapus filter</a>
            <?php endif ?>
        </form>

        <form class="d-flex gap-2 align-items-end ms-lg-auto" method="post" action="<?= site_url('reset_login/reset') ?>"
              data-confirm="Reset login semua peserta di ruang yang dipilih? Mereka harus login ulang.">
            <?= csrf_field() ?>
            <div>
                <label class="form-label small mb-1" for="fRuangReset">Reset satu ruang</label>
                <select class="form-select form-select-sm" id="fRuangReset" name="ruang_id" required>
                    <option value="">Pilih ruang</option>
                    <?php foreach ($ruangList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>"><?= esc($r['nama']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset ruang</button>
        </form>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= site_url('reset_login/reset') ?>" data-bulk>
        <?= csrf_field() ?>
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="small text-muted">
                <?= $cari !== '' ? 'Hasil pencarian "' . esc($cari) . '"' : 'Peserta yang sedang login' ?>: <?= count($rows) ?>
            </span>
            <button class="btn btn-outline-danger btn-sm ms-auto" type="submit"
                    data-confirm-button="Reset login peserta yang dicentang? Mereka harus login ulang, lalu bisa melanjutkan ujian di perangkat lain.">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset yang dicentang
            </button>
        </div>
        <div class="card-body">
            <table class="table table-hover align-middle w-100 datatable tabel-hp" <?= $nTolak > 0 ? 'data-order-col="10" data-order-dir="desc"' : 'data-order-col="1"' ?>>
                <thead>
                <tr>
                    <th data-orderable="false" style="width:1%"><input class="form-check-input" type="checkbox" data-check-all aria-label="Centang semua"></th>
                    <th>Username</th><th>Nama</th><th>Kelas</th><th>Ruang</th><th>IP terakhir</th><th>Login</th><th>Aktif terakhir</th><th class="text-center">Sesi</th><th>Perangkat lain</th>
                    <th data-orderable="false"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr<?= $r['tolak'] !== null ? ' class="table-danger"' : '' ?>>
                        <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= $r['userid'] ?>" aria-label="Pilih <?= esc($r['username'], 'attr') ?>"></td>
                        <td><code><?= esc($r['username']) ?></code></td>
                        <td><?= esc($r['nama']) ?></td>
                        <td><?= esc($r['kelas']) ?></td>
                        <td><?= esc($r['ruang'] ?? '-') ?></td>
                        <td><?= esc($r['ip'] !== '' ? $r['ip'] : '–') ?></td>
                        <td data-order="<?= $r['login'] ?>"><?= $fmt($r['login']) ?></td>
                        <td data-order="<?= $r['aktif'] ?>"><?= $fmt($r['aktif']) ?></td>
                        <td class="text-center"><?= $r['sesi'] > 0 ? '<span class="badge text-bg-success">' . $r['sesi'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                        <td data-order="<?= $r['tolak']['w'] ?? 0 ?>"><?= $r['tolak'] !== null
                            ? '<span class="badge text-bg-danger">Ditolak ' . esc($fmt($r['tolak']['w'])) . '</span>' . ($r['tolak']['ip'] !== '' ? ' <small class="text-muted">' . esc($r['tolak']['ip']) . '</small>' : '')
                            : '<span class="text-muted">–</span>' ?></td>
                        <td class="text-end">
                            <button class="btn btn-outline-danger btn-sm text-nowrap" type="button" <?= $r['sesi'] === 0 ? 'disabled' : '' ?>
                                    data-post="<?= site_url('reset_login/reset') ?>" data-fields="<?= esc(json_encode(['ids[]' => $r['userid']]), 'attr') ?>"
                                    data-confirm-text="Reset login <?= esc($r['username'], 'attr') ?>? Peserta harus login ulang.">Reset Login</button>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?= $this->endSection() ?>
