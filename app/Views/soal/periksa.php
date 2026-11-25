<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
// Pratinjau: gambar ditampilkan dari data hasil periksa (di Moodle memakai @@PLUGINFILE@@).
$tampil = static function (string $html, array $gambar): string {
    foreach ($gambar as $g) {
        $mime = str_ends_with($g['nama'], '.png') ? 'image/png' : 'image/jpeg';
        $html = str_replace('@@PLUGINFILE@@/' . $g['nama'], 'data:' . $mime . ';base64,' . $g['data'], $html);
    }

    return $html;
};
$adaAttempt = (int) $info['attempt'] > 0;
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <div class="me-auto">
                <div class="fw-semibold"><?= count($soal) ?> soal terbaca dari <?= esc($berkas) ?></div>
                <div class="small text-body-secondary">Tujuan: <?= esc($info['fullname']) ?> (<?= esc($info['shortname']) ?>) · <?= esc($info['kategori']) ?> ·
                    quiz sekarang berisi <?= (int) $info['soal'] ?> soal<?= $adaAttempt ? ', ' . (int) $info['attempt'] . ' attempt siswa' : '' ?></div>
                <?php if (($gambar['asli'] ?? 0) > 0): ?>
                    <?php $kb = static fn (int $b): string => number_format($b / 1024, 0, ',', '.') . ' KB'; ?>
                    <div class="small text-body-secondary">Gambar: <?= $kb((int) $gambar['asli']) ?> → <?= $kb((int) $gambar['jadi']) ?>
                        <?= (int) $gambar['jumlah'] > 0 ? '(' . (int) $gambar['jumlah'] . ' gambar dikecilkan: lebar maks. ' . \App\Libraries\SoalRtf::LEBAR_MAKS . ' px, JPG ≤ 150 KB)' : '(sudah kecil, tidak diubah)' ?></div>
                <?php endif ?>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('soal') ?>?mapel=<?= (int) $info['id'] ?>">Batal</a>
        </div>
        <?php if ($peringatan !== []): ?>
            <div class="alert alert-warning small mt-3 mb-0"><ul class="mb-0 ps-3"><?php foreach ($peringatan as $p): ?><li><?= esc($p) ?></li><?php endforeach ?></ul></div>
        <?php endif ?>
        <form class="mt-3 d-flex flex-wrap gap-3 align-items-center" method="post" action="<?= site_url('soal/kirim') ?>"
              data-confirm="Masukkan <?= count($soal) ?> soal ke quiz <?= esc($info['fullname'], 'attr') ?> di Moodle?">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mode" value="tambah" id="m_tambah" <?= (int) $info['soal'] === 0 || $adaAttempt ? 'checked' : '' ?>>
                <label class="form-check-label" for="m_tambah">Tambahkan ke soal yang ada</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mode" value="ganti" id="m_ganti" <?= $adaAttempt ? 'disabled' : '' ?> <?= (int) $info['soal'] > 0 && ! $adaAttempt ? 'checked' : '' ?>>
                <label class="form-check-label" for="m_ganti">Ganti soal lama<?= $adaAttempt ? ' (tidak bisa: sudah ada jawaban siswa)' : '' ?></label>
            </div>
            <button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-send me-1"></i>Masukkan ke Moodle</button>
        </form>
    </div>
</div>

<?php foreach ($soal as $i => $s): ?>
    <div class="card mb-2">
        <div class="card-body">
            <div class="d-flex gap-2">
                <span class="badge text-bg-secondary align-self-start"><?= $i + 1 ?></span>
                <div class="flex-grow-1 soal-pratinjau">
                    <?= $tampil($s['teks'], $s['gambar']) /* HTML hasil SoalRtf: teks sudah di-escape, hanya tag p/b/i/u/sup/sub/br/img */ ?>
                    <ol type="A" class="mb-0">
                        <?php foreach ($s['opsi'] as $j => $o): ?>
                            <li class="<?= $j === $s['kunci'] ? 'fw-semibold text-success' : '' ?>"><?= $tampil($o, $s['gambar']) ?><?= $j === $s['kunci'] ? ' <i class="bi bi-check-circle-fill" aria-label="kunci"></i>' : '' ?></li>
                        <?php endforeach ?>
                    </ol>
                </div>
            </div>
        </div>
    </div>
<?php endforeach ?>
<?= $this->endSection() ?>
