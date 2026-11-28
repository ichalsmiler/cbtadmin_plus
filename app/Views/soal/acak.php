<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<form class="card" method="post" action="<?= site_url('acak_soal/simpan') ?>" id="acakForm">
    <?= csrf_field() ?>
    <input type="hidden" name="kat" value="<?= (int) $katId ?>">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <span class="fw-semibold me-auto"><i class="bi bi-shuffle me-1"></i>Acak Soal per Mapel</span>
        <select class="form-select form-select-sm w-auto" aria-label="Kategori ujian" onchange="location.href='<?= site_url('acak_soal') ?>' + (this.value ? '?kat=' + this.value : '')">
            <option value="">Semua ujian</option>
            <?php foreach ($kategori as $k): ?>
                <option value="<?= (int) $k['id'] ?>" <?= (int) $k['id'] === $katId ? 'selected' : '' ?>><?= esc($k['name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-2 align-items-end">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small mb-1" for="ac_soal">Urutan soal</label>
                <select class="form-select" id="ac_soal" name="soal">
                    <option value="">Tidak diubah</option>
                    <option value="1">Acak</option>
                    <option value="0">Urut (sesuai nomor)</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small mb-1" for="ac_opsi">Opsi jawaban</label>
                <select class="form-select" id="ac_opsi" name="opsi">
                    <option value="">Tidak diubah</option>
                    <option value="1">Acak</option>
                    <option value="0">Urut (A, B, C, …)</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-3">
                <button class="btn btn-primary w-100" type="submit" id="ac_kirim" disabled><i class="bi bi-check-lg me-1"></i>Terapkan</button>
            </div>
            <div class="col-lg-3 small text-body-secondary" id="ac_jumlah" aria-live="polite"></div>
        </div>
        <div class="form-text">Berlaku untuk siswa yang mulai mengerjakan sesudah disimpan; siswa yang sudah mulai tetap memakai susunan soalnya. Soal acak berbeda tiap siswa, nomor di analisis soal tetap mengikuti nomor asli.</div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th class="kolom-cek"><input class="form-check-input" type="checkbox" id="ac_semua" aria-label="Centang semua mapel"></th>
                <th>Mapel</th><th>Ujian</th><th class="text-center">Soal</th><th class="text-center">Acak soal</th><th class="text-center">Acak opsi</th><th class="text-center">Dikerjakan</th>
            </tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-muted">Belum ada quiz.</td></tr>
            <?php endif ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="kolom-cek"><input class="form-check-input" type="checkbox" name="quiz[]" value="<?= (int) $r['id'] ?>" data-ac-cek aria-label="Pilih <?= esc($r['course_name'], 'attr') ?>"></td>
                    <td><span class="fw-semibold d-block"><?= esc($r['course_name']) ?></span><span class="small text-body-secondary"><?= esc($r['course_short']) ?></span></td>
                    <td><?= esc($r['category_name']) ?></td>
                    <td class="text-center"><?= (int) $r['jml_soal'] ?></td>
                    <?php foreach (['soal' => ['acak_soal', 'urutan soal'], 'opsi' => ['acak_opsi', 'opsi jawaban']] as $f => [$k, $lbl]): $on = $r[$k]; ?>
                    <td class="text-center"><button type="button" class="badge border-0 <?= $on ? 'text-bg-success' : 'text-bg-secondary' ?>"
                        title="Klik untuk mengubah jadi <?= $on ? 'Urut' : 'Acak' ?>"
                        data-post="<?= site_url('acak_soal/simpan') ?>"
                        data-fields='<?= esc(json_encode(['quiz[]' => (int) $r['id'], $f => $on ? '0' : '1', 'kat' => $katId]), 'attr') ?>'
                        data-confirm-text="<?= esc('Ubah ' . $lbl . ' ' . $r['course_name'] . ' jadi ' . ($on ? 'Urut' : 'Acak') . '? Berlaku untuk siswa yang mulai mengerjakan sesudah ini.', 'attr') ?>"><?= $on ? 'Acak' : 'Urut' ?></button></td>
                    <?php endforeach ?>
                    <td class="text-center"><?= $r['berjalan'] > 0 ? '<span class="badge text-bg-warning">' . (int) $r['berjalan'] . ' siswa</span>' : '<span class="text-body-secondary">–</span>' ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</form>
<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var cek = Array.prototype.slice.call(document.querySelectorAll('[data-ac-cek]'));
    var semua = document.getElementById('ac_semua');
    var soal = document.getElementById('ac_soal');
    var opsi = document.getElementById('ac_opsi');
    var hitung = function () {
        var n = cek.filter(function (el) { return el.checked; }).length;
        document.getElementById('ac_kirim').disabled = n === 0 || (!soal.value && !opsi.value);
        document.getElementById('ac_jumlah').textContent = n ? n + ' mapel dicentang' : 'Centang mapel yang akan diatur.';
        semua.checked = n > 0 && n === cek.length;
        semua.indeterminate = n > 0 && n < cek.length;
    };
    semua.disabled = cek.length === 0;
    semua.addEventListener('change', function () { cek.forEach(function (el) { el.checked = semua.checked; }); hitung(); });
    cek.forEach(function (el) { el.addEventListener('change', hitung); });
    soal.addEventListener('change', hitung);
    opsi.addEventListener('change', hitung);
    hitung();
})();
</script>
<?= $this->endSection() ?>
