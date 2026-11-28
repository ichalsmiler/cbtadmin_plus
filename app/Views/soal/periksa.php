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
        <?php if ($cek !== null): ?>
            <div class="alert <?= $cek['pasti'] ? 'alert-success' : 'alert-warning' ?> mt-3 mb-0">
                <div class="d-flex flex-wrap align-items-center gap-2 fs-6">
                    <span><i class="bi bi-person me-1"></i><b><?= esc($cek['mapel']) ?></b> · Kelas <?= esc($cek['tingkat']) ?> · <?= esc($cek['guru']) ?></span>
                    <i class="bi bi-arrow-right"></i>
                    <span><b><?= esc($info['fullname']) ?></b> <span class="small">(<?= esc($info['shortname']) ?>)</span></span>
                </div>
                <div class="small mt-1">
                    <?php if ($cek['pasti']): ?>
                        <i class="bi bi-check-circle-fill me-1"></i>Pasangan mapel ini sudah pernah dipastikan.
                    <?php elseif ($cek['petaLama'] !== null): ?>
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Berbeda dari pasangan tersimpan (<b><?= esc($cek['petaLama']) ?></b>). Pastikan mapel tujuan benar; pasangan diperbarui setelah soal masuk.
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Pasangan mapel ini belum pernah dipastikan (tebakan dari nama). Setelah soal masuk, pasangan ini disimpan untuk kiriman berikutnya.
                    <?php endif ?>
                    <?php if ($cek['tanpaKls']): ?><br><i class="bi bi-exclamation-triangle-fill me-1"></i>Kelas mapel tujuan tidak terbaca dari shortname/nama: periksa sendiri.<?php endif ?>
                    <?php if ($cek['bedaJml'] > 0): ?><br><i class="bi bi-exclamation-triangle-fill me-1"></i>Guru mengirim <?= $cek['bedaJml'] ?> soal, yang terbaca <?= count($soal) ?> soal.<?php endif ?>
                </div>
            </div>
        <?php endif ?>
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
            <?php $wajib = $cek !== null && ! $cek['pasti']; ?>
            <?php if ($wajib): ?>
                <div class="form-check ms-lg-auto">
                    <input class="form-check-input" type="checkbox" name="yakin" value="1" id="m_yakin" required
                           onchange="document.getElementById('m_kirim').disabled = !this.checked">
                    <label class="form-check-label fw-semibold" for="m_yakin">Mapel tujuan sudah benar</label>
                </div>
            <?php endif ?>
            <button class="btn btn-primary <?= $wajib ? '' : 'ms-auto' ?>" id="m_kirim" type="submit" <?= $wajib ? 'disabled' : '' ?>><i class="bi bi-send me-1"></i>Masukkan ke Moodle</button>
        </form>
    </div>
</div>

<?php if ($aiSiap): ?>
<div class="card mb-3" id="aiKartu">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button class="btn btn-outline-primary" type="button" id="aiTombol"><i class="bi bi-stars me-1"></i>Cek dengan AI</button>
            <span class="small text-body-secondary" id="aiStatus">Kunci, opsi, kalimat soal, dan salah ketik ditelaah AI. Bisa 1–3 menit.</span>
        </div>
        <div id="aiHasil" class="mt-3" hidden></div>
    </div>
</div>
<?php endif ?>

<?php foreach ($soal as $i => $s): ?>
    <div class="card mb-2" id="soal-<?= $i + 1 ?>">
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
                    <div class="ai-catatan"></div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach ?>
<?= $this->endSection() ?>

<?php if ($aiSiap): ?>
<?= $this->section('scripts') ?>
<script>
// Cek dengan AI: kirim token periksa, tampilkan temuan di atas dan di kartu soalnya. Token CSRF diperbarui dari
// jawaban supaya tombol "Masukkan ke Moodle" tetap sah.
(function () {
    var tombol = document.getElementById('aiTombol'), status = document.getElementById('aiStatus'), hasil = document.getElementById('aiHasil');
    var csrfNama = '<?= csrf_token() ?>';
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    var label = { kunci: 'Kunci', opsi: 'Opsi', soal: 'Soal', ejaan: 'Ejaan', gambar: 'Gambar', lain: 'Lain' };
    tombol.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('token', '<?= esc($token, 'js') ?>');
        fd.append(csrfNama, document.querySelector('input[name="' + csrfNama + '"]').value);
        tombol.disabled = true;
        status.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>AI sedang menelaah soal…';
        fetch('<?= site_url('soal/ai_cek') ?>', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.csrf) { document.querySelectorAll('input[name="' + csrfNama + '"]').forEach(function (i) { i.value = d.csrf; }); }
                document.querySelectorAll('.ai-catatan').forEach(function (el) { el.innerHTML = ''; });
                if (!d.ok) { status.textContent = d.pesan || 'Gagal.'; tombol.disabled = false; return; }
                var t = d.temuan || [], penting = t.filter(function (x) { return x.tingkat === 'penting'; }).length;
                status.textContent = 'Selesai (' + d.model + '): ' + t.length + ' temuan, ' + penting + ' penting.';
                var html = '<p class="mb-2">' + esc(d.ringkasan) + '</p>';
                if (t.length) {
                    html += '<ul class="list-group list-group-flush small">';
                    t.sort(function (a, b) { return (a.tingkat === b.tingkat ? 0 : a.tingkat === 'penting' ? -1 : 1) || a.nomor - b.nomor; });
                    t.forEach(function (x) {
                        var warna = x.tingkat === 'penting' ? 'text-bg-danger' : 'text-bg-warning';
                        var isi = '<span class="badge ' + warna + ' me-1">' + esc(x.tingkat) + '</span><span class="badge text-bg-light border me-1">' + esc(label[x.jenis] || x.jenis) + '</span>'
                            + esc(x.pesan) + (x.saran_kunci ? ' <b>(saran kunci: ' + esc(x.saran_kunci) + ')</b>' : '');
                        html += '<li class="list-group-item px-0"><a href="#soal-' + x.nomor + '">Soal ' + x.nomor + '</a> · ' + isi + '</li>';
                        var kartu = document.querySelector('#soal-' + x.nomor + ' .ai-catatan');
                        if (kartu) { kartu.insertAdjacentHTML('beforeend', '<div class="alert ' + (x.tingkat === 'penting' ? 'alert-danger' : 'alert-warning') + ' small py-1 px-2 mt-2 mb-0"><i class="bi bi-stars me-1"></i>' + isi + '</div>'); }
                    });
                    html += '</ul>';
                }
                html += '<p class="small text-body-secondary mt-2 mb-0">Saran AI bisa keliru: perbaiki naskah bila perlu (minta guru kirim ulang atau edit setelah masuk di Review &amp; Edit Soal).</p>';
                hasil.innerHTML = html;
                hasil.hidden = false;
                tombol.disabled = false;
                tombol.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Cek ulang';
            })
            .catch(function () { status.textContent = 'Tidak ada jawaban dari server (mungkin terlalu lama). Coba lagi.'; tombol.disabled = false; });
    });
})();
</script>
<?= $this->endSection() ?>
<?php endif ?>
