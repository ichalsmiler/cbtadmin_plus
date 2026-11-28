<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$catId   = $category ? (int) $category['id'] : 0;
$shownId  = $shown ? (int) $shown['id'] : 0;
$indent   = static fn ($c) => str_repeat('— ', max(0, (int) $c['depth'] - 1));
?>
<?php if ($moodleError): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<div class="card">
    <div class="card-body">
        <form method="post" data-no-dirty action="<?= site_url('status_tes/kategori') ?>">
            <?= csrf_field() ?>
            <div class="tes-bagian">
                <label class="tes-judul" for="sesi_id">Sesi ujian</label>
                <p class="tes-ket">Wajib dipilih. Pengawas hanya melihat siswa sesi ini. Bila sesi dinonaktifkan, semua sesi tertutup: semua mapel disembunyikan (siswa yang sedang mengerjakan terhenti), quiz dikunci password bawaan, dan token pengawas disembunyikan.</p>
                <?php $sesiAktifRow = null; foreach ($sesiList as $s) { if ($s['id'] === $sesiAktif) { $sesiAktifRow = $s; } } ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <?php if ($sesiAktifRow !== null): ?>
                        <span class="badge text-bg-success fs-6 fw-normal"><i class="bi bi-broadcast me-1"></i>Aktif: <?= esc($sesiAktifRow['label']) ?></span>
                        <button class="btn btn-outline-danger btn-sm ms-auto" type="submit" formaction="<?= site_url('status_tes/sesi') ?>" formnovalidate name="aksi" value="nonaktifkan"
                                onclick="return confirm('Nonaktifkan <?= esc($sesiAktifRow['label'], 'js') ?>? Semua sesi tertutup: semua mapel disembunyikan dan quiz dikunci password bawaan. Siswa yang sedang mengerjakan LANGSUNG TERHENTI (jawaban tersimpan tetap ada).')">
                            <i class="bi bi-stop-circle me-1"></i>Nonaktifkan sesi
                        </button>
                    <?php else: ?>
                        <span class="badge text-bg-secondary fs-6 fw-normal"><i class="bi bi-slash-circle me-1"></i>Semua sesi nonaktif</span>
                    <?php endif ?>
                </div>
            <div class="input-group">
            <select class="form-select" id="sesi_id" name="sesi_id" required>
                <option value="">Pilih Sesi</option>
                <?php foreach ($sesiList as $s): ?>
                    <option value="<?= $s['id'] ?>" data-tingkat="<?= esc($s['tingkat'], 'attr') ?>" <?= $s['id'] === $sesiPilih ? 'selected' : '' ?>>
                        <?= esc($s['label']) ?> · <?= number_format($s['jumlah'], 0, ',', '.') ?> peserta<?= $s['id'] === $sesiAktif ? ' — aktif' : '' ?>
                    </option>
                <?php endforeach ?>
            </select>
                <button class="btn btn-success" type="submit" formaction="<?= site_url('status_tes/sesi') ?>" formnovalidate name="aksi" value="aktifkan"
                        title="Aktifkan sesi yang dipilih tanpa mengubah tes dan mapel">
                    <i class="bi bi-play-circle me-1"></i>Aktifkan
                </button>
            </div>
            <?php if ($sesiList === []): ?>
                <div class="alert alert-warning small py-2 mt-2 mb-0">Belum ada sesi. Impor peserta (kolom sesi), atau atur sesi per tingkatan di Admin Tools → <a href="<?= site_url('sesi_tingkat') ?>">Sesi</a> lalu centang "Terapkan".</div>
            <?php endif ?>
            </div>

            <div class="tes-bagian">
            <label class="tes-judul" for="kat_id">Tes</label>
            <p class="tes-ket">Kategori kursus Moodle yang dibuka untuk ujian.</p>
            <select class="form-select" id="kat_id" name="kat_id" required data-no-dirty>
                <option value="">Pilih Test</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $shownId ? 'selected' : '' ?>>
                        <?= esc($indent($c) . $c['name']) ?><?= (int) $c['id'] === $catId ? ' — aktif' : '' ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?php if ($shown && $shownId !== $catId): ?>
                <div class="alert alert-warning small py-2 mt-3 mb-0">
                    Anda sedang melihat <strong><?= esc($shown['name']) ?></strong>. Klik <strong>Simpan</strong> untuk menjadikannya tes aktif
                    <?= $category ? '(menggantikan ' . esc($category['name']) . ')' : '' ?>.
                </div>
            <?php endif ?>
            </div>

            <?php if ($shown): ?>
            <div class="tes-bagian">
                <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                    <div>
                        <span class="tes-judul">Mata pelajaran</span>
                        <p class="tes-ket mb-0">Isi <?= esc($shown['name']) ?>. Hanya mapel yang aktif tampil untuk siswa.</p>
                    </div>
                    <?php if ($courses !== []): ?>
                        <span class="ms-auto d-flex gap-2">
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-mapel-all="1">Aktifkan semua</button>
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-mapel-all="0">Matikan semua</button>
                        </span>
                    <?php endif ?>
                </div>
                <?php if ($courses === []): ?>
                    <p class="text-muted border rounded p-3 mb-0">Belum ada mata pelajaran (kursus) di kategori ini.</p>
                <?php else: ?>
                    <div class="list-group mapel-list">
                        <?php foreach ($courses as $c): ?>
                            <?php
                            // Tingkatan mapel dari kode (USBK-X-BING) atau nama (BHS INGGRIS X); kosong = tampil di semua sesi.
                            $tk = '';
                            foreach ([$c['shortname'], $c['fullname']] as $teks) {
                                if ($tk === '' && preg_match('/(?:^|[\s\-_])(XII|XI|X)(?=$|[\s\-_])/i', (string) $teks, $m)) {
                                    $tk = strtoupper($m[1]);
                                }
                            }
                            ?>
                            <label class="list-group-item d-flex align-items-center gap-3" for="mapel<?= (int) $c['id'] ?>" data-tingkat="<?= $tk ?>">
                                <span class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="ids[]" value="<?= (int) $c['id'] ?>"
                                           id="mapel<?= (int) $c['id'] ?>" <?= (int) $c['visible'] ? 'checked' : '' ?>>
                                </span>
                                <span class="flex-grow-1">
                                    <span class="d-block"><?= esc($c['fullname']) ?></span>
                                    <span class="d-block small text-muted"><?= esc($c['shortname']) ?><?= (int) $c['category'] !== $shownId ? ' · ' . esc($c['category_name']) : '' ?></span>
                                </span>
                                <span class="badge mapel-state"></span>
                            </label>
                        <?php endforeach ?>
                    </div>
                    <p class="small text-muted mt-2 mb-0" id="mapelCount"></p>
                <?php endif ?>
            </div>
            <?php endif ?>

            <button class="btn btn-primary mt-4" type="submit"><i class="bi bi-save me-1"></i>Simpan</button>
        </form>
    </div>
</div>

<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    // Mapel: hanya mapel tingkatan sesi yang dipilih yang tampil (mapel tanpa tingkatan selalu tampil);
    // mapel tingkatan lain dinonaktifkan (tidak ikut dikirim, jadi tersembunyi dari siswa setelah Simpan).
    // Label Aktif/Nonaktif per baris, jumlah yang aktif, dan tombol semua hanya untuk mapel yang tampil.
    var boxes = document.querySelectorAll('.mapel-list input[name="ids[]"]');
    var sesiSelect = document.getElementById('sesi_id');
    var terlihat = function (box) { return !box.disabled; };
    var saring = function () {
        var opt = sesiSelect ? sesiSelect.options[sesiSelect.selectedIndex] : null;
        var tingkat = opt && opt.dataset.tingkat ? opt.dataset.tingkat.split('/') : [];
        boxes.forEach(function (box) {
            var item = box.closest('.list-group-item');
            var t = item.dataset.tingkat || '';
            var tampil = tingkat.length === 0 || t === '' || tingkat.indexOf(t) !== -1;
            item.classList.toggle('d-none', !tampil);
            box.disabled = !tampil;
        });
    };
    var refresh = function () {
        var on = 0, total = 0;
        boxes.forEach(function (box) {
            var badge = box.closest('.list-group-item').querySelector('.mapel-state');
            badge.textContent = box.checked ? 'Aktif' : 'Nonaktif';
            badge.className = 'badge mapel-state ' + (box.checked ? 'text-bg-success' : 'text-bg-secondary');
            if (terlihat(box)) { total++; if (box.checked) { on++; } }
        });
        var count = document.getElementById('mapelCount');
        var lain = boxes.length - total;
        if (count) { count.textContent = on + ' dari ' + total + ' mapel aktif' + (lain > 0 ? ' (' + lain + ' mapel tingkatan lain tidak ditampilkan dan ikut disembunyikan)' : '') + '. Setelah disimpan, mapel nonaktif, kategori lain, dan semua course di dalamnya disembunyikan dari peserta.'; }
    };
    boxes.forEach(function (box) { box.addEventListener('change', refresh); });
    document.querySelectorAll('[data-mapel-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var on = button.dataset.mapelAll === '1';
            boxes.forEach(function (box) { if (terlihat(box)) { box.checked = on; } });
            refresh();
        });
    });
    if (sesiSelect) { sesiSelect.addEventListener('change', function () { saring(); refresh(); }); }
    saring();
    refresh();

    // Ganti pilihan tes: tampilkan mapel kategori itu (belum disimpan).
    var select = document.getElementById('kat_id');
    select.addEventListener('change', function () {
        if (select.value !== '') {
            var sesi = document.getElementById('sesi_id');
            window.location.href = '<?= site_url('status_tes') ?>?kat=' + encodeURIComponent(select.value)
                + (sesi && sesi.value !== '' ? '&sesi=' + encodeURIComponent(sesi.value) : '');
        }
    });
})();
</script>
<?= $this->endSection() ?>
