<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$badge = [
    'buka'     => ['success', 'Dibuka'],
    'belum'    => ['info', 'Belum dibuka'],
    'tutup'    => ['secondary', 'Ditutup'],
    'sembunyi' => ['warning', 'Disembunyikan'],
];
$fmt      = static fn ($ts) => (int) $ts > 0 ? date('d/m/Y H:i', (int) $ts) : '–';
$dtl      = static fn ($ts) => (int) $ts > 0 ? date('Y-m-d\TH:i', (int) $ts) : '';
$catId    = $category ? (int) $category['id'] : 0;
$shownId  = $shown ? (int) $shown['id'] : 0;
$indent   = static fn ($c) => str_repeat('— ', max(0, (int) $c['depth'] - 1));
?>
<?php if ($moodleError): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<div class="card">
    <div class="card-header d-flex align-items-center">
        <span class="fw-semibold"><i class="bi bi-check-square me-1"></i>Setting Ujian</span>
        <div class="dropdown ms-auto">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-gear me-1"></i>Setting
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#tokenModal"><i class="bi bi-key me-2"></i>Setting Token</a></li>
                <li>
                    <button class="dropdown-item" type="button" <?= $catId ? '' : 'disabled' ?>
                            data-post="<?= site_url('status_tes/reset') ?>"
                            data-confirm-text="Kembalikan status tes ke pengaturan awal? Seluruh mata pelajaran di tes ini akan dibuka kembali (bisa dipakai untuk ujian susulan).">
                        <i class="bi bi-arrow-counterclockwise me-2"></i>Reset Default
                    </button>
                </li>
            </ul>
        </div>
    </div>
    <div class="card-body">
        <label class="tes-judul" for="tokenValue">Token</label>
        <p class="tes-ket">Dipasang sebagai password semua quiz di tes aktif.</p>
        <form class="row g-2 mb-1" method="post" data-no-dirty action="<?= site_url('status_tes/release') ?>">
            <?= csrf_field() ?>
            <div class="col-md-9">
                <input class="form-control form-control-lg font-monospace fw-bold text-center token-box" id="tokenValue" readonly
                       value="<?= esc($tokenEnabled ? ($token !== '' ? $token : 'Silakan klik tombol Release') : 'Token dinonaktifkan') ?>">
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-success btn-lg" type="submit" <?= $tokenEnabled && $catId ? '' : 'disabled' ?>>
                    <i class="bi bi-arrow-repeat me-1"></i>Release
                </button>
            </div>
        </form>
        <p class="small text-muted mb-0" id="tokenInfo">
            <?php if (! $tokenEnabled): ?>
                Quiz di tes ini bisa dimulai tanpa token.
            <?php elseif ($nextRelease > 0): ?>
                Token berganti otomatis setiap <?= (int) $tokenMinutes ?> menit. Berikutnya pukul
                <strong id="tokenNext"><?= date('H:i', $nextRelease) ?></strong> (<span id="tokenLeft">–</span> lagi).
            <?php else: ?>
                Pilih tes lalu klik Simpan; token dibuat otomatis.
            <?php endif ?>
        </p>

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
                <div class="alert alert-warning small py-2 mt-2 mb-0">Belum ada sesi. Impor peserta (kolom sesi) atau tambah sesi di Daftar Ruang.</div>
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

<?php if ($category): ?>
<div class="card mt-4">
    <div class="card-header fw-semibold">Quiz di tes aktif: <?= esc($category['name']) ?> <span class="text-muted fw-normal small">(<?= count($rows) ?> quiz)</span></div>
    <div class="card-body">
        <table class="table table-hover align-middle w-100 datatable" data-order-col="1">
            <thead>
            <tr>
                <th>Mapel / Quiz</th><th>Buka</th><th>Tutup</th><th>Durasi</th>
                <th class="text-center">Mulai</th><th class="text-center">Mengerjakan</th><th class="text-center">Selesai</th>
                <th>Status</th><th data-orderable="false"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $q): ?>
                <?php [$cls, $text] = $badge[$q['status']]; ?>
                <tr>
                    <td>
                        <a href="<?= esc(\App\Libraries\Moodle::url('/mod/quiz/view.php?id=' . (int) $q['cmid']), 'attr') ?>" target="_blank" rel="noopener"><?= esc($q['name']) ?></a>
                        <div class="small text-muted"><?= esc($q['course_short']) ?></div>
                    </td>
                    <td data-order="<?= (int) $q['timeopen'] ?>"><?= $fmt($q['timeopen']) ?></td>
                    <td data-order="<?= (int) $q['timeclose'] ?>"><?= $fmt($q['timeclose']) ?></td>
                    <td data-order="<?= (int) $q['timelimit'] ?>"><?= (int) $q['timelimit'] > 0 ? round($q['timelimit'] / 60) . ' menit' : '–' ?></td>
                    <td class="text-center"><?= (int) $q['mulai'] ?></td>
                    <td class="text-center"><a href="<?= site_url('status_peserta/inprogress?quiz=' . (int) $q['id']) ?>"><?= (int) $q['sedang'] ?></a></td>
                    <td class="text-center"><a href="<?= site_url('status_peserta/finished?quiz=' . (int) $q['id']) ?>"><?= (int) $q['selesai'] ?></a></td>
                    <td><span class="badge text-bg-<?= $cls ?>"><?= $text ?></span></td>
                    <td class="text-end text-nowrap">
                        <?php if ($q['status'] !== 'buka'): ?>
                            <button class="btn btn-outline-success btn-sm" type="button" title="Buka sekarang"
                                    data-post="<?= site_url('status_tes/waktu') ?>" data-fields="<?= esc(json_encode(['quiz' => $q['id'], 'aksi' => 'buka']), 'attr') ?>"
                                    data-confirm-text="Buka quiz &quot;<?= esc($q['name'], 'attr') ?>&quot; sekarang?"><i class="bi bi-play-fill"></i></button>
                        <?php else: ?>
                            <button class="btn btn-outline-danger btn-sm" type="button" title="Tutup sekarang"
                                    data-post="<?= site_url('status_tes/waktu') ?>" data-fields="<?= esc(json_encode(['quiz' => $q['id'], 'aksi' => 'tutup']), 'attr') ?>"
                                    data-confirm-text="Tutup quiz &quot;<?= esc($q['name'], 'attr') ?>&quot; sekarang? Peserta yang masih mengerjakan akan disubmit otomatis oleh Moodle sesuai pengaturan quiz."><i class="bi bi-stop-fill"></i></button>
                        <?php endif ?>
                        <button class="btn btn-outline-secondary btn-sm" type="button" title="Atur waktu" data-fill-modal="#waktuModal"
                                data-fill="<?= esc(json_encode(['quiz' => $q['id'], 'nama' => $q['name'], 'timeopen' => $dtl($q['timeopen']), 'timeclose' => $dtl($q['timeclose'])]), 'attr') ?>">
                            <i class="bi bi-clock"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>

<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>

<!-- Pengaturan token -->
<div class="modal fade" id="tokenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('status_tes/token_setting') ?>">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Pengaturan Token</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="f_aktif" name="aktif" value="1" <?= $tokenEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="f_aktif">Gunakan token dengan release otomatis</label>
                </div>
                <label class="form-label" for="f_menit">Timer auto release token (menit)</label>
                <input class="form-control" type="number" id="f_menit" name="menit" min="1" max="1440" required value="<?= (int) $tokenMinutes ?>" style="max-width: 140px">
                <p class="small text-muted mt-2 mb-0">Token dipasang sebagai password di semua quiz pada tes aktif. Bila dimatikan, password quiz dikosongkan.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="waktuModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('status_tes/waktu') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="quiz">
            <input type="hidden" name="aksi" value="atur">
            <div class="modal-header"><h5 class="modal-title">Atur waktu quiz</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Quiz</label><input class="form-control" name="nama" readonly></div>
                <div class="mb-3"><label class="form-label">Waktu buka</label><input class="form-control" type="datetime-local" name="timeopen"></div>
                <div><label class="form-label">Waktu tutup</label><input class="form-control" type="datetime-local" name="timeclose"></div>
                <p class="small text-muted mt-2 mb-0">Kosongkan untuk tanpa batas. Waktu mengikuti zona server (<?= esc(date('T')) ?>).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>
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

    // Token berganti di server (cron); perbarui tampilan tanpa muat ulang halaman.
    var box = document.getElementById('tokenValue');
    var offset = 0, next = <?= (int) $nextRelease ?>;
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var tick = function () {
        var el = document.getElementById('tokenLeft');
        if (!el || !next) { return; }
        var left = next - (Math.floor(Date.now() / 1000) + offset);
        el.textContent = left > 0 ? Math.floor(left / 60) + ':' + pad(left % 60) : 'sebentar';
    };
    var poll = function () {
        fetch('<?= site_url('status_tes/token') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401) { window.location.reload(); return null; }
                return r.ok ? r.json() : null;
            })
            .then(function (d) {
                if (!d || !d.enabled) { return; }
                offset = d.now - Math.floor(Date.now() / 1000);
                if (d.token && box.value !== d.token) {
                    box.value = d.token;
                    box.classList.add('token-new');
                    setTimeout(function () { box.classList.remove('token-new'); }, 1500);
                }
                if (d.next) {
                    next = d.next;
                    var at = document.getElementById('tokenNext');
                    if (at) { var t = new Date(d.next * 1000); at.textContent = pad(t.getHours()) + ':' + pad(t.getMinutes()); }
                }
            })
            .catch(function () { /* coba lagi di putaran berikutnya */ });
    };
    tick();
    setInterval(tick, 1000);
    setInterval(poll, 15000);
})();
</script>
<?= $this->endSection() ?>
