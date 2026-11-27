<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$fmt    = static fn ($ts) => $ts > 0 ? date('d/m H:i', $ts) : '–';
$nKunci = count(array_filter($rows, static fn ($r) => $r['kunci']));
?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Data Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>
<?php if ($jenisAktif === []): ?>
    <div class="alert alert-secondary" role="status">Batas login per mapel sedang tidak aktif (Tools → Moodle Tools), jadi tidak ada siswa yang dikunci.</div>
<?php endif ?>

<div class="alert alert-info small py-2" role="note">
    <?php if (in_array('login', $jenisAktif, true)): ?>
        Siswa yang login lebih dari <strong><?= (int) $maks['login'] ?> kali</strong> saat ujian satu mapel masih berjalan akan <strong>terkunci</strong> (baris merah).
        Tekan <strong>Buka kunci</strong> supaya siswa bisa login sekali lagi; login berikutnya langsung terkunci lagi. Jawaban siswa tetap tersimpan.
    <?php endif ?>
    <?php if (in_array('keluar', $jenisAktif, true)): ?>
        Siswa yang keluar dari aplikasi ujian lebih dari <?= (int) $maks['keluar'] ?> kali juga terkunci.
    <?php endif ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('lock_peserta') ?>">
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
            <div>
                <label class="form-label small mb-1" for="fRuang">Ruang</label>
                <select class="form-select form-select-sm" id="fRuang" name="ruang" onchange="this.form.submit()" <?= $lockRuang ? 'disabled' : '' ?>>
                    <?php if (! $lockRuang): ?>
                        <option value="">Semua ruang</option>
                    <?php endif ?>
                    <?php foreach ($ruangList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" data-kelas="<?= esc((string) $r['kelas'], 'attr') ?>" <?= (string) $r['id'] === $ruangId ? 'selected' : '' ?>><?= esc($r['nama']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc((string) current_url(true), 'attr') ?>"><i class="bi bi-arrow-clockwise me-1"></i>Muat ulang</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <span class="small text-muted">Siswa tercatat: <?= count($rows) ?></span>
        <?php if ($nKunci): ?><span class="badge text-bg-danger"><?= $nKunci ?> terkunci</span><?php endif ?>
    </div>
    <div class="card-body">
        <table class="table table-hover align-middle w-100 datatable tabel-hp" data-order-col="5" data-order-dir="desc">
            <thead>
            <tr><th>Username</th><th>Nama</th><th>Kelas</th><th>Ruang</th><th>Jenis</th><th class="text-center">Jumlah</th><th>Terakhir</th><th data-orderable="false"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $r['kunci'] ? 'table-danger' : '' ?>">
                    <td><code><?= esc($r['username']) ?></code></td>
                    <td><?= esc($r['nama']) ?></td>
                    <td><?= esc($r['kelas']) ?></td>
                    <td><?= esc($r['ruang'] ?? '–') ?></td>
                    <td><?= $r['jenis'] === 'login' ? 'Login' : 'Keluar aplikasi' ?></td>
                    <td class="text-center" data-order="<?= ($r['kunci'] ? 1000 : 0) + $r['total'] ?>">
                        <span class="badge <?= $r['kunci'] ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= (int) $r['total'] ?>x<?= $r['kunci'] ? ' · terkunci' : '' ?></span>
                    </td>
                    <td data-order="<?= (int) $r['waktu'] ?>"><?= $fmt((int) $r['waktu']) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($r['kunci']): ?>
                            <button class="btn btn-danger btn-sm" type="button"
                                    data-post="<?= site_url('lock_peserta/buka') ?>" data-fields="<?= esc(json_encode(['userid' => $r['userid'], 'attempt' => $r['attempt'], 'jenis' => $r['jenis']]), 'attr') ?>"
                                    data-confirm-text="<?= esc('Buka kunci ' . $r['nama'] . '? ' . ($r['jenis'] === 'login' ? 'Siswa bisa login sekali lagi; login berikutnya langsung terkunci lagi.' : 'Bila keluar sekali lagi, ujiannya langsung terkunci lagi.'), 'attr') ?>"><i class="bi bi-unlock me-1"></i>Buka kunci</button>
                        <?php else: ?>
                            <span class="small text-body-secondary">belum terkunci</span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?= $this->endSection() ?>
