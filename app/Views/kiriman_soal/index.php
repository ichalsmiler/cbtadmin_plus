<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$ditutup = $tutup !== null && time() >= $tutup;
$total   = array_sum(array_map('count', $rekap));
$sudah   = 0;
foreach ($rekap as $list) {
    foreach ($list as $r) {
        $sudah += $r['kirim'] !== null ? 1 : 0;
    }
}
$q = $lihat ? '?ta=' . rawurlencode($lihat['ta']) . '&semester=' . rawurlencode($lihat['semester']) : '';
?>
<?php if (! $adaApp): ?>
    <div class="alert alert-warning">Aplikasi Konver Soal belum disalin. Di PC jalankan <code>bash deploy/salin-konver-soal.sh</code>, lalu deploy ulang.</div>
<?php endif ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <span class="text-body-secondary small">Kiriman guru tersimpan per tahun ajaran → semester → kelas.</span>
    <a class="btn btn-primary btn-sm ms-auto" href="<?= site_url('kiriman_soal/folder') . ($aktif ? '?p=' . rawurlencode(str_replace('/', '-', $aktif['ta']) . '/' . $aktif['semester']) : '') ?>"><i class="bi bi-folder2-open me-1"></i>Lihat folder soal</a>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-folder2-open me-1"></i>Periode pengumpulan</div>
            <div class="card-body">
            <form method="post" action="<?= site_url('kiriman_soal/aktifkan') ?>">
                <?= csrf_field() ?>
                <p class="small text-muted mb-3">
                    <?php if ($aktif): ?>
                        Aktif: <b>Semester <?= esc($aktif['semester']) ?> <?= esc($aktif['ta']) ?></b>. Kiriman guru masuk ke folder
                        <code><?= esc(str_replace('/', '-', $aktif['ta']) . '/' . $aktif['semester']) ?>/X · XI · XII</code>.
                    <?php else: ?>
                        Belum ada periode aktif: guru belum bisa mengirim.
                    <?php endif ?>
                </p>
                <div class="d-flex flex-wrap gap-2 align-items-end">
                    <div>
                        <label class="form-label small" for="kTa">Tahun ajaran</label>
                        <select class="form-select" id="kTa" name="ta">
                            <?php foreach ($pilihTa as $t): ?>
                                <option <?= ($aktif['ta'] ?? '') === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small" for="kSem">Semester</label>
                        <select class="form-select" id="kSem" name="semester">
                            <?php foreach (['Ganjil', 'Genap'] as $s): ?>
                                <option <?= ($aktif['semester'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Aktifkan</button>
                </div>
                <?php $dg = $dataGuru; ?>
                <div class="alert alert-warning small mt-3 mb-0 d-none" id="kGuruCek"
                     data-ta="<?= esc($aktif['ta'] ?? '') ?>" data-sem="<?= esc($aktif['semester'] ?? '') ?>">
                    <div class="fw-semibold mb-1"><i class="bi bi-people me-1"></i>Periode baru: periksa Data Guru dulu</div>
                    Data Guru (Cek Soal): <b><?= $dg['guru'] ?> guru</b> ·
                    <?php foreach ($dg['per'] as $t => $n): ?>
                        <?= esc($t) ?>: <?= $n ?><?= $t !== array_key_last($dg['per']) ? ' ·' : '' ?>
                    <?php endforeach ?>
                    <br>Terakhir diubah: <b><?= $dg['diubah'] !== '' ? esc(date('d-m-Y H:i', strtotime($dg['diubah']))) : 'belum ada data' ?></b>.
                    Bila ada guru pindah, guru baru, atau pembagian mapel berubah, perbarui dulu di
                    <a href="<?= site_url('cek_soal') ?>?tab=guru">Cek Soal → Data Guru</a>.
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="guru_sesuai" value="1" id="kGuruSesuai">
                        <label class="form-check-label fw-semibold" for="kGuruSesuai">Data guru sudah sesuai untuk periode ini</label>
                    </div>
                </div>
            </form>
            <script>
            (() => {
                const box = document.getElementById('kGuruCek'), cek = document.getElementById('kGuruSesuai');
                const ta = document.getElementById('kTa'), sem = document.getElementById('kSem');
                const cocokkan = () => {
                    const baru = ta.value !== box.dataset.ta || sem.value !== box.dataset.sem;
                    box.classList.toggle('d-none', !baru);
                    cek.required = baru;
                };
                ta.addEventListener('change', cocokkan);
                sem.addEventListener('change', cocokkan);
                cocokkan();
            })();
            </script>
            <form class="d-flex flex-wrap gap-2 align-items-end mt-3 pt-3 border-top" method="post" action="<?= site_url('kiriman_soal/ta') ?>">
                <?= csrf_field() ?>
                <div>
                    <label class="form-label small" for="kTaBaru">Tambah tahun ajaran</label>
                    <input class="form-control" id="kTaBaru" name="ta" placeholder="2027/2028" pattern="\d{4}/\d{4}" maxlength="9" required>
                </div>
                <button class="btn btn-outline-primary" name="aksi" value="tambah"><i class="bi bi-plus-lg me-1"></i>Tambah</button>
            </form>
            <div class="d-flex flex-wrap gap-1 mt-2">
                <?php foreach ($pilihTa as $t): ?>
                    <span class="badge text-bg-light border d-inline-flex align-items-center gap-1"><?= esc($t) ?>
                        <?php if (($aktif['ta'] ?? '') !== $t && ! in_array($t, $taTerpakai, true)): ?>
                            <button class="btn btn-link btn-sm p-0 text-danger lh-1" type="button" title="Hapus dari daftar" data-post="<?= site_url('kiriman_soal/ta') ?>" data-fields='<?= esc(json_encode(['aksi' => 'hapus', 'ta' => $t]), 'attr') ?>' data-confirm-text="Hapus tahun ajaran <?= esc($t, 'attr') ?> dari daftar?"><i class="bi bi-x"></i></button>
                        <?php endif ?>
                    </span>
                <?php endforeach ?>
            </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-send"></i>Pengiriman guru
                <span class="badge ms-auto <?= $ditutup ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $ditutup ? 'Ditutup' : 'Dibuka' ?></span>
            </div>
            <div class="card-body">
                <label class="form-label small" for="kTautan">Tautan untuk guru (tanpa login)</label>
                <div class="input-group mb-3">
                    <input class="form-control" id="kTautan" value="<?= esc($tautan, 'attr') ?>" readonly>
                    <button class="btn btn-outline-secondary" type="button" data-salin="#kTautan" title="Salin tautan"><i class="bi bi-clipboard"></i></button>
                    <a class="btn btn-outline-secondary" href="<?= esc($tautan, 'attr') ?>" target="_blank" rel="noopener" title="Buka"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>
                <label class="form-label small" for="kStatus">Status pengumpulan (tanpa login, sudah/belum per guru)</label>
                <div class="input-group mb-3">
                    <input class="form-control" id="kStatus" value="<?= esc($tautan . 'status', 'attr') ?>" readonly>
                    <button class="btn btn-outline-secondary" type="button" data-salin="#kStatus" title="Salin tautan"><i class="bi bi-clipboard"></i></button>
                    <a class="btn btn-outline-secondary" href="<?= esc($tautan . 'status', 'attr') ?>" target="_blank" rel="noopener" title="Buka"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>
                <?php if ($tutup !== null && ! $ditutup): ?>
                    <p class="small mb-2">Dijadwalkan tutup <b><?= date('d-m-Y H:i', $tutup) ?></b>.</p>
                <?php elseif ($ditutup): ?>
                    <p class="small mb-2">Ditutup sejak <b><?= date('d-m-Y H:i', $tutup) ?></b>.</p>
                <?php endif ?>
                <form class="d-flex flex-wrap gap-2 align-items-end" method="post" action="<?= site_url('kiriman_soal/tutup') ?>">
                    <?= csrf_field() ?>
                    <?php if ($tutup !== null): ?>
                        <button class="btn btn-success" name="aksi" value="buka"><i class="bi bi-unlock me-1"></i>Buka</button>
                    <?php endif ?>
                    <?php if (! $ditutup): ?>
                        <button class="btn btn-outline-danger" name="aksi" value="sekarang"><i class="bi bi-lock me-1"></i>Tutup sekarang</button>
                        <input class="form-control w-auto" type="datetime-local" name="waktu" aria-label="Waktu tutup">
                        <button class="btn btn-outline-secondary" name="aksi" value="jadwal">Jadwalkan tutup</button>
                    <?php endif ?>
                </form>
                <div class="form-text">Nama guru, mapel, dan kelas diambil dari <a href="<?= site_url('cek_soal') ?>?tab=guru">Cek Soal → Data Guru</a>.</div>
            </div>
        </div>
    </div>
</div>

<?php if ($lihat): ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0">Semester <?= esc($lihat['semester']) ?> <?= esc($lihat['ta']) ?></h2>
    <span class="badge text-bg-primary"><?= $sudah ?> / <?= $total ?> sudah kirim</span>
    <?php if (count($periode) > 1 || ($periode && $aktif && ($periode[0]['ta'] !== $aktif['ta'] || $periode[0]['semester'] !== $aktif['semester']))): ?>
        <select class="form-select form-select-sm w-auto ms-auto" onchange="location.href='?'+this.value" aria-label="Periode">
            <?php foreach ($periode as $p): ?>
                <option value="ta=<?= esc(rawurlencode($p['ta']), 'attr') ?>&semester=<?= esc($p['semester'], 'attr') ?>" <?= $p['ta'] === $lihat['ta'] && $p['semester'] === $lihat['semester'] ? 'selected' : '' ?>><?= esc($p['semester'] . ' ' . $p['ta']) ?> (<?= (int) $p['n'] ?>)</option>
            <?php endforeach ?>
        </select>
    <?php endif ?>
    <a class="btn btn-outline-secondary btn-sm <?= count($periode) > 1 ? '' : 'ms-auto' ?>" href="<?= site_url('kiriman_soal/zip') . $q ?>"><i class="bi bi-file-zip me-1"></i>Unduh semua (.zip)</a>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <?php $i = 0; foreach ($rekap as $t => $list): $n = count(array_filter($list, static fn ($r) => $r['kirim'] !== null)); ?>
        <li class="nav-item"><button class="nav-link <?= $i++ === 0 ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#t<?= $t ?>" type="button">Kelas <?= $t ?> <span class="badge <?= $n === count($list) && $n > 0 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $n ?>/<?= count($list) ?></span></button></li>
    <?php endforeach ?>
</ul>
<div class="tab-content">
    <?php $i = 0; foreach ($rekap as $t => $list): ?>
    <div class="tab-pane fade <?= $i++ === 0 ? 'show active' : '' ?>" id="t<?= $t ?>">
        <?php if (! $list): ?>
            <p class="text-muted">Belum ada guru kelas <?= $t ?> di Data Guru.</p>
        <?php else: ?>
        <div class="d-flex mb-2"><a class="btn btn-outline-secondary btn-sm ms-auto" href="<?= site_url('kiriman_soal/zip') . $q . '&tingkat=' . $t ?>"><i class="bi bi-file-zip me-1"></i>Unduh folder <?= $t ?> (.zip)</a></div>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Mapel</th><th>Guru</th><th>Status</th><th class="text-end">Soal</th><th>Waktu kirim</th><th class="text-end">Berkas</th></tr></thead>
            <tbody>
            <?php foreach ($list as $r): $k = $r['kirim']; ?>
                <tr>
                    <td><?= esc($r['mapel']) ?></td>
                    <td><?= esc($r['guru']) ?><?= ! empty($r['lain']) ? ' <span class="badge text-bg-light border" title="Tidak ada lagi di Data Guru">lain</span>' : '' ?></td>
                    <td>
                        <?php if ($k): ?>
                            <span class="badge text-bg-success">Sudah</span>
                            <?php if ((int) $k['kali'] > 1): ?><span class="badge text-bg-light border" title="Dikirim ulang, berkas terakhir yang disimpan"><?= (int) $k['kali'] ?>×</span><?php endif ?>
                            <?php if (! empty($k['masuk_at'])): ?><span class="badge text-bg-primary" title="Dimasukkan ke Moodle <?= esc(date('d-m-Y H:i', strtotime($k['masuk_at'])), 'attr') ?>">Di Moodle</span><?php endif ?>
                        <?php else: ?>
                            <span class="badge text-bg-warning">Belum</span>
                        <?php endif ?>
                    </td>
                    <td class="text-end"><?php if ($k): ?><?= (int) $k['jumlah_soal'] ?><?php if ((int) $k['perlu_dicek'] > 0): ?> <span class="badge text-bg-warning" title="Soal yang ditandai perlu dicek saat dikirim"><?= (int) $k['perlu_dicek'] ?> cek</span><?php endif ?><?php endif ?></td>
                    <td class="small"><?= $k ? esc(date('d-m-Y H:i', strtotime($k['updated_at']))) : '' ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($k): ?>
                            <?php if (session('admin_role') === 'admin' && $aktif && $k['ta'] === $aktif['ta'] && $k['semester'] === $aktif['semester']): ?>
                                <a class="btn btn-primary btn-sm" href="<?= site_url('soal') . '?kiriman=' . (int) $k['id'] ?>" title="Periksa lalu masukkan ke Moodle"><i class="bi bi-box-arrow-in-right"></i> Masukkan</a>
                            <?php endif ?>
                            <?php if (! empty($k['courseid'])): ?>
                                <a class="btn btn-outline-primary btn-sm" href="<?= site_url('kiriman_soal/bandingkan/' . $k['id']) ?>" title="Bandingkan soal kiriman dengan soal di Moodle"><i class="bi bi-layout-split"></i> Bandingkan</a>
                            <?php endif ?>
                            <a class="btn btn-outline-primary btn-sm" href="<?= site_url('kiriman_soal/unduh/' . $k['id'] . '/rtf') ?>" title="Unduh RTF"><i class="bi bi-file-earmark-text"></i> RTF</a>
                            <?php if ($k['asli']): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('kiriman_soal/unduh/' . $k['id'] . '/asli') ?>" title="Unduh Word asli"><i class="bi bi-file-earmark-word"></i></a><?php endif ?>
                            <button class="btn btn-outline-danger btn-sm" type="button" title="Hapus kiriman" data-post="<?= site_url('kiriman_soal/hapus/' . $k['id']) ?>" data-confirm-text="Hapus kiriman <?= esc($r['mapel'] . ' – ' . $r['guru'], 'attr') ?>? Berkasnya ikut terhapus."><i class="bi bi-trash"></i></button>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php endif ?>
    </div>
    <?php endforeach ?>
</div>
<?php endif ?>
<div class="card mt-4" id="peta">
    <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-1"></i>Pasangan mapel → Moodle <span class="badge text-bg-secondary"><?= count($peta) ?></span></div>
    <div class="card-body">
        <p class="small text-muted">Tersimpan otomatis setiap kali soal kiriman berhasil dimasukkan. Kiriman berikutnya (juga semester lain) langsung memakai mapel ini tanpa konfirmasi. Hapus bila salah: kiriman berikutnya kembali ditebak dan wajib dikonfirmasi.</p>
        <?php if (! $peta): ?>
            <p class="text-muted mb-0">Belum ada.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Kelas</th><th>Mapel (Data Guru)</th><th>Course Moodle</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($peta as $kunci => $sn): [$kls, $mp] = array_pad(explode('|', $kunci, 2), 2, ''); ?>
                <tr>
                    <td><?= esc($kls) ?></td>
                    <td><?= esc($mp) ?></td>
                    <td><code><?= esc($sn) ?></code></td>
                    <td class="text-end">
                        <?php if (session('admin_role') === 'admin'): ?>
                            <button class="btn btn-outline-danger btn-sm" type="button" title="Hapus pasangan" data-post="<?= site_url('kiriman_soal/peta/hapus') ?>" data-fields='<?= esc(json_encode(['kunci' => $kunci]), 'attr') ?>' data-confirm-text="Hapus pasangan <?= esc($mp . ' kelas ' . $kls . ' → ' . $sn, 'attr') ?>?"><i class="bi bi-trash"></i></button>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php endif ?>
    </div>
</div>
<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('[data-salin]').forEach(function (b) {
    b.addEventListener('click', function () {
        var el = document.querySelector(b.dataset.salin);
        var selesai = function () { b.innerHTML = '<i class="bi bi-check2"></i>'; };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(el.value).then(selesai); return; }
        el.select(); document.execCommand('copy'); selesai();
    });
});
</script>
<?= $this->endSection() ?>
