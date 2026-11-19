<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>
<div class="row g-4">
    <div class="col-lg-7">
        <form class="card" method="post" action="<?= site_url('soal/periksa') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><i class="bi bi-file-earmark-arrow-up me-1"></i>Masukkan soal ke Moodle</div>
            <div class="card-body">
                <label class="form-label" for="s_mapel">Mapel tujuan</label>
                <select class="form-select" id="s_mapel" name="mapel" required>
                    <option value="">Pilih mapel…</option>
                    <?php $kat = null; foreach ($mapel as $m): ?>
                        <?php if ($m['kategori'] !== $kat): ?><?= $kat !== null ? '</optgroup>' : '' ?><optgroup label="<?= esc($m['kategori'], 'attr') ?>"><?php $kat = $m['kategori']; endif ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === $dipilih ? 'selected' : '' ?>>
                            <?= esc($m['fullname']) ?> (<?= esc($m['shortname']) ?>)<?= (int) $m['soal'] > 0 ? ' · ' . (int) $m['soal'] . ' soal' : '' ?>
                        </option>
                    <?php endforeach ?>
                    <?= $kat !== null ? '</optgroup>' : '' ?>
                </select>
                <label class="form-label mt-3" for="s_berkas">Berkas soal (.rtf)</label>
                <input class="form-control" type="file" id="s_berkas" name="berkas" accept=".rtf,.doc" required>
                <p class="small text-body-secondary mt-2 mb-0">Berkas belum dikirim ke Moodle: langkah berikutnya menampilkan pratinjau soal dan kuncinya untuk diperiksa dulu.</p>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary" type="submit">Periksa soal</button></div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Format berkas</div>
            <div class="card-body small">
                <ul class="ps-3 mb-2">
                    <li>Soal pilihan ganda: teks bacaan/pertanyaan (boleh bergambar), lalu opsi berupa <strong>daftar berhuruf</strong> (a, b, c, d, e).</li>
                    <li>Di bawah setiap soal ada baris kunci <code>ANS: C</code>.</li>
                    <li>Judul bagian (<em>Multiple choice</em>) dan baris info (PTS:, DIF:, …) diabaikan. Soal essay/benar-salah dilewati.</li>
                    <li>Simpan dari Word sebagai <strong>Rich Text Format (.rtf)</strong>; gambar JPEG/PNG ikut terbawa.</li>
                </ul>
                <p class="mb-0 text-body-secondary">Soal masuk ke quiz mapel itu (quiz dibuat otomatis bila belum ada), opsi diacak, setiap soal bernilai sama, nilai maksimal 100.</p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
