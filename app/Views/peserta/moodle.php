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
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <span class="text-muted small"><?= count($rows) ?><?= count($rows) !== $semua ? ' dari ' . (int) $semua : '' ?> user Moodle (tanpa admin dan guest). Siswa dalam jumlah banyak dikirim lewat Konvert User → User → Moodle.</span>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#tambahModal"><i class="bi bi-person-plus me-1"></i>Tambah user</button>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-hover align-middle w-100 datatable" data-order-col="0">
            <thead><tr><th>Username</th><th>Nama</th><th>Email</th><th>Kelas</th><th>Cohort</th><th>Akses terakhir</th><th>CBTAdmin</th><th data-orderable="false"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u): ?>
                <tr>
                    <td><code><?= esc($u['username']) ?></code><?= $u['suspended'] ? ' <span class="badge text-bg-warning">Ditangguhkan</span>' : '' ?></td>
                    <td><?= esc(trim($u['firstname'] . ' ' . $u['lastname'])) ?></td>
                    <td><?= esc($u['email']) ?></td>
                    <td><?= esc($u['kelas'] !== '' ? $u['kelas'] : '-') ?></td>
                    <td><?= esc($u['cohort'] ?? '-') ?></td>
                    <td data-order="<?= (int) $u['lastaccess'] ?>"><?= $u['lastaccess'] ? date('d/m/Y H:i', (int) $u['lastaccess']) : 'Belum pernah' ?></td>
                    <td><?= isset($linked[$u['username']]) ? '<span class="badge text-bg-success">Ada</span>' : '<span class="badge text-bg-secondary">Belum</span>' ?></td>
                    <td class="text-end">
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
<?= $this->endSection() ?>
