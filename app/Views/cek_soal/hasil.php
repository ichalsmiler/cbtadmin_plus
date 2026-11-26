<?php use App\Libraries\CekSoal; ?>
<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php $cat = CekSoal::catatan($t); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('cek_soal') ?>"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <div class="me-auto">
        <div class="fw-semibold"><?= esc($t['guru_nama']) ?> · <?= esc($t['mapel']) ?></div>
        <div class="small text-muted">
            <?= CekSoal::NAMA_STATUS[CekSoal::status($t)] ?>
            <?php if ($t['selesai_at'] !== null): ?> · dikirim <?= esc(date('d/m/Y H:i', strtotime((string) $t['selesai_at']))) ?><?php endif ?>
        </div>
    </div>
</div>
<?php if (! empty($hasil['konfirmasi'])): ?>
    <div class="alert <?= $cat !== '' ? 'alert-warning' : 'alert-success' ?>">
        <i class="bi bi-check-circle me-1"></i><?= esc((string) ($hasil['pernyataan'] ?? CekSoal::pernyataan($t))) ?>
        <?php if ($cat !== ''): ?><div class="mt-2"><i class="bi bi-chat-left-text me-1"></i><strong>Catatan guru:</strong> <?= nl2br(esc($cat)) ?></div><?php endif ?>
    </div>
<?php endif ?>
<?php if ($gagal !== null): ?><div class="alert alert-danger">Soal tidak bisa dimuat: <?= esc($gagal) ?></div><?php endif ?>
<?php foreach ($isi as $q): ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="fw-semibold me-auto">Nomor <?= (int) $q['slot'] ?></span>
            <a class="btn btn-outline-primary btn-sm<?= $adminRole === 'kurikulum' ? ' d-none' : '' ?>" href="<?= site_url('soal/edit') ?>?mapel=<?= (int) $t['courseid'] ?>#soal-<?= (int) $q['slot'] ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
        </div>
        <div class="card-body review-soal soal-pratinjau">
            <?= $q['teks'] ?>
            <?php if ($q['opsi'] !== []): ?>
                <ol type="A" class="mb-0">
                    <?php foreach ($q['opsi'] as $o): ?>
                        <li class="<?= $o['kunci'] ? 'review-kunci' : '' ?>"><?= $o['teks'] ?><?= $o['kunci'] ? ' <i class="bi bi-check-circle-fill text-success" aria-label="kunci"></i>' : '' ?></li>
                    <?php endforeach ?>
                </ol>
            <?php endif ?>
        </div>
    </div>
<?php endforeach ?>
<?= $this->endSection() ?>
