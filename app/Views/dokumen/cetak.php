<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
// Pengingat jam ujian per tingkatan (Admin Tools → Sesi) sebelum mencetak kartu / daftar hadir.
$jamTingkat = [];
foreach (\App\Libraries\Tingkat::LIST as $t) {
    $jamTingkat[$t] = \App\Libraries\Tingkat::jam($t);
}
$jamKosong = array_keys(array_filter($jamTingkat, static fn ($j) => $j === ''));
?>
<?php if ($jamKosong !== []): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
        <i class="bi bi-clock-history fs-5"></i>
        <div class="me-auto">
            <strong>Jam ujian belum diatur</strong> untuk Kelas <?= esc(implode(', ', $jamKosong)) ?>.
            Kartu peserta kelas itu hanya mencetak nama sesi tanpa jam.
        </div>
        <a class="btn btn-warning btn-sm" href="<?= site_url('sesi_tingkat') ?>"><i class="bi bi-gear me-1"></i>Atur jam di menu Sesi</a>
    </div>
<?php else: ?>
    <div class="alert alert-success d-flex flex-wrap align-items-center gap-2 py-2" role="status">
        <i class="bi bi-clock"></i>
        <span class="me-auto">Jam ujian: <?= esc(implode(' · ', array_map(static fn ($t, $j) => "Kelas $t $j", array_keys($jamTingkat), $jamTingkat))) ?></span>
        <a class="small" href="<?= site_url('sesi_tingkat') ?>">Ubah</a>
    </div>
<?php endif ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Cetak kartu peserta atau daftar hadir</div>
            <div class="card-body">
                <form method="get" target="_blank" id="cetakForm" data-pdf>
                    <div class="mb-3">
                        <span class="form-label d-block">Cetak</span>
                        <div class="btn-group" role="group" aria-label="Cetak per">
                            <input type="radio" class="btn-check" name="per" id="perRuang" value="ruang" checked>
                            <label class="btn btn-outline-primary" for="perRuang"><i class="bi bi-door-open me-1"></i>Per Ruang</label>
                            <input type="radio" class="btn-check" name="per" id="perKelas" value="kelas">
                            <label class="btn btn-outline-primary" for="perKelas"><i class="bi bi-people me-1"></i>Per Kelas</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <?= view('partials/filter_tingkat', ['tingkat' => \App\Libraries\Tingkat::dariRequest(), 'size' => '', 'autoSubmit' => false]) ?>
                        </div>
                        <div class="col-sm-6" data-per="ruang">
                            <label class="form-label" for="fRuang">Ruang</label>
                            <select class="form-select" id="fRuang" name="ruang">
                                <option value="" data-semua="Semua ruang">Semua ruang (<?= (int) $total ?> peserta)</option>
                                <option value="0">(tanpa ruang)</option>
                                <?php foreach ($ruangList as $r): ?>
                                    <option value="<?= (int) $r['id'] ?>" data-kelas="<?= esc((string) $r['kelas'], 'attr') ?>"><?= esc($r['nama']) ?> (<?= (int) $r['jumlah'] ?> peserta)</option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-sm-6 d-none" data-per="kelas">
                            <label class="form-label" for="fKelas">Kelas</label>
                            <select class="form-select" id="fKelas" name="kelas" disabled>
                                <option value="__semua" data-semua="Semua kelas">Semua kelas (<?= (int) $total ?> peserta)</option>
                                <?php foreach ($kelasList as $k): ?>
                                    <option value="<?= esc($k['kelas'], 'attr') ?>" data-jumlah="<?= (int) $k['jumlah'] ?>"><?= esc($k['kelas'] !== '' ? $k['kelas'] : '(tanpa kelas)') ?> (<?= (int) $k['jumlah'] ?> peserta)</option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="fSesi">Sesi</label>
                            <select class="form-select" id="fSesi" name="sesi">
                                <option value="">Semua sesi</option>
                                <?php foreach ($sesiList as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>"><?= esc($s['nama']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="fQuiz">Mata pelajaran untuk daftar hadir (opsional)</label>
                            <select class="form-select" id="fQuiz" name="quiz">
                                <option value="">(dikosongkan, diisi tangan)</option>
                                <?php foreach ($quizzes as $q): ?>
                                    <option value="<?= (int) $q['id'] ?>"><?= esc($q['course_short'] . ' · ' . $q['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button class="btn btn-primary" type="submit" name="cetak" value="1" data-no-pdf formaction="<?= site_url('dokumen/cetak_doc/kartu') ?>"><i class="bi bi-printer me-1"></i>Cetak Kartu Peserta</button>
                        <button class="btn btn-outline-secondary" type="submit" formaction="<?= site_url('dokumen/cetak_doc/kartu') ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Kartu PDF</button>
                        <input type="hidden" name="token_unduh" id="tokenUnduh" value="" disabled>
                        <button class="btn btn-outline-secondary" type="submit" id="btnZip" data-no-pdf formtarget="_self" formaction="<?= site_url('dokumen/cetak_doc/kartu_zip') ?>" title="Satu PDF per kelas (Per Kelas) atau per ruang (Per Ruang), dalam satu ZIP"><i class="bi bi-file-earmark-zip me-1"></i>Kartu per Berkas (ZIP)</button>
                        <button class="btn btn-outline-primary" type="submit" formaction="<?= site_url('dokumen/cetak_doc/daftar_hadir') ?>"><i class="bi bi-list-check me-1"></i>Cetak Daftar Hadir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body small text-muted">
                <p class="mb-2"><strong>Kartu peserta</strong>: 8 kartu per halaman A4, berisi nama, nomor peserta, username, password, ruang, sesi, nama kegiatan, logo, dan tanda tangan kepala sekolah.</p>
                <p class="mb-2"><strong>Per Ruang / Per Kelas</strong>: kartu dikelompokkan menurut ruang atau kelas; tiap ruang/kelas mulai di halaman baru dengan namanya tertulis di atas, jadi mudah dipotong dan dibagikan. Pilih "Semua" untuk mencetak semuanya sekaligus.</p>
                <p class="mb-2"><strong>Daftar hadir</strong>: satu halaman per ruang dan sesi, dengan kolom tanda tangan dan tempat tanda tangan pengawas.</p>
                <p class="mb-0">Nama kegiatan, identitas sekolah, logo, dan kepala sekolah diatur di <a href="<?= site_url('tools/cbtadmin') ?>">Tools → Info Sekolah</a>. <strong>Cetak Kartu Peserta</strong> membuka halaman siap cetak di tab baru lalu langsung memunculkan dialog print browser (pilih A4, skala 100%); <strong>Kartu PDF</strong> membuka pratinjau PDF; <strong>Kartu per Berkas (ZIP)</strong> mengunduh satu PDF per kelas/ruang (sesuai Per Kelas/Per Ruang dan Tingkatan).</p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    // Per Ruang / Per Kelas: tampilkan pilihan yang sesuai; pilihan tersembunyi tidak ikut dikirim.
    var sync = function () {
        var per = document.querySelector('input[name=per]:checked').value;
        document.querySelectorAll('[data-per]').forEach(function (el) {
            var on = el.dataset.per === per;
            el.classList.toggle('d-none', !on);
            el.querySelectorAll('select').forEach(function (s) { s.disabled = !on; });
        });
    };
    document.querySelectorAll('input[name=per]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();

    // Kartu per Berkas (ZIP): diunduh di tab ini; tombol menunjukkan proses sampai server mengirim
    // cookie kartu_zip=<token> bersama berkasnya (pembuatan ±1 detik per kelas/ruang).
    var btnZip = document.getElementById('btnZip');
    var tokenInput = document.getElementById('tokenUnduh');
    if (btnZip && tokenInput) {
        var asli = btnZip.innerHTML;
        btnZip.addEventListener('click', function () {
            var token = String(Date.now());
            tokenInput.value = token;
            tokenInput.disabled = false;
            setTimeout(function () {
                tokenInput.disabled = true;
                btnZip.disabled = true;
                btnZip.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Menyiapkan ZIP…';
            }, 0);
            // Selesai bila cookie tanda sampai, ATAU server menyatakan ZIP sudah dibuat (zip_status) — yang kedua
            // tetap jalan bila unduhan diambil alih pengelola unduhan (IDM dsb.) sehingga cookie tidak sampai.
            var mulai = Date.now(), selesai = false, cekServer = 0;
            var pulih = function () {
                if (selesai) { return; }
                selesai = true;
                clearInterval(cek);
                btnZip.disabled = false;
                btnZip.innerHTML = asli;
            };
            var cek = setInterval(function () {
                if (document.cookie.indexOf('kartu_zip=' + token) !== -1 || Date.now() - mulai > 300000) { pulih(); return; }
                if (++cekServer % 2) { return; }   // tanya server tiap 2 detik
                fetch('<?= site_url('dokumen/cetak_doc/zip_status') ?>?token=' + token, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) { if (d && d.selesai) { setTimeout(pulih, 1500); } })   // beri waktu berkas mulai terkirim
                    .catch(function () {});
            }, 1000);
        });
    }
})();
</script>
<?= $this->endSection() ?>
