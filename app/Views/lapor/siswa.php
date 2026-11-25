<?php
use App\Libraries\LaporanSiswa;

$sukses = session()->getFlashdata('success');
$galat  = session()->getFlashdata('error');
$lama   = static fn (string $k): string => (string) old($k, '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporkan<?= $sekolah !== '' ? ' · ' . esc($sekolah) : '' ?></title>
    <?= view('partials/favicon') ?>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc($version) ?>">
</head>
<body class="publik-page">
<main class="container py-3" style="max-width:640px">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-flag"></i> Laporkan</h1>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="history.back()"><i class="bi bi-arrow-left"></i> Kembali ke ujian</button>
    </div>

    <?php if ($sukses): ?><div class="alert alert-success" role="status"><?= esc($sukses) ?></div><?php endif ?>
    <?php if ($galat): ?><div class="alert alert-danger" role="alert"><?= esc($galat) ?></div><?php endif ?>

<?php if ($p === null): ?>
    <div class="alert alert-warning"><?= esc($tutup) ?></div>
<?php else: ?>
    <div class="small text-muted mb-3"><?= esc($p['nama']) ?> · <?= esc($p['kelas']) ?><?= ! empty($p['ruang_nama']) ? ' · ' . esc($p['ruang_nama']) : '' ?></div>

    <?php if ($tutup !== ''): ?>
        <div class="alert alert-info"><?= esc($tutup) ?></div>
    <?php elseif ($sisa <= 0): ?>
        <div class="alert alert-info">Batas <?= LaporanSiswa::MAKS_PER_SESI ?> laporan di sesi ini sudah tercapai. Bila mendesak, sampaikan langsung ke pengawas.</div>
    <?php else: ?>
    <form class="card card-body" method="post" action="<?= site_url('lapor/kirim') ?>" enctype="multipart/form-data" id="formLapor">
        <?= csrf_field() ?>
        <div class="alert alert-secondary small py-2"><i class="bi bi-shield-lock"></i> Identitas pelapor dirahasiakan. Laporan hanya dibaca Proktor.</div>

        <label class="form-label fw-semibold">Jenis laporan</label>
        <div class="btn-group w-100 mb-3" role="group">
            <?php foreach (LaporanSiswa::NAMA_JENIS as $k => $nama): ?>
                <input type="radio" class="btn-check" name="jenis" id="jenis_<?= $k ?>" value="<?= $k ?>" <?= ($lama('jenis') ?: 'curang') === $k ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="jenis_<?= $k ?>"><?= esc($nama) ?></label>
            <?php endforeach ?>
        </div>

        <label class="form-label fw-semibold" for="kategori">Apa yang terjadi?</label>
        <select class="form-select mb-3" name="kategori" id="kategori" required>
            <?php foreach (LaporanSiswa::JENIS as $k => $list): ?>
                <?php foreach ($list as $kat): ?>
                    <option value="<?= esc($kat, 'attr') ?>" data-jenis="<?= $k ?>" <?= $lama('kategori') === $kat ? 'selected' : '' ?>><?= esc($kat) ?></option>
                <?php endforeach ?>
            <?php endforeach ?>
        </select>

        <div id="bagianTerlapor">
            <label class="form-label fw-semibold" for="terlapor">Siswa yang dilaporkan <span class="fw-normal text-muted">(boleh dikosongkan)</span></label>
            <select class="form-select mb-3" name="terlapor_id" id="terlapor">
                <option value="0">Tidak tahu / tidak menyebut nama</option>
                <?php foreach ($teman as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= (int) $lama('terlapor_id') === (int) $t['id'] ? 'selected' : '' ?>><?= esc($t['nama']) ?> (<?= esc($t['kelas']) ?>)</option>
                <?php endforeach ?>
            </select>
        </div>

        <label class="form-label fw-semibold" for="keterangan">Keterangan</label>
        <textarea class="form-control mb-3" name="keterangan" id="keterangan" rows="3" maxlength="1000" required
                  placeholder="Ceritakan singkat: apa, kapan, di mana."><?= esc($lama('keterangan')) ?></textarea>

        <label class="form-label fw-semibold" for="foto">Foto <span class="fw-normal text-muted">(opsional)</span></label>
        <input class="form-control mb-1" type="file" name="foto" id="foto" accept="image/jpeg,image/png,image/webp">
        <div class="form-text mb-3">Ambil foto dengan kamera atau pilih dari galeri.</div>

        <button class="btn btn-danger w-100" type="submit" id="tombolKirim"><i class="bi bi-send"></i> Kirim laporan</button>
        <div class="form-text text-center">Sisa <?= (int) $sisa ?> laporan di sesi ini.</div>
    </form>
    <?php endif ?>

    <?php if ($riwayat !== []): ?>
        <h2 class="h6 mt-4">Laporan Anda hari ini</h2>
        <ul class="list-group">
            <?php foreach ($riwayat as $r): ?>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span>#<?= (int) $r['id'] ?> · <?= esc($r['kategori']) ?> <span class="text-muted small"><?= date('H:i', strtotime($r['created_at'])) ?></span></span>
                    <span class="badge <?= $r['status'] === 'baru' ? 'text-bg-secondary' : 'text-bg-success' ?> align-self-center"><?= $r['status'] === 'baru' ? 'Terkirim' : 'Diterima' ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
<?php endif ?>
</main>
<script>
(function () {
    var form = document.getElementById('formLapor');
    if (!form) return;
    var kat = document.getElementById('kategori'), terlapor = document.getElementById('bagianTerlapor');
    function aturJenis() {
        var j = form.querySelector('[name=jenis]:checked').value, pertama = null;
        Array.prototype.forEach.call(kat.options, function (o) {
            var cocok = o.dataset.jenis === j;
            o.hidden = !cocok; o.disabled = !cocok;
            if (cocok && !pertama) pertama = o;
        });
        if (kat.selectedOptions[0] && kat.selectedOptions[0].disabled) kat.value = pertama.value;
        terlapor.hidden = j !== 'curang';
    }
    form.querySelectorAll('[name=jenis]').forEach(function (r) { r.addEventListener('change', aturJenis); });
    aturJenis();

    // Foto dikecilkan di HP dulu (lebar maks 1280 px, JPEG 70%) bila browser mendukung; server tetap mengecilkan lagi.
    var foto = document.getElementById('foto'), kecil = null;
    foto.addEventListener('change', function () {
        kecil = null;
        var f = foto.files[0];
        if (!f || !window.createImageBitmap || !HTMLCanvasElement.prototype.toBlob) return;
        createImageBitmap(f).then(function (img) {
            var s = Math.min(1, 1280 / img.width), c = document.createElement('canvas');
            c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            c.toBlob(function (b) { if (b && b.size < f.size) kecil = b; }, 'image/jpeg', 0.7);
        }).catch(function () {});
    });

    var tombol = document.getElementById('tombolKirim');
    form.addEventListener('submit', function (e) {
        tombol.disabled = true;
        tombol.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim…';
        if (!kecil || !window.fetch || !window.FormData) return;  // kirim biasa
        e.preventDefault();
        var data = new FormData(form);
        data.set('foto', kecil, 'foto.jpg');
        fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin'})
            .then(function (r) { window.location.href = r.url || form.action.replace(/\/kirim$/, ''); })
            .catch(function () { form.submit(); });
    });
})();
</script>
</body>
</html>
