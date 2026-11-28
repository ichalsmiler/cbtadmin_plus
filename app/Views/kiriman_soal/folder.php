<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$url  = static fn (string $p): string => site_url('kiriman_soal/folder') . ($p !== '' ? '?p=' . rawurlencode($p) : '');
$jalur = $p !== '' ? explode('/', $p) : [];
$ukur = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, (int) ceil($b / 1024)), 0, ',', '.') . ' KB';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <nav aria-label="Lokasi folder">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= site_url('kiriman_soal') ?>">Kiriman Soal</a></li>
            <li class="breadcrumb-item<?= $jalur ? '' : ' active' ?>"><?php if ($jalur): ?><a href="<?= $url('') ?>">kirim_soal</a><?php else: ?>kirim_soal<?php endif ?></li>
            <?php foreach ($jalur as $i => $bag): $ke = implode('/', array_slice($jalur, 0, $i + 1)); ?>
                <li class="breadcrumb-item<?= $i === count($jalur) - 1 ? ' active' : '' ?>"><?php if ($i < count($jalur) - 1): ?><a href="<?= $url($ke) ?>"><?= esc($bag) ?></a><?php else: ?><?= esc($bag) ?><?php endif ?></li>
            <?php endforeach ?>
        </ol>
    </nav>
    <?php if ($isi): ?>
        <a class="btn btn-outline-secondary btn-sm ms-auto" href="<?= $url($p) . ($p !== '' ? '&' : '?') . 'zip=1' ?>"><i class="bi bi-file-zip me-1"></i>Unduh folder ini (.zip)</a>
    <?php endif ?>
</div>

<?php if (! $isi): ?>
    <p class="text-muted">Folder masih kosong. Folder dibuat saat periode diaktifkan di Kiriman Soal.</p>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Nama</th><th class="text-end">Ukuran</th><th>Diubah</th></tr></thead>
    <tbody>
    <?php if ($jalur): ?>
        <tr><td colspan="3"><a href="<?= $url(implode('/', array_slice($jalur, 0, -1))) ?>"><i class="bi bi-arrow-90deg-up me-2"></i>..</a></td></tr>
    <?php endif ?>
    <?php foreach ($isi as $f): $ke = ($p !== '' ? $p . '/' : '') . $f['nama']; ?>
        <tr>
            <td>
                <a href="<?= $url($ke) ?>">
                    <i class="bi <?= $f['dir'] ? 'bi-folder-fill text-warning' : (str_ends_with($f['nama'], '.rtf') ? 'bi-file-earmark-text' : 'bi-file-earmark-word') ?> me-2"></i><?= esc($f['nama']) ?>
                </a>
            </td>
            <td class="text-end small"><?= $f['dir'] ? $f['jumlah'] . ' isi' : $ukur($f['ukuran']) ?></td>
            <td class="small"><?= date('d-m-Y H:i', $f['waktu']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
<?= $this->endSection() ?>
