<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
// Gambar kiriman memakai @@PLUGINFILE@@/<nama> (hasil SoalRtf), diganti data URI untuk pratinjau.
$tampil = static function (string $html, array $gambar): string {
    foreach ($gambar as $g) {
        $mime = str_ends_with($g['nama'], '.png') ? 'image/png' : 'image/jpeg';
        $html = str_replace('@@PLUGINFILE@@/' . $g['nama'], 'data:' . $mime . ';base64,' . $g['data'], $html);
    }

    return $html;
};
$r     = $hasil['ringkas'];
$label = [
    'sama'     => ['success', 'check-circle-fill', 'Sama'],
    'beda'     => ['warning', 'exclamation-triangle-fill', 'Berbeda'],
    'kunci'    => ['danger', 'x-octagon-fill', 'Kunci berbeda'],
    'hilang'   => ['danger', 'dash-circle-fill', 'Tidak ada di Moodle'],
    'tambahan' => ['secondary', 'plus-circle-fill', 'Tambahan di Moodle'],
];
$hitungBeda = $r['beda'] + $r['kunci'] + $r['hilang'] + $r['tambahan'];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('kiriman_soal') ?>"><i class="bi bi-arrow-left"></i> Kiriman Soal</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="small text-body-secondary">Kiriman guru</div>
                <div class="fw-semibold"><?= esc($k['mapel']) ?> · Kelas <?= esc($k['tingkat']) ?></div>
                <div class="small"><?= esc($k['guru']) ?> · <?= count($kiriman) ?> soal · dikirim <?= esc(date('d-m-Y H:i', strtotime($k['updated_at']))) ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-body-secondary">Soal di Moodle</div>
                <div class="fw-semibold"><?= esc($course['fullname'] ?? '?') ?> <span class="small text-body-secondary">(<?= esc($course['shortname'] ?? '') ?>)</span></div>
                <div class="small"><?= esc($quiz['nama']) ?> · <?= count($moodle) ?> soal
                    <?php if (! empty($k['masuk_at'])): ?> · dimasukkan <?= esc(date('d-m-Y H:i', strtotime($k['masuk_at']))) ?><?php else: ?> · <span class="text-warning-emphasis">guru mengirim ulang setelah soal dimasukkan</span><?php endif ?></div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
            <?php foreach ($label as $s => [$warna, $ikon, $teks]): if ($r[$s] === 0) { continue; } ?>
                <span class="badge text-bg-<?= $warna ?>"><i class="bi bi-<?= $ikon ?> me-1"></i><?= $r[$s] ?> <?= $teks ?></span>
            <?php endforeach ?>
            <div class="form-check form-switch ms-auto mb-0">
                <input class="form-check-input" type="checkbox" id="bdBeda" <?= $hitungBeda > 0 ? '' : 'disabled' ?>>
                <label class="form-check-label small" for="bdBeda">Tampilkan yang berbeda saja</label>
            </div>
        </div>
        <?php if ($hitungBeda === 0): ?>
            <div class="alert alert-success small mt-3 mb-0">Semua soal di Moodle sama dengan kiriman guru: teks, opsi, kunci, dan jumlah gambar.</div>
        <?php endif ?>
        <div class="form-text mt-2">Soal dipasangkan menurut kemiripan isi, bukan nomor. Pembanding sisi kiri = berkas RTF hasil konversi yang dikirim guru.</div>
    </div>
</div>

<?php foreach ($hasil['baris'] as $b):
    [$warna, $ikon, $teks] = $label[$b['status']];
    $sk = $b['k'] !== null ? $kiriman[$b['k']] : null;
    $sm = $b['m'] !== null ? $moodle[$b['m']] : null;
    ?>
    <div class="card mb-3 bd-baris" data-status="<?= $b['status'] ?>">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="badge text-bg-<?= $warna ?>"><i class="bi bi-<?= $ikon ?> me-1"></i><?= $teks ?></span>
            <span class="small">Kiriman no. <b><?= $sk !== null ? $b['k'] + 1 : '–' ?></b> · Moodle no. <b><?= $sm !== null ? (int) $sm['slot'] : '–' ?></b></span>
            <?php if ($b['status'] !== 'sama'): ?><span class="small text-body-secondary ms-auto"><?= esc(implode(' · ', $b['catatan'])) ?></span><?php endif ?>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-body-secondary mb-1">Kiriman guru</div>
                    <?php if ($sk !== null): ?>
                        <div class="soal-pratinjau"><?= $tampil($sk['teks'], $sk['gambar']) /* HTML SoalRtf: teks sudah di-escape */ ?></div>
                        <ol type="A" class="mb-0">
                            <?php foreach ($sk['opsi'] as $j => $o): $kunci = $j === (int) $sk['kunci']; ?>
                                <li class="<?= $kunci ? 'fw-semibold text-success' : '' ?> <?= in_array($j, $b['opsiBeda'], true) ? 'bg-warning-subtle' : '' ?>"><span class="soal-pratinjau"><?= $tampil($o, $sk['gambar']) ?></span><?= $kunci ? ' <i class="bi bi-check-circle-fill" aria-label="kunci"></i>' : '' ?></li>
                            <?php endforeach ?>
                        </ol>
                    <?php else: ?>
                        <div class="text-body-secondary small">Tidak ada di kiriman.</div>
                    <?php endif ?>
                </div>
                <div class="col-md-6">
                    <div class="small text-body-secondary mb-1">Moodle</div>
                    <?php if ($sm !== null): ?>
                        <div class="soal-pratinjau"><?= $sm['teks'] /* sudah clean_text() di Moodle */ ?></div>
                        <ol type="A" class="mb-0">
                            <?php foreach ($sm['opsi'] as $j => $o): ?>
                                <li class="<?= $o['kunci'] ? 'fw-semibold text-success' : '' ?> <?= in_array($j, $b['opsiBeda'], true) ? 'bg-warning-subtle' : '' ?>"><span class="soal-pratinjau"><?= $o['teks'] ?></span><?= $o['kunci'] ? ' <i class="bi bi-check-circle-fill" aria-label="kunci"></i>' : '' ?></li>
                            <?php endforeach ?>
                        </ol>
                    <?php else: ?>
                        <div class="text-body-secondary small">Tidak ada di quiz Moodle.</div>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach ?>

<script>
document.getElementById('bdBeda').addEventListener('change', function () {
    document.querySelectorAll('.bd-baris[data-status="sama"]').forEach((el) => el.classList.toggle('d-none', this.checked));
});
</script>
<?= $this->endSection() ?>
