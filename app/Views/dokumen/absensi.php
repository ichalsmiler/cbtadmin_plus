<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$sum = static fn (string $k) => array_sum(array_map(static fn ($r) => (int) $r[$k], $rows));
$total = $sum('total');
$hadir = $sum('hadir');
$tidak = $sum('tidak_hadir');
$q     = http_build_query(array_filter(['tanggal' => $tanggal, 'tingkat' => $tingkat]));
?>
<?= view('partials/tab_kehadiran', ['tab' => 'pengawas']) ?>
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-end gap-3">
        <form class="d-flex align-items-end gap-2" method="get" action="<?= site_url('dokumen/laporan_kehadiran') ?>">
            <input type="hidden" name="tab" value="pengawas">
            <div>
                <label class="form-label small mb-1" for="fTanggal">Tanggal</label>
                <input class="form-control form-control-sm" type="date" id="fTanggal" name="tanggal" value="<?= esc($tanggal, 'attr') ?>" onchange="this.form.submit()">
            </div>
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
        </form>
        <div class="small">
            <strong><?= $total ?></strong> siswa ·
            <span class="text-success"><strong><?= $hadir ?></strong> hadir</span> ·
            <span class="text-danger"><strong><?= $tidak ?></strong> tidak hadir</span> ·
            <strong><?= $total - $hadir - $tidak ?></strong> belum diabsen
        </div>
        <a class="btn btn-outline-success btn-sm ms-auto" href="<?= site_url('dokumen/absensi/export') . '?' . esc($q, 'attr') ?>">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">Absensi per ruang · <?= date('d/m/Y', strtotime($tanggal)) ?><?= $tingkat !== '' ? ' · Kelas ' . esc($tingkat) : '' ?></div>
    <div class="card-body">
        <?php if ($rows === []): ?>
            <p class="text-muted mb-0">Belum ada ruang.</p>
        <?php else: ?>
            <table class="table table-hover align-middle w-100 datatable" data-order-col="0">
                <thead>
                <tr><th>Ruang</th><th>Pengawas</th><th class="text-center">Siswa</th><th class="text-center">Hadir</th><th class="text-center">Tidak hadir</th><th class="text-center">Belum diabsen</th><th class="text-center">Diblokir</th><th>Diisi oleh</th><th>Diperbarui</th><th data-orderable="false"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $belum = (int) $r['total'] - (int) $r['hadir'] - (int) $r['tidak_hadir']; ?>
                    <tr>
                        <td data-order="<?= esc(str_pad($r['nama'], 10, '0', STR_PAD_LEFT)) ?>"><?= esc($r['nama']) ?></td>
                        <td><?= esc($r['pengawas'] ?: '–') ?></td>
                        <td class="text-center"><?= (int) $r['total'] ?></td>
                        <td class="text-center text-success"><?= (int) $r['hadir'] ?></td>
                        <td class="text-center text-danger"><?= (int) $r['tidak_hadir'] ?></td>
                        <td class="text-center"><?= $belum > 0 ? '<span class="badge text-bg-warning">' . $belum . '</span>' : '0' ?></td>
                        <td class="text-center"><?= (int) $r['diblokir'] ?></td>
                        <td class="small"><?= $r['diisi_oleh'] ? esc($r['diisi_oleh']) : '<span class="badge text-bg-secondary">Belum mengisi absensi</span>' ?></td>
                        <td><?= $r['terakhir'] ? esc(date('H:i', strtotime($r['terakhir']))) : '–' ?></td>
                        <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= site_url('kehadiran') . '?' . esc(http_build_query(array_filter(['ruang' => $r['id'], 'tanggal' => $tanggal, 'tingkat' => $tingkat])), 'attr') ?>">Detail</a></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>
