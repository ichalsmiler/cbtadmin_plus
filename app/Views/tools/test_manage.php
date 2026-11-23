<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <span class="fw-semibold"><i class="bi bi-journal-text me-1"></i>Daftar Ujian</span>
                <button class="btn btn-outline-primary btn-sm ms-auto" type="button" data-bs-toggle="modal" data-bs-target="#ujianModal">
                    <i class="bi bi-plus-lg me-1"></i>Tambah Ujian
                </button>
            </div>
            <?php // Buat hari ujian sekaligus (H1, H2, … Hn): kategori yang sudah ada dipakai dulu (urut id, diganti namanya), kekurangannya dibuat baru. ?>
            <form class="card-body border-bottom" method="post" action="<?= site_url('tools/test_manage/hari') ?>" id="hariForm" data-kategori="<?= esc(json_encode(array_map(static fn ($k) => ['id' => (int) $k['id'], 'name' => (string) $k['name']], $kategori)), 'attr') ?>">
                <?= csrf_field() ?>
                <div class="row g-2 align-items-end">
                    <div class="col-4">
                        <label class="form-label small mb-1" for="h_jumlah">Jumlah hari</label>
                        <input class="form-control" type="number" id="h_jumlah" name="jumlah" min="1" max="50" required placeholder="mis. 8">
                    </div>
                    <div class="col-3">
                        <label class="form-label small mb-1" for="h_awalan">Awalan</label>
                        <input class="form-control" id="h_awalan" name="awalan" maxlength="50" value="H">
                    </div>
                    <div class="col-2">
                        <label class="form-label small mb-1" for="h_mulai">Mulai</label>
                        <input class="form-control" type="number" id="h_mulai" name="mulai" min="1" max="99" value="1">
                    </div>
                    <div class="col-3">
                        <button class="btn btn-primary w-100" type="submit">Buat</button>
                    </div>
                </div>
                <div class="form-text" id="h_pratinjau" aria-live="polite">Kategori yang sudah ada dipakai dulu (urut id) dan diganti namanya menjadi H1, H2, dst.; kekurangannya dibuat baru.</div>
            </form>
            <div class="list-group list-group-flush">
                <?php if ($kategori === []): ?>
                    <div class="list-group-item text-muted">Belum ada ujian.</div>
                <?php endif ?>
                <?php foreach ($kategori as $i => $k): ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 <?= $aktif && (int) $aktif['id'] === (int) $k['id'] ? 'active' : '' ?>"
                       href="<?= site_url('tools/test_manage') ?>?kat=<?= (int) $k['id'] ?>">
                        <span class="ruang-avatar avatar-netral"><?= esc(mb_strtoupper(mb_substr($k['name'], 0, 1))) ?></span>
                        <span>
                            <span class="fw-semibold d-block"><?= esc($k['name']) ?></span>
                            <span class="small <?= $aktif && (int) $aktif['id'] === (int) $k['id'] ? '' : 'text-muted' ?>">Id: <?= (int) $k['id'] ?> · Total pelajaran: <?= (int) $k['coursecount'] ?></span>
                        </span>
                        <i class="bi bi-chevron-right ms-auto"></i>
                    </a>
                <?php endforeach ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <?php $tesAktif = $aktif && (int) $aktif['id'] === \App\Libraries\ActiveTest::categoryId(); ?>
                <span class="fw-semibold me-auto"><i class="bi bi-book me-1"></i>Daftar Pelajaran<?= $aktif ? ' · ' . esc($aktif['name']) : '' ?></span>
                <?php if ($aktif): ?>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#ubahUjianModal">
                        <i class="bi bi-pencil me-1"></i>Ubah ujian
                    </button>
                    <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#hapusUjianModal">
                        <i class="bi bi-trash me-1"></i>Hapus ujian
                    </button>
                    <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#mapelModal">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Pelajaran
                    </button>
                <?php endif ?>
            </div>
            <?php if (! $aktif): ?>
                <div class="card-body text-muted">Pilih ujian di sebelah kiri untuk melihat pelajarannya.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th class="kolom-cek" data-hanya-admin><input class="form-check-input" type="checkbox" id="pm_semua" aria-label="Centang semua mapel"></th><th>Nama Pelajaran</th><th>Shortname</th><th class="text-center">Status</th><th class="text-center">Aksi</th></tr></thead>
                        <tbody>
                        <?php if ($mapel === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada pelajaran.</td></tr>
                        <?php endif ?>
                        <?php foreach ($mapel as $c): ?>
                            <?php $on = (int) $c['visible'] === 1; ?>
                            <tr>
                                <td data-hanya-admin>
                                    <input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" form="pindahForm" data-pm-cek
                                           <?= $tesAktif && $on ? 'disabled title="Aktif di tes aktif; nonaktifkan dulu untuk memindah"' : '' ?>
                                           aria-label="Pilih <?= esc($c['fullname'], 'attr') ?>">
                                </td>
                                <td><?= esc($c['fullname']) ?></td>
                                <td><code><?= esc($c['shortname']) ?></code></td>
                                <td class="text-center">
                                    <button type="button" class="status-toggle <?= $on ? 'stat-ok' : 'stat-redup' ?>" data-hanya-admin
                                            title="Klik untuk <?= $on ? 'menonaktifkan' : 'mengaktifkan' ?>"
                                            data-post="<?= site_url('tools/test_manage/mapel/status') ?>"
                                            data-fields="<?= esc(json_encode(['id' => (int) $c['id']]), 'attr') ?>"
                                            data-confirm-text="<?= esc(($on ? 'Nonaktifkan' : 'Aktifkan') . ' mapel "' . $c['fullname'] . '"?' . ($on ? ' Peserta tidak bisa membukanya lagi.' : ' Peserta bisa membukanya.'), 'attr') ?>">
                                        <?= $on ? 'Aktif' : 'Nonaktif' ?>
                                    </button>
                                </td>
                                <td class="text-center">
                                    <a class="btn btn-sm btn-link" href="<?= esc($moodle . '/course/view.php?id=' . (int) $c['id'], 'attr') ?>" target="_blank" rel="noopener" title="Buka di Moodle untuk mengisi soal">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <button class="btn btn-sm btn-link text-danger" type="button" title="Hapus mapel" data-hapus-mapel
                                            data-mapel="<?= esc(json_encode(['id' => (int) $c['id'], 'fullname' => $c['fullname'], 'shortname' => $c['shortname'], 'attempt' => (int) ($c['attempt'] ?? 0), 'kunci' => $tesAktif && (int) $c['visible'] === 1]), 'attr') ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($mapel !== []): ?>
                    <form class="card-body border-top d-flex flex-wrap gap-2 align-items-center" id="pindahForm" method="post" data-hanya-admin
                          action="<?= site_url('tools/test_manage/mapel/pindah') ?>"
                          data-confirm="Pindahkan mapel yang dicentang? Soal, jawaban, dan nilai ikut pindah; mapel menjadi Nonaktif di ujian tujuan.">
                        <?= csrf_field() ?>
                        <label class="small" for="pm_kategori">Pindahkan mapel yang dicentang ke</label>
                        <select class="form-select form-select-sm w-auto" id="pm_kategori" name="kategori" required>
                            <option value="">Pilih ujian…</option>
                            <?php foreach ($kategori as $k): ?>
                                <?php if ((int) $k['id'] === (int) $aktif['id'] || \App\Libraries\ActiveTest::isNonaktif($k['name'])) { continue; } ?>
                                <option value="<?= (int) $k['id'] ?>"><?= esc($k['name']) ?> (<?= (int) $k['coursecount'] ?> mapel)</option>
                            <?php endforeach ?>
                        </select>
                        <button class="btn btn-primary btn-sm" type="submit" id="pm_kirim" disabled>Pindah</button>
                        <span class="small text-body-secondary" id="pm_jumlah" aria-live="polite"></span>
                    </form>
                <?php endif ?>
                <div class="card-body small text-muted border-top">
                    Klik status untuk mengaktifkan/menonaktifkan mapel (aktifkan hanya bisa di tes aktif).
                    Pelajaran baru dibuat sebagai course <em>Single activity</em> berisi Quiz dan tersembunyi dari peserta.
                    Isi soalnya di Moodle (ikon <i class="bi bi-box-arrow-up-right"></i>), lalu aktifkan di <a href="<?= site_url('status_tes') ?>?kat=<?= (int) $aktif['id'] ?>">Setting Ujian</a>.
                </div>
            <?php endif ?>
        </div>
    </div>
</div>

<form class="card mt-4" method="post" action="<?= site_url('tools/test_manage/durasi') ?>"
      data-confirm="Terapkan durasi pengerjaan ke quiz mapel? Quiz yang sedang dikerjakan siswa dilewati.">
    <?= csrf_field() ?>
    <input type="hidden" name="kategori" value="<?= $aktif ? (int) $aktif['id'] : 0 ?>">
    <div class="card-body d-flex flex-wrap gap-3 align-items-end">
        <div class="me-auto">
            <div class="fw-semibold"><i class="bi bi-stopwatch me-1"></i>Durasi pengerjaan</div>
            <div class="small text-body-secondary">Batas waktu mengerjakan setiap mapel (quiz) sejak siswa mulai. Mapel baru otomatis memakai durasi ini.
                Sekarang: <strong><?= $durasi > 0 ? $durasi . ' menit' : 'tanpa batas' ?></strong>.</div>
        </div>
        <div>
            <label class="form-label small mb-1" for="d_menit">Menit</label>
            <div class="input-group input-group-sm" style="width:9rem">
                <input class="form-control" type="number" id="d_menit" name="menit" min="0" max="600" required value="<?= (int) $durasi ?>">
                <span class="input-group-text">menit</span>
            </div>
        </div>
        <div>
            <label class="form-label small mb-1" for="d_cakupan">Untuk</label>
            <select class="form-select form-select-sm" id="d_cakupan" name="cakupan">
                <option value="semua">Semua mapel</option>
                <?php foreach ($kategori as $k): ?>
                    <option value="<?= (int) $k['id'] ?>" <?= $aktif && (int) $aktif['id'] === (int) $k['id'] ? 'selected' : '' ?>>Ujian <?= esc($k['name']) ?> (<?= (int) $k['coursecount'] ?> mapel)</option>
                <?php endforeach ?>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Terapkan</button>
    </div>
</form>

<div class="card border-danger-subtle mt-4">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <div class="me-auto">
            <div class="fw-semibold text-danger"><i class="bi bi-exclamation-octagon me-1"></i>Reset Moodle</div>
            <div class="small text-body-secondary">Kosongkan Moodle untuk kegiatan ujian baru: akun siswa, semua ujian (mapel, soal, jawaban, nilai), dan log.
                Setelan, plugin, tema, dan akun admin tetap. Database dibackup otomatis sebelum dihapus.</div>
        </div>
        <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#resetMoodleModal"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset Moodle</button>
    </div>
</div>

<div class="modal fade" id="resetMoodleModal" tabindex="-1" aria-labelledby="resetMoodleLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('tools/test_manage/reset') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title text-danger" id="resetMoodleLabel">Reset Moodle</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <p class="small">Data yang dicentang <strong>dihapus permanen</strong> dari Moodle. Sebelum mulai, database Moodle dan panel dibackup ke <code>writable/backup</code> di server (disimpan 30 hari; berkas soal/gambar di moodledata tidak ikut dibackup).</p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="tahap[]" value="siswa" id="rm_siswa" checked>
                    <label class="form-check-label" for="rm_siswa">Hapus akun siswa<?= $jumlah ? ' <span class="text-body-secondary">(' . (int) $jumlah['user'] . ' user selain admin)</span>' : '' ?></label>
                </div>
                <div class="form-check ms-4">
                    <input class="form-check-input" type="checkbox" name="simpan_guru" value="1" id="rm_guru" checked>
                    <label class="form-check-label small" for="rm_guru">Simpan akun guru/manager (yang punya peran guru di mapel mana pun)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="tahap[]" value="ujian" id="rm_ujian" checked>
                    <label class="form-check-label" for="rm_ujian">Hapus semua ujian: mapel, soal, jawaban, nilai<?= $jumlah ? ' <span class="text-body-secondary">(' . (int) $jumlah['mapel'] . ' mapel, ' . (int) $jumlah['kategori'] . ' kategori; kategori SESI NONAKTIF disisakan)</span>' : '' ?></label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="tahap[]" value="log" id="rm_log" checked>
                    <label class="form-check-label" for="rm_log">Kosongkan log Moodle<?= $jumlah ? ' <span class="text-body-secondary">(' . number_format((int) $jumlah['log'], 0, ',', '.') . ' baris)</span>' : '' ?></label>
                </div>
                <p class="small text-body-secondary mt-2 mb-0">Data peserta panel (peserta, absensi, blokir) tidak ikut terhapus. Kosongkan lewat
                    Daftar Peserta → <a href="<?= site_url('daftar_peserta/cbtadmin') ?>">Data CBTAdmin</a> → Reset Peserta.</p>
                <label class="form-label mt-3" for="rm_konfirmasi">Ketik <code>RESET MOODLE</code> untuk mengonfirmasi</label>
                <input class="form-control" id="rm_konfirmasi" name="konfirmasi" required>
                <p class="small text-body-secondary mt-2 mb-0">Ditolak bila masih ada sesi ujian aktif atau siswa yang sedang mengerjakan. Proses berjalan bertahap; jangan tutup halaman sampai selesai.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger" id="rm_kirim" disabled>Reset</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="ujianModal" tabindex="-1" aria-labelledby="ujianModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('tools/test_manage/ujian') ?>">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title" id="ujianModalLabel">Tambah Daftar Ujian</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <label class="form-label" for="u_nama">Nama ujian</label>
                <input class="form-control" id="u_nama" name="nama" maxlength="255" required placeholder="Masukkan nama ujian">
                <p class="small text-muted mt-2 mb-0">Tips: gunakan nama kegiatan dan hari ujian, mis. PTS-1, PTS-2, PAS-1-1, PAS-1-2, H1, H2.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </div>
        </form>
    </div>
</div>

<?php if ($aktif): ?>
<?php $isiMapel = (int) $aktif['coursecount']; ?>
<div class="modal fade" id="hapusUjianModal" tabindex="-1" aria-labelledby="hapusUjianLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('tools/test_manage/ujian/hapus') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $aktif['id'] ?>">
            <div class="modal-header"><h5 class="modal-title text-danger" id="hapusUjianLabel">Hapus Ujian</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <?php if ($tesAktif): ?>
                    <p class="mb-0">Ujian <strong><?= esc($aktif['name']) ?></strong> sedang dipakai sebagai <strong>tes aktif</strong> di <a href="<?= site_url('status_tes') ?>">Setting Ujian</a>. Pilih tes lain di sana dulu sebelum menghapusnya.</p>
                <?php elseif ($isiMapel === 0): ?>
                    <p class="mb-0">Hapus kategori ujian <strong><?= esc($aktif['name']) ?></strong> dari Moodle? Kategori ini kosong (belum ada mapel).</p>
                <?php else: ?>
                    <p>Kategori <strong><?= esc($aktif['name']) ?></strong> berisi <strong><?= $isiMapel ?> mapel</strong>. Semua mapel, soal, jawaban siswa, dan nilainya ikut <strong>terhapus permanen</strong> dari Moodle dan tidak bisa dikembalikan.</p>
                    <label class="form-label" for="hu_konfirmasi">Ketik <code><?= esc($aktif['name']) ?></code> untuk mengonfirmasi</label>
                    <input class="form-control" id="hu_konfirmasi" name="konfirmasi" required data-harus="<?= esc($aktif['name'], 'attr') ?>">
                <?php endif ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <?php if (! $tesAktif): ?>
                    <button type="submit" class="btn btn-danger" id="hu_kirim" <?= $isiMapel > 0 ? 'disabled' : '' ?>>Hapus</button>
                <?php endif ?>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<?php if ($aktif): ?>
<div class="modal fade" id="hapusMapelModal" tabindex="-1" aria-labelledby="hapusMapelLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('tools/test_manage/mapel/hapus') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="hm_id">
            <div class="modal-header"><h5 class="modal-title text-danger" id="hapusMapelLabel">Hapus Mapel</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <p id="hm_kunci" class="mb-0" hidden>Mapel <strong data-hm="fullname"></strong> sedang <strong>aktif</strong> di tes aktif. Nonaktifkan dulu di <a href="<?= site_url('status_tes') ?>">Setting Ujian</a> sebelum menghapusnya.</p>
                <div id="hm_isi">
                    <p>Hapus mapel <strong data-hm="fullname"></strong> dari Moodle? Quiz, soal, <span id="hm_attempt"></span>dan nilainya ikut <strong>terhapus permanen</strong> dan tidak bisa dikembalikan.</p>
                    <label class="form-label" for="hm_konfirmasi">Ketik <code data-hm="shortname"></code> untuk mengonfirmasi</label>
                    <input class="form-control" id="hm_konfirmasi" name="konfirmasi" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger" id="hm_kirim" disabled>Hapus</button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<?php if ($aktif): ?>
<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?php endif ?>

<?php if ($aktif): ?>
<div class="modal fade" id="ubahUjianModal" tabindex="-1" aria-labelledby="ubahUjianLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('tools/test_manage/ujian/ubah') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $aktif['id'] ?>">
            <div class="modal-header"><h5 class="modal-title" id="ubahUjianLabel">Ubah Ujian</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <label class="form-label" for="uu_nama">Nama ujian</label>
                <input class="form-control" id="uu_nama" name="nama" maxlength="255" required value="<?= esc($aktif['name'], 'attr') ?>">
                <p class="small text-muted mt-2 mb-0">Nama kategori di Moodle ikut berganti. Mapel di dalamnya, soal, dan nilai tidak berubah.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<?php if ($aktif): ?>
<div class="modal fade" id="mapelModal" tabindex="-1" aria-labelledby="mapelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('tools/test_manage/mapel') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kategori" value="<?= (int) $aktif['id'] ?>">
            <div class="modal-header"><h5 class="modal-title" id="mapelModalLabel">Tambah Pelajaran · <?= esc($aktif['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <label class="form-label" for="m_full">Nama pelajaran</label>
                <input class="form-control" id="m_full" name="fullname" maxlength="254" required placeholder="mis. MATEMATIKA X">
                <label class="form-label mt-3" for="m_short">Shortname</label>
                <input class="form-control" id="m_short" name="shortname" maxlength="100" required placeholder="mis. USBK-X-MATEMATIKA">
                <p class="small text-muted mt-2 mb-0">Shortname harus unik (belum pernah dipakai pelajaran lain).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    // Pratinjau rencana "Jumlah hari" (sama dengan Tools::rencanaHari di server): kategori yang ada dipakai
    // dulu urut id dan diganti namanya, kekurangannya dibuat baru. Konfirmasi bila ada yang diganti namanya.
    var form = document.getElementById('hariForm');
    var kategori = JSON.parse(form.dataset.kategori || '[]').sort(function (x, y) { return x.id - y.id; });
    var jumlah = document.getElementById('h_jumlah');
    var awalan = document.getElementById('h_awalan');
    var mulai = document.getElementById('h_mulai');
    var teks = document.getElementById('h_pratinjau');
    var awal = teks.textContent;
    var rencana = function () {
        var n = parseInt(jumlah.value, 10);
        var m = parseInt(mulai.value, 10) || 1;
        var a = awalan.value.trim() || 'H';
        if (!(n >= 1 && n <= 50)) { return null; }
        var pola = new RegExp('^' + a.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(\\d+)$', 'i');
        var pakai = kategori.filter(function (k) { var c = k.name.trim().match(pola); return !(c && parseInt(c[1], 10) < m); });
        var hasil = { ganti: [], buat: [] };
        for (var i = 0; i < n; i++) {
            var nama = a + (m + i);
            if (pakai[i]) {
                if (pakai[i].name !== nama) { hasil.ganti.push(pakai[i].name + ' → ' + nama); }
            } else {
                hasil.buat.push(nama);
            }
        }
        return hasil;
    };
    var perbarui = function () {
        var r = rencana();
        if (!r) { teks.textContent = awal; return; }
        var bagian = [];
        if (r.ganti.length) { bagian.push('Diganti namanya: ' + r.ganti.join(', ')); }
        if (r.buat.length) { bagian.push('Dibuat baru: ' + r.buat.join(', ')); }
        teks.textContent = bagian.length ? bagian.join('. ') + '.' : 'Semua sudah ada, tidak ada perubahan.';
    };
    [jumlah, awalan, mulai].forEach(function (el) { el.addEventListener('input', perbarui); });
    // Hapus ujian berisi mapel: tombol Hapus aktif hanya bila nama diketik persis.
    var konfirmasi = document.getElementById('hu_konfirmasi');
    if (konfirmasi) {
        konfirmasi.addEventListener('input', function () {
            document.getElementById('hu_kirim').disabled = konfirmasi.value.trim() !== konfirmasi.dataset.harus;
        });
    }
    // Hapus mapel: isi dialog dari baris yang diklik; tombol Hapus aktif bila shortname diketik persis.
    var hm = document.getElementById('hapusMapelModal');
    if (hm) {
        var hmKonfirmasi = document.getElementById('hm_konfirmasi');
        var hmKirim = document.getElementById('hm_kirim');
        var hmShort = '';
        document.addEventListener('click', function (event) {
            var tombol = event.target.closest('[data-hapus-mapel]');
            if (!tombol) { return; }
            var m = JSON.parse(tombol.dataset.mapel);
            hmShort = m.shortname;
            document.getElementById('hm_id').value = m.id;
            hm.querySelectorAll('[data-hm]').forEach(function (el) { el.textContent = m[el.dataset.hm]; });
            document.getElementById('hm_attempt').textContent = m.attempt > 0 ? m.attempt + ' jawaban siswa (attempt), ' : '';
            document.getElementById('hm_kunci').hidden = !m.kunci;
            document.getElementById('hm_isi').hidden = m.kunci;
            hmKirim.hidden = m.kunci;
            hmKonfirmasi.value = '';
            hmKonfirmasi.disabled = m.kunci;
            hmKirim.disabled = true;
            bootstrap.Modal.getOrCreateInstance(hm).show();
        });
        hmKonfirmasi.addEventListener('input', function () {
            hmKirim.disabled = hmKonfirmasi.value.trim() !== hmShort;
        });
    }
    // Pindah mapel massal: tombol Pindah aktif bila ada mapel dicentang dan ujian tujuan dipilih.
    var pf = document.getElementById('pindahForm');
    if (pf) {
        var cek = Array.prototype.slice.call(document.querySelectorAll('[data-pm-cek]:not(:disabled)'));
        var semua = document.getElementById('pm_semua');
        var tujuan = document.getElementById('pm_kategori');
        var hitung = function () {
            var n = cek.filter(function (el) { return el.checked; }).length;
            document.getElementById('pm_kirim').disabled = n === 0 || !tujuan.value;
            document.getElementById('pm_jumlah').textContent = n ? n + ' mapel dicentang' : '';
            semua.checked = n > 0 && n === cek.length;
            semua.indeterminate = n > 0 && n < cek.length;
        };
        semua.disabled = cek.length === 0;
        semua.addEventListener('change', function () { cek.forEach(function (el) { el.checked = semua.checked; }); hitung(); });
        cek.forEach(function (el) { el.addEventListener('change', hitung); });
        tujuan.addEventListener('change', hitung);
    }
    // Reset Moodle: tombol aktif bila RESET MOODLE diketik persis; "simpan guru" hanya berlaku bila akun siswa dihapus.
    var rm = document.getElementById('rm_konfirmasi');
    rm.addEventListener('input', function () { document.getElementById('rm_kirim').disabled = rm.value.trim() !== 'RESET MOODLE'; });
    var rmSiswa = document.getElementById('rm_siswa');
    rmSiswa.addEventListener('change', function () { document.getElementById('rm_guru').disabled = !rmSiswa.checked; });
    form.addEventListener('submit', function (event) {
        var r = rencana();
        if (r && r.ganti.length && !confirm('Kategori yang sudah ada akan diganti namanya:\n' + r.ganti.join('\n') + '\n\nMapel, soal, dan nilai di dalamnya tetap. Lanjutkan?')) {
            event.preventDefault();
        }
    });
})();
</script>
<?= $this->endSection() ?>
