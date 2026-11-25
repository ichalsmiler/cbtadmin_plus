<?php use App\Libraries\LaporanSiswa; ?>
<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$warna = ['baru' => 'text-bg-danger', 'tindak' => 'text-bg-success', 'tidak' => 'text-bg-secondary'];
$url   = static fn (array $q): string => site_url('laporan_siswa') . '?' . http_build_query($q);
$jejak = static fn (?string $u, string $teks): string => $u ? '<a href="' . site_url('tools/jejak') . '?' . http_build_query(['u' => $u]) . '" target="_blank" title="Lihat jejak siswa (login, perangkat, mulai/selesai)">' . $teks . '</a>' : $teks;
?>
<?php if ($adminRole === 'admin'): ?>
<form class="card card-body mb-3 d-flex flex-row flex-wrap align-items-center gap-2" method="post" action="<?= site_url('laporan_siswa/setelan') ?>" data-hanya-admin>
    <?= csrf_field() ?>
    <input type="hidden" name="aktif" value="<?= $aktif ? '0' : '1' ?>">
    <div class="me-auto">
        <span class="fw-semibold">Tombol Laporkan di Moodle:</span>
        <span class="badge <?= $aktif ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $aktif ? 'Aktif' : 'Mati' ?></span>
        <div class="small text-muted">Tampil sebelum dan sesudah ujian selama ada sesi aktif, disembunyikan saat siswa mengerjakan. Maks. <?= LaporanSiswa::MAKS_PER_SESI ?> laporan per siswa per sesi; foto dihapus otomatis setelah <?= LaporanSiswa::SIMPAN_FOTO_HARI ?> hari.</div>
    </div>
    <button class="btn btn-sm <?= $aktif ? 'btn-outline-danger' : 'btn-primary' ?>" type="submit"><?= $aktif ? 'Matikan' : 'Aktifkan' ?></button>
</form>
<?php endif ?>

<ul class="nav nav-tabs mb-3">
    <?php foreach (LaporanSiswa::NAMA_JENIS as $k => $nama): ?>
        <li class="nav-item">
            <a class="nav-link <?= $jenis === $k ? 'active' : '' ?>" href="<?= $url(['jenis' => $k, 'tgl' => $tgl]) ?>">
                <?= esc($nama) ?><?php if (! empty($baru[$k])): ?> <span class="badge text-bg-danger"><?= (int) $baru[$k] ?></span><?php endif ?>
            </a>
        </li>
    <?php endforeach ?>
</ul>

<div class="card">
    <div class="card-header">
        <form method="get" action="<?= site_url('laporan_siswa') ?>" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="jenis" value="<?= esc($jenis, 'attr') ?>">
            <input type="date" class="form-control form-control-sm w-auto" name="tgl" value="<?= esc($tgl, 'attr') ?>" onchange="this.form.submit()" aria-label="Tanggal">
            <select class="form-select form-select-sm w-auto" name="status" onchange="this.form.submit()" aria-label="Status">
                <option value="">Semua status</option>
                <?php foreach (LaporanSiswa::STATUS as $k => $nama): ?>
                    <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= esc($nama) ?></option>
                <?php endforeach ?>
            </select>
        </form>
    </div>
    <div class="card-body">
        <table class="table align-middle w-100 datatable tabel-hp" data-order-col="0" data-order-dir="desc">
            <thead>
            <tr>
                <th>#</th><th>Jam</th><th>Laporan</th><?php if ($jenis === 'curang'): ?><th>Terlapor</th><?php endif ?>
                <th>Ruang</th><th>Pelapor</th><th data-orderable="false">Tindak lanjut</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= date('H:i', strtotime($r['created_at'])) ?></td>
                    <td style="min-width:220px">
                        <div class="fw-semibold"><?= esc($r['kategori']) ?></div>
                        <div class="small"><?= nl2br(esc((string) $r['keterangan'])) ?></div>
                        <?php if ($r['foto']): ?>
                            <a class="small" href="<?= site_url('laporan_siswa/foto/' . (int) $r['id']) ?>" target="_blank"><i class="bi bi-image"></i> Lihat foto</a>
                        <?php endif ?>
                    </td>
                    <?php if ($jenis === 'curang'): ?>
                        <td><?= $r['terlapor_nama'] ? $jejak($r['terlapor_username'], esc($r['terlapor_nama'])) . '<div class="small text-muted">' . esc($r['terlapor_kelas']) . '</div>' : '<span class="text-muted">–</span>' ?></td>
                    <?php endif ?>
                    <td><?= esc($r['ruang_nama'] ?? '-') ?><?= $r['sesi_nama'] !== null ? '<div class="small text-muted">Sesi ' . esc($r['sesi_nama']) . '</div>' : '' ?></td>
                    <td>
                        <?= $jejak($r['pelapor_username'], esc($r['pelapor_nama'] ?? '-')) ?>
                        <div class="small text-muted"><?= esc($r['pelapor_kelas'] ?? '') ?></div>
                        <?php if (! empty($meragukan[(int) $r['pelapor_id']])): ?>
                            <span class="badge text-bg-warning" title="Laporan pelapor ini sudah <?= (int) $meragukan[(int) $r['pelapor_id']] ?> kali tidak terbukti">Sering tidak terbukti</span>
                        <?php endif ?>
                    </td>
                    <td style="min-width:220px">
                        <span class="badge <?= $warna[$r['status']] ?? 'text-bg-secondary' ?>"><?= esc(LaporanSiswa::STATUS[$r['status']] ?? $r['status']) ?></span>
                        <?php if ($r['ditangani_oleh']): ?><span class="small text-muted"><?= esc($r['ditangani_oleh']) ?>, <?= date('H:i', strtotime($r['ditangani_at'])) ?></span><?php endif ?>
                        <form method="post" action="<?= site_url('laporan_siswa/status') ?>" class="mt-1 d-flex flex-wrap gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <select name="status" class="form-select form-select-sm w-auto" aria-label="Status">
                                <?php foreach (LaporanSiswa::STATUS as $k => $nama): ?>
                                    <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= esc($nama) ?></option>
                                <?php endforeach ?>
                            </select>
                            <input type="text" name="catatan" class="form-control form-control-sm" style="min-width:140px;flex:1" maxlength="1000" placeholder="Catatan tindakan" value="<?= esc((string) $r['catatan'], 'attr') ?>">
                            <button class="btn btn-sm btn-primary" type="submit">Simpan</button>
                        </form>
                        <?php if ($jenis === 'curang' && $r['terlapor_nama']): ?>
                            <a class="small" href="<?= site_url('blokir_siswa') . '?' . http_build_query(['ruang' => (int) $r['ruang_id'], 'tingkat' => '']) ?>"><i class="bi bi-slash-circle"></i> Ke Blokir Siswa</a>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
