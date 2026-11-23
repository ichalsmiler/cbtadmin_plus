<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php if ($moodleError): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('daftar_peserta/moodle') ?>">
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
            <div>
                <label class="form-label small mb-1" for="fKelas">Kelas</label>
                <select class="form-select form-select-sm" id="fKelas" name="kelas" onchange="this.form.submit()">
                    <option value="">Semua kelas</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= esc($k, 'attr') ?>" <?= $k === $filterKelas ? 'selected' : '' ?>><?= esc($k) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="fPilihan">Mapel pilihan (XI/XII)</label>
                <div class="input-group input-group-sm">
                    <select class="form-select form-select-sm" id="fPilihan" name="pilihan" onchange="this.form.submit()">
                        <option value="">Semua siswa</option>
                        <option value="kurang" <?= $saringPilihan === 'kurang' ? 'selected' : '' ?>>Kurang dari</option>
                        <option value="lebih" <?= $saringPilihan === 'lebih' ? 'selected' : '' ?>>Lebih dari</option>
                        <option value="pas" <?= $saringPilihan === 'pas' ? 'selected' : '' ?>>Tepat</option>
                    </select>
                    <input class="form-control" style="max-width:4.5rem" type="number" name="wajib" min="1" max="10" value="<?= (int) $wajibPilihan ?>"
                           aria-label="Jumlah mapel pilihan wajib" onchange="this.form.submit()">
                    <span class="input-group-text">mapel</span>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <span class="text-muted small"><?= count($rows) ?><?= count($rows) !== $semua ? ' dari ' . (int) $semua : '' ?> user Moodle (tanpa admin dan guest). Siswa dalam jumlah banyak dikirim lewat Konvert User → User → Moodle.</span>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <button class="btn btn-primary btn-sm" data-hanya-admin type="button" data-bs-toggle="modal" data-bs-target="#tambahModal"><i class="bi bi-person-plus me-1"></i>Tambah user</button>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-hover align-middle w-100 datatable" data-order-col="0">
            <thead><tr><th>Username</th><th>Nama</th><th>Email</th><th>Kelas</th><th>Cohort</th><th>Mapel pilihan</th><th>Akses terakhir</th><th>CBTAdmin</th><th data-orderable="false"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u): ?>
                <tr>
                    <td><code><?= esc($u['username']) ?></code><?= $u['suspended'] ? ' <span class="badge text-bg-warning">Ditangguhkan</span>' : '' ?></td>
                    <td><?= esc(trim($u['firstname'] . ' ' . $u['lastname'])) ?></td>
                    <td><?= esc($u['email']) ?></td>
                    <td><?= esc($u['kelas'] !== '' ? $u['kelas'] : '-') ?></td>
                    <td><?= esc($u['cohort'] ?? '-') ?></td>
                    <?php
                    $tk    = \App\Libraries\Tingkat::of($u['kelas']);
                    $punya = isset($pilihan[$tk]) ? array_values(array_intersect($pilihanSiswa[(int) $u['id']] ?? [], array_keys($pilihan[$tk]))) : [];
                    ?>
                    <td>
                        <?php if (! isset($pilihan[$tk])): ?>
                            <span class="text-body-secondary">-</span>
                        <?php else: ?>
                            <?php foreach ($punya as $cid): ?><span class="kode-tag me-1"><?= esc($pilihan[$tk][$cid]['kode']) ?></span><?php endforeach ?>
                            <?= $punya === [] ? '<span class="text-body-secondary small">Belum ada</span>' : '' ?>
                        <?php endif ?>
                    </td>
                    <td data-order="<?= (int) $u['lastaccess'] ?>"><?= $u['lastaccess'] ? date('d/m/Y H:i', (int) $u['lastaccess']) : 'Belum pernah' ?></td>
                    <td><?= isset($linked[$u['username']]) ? '<span class="badge text-bg-success">Ada</span>' : '<span class="badge text-bg-secondary">Belum</span>' ?></td>
                    <td class="text-end text-nowrap" data-hanya-admin>
                        <?php if (isset($pilihan[$tk]) && $pilihan[$tk] !== []): ?>
                            <button class="btn btn-outline-secondary btn-sm text-nowrap" type="button" data-mapel-pilihan
                                    data-siswa="<?= esc(json_encode(['userid' => (int) $u['id'], 'nama' => trim($u['firstname'] . ' ' . $u['lastname']), 'kelas' => $u['kelas'], 'tingkat' => $tk, 'course' => $punya]), 'attr') ?>">
                                <i class="bi bi-ui-checks me-1"></i>Mapel pilihan
                            </button>
                        <?php endif ?>
                        <button class="btn btn-outline-secondary btn-sm text-nowrap" type="button" data-fill-modal="#resetModal"
                                data-fill="<?= esc(json_encode(['userid' => $u['id'], 'username' => $u['username'], 'password' => '']), 'attr') ?>">
                            <i class="bi bi-key me-1"></i>Reset password
                        </button>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="tambahModal" tabindex="-1" aria-labelledby="tambahModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('daftar_peserta/moodle/tambah') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title" id="tambahModalLabel">Tambah user Moodle</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label class="form-label" for="tm_nama">Nama</label><input class="form-control" id="tm_nama" name="nama" maxlength="100" required value="<?= esc(old('nama') ?? '', 'attr') ?>"></div>
                <div class="col-6"><label class="form-label" for="tm_username">Username</label><input class="form-control" id="tm_username" name="username" maxlength="100" required placeholder="mis. 91-26-0001" value="<?= esc(old('username') ?? '', 'attr') ?>"></div>
                <div class="col-6"><label class="form-label" for="tm_password">Password</label><input class="form-control" id="tm_password" name="password" maxlength="100" placeholder="kosong = acak"></div>
                <div class="col-6"><label class="form-label" for="tm_kelas">Kelas</label><input class="form-control" id="tm_kelas" name="kelas" maxlength="60" placeholder="mis. X-1" value="<?= esc(old('kelas') ?? '', 'attr') ?>"></div>
                <div class="col-6"><label class="form-label" for="tm_ruang">Ruang</label><input class="form-control" id="tm_ruang" name="ruang" maxlength="60" placeholder="mis. 1" value="<?= esc(old('ruang') ?? '', 'attr') ?>"></div>
                <div class="col-12"><label class="form-label" for="tm_email">Email</label><input class="form-control" id="tm_email" name="email" type="email" maxlength="100" placeholder="kosong = username@domain sekolah" value="<?= esc(old('email') ?? '', 'attr') ?>"></div>
                <div class="col-12"><label class="form-label" for="tm_mapel">Mapel (shortname, opsional)</label>
                    <textarea class="form-control" id="tm_mapel" name="mapel" rows="3" placeholder="Satu per baris atau dipisah koma, mis. USBK-X-BINDO"><?= esc(old('mapel') ?? '') ?></textarea>
                    <div class="form-text">Siswa langsung didaftarkan ke mapel ini sebagai siswa.</div>
                </div>
                <div class="col-12 form-check ms-2">
                    <input class="form-check-input" type="checkbox" name="simpan_data" value="1" id="tm_simpan" checked>
                    <label class="form-check-label" for="tm_simpan">Simpan juga ke Data CBTAdmin (untuk kartu login, ruang, dan absensi)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('daftar_peserta/moodle/reset_password') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="userid">
            <div class="modal-header"><h5 class="modal-title">Reset password</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" readonly></div>
                <div><label class="form-label">Password baru</label><input class="form-control" name="password" maxlength="100" placeholder="kosong = dibuat acak, mis. Kmtr-482"></div>
                <p class="small text-muted mt-2 mb-0">Password baru ditampilkan setelah disimpan dan ikut tercatat di Data CBTAdmin.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Reset</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="pilihanModal" tabindex="-1" aria-labelledby="pilihanModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('daftar_peserta/moodle/mapel_pilihan') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="userid" id="mp_userid">
            <input type="hidden" name="kembali" value="<?= esc(service('request')->getUri()->getQuery(), 'attr') ?>">
            <div class="modal-header"><h5 class="modal-title" id="pilihanModalLabel">Mapel pilihan</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <p class="mb-2"><strong id="mp_nama"></strong> · <span id="mp_kelas"></span></p>
                <?php foreach ($pilihan as $tk => $daftar): ?>
                    <fieldset class="row g-1" data-mp-tingkat="<?= esc($tk, 'attr') ?>" hidden>
                        <legend class="small text-body-secondary mb-1">Mapel pilihan kelas <?= esc($tk) ?> (centang yang diikuti)</legend>
                        <?php foreach ($daftar as $c): ?>
                            <div class="col-sm-6 form-check ps-4">
                                <input class="form-check-input" type="checkbox" name="course[]" value="<?= (int) $c['id'] ?>" id="mp_<?= (int) $c['id'] ?>">
                                <label class="form-check-label" for="mp_<?= (int) $c['id'] ?>" title="<?= esc($c['shortname'], 'attr') ?>"><?= esc($c['kode']) ?></label>
                            </div>
                        <?php endforeach ?>
                    </fieldset>
                <?php endforeach ?>
                <p class="small text-body-secondary mt-3 mb-0"><span id="mp_jumlah"></span> Yang dicentang didaftarkan ke Moodle, yang tidak dicentang dikeluarkan.
                    Mapel yang sudah dikerjakan siswa ini (ada jawaban) tidak dikeluarkan.</p>
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
    // Mapel pilihan: tampilkan pilihan tingkat siswa (XI/XII) dan centang yang sudah diikuti.
    var modal = document.getElementById('pilihanModal');
    var jumlah = function () {
        var n = modal.querySelectorAll('fieldset:not([hidden]) input:checked').length;
        document.getElementById('mp_jumlah').textContent = n + ' mapel dicentang.';
    };
    modal.addEventListener('change', jumlah);
    document.addEventListener('click', function (event) {
        var tombol = event.target.closest('[data-mapel-pilihan]');
        if (!tombol) { return; }
        var s = JSON.parse(tombol.dataset.siswa);
        document.getElementById('mp_userid').value = s.userid;
        document.getElementById('mp_nama').textContent = s.nama;
        document.getElementById('mp_kelas').textContent = s.kelas;
        modal.querySelectorAll('[data-mp-tingkat]').forEach(function (fs) {
            var ini = fs.dataset.mpTingkat === s.tingkat;
            fs.hidden = !ini;
            fs.disabled = !ini;
            fs.querySelectorAll('input').forEach(function (el) { el.checked = ini && s.course.indexOf(parseInt(el.value, 10)) >= 0; });
        });
        jumlah();
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
})();
</script>
<?= $this->endSection() ?>
