<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('daftar_peserta/cbtadmin') ?>">
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
                <label class="form-label small mb-1" for="fRuang">Ruang</label>
                <select class="form-select form-select-sm" id="fRuang" name="ruang" onchange="this.form.submit()">
                    <option value="">Semua ruang</option>
                    <option value="0" <?= $filterRuang === '0' ? 'selected' : '' ?>>(tanpa ruang)</option>
                    <?php foreach ($ruangList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" data-kelas="<?= esc((string) $r['kelas'], 'attr') ?>" <?= (string) $r['id'] === $filterRuang ? 'selected' : '' ?>><?= esc($r['nama']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </form>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <button class="btn btn-primary btn-sm" type="button" data-fill-modal="#pesertaModal"
                    data-fill='{"id":"","nomor_peserta":"","nama":"","kelas":"","username":"","password":"","ruang_id":"","sesi_id":""}'>
                <i class="bi bi-plus-lg me-1"></i>Tambah
            </button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import Excel</button>
            <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#resetModal"><i class="bi bi-trash3 me-1"></i>Reset Peserta</button>
        </div>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= site_url('daftar_peserta/cbtadmin/massal') ?>" id="bulkForm" data-bulk>
        <?= csrf_field() ?>
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <button class="btn btn-outline-danger btn-sm ms-auto" type="submit" name="aksi" value="hapus"
                    data-confirm-button="Hapus semua peserta yang dicentang dari Data CBTAdmin? Akun Moodle mereka tidak ikut terhapus.">
                <i class="bi bi-trash me-1"></i>Hapus yang dicentang
            </button>
        </div>
        <div class="card-body">
            <table class="table table-hover align-middle w-100 datatable" data-order-col="1">
                <thead>
                <tr>
                    <th data-orderable="false" style="width:1%"><input class="form-check-input" type="checkbox" data-check-all aria-label="Centang semua"></th>
                    <th>No. Peserta</th><th>Nama</th><th>Kelas</th><th>Username</th><th>Password</th><th>Ruang</th><th>Sesi</th><th>Moodle</th>
                    <th data-orderable="false"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" aria-label="Pilih <?= esc($p['nama'], 'attr') ?>"></td>
                        <td><?= esc($p['nomor_peserta']) ?></td>
                        <td><?= esc($p['nama']) ?></td>
                        <td><?= esc($p['kelas']) ?></td>
                        <td><code><?= esc($p['username']) ?></code></td>
                        <td><code><?= esc($p['password_plain'] ?? '') ?></code></td>
                        <td><?= esc($p['ruang_nama'] ?? '-') ?></td>
                        <td><?= esc($p['sesi_nama'] ?? '-') ?></td>
                        <td><?= $p['moodle_userid'] ? '<span class="badge text-bg-success">Ada</span>' : '<span class="badge text-bg-secondary">Belum</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-outline-secondary btn-sm" type="button" title="Ubah" data-fill-modal="#pesertaModal"
                                    data-fill="<?= esc(json_encode([
                                        'id' => $p['id'], 'nomor_peserta' => $p['nomor_peserta'], 'nama' => $p['nama'], 'kelas' => $p['kelas'],
                                        'username' => $p['username'], 'password' => $p['password_plain'] ?? '',
                                        'ruang_id' => $p['ruang_id'] ?? '', 'sesi_id' => $p['sesi_id'] ?? '',
                                    ]), 'attr') ?>"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-outline-danger btn-sm" type="button" title="Hapus"
                                    data-post="<?= site_url('daftar_peserta/cbtadmin/hapus/' . $p['id']) ?>"
                                    data-confirm-text="Hapus &quot;<?= esc($p['nama'], 'attr') ?>&quot; dari Data CBTAdmin? Akun Moodle-nya tidak ikut terhapus."><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>

<div class="modal fade" id="pesertaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('daftar_peserta/cbtadmin/simpan') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="id">
            <div class="modal-header"><h5 class="modal-title">Peserta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-5"><label class="form-label">No. peserta</label><input class="form-control" name="nomor_peserta" maxlength="40" placeholder="kosong = sama dengan username"></div>
                <div class="col-sm-7"><label class="form-label">Kelas</label><input class="form-control" name="kelas" maxlength="60"></div>
                <div class="col-12"><label class="form-label">Nama</label><input class="form-control" name="nama" maxlength="150" required></div>
                <div class="col-sm-6"><label class="form-label">Username</label><input class="form-control" name="username" maxlength="100" pattern="[A-Za-z0-9._@\-]{2,100}" required></div>
                <div class="col-sm-6"><label class="form-label">Password</label><input class="form-control" name="password" maxlength="100" placeholder="kosong = dibuat acak, mis. Kmtr-482"></div>
                <div class="col-sm-6"><label class="form-label">Ruang</label>
                    <select class="form-select" name="ruang_id">
                        <option value="">(tanpa ruang)</option>
                        <?php foreach ($ruangList as $r): ?><option value="<?= (int) $r['id'] ?>"><?= esc($r['nama']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-sm-6"><label class="form-label">Sesi</label>
                    <select class="form-select" name="sesi_id">
                        <option value="">(tanpa sesi)</option>
                        <?php foreach ($sesiList as $s): ?><option value="<?= (int) $s['id'] ?>"><?= esc($s['nama']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <p class="small text-muted mb-0">Mengganti password peserta yang sudah punya akun Moodle langsung mengganti password Moodle-nya.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('daftar_peserta/cbtadmin/import') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Import Excel</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <p class="mb-2">Isi <a href="<?= site_url('daftar_peserta/cbtadmin/template') ?>">template peserta</a>, lalu unggah di sini.</p>
                <ul class="small text-muted ps-3">
                    <li>Kolom wajib: <code>nama</code> dan <code>username</code>.</li>
                    <li>Username yang sudah ada akan diperbarui, bukan digandakan. Peserta yang tidak ada di berkas tetap disimpan.</li>
                    <li>Password kosong dibuat acak. Ruang dan sesi yang belum ada dibuat otomatis.</li>
                </ul>
                <input class="form-control" type="file" name="berkas" accept=".xlsx,.xls,.csv" required>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="ganti" value="1" id="importGanti">
                    <label class="form-check-label" for="importGanti">Hapus semua data lama sebelum impor</label>
                    <div class="form-text text-danger">Semua peserta, absensi, dan blokir diganti isi berkas. Password yang kosong di berkas dibuat ulang, jadi kartu login lama tidak berlaku.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Import</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('daftar_peserta/cbtadmin/reset') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title text-danger">Reset Peserta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <p>Semua peserta di Data CBTAdmin akan dihapus, beserta absensi dan blokirnya. Tindakan ini tidak bisa dibatalkan.</p>
                <ul class="small text-muted ps-3">
                    <li>Akun Moodle siswa tidak ikut terhapus. Siswa yang sedang diblokir dibuka dulu di Moodle.</li>
                    <li>Token pengawas terkunci lagi sampai absensi diisi ulang.</li>
                </ul>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="ruang" value="1" id="resetRuang">
                    <label class="form-check-label" for="resetRuang">Hapus juga daftar ruang, sesi, dan akun pengawas</label>
                    <div class="form-text">Bila tidak dicentang, ruang (beserta nama lab) dan kode login pengawas tetap ada. Bila dicentang, semuanya dibuat ulang saat impor berikutnya dengan kode dan password baru, jadi kartu ID Login harus dicetak ulang.</div>
                </div>
                <label class="form-label" for="resetKonfirmasi">Ketik <strong>HAPUS</strong> untuk melanjutkan</label>
                <input class="form-control" type="text" name="konfirmasi" id="resetKonfirmasi" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger" id="resetOk" disabled>Hapus semua peserta</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('resetKonfirmasi').addEventListener('input', function () {
    document.getElementById('resetOk').disabled = this.value.trim() !== 'HAPUS';
});
</script>
<?= $this->endSection() ?>
