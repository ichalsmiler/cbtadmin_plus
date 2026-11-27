<?php
/** Halaman cek soal guru (publik, tanpa layout panel). Soal dari jembatan soal_semua (clean_text Moodle). */
$galat = session()->getFlashdata('error');
$watermark = '';
if ($t !== null) {
    $teks = $t['guru_nama'] . ' · ' . date('d/m/Y H:i') . ' · ' . $ip;
    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="220"><text x="10" y="130" transform="rotate(-25 210 110)" '
        . 'font-family="sans-serif" font-size="15" fill="#888" fill-opacity=".22">' . htmlspecialchars($teks, ENT_XML1) . '</text></svg>';
    $watermark = 'data:image/svg+xml;base64,' . base64_encode($svg);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Cek Soal<?= $sekolah !== '' ? ' · ' . esc($sekolah) : '' ?></title>
    <?= view('partials/favicon') ?>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc($version) ?>">
    <?php if ($t !== null && ! $pembuka): ?>
    <style>
        .cek-guru { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
        .cek-guru textarea { -webkit-user-select: text; user-select: text; }
        .cek-watermark { position: fixed; inset: 0; z-index: 1080; pointer-events: none; background-image: url("<?= $watermark ?>"); background-repeat: repeat; }
        @media print { body * { display: none !important; } body::after { content: "Halaman ini tidak boleh dicetak."; display: block; } }
    </style>
    <?php endif ?>
</head>
<body class="publik-page">
<main class="container py-3 <?= $t !== null && ! $pembuka ? 'cek-guru' : '' ?>" style="max-width:820px">
    <h1 class="h4 mb-1"><i class="bi bi-patch-check"></i> Cek Soal</h1>
    <?php if ($sekolah !== ''): ?><div class="small text-muted mb-3"><?= esc($sekolah) ?></div><?php endif ?>

<?php if ($t === null): ?>
    <div class="alert <?= $sukses ? 'alert-success' : 'alert-warning' ?>"><?= esc($pesan) ?></div>
<?php elseif ($pembuka): ?>
    <?php // Halaman pembuka: tanpa soal dan tanpa mengunci (pratinjau tautan WhatsApp/Telegram berhenti di sini). ?>
    <div class="card card-body">
        <p class="mb-1">Yth. <strong><?= esc($t['guru_nama']) ?></strong>,</p>
        <p class="mb-3">mohon cek soal <strong><?= esc($t['mapel']) ?></strong> sebelum batas <?= esc(\App\Libraries\CekSoal::tanggal((string) $t['batas'])) ?>.</p>
        <div class="alert alert-info small mb-3"><i class="bi bi-phone"></i> Setelah tombol di bawah ditekan, tautan ini hanya bisa dibuka di <strong>perangkat dan browser ini</strong>. Bila tautan dibuka dari WhatsApp, sebaiknya pilih <em>Buka di browser</em> (Chrome/Safari) dulu.</div>
        <form method="post" action="<?= site_url('cek/' . $t['token'] . '/buka') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Buka soal</button>
        </form>
    </div>
<?php else: ?>
    <div class="cek-watermark" aria-hidden="true"></div>
    <div class="card card-body mb-3">
        <div class="fw-semibold"><?= esc($t['guru_nama']) ?> · <?= esc($t['mapel']) ?></div>
        <div class="small text-muted">Batas: <?= esc(\App\Libraries\CekSoal::tanggal((string) $t['batas'])) ?> · <?= count($isi) ?> soal · kunci ditandai hijau</div>
        <div class="small mt-2"><i class="bi bi-shield-lock"></i> Halaman ini rahasia dan diberi tanda nama Anda. Mohon tidak difoto atau disebarkan.</div>
    </div>
    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><strong>Penting:</strong> setelah semua soal diperiksa, gulir ke <strong>akhir halaman</strong>, centang pernyataan <strong>setuju</strong>, lalu tekan <strong>Kirim pernyataan</strong>. Pemeriksaan Anda belum tercatat sebelum tombol itu ditekan.</div>
    </div>
    <?php if ($galat): ?><div class="alert alert-danger" role="alert"><?= esc($galat) ?></div><?php endif ?>

    <form method="post" action="<?= site_url('cek/' . $t['token'] . '/kirim') ?>">
        <?= csrf_field() ?>
        <?php foreach ($isi as $i => $s): ?>
            <div class="card mb-3">
                <div class="card-header fw-semibold">Soal <?= $i + 1 ?> <span class="text-muted fw-normal small">(nomor <?= (int) $s['slot'] ?> di quiz)</span></div>
                <div class="card-body review-soal soal-pratinjau">
                    <?= $s['teks'] !== '' ? $s['teks'] : '<p class="text-body-secondary">(tanpa teks)</p>' ?>
                    <?php if ($s['opsi'] !== []): ?>
                        <ol type="A" class="mb-0">
                            <?php foreach ($s['opsi'] as $o): ?>
                                <li class="<?= $o['kunci'] ? 'review-kunci' : '' ?>"><?= $o['teks'] ?><?= $o['kunci'] ? ' <i class="bi bi-check-circle-fill text-success" aria-label="kunci"></i>' : '' ?></li>
                            <?php endforeach ?>
                        </ol>
                    <?php elseif ($s['qtype'] !== 'multichoice'): ?>
                        <p class="small text-body-secondary mb-0">Tipe soal: <?= esc($s['qtype']) ?></p>
                    <?php endif ?>
                </div>
            </div>
        <?php endforeach ?>
        <div class="card mb-3 border-primary" id="kartuSetuju">
            <div class="card-body">
                <div class="alert alert-warning small py-2 mb-3"><i class="bi bi-hand-index-thumb"></i> Centang pernyataan di bawah, lalu tekan <strong>Kirim pernyataan</strong> supaya pemeriksaan tercatat.</div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="setuju" value="1" id="setuju" required>
                    <label class="form-check-label fw-semibold" for="setuju"><?= esc(\App\Libraries\CekSoal::pernyataan($t)) ?></label>
                </div>
                <label class="form-label small" for="catatan">Catatan <span class="text-muted">(opsional, bila ada yang perlu diperbaiki)</span></label>
                <textarea class="form-control" name="catatan" id="catatan" rows="2" maxlength="1000" placeholder="mis. soal 2: kunci seharusnya C"><?= esc((string) old('catatan')) ?></textarea>
            </div>
        </div>
        <button class="btn btn-primary w-100 mb-2" type="submit" onclick="return confirm('Kirim pernyataan? Setelah dikirim, tautan ini ditutup.')"><i class="bi bi-send"></i> Kirim pernyataan</button>
        <div class="form-text text-center mb-4">Setelah dikirim, tautan ini tidak bisa dibuka lagi.</div>
    </form>
    <?php // Pengingat melayang di bawah layar sampai kartu setuju terlihat; tombolnya menggulir ke kartu itu. ?>
    <button type="button" id="ingatSetuju" class="btn btn-warning shadow position-fixed bottom-0 start-50 translate-middle-x mb-3 fw-semibold" style="z-index:1090">
        <i class="bi bi-arrow-down-circle"></i> Jangan lupa centang setuju &amp; kirim di akhir soal
    </button>
    <script>
        (function () {
            var kartu = document.getElementById('kartuSetuju');
            var ingat = document.getElementById('ingatSetuju');
            var form = kartu.closest('form');
            var terkirim = false;
            ingat.addEventListener('click', function () { kartu.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
            if ('IntersectionObserver' in window) {
                new IntersectionObserver(function (e) { ingat.classList.toggle('d-none', e[0].isIntersecting); }).observe(kartu);
            }
            form.addEventListener('submit', function () { terkirim = true; });
            // Menutup/meninggalkan halaman sebelum mengirim: browser menampilkan peringatan bawaannya.
            window.addEventListener('beforeunload', function (e) { if (!terkirim) { e.preventDefault(); e.returnValue = ''; } });
        })();
    </script>
    <script>
        document.addEventListener('contextmenu', function (e) { if (!e.target.closest('textarea')) e.preventDefault(); });
        document.addEventListener('copy', function (e) { if (!e.target.closest || !e.target.closest('textarea')) e.preventDefault(); });
        document.addEventListener('dragstart', function (e) { e.preventDefault(); });
    </script>
<?php endif ?>
</main>
</body>
</html>
