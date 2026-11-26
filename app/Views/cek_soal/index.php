<?php use App\Libraries\CekSoal; ?>
<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$warna = ['selesai' => 'text-bg-success', 'dicabut' => 'text-bg-secondary', 'kedaluwarsa' => 'text-bg-secondary', 'dibuka' => 'text-bg-warning', 'belum' => 'text-bg-light border'];
$perT  = [];
foreach ($mapel as $m) {
    $perT[$m['tingkat'] !== '' ? $m['tingkat'] : 'Lainnya'][] = $m;
}
$namaGuru = array_column($guru, 'nama', 'id');
?>
<?php if ($error !== null): ?>
    <div class="alert alert-danger">Data Moodle tidak bisa dibaca: <?= esc($error) ?></div>
<?php endif ?>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab === 'tugas' ? 'active' : '' ?>" href="<?= site_url('cek_soal') ?>">Tautan Cek</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'guru' ? 'active' : '' ?>" href="<?= site_url('cek_soal') ?>?tab=guru">Data Guru <span class="badge text-bg-secondary"><?= count($guru) ?></span></a></li>
</ul>

<?php if ($tab === 'tugas'): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <form class="card" method="post" action="<?= site_url('cek_soal/buat') ?>">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-1"></i>Buat tautan cek soal</div>
            <div class="card-body">
                <label class="form-label" for="cMapel">Mapel</label>
                <select class="form-select" id="cMapel" name="mapel" required>
                    <option value="">Pilih mapel…</option>
                    <?php foreach ($perT as $t => $list): ?>
                        <optgroup label="<?= esc($t === 'Lainnya' ? $t : 'Kelas ' . $t, 'attr') ?>">
                            <?php foreach ($list as $m): ?>
                                <option value="<?= (int) $m['id'] ?>" <?= (int) $m['soal'] === 0 || $m['tutup'] !== '' ? 'disabled' : '' ?>><?= esc($m['fullname']) ?> · <?= esc($m['kategori']) ?> (<?= (int) $m['soal'] === 0 ? 'belum ada soal' : (int) $m['soal'] . ' soal' ?><?= $m['tutup'] !== '' ? ', ' . esc($m['tutup']) : '' ?>)</option>
                            <?php endforeach ?>
                        </optgroup>
                    <?php endforeach ?>
                </select>

                <label class="form-label mt-3">Guru</label>
                <div id="cGuru" class="d-flex flex-wrap gap-2 small text-muted">Pilih mapel dulu: guru pengampunya otomatis tercentang.</div>
                <input class="form-control form-control-sm mt-2" name="nama_lain" maxlength="300" placeholder="Nama guru lain (opsional, pisahkan koma)">

                <label class="form-label mt-3" for="cHari">Batas waktu</label>
                <select class="form-select w-auto" id="cHari" name="hari">
                    <?php foreach (CekSoal::BATAS_HARI as $h): ?>
                        <option value="<?= $h ?>" <?= $h === 1 ? 'selected' : '' ?>><?= $h ?> hari</option>
                    <?php endforeach ?>
                </select>
                <div class="form-text">Tiap guru mendapat <?= CekSoal::jumlah() === 0 ? 'semua soal' : CekSoal::jumlah() . ' soal acak (berbeda antar guru, diutamakan yang belum pernah dicek)' ?>; ubah di kartu Pengaturan. Tautan juga tertutup otomatis bila mapel sedang diujikan atau siswa sudah mengerjakannya.</div>
            </div>
            <div class="card-footer d-flex align-items-center gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i>Buat<?= $telegram ? ' &amp; kirim ke Telegram' : '' ?></button>
                <?php if (! $telegram): ?><span class="small text-muted">Bot Telegram belum diatur (Admin Tools → Server Cadangan): pesan disalin dari tabel.</span><?php endif ?>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="bi bi-telegram me-1"></i>Lewat bot Telegram</div>
            <div class="card-body small">
                Ketik <code>/ceksoal</code> di chat admin: pilih tingkat → mapel → (guru tercentang otomatis) → batas waktu.
                Bot mengirim 1 pesan per guru; salin pesannya lalu teruskan ke WA guru. <code>/cekstatus</code> = siapa yang belum selesai.
            </div>
        </div>
        <form class="card" method="post" action="<?= site_url('cek_soal/setelan') ?>">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Pengaturan</div>
            <div class="card-body">
                <label class="form-label small" for="cJumlah">Jumlah soal per guru</label>
                <select class="form-select form-select-sm mb-1" id="cJumlah" name="jumlah">
                    <?php foreach (CekSoal::PILIHAN_JUMLAH as $n): ?>
                        <option value="<?= $n ?>" <?= $n === CekSoal::jumlah() ? 'selected' : '' ?>><?= $n === 0 ? 'Semua soal' : $n . ' soal' ?></option>
                    <?php endforeach ?>
                </select>
                <div class="form-text mb-3">Berlaku untuk tautan yang dibuat sesudahnya (panel dan bot). Bila soal mapel lebih sedikit, semua soalnya ditampilkan.</div>
                <label class="form-label small" for="cAlamat">Alamat tautan</label>
                <input class="form-control form-control-sm" id="cAlamat" name="alamat" value="<?= esc($alamat, 'attr') ?>" required>
                <div class="form-text">Cukup domainnya, mis. <code>https://pengawasujian.sekolah.sch.id</code> (path /cbtadmin/ ditambahkan otomatis). Pakai domain bila guru membuka dari luar jaringan sekolah.</div>
            </div>
            <div class="card-footer"><button class="btn btn-outline-primary btn-sm" type="submit">Simpan</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">Daftar tautan cek</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Guru</th><th>Mapel</th><th>Batas</th><th>Status</th><th>Hasil</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
            <?php if ($tugas === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada tautan cek.</td></tr>
            <?php endif ?>
            <?php foreach ($tugas as $t): ?>
                <?php $st = CekSoal::status($t); $cat = CekSoal::catatan($t); $aktif = in_array($st, ['belum', 'dibuka'], true); ?>
                <tr class="<?= in_array((int) $t['id'], array_map('intval', $baru), true) ? 'table-info' : '' ?>">
                    <td><?= esc($t['guru_nama']) ?></td>
                    <td><?= esc($t['mapel']) ?></td>
                    <td class="small text-nowrap"><?= esc(date('d/m H:i', strtotime((string) $t['batas']))) ?></td>
                    <td>
                        <span class="badge <?= $warna[$st] ?>"><?= CekSoal::NAMA_STATUS[$st] ?></span>
                        <?php if ($t['dibuka_at'] !== null): ?><div class="small text-muted" title="IP <?= esc((string) $t['ip'], 'attr') ?>">dibuka <?= esc(date('d/m H:i', strtotime((string) $t['dibuka_at']))) ?> · <?= (int) $t['akses'] ?>×</div><?php endif ?>
                        <?php if ((int) $t['ditolak'] > 0): ?><div class="small text-danger"><i class="bi bi-exclamation-triangle"></i> <?= (int) $t['ditolak'] ?>× dicoba dari perangkat lain</div><?php endif ?>
                    </td>
                    <td class="small">
                        <?php if ($st === 'selesai'): ?>
                            <a href="<?= site_url('cek_soal/hasil/' . $t['id']) ?>"><i class="bi bi-check-circle text-success"></i> Dinyatakan benar</a><?php if ($cat !== ''): ?><div class="text-danger"><i class="bi bi-chat-left-text"></i> <?= esc(mb_strimwidth($cat, 0, 80, '…')) ?></div><?php endif ?>
                        <?php else: ?>–<?php endif ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($aktif): ?>
                            <textarea class="d-none" id="pesan<?= (int) $t['id'] ?>" readonly><?= esc(CekSoal::pesan($t)) ?></textarea>
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-salin="#pesan<?= (int) $t['id'] ?>" title="Salin pesan untuk WA"><i class="bi bi-clipboard"></i></button>
                            <?php if ($telegram): ?>
                                <button class="btn btn-outline-primary btn-sm" type="button" title="Kirim ulang ke Telegram" data-post="<?= site_url('cek_soal/aksi/' . $t['id']) ?>" data-fields='{"aksi":"kirim"}' data-confirm-text="Kirim ulang pesan <?= esc($t['guru_nama'], 'attr') ?> ke Telegram admin?"><i class="bi bi-telegram"></i></button>
                            <?php endif ?>
                            <?php if ($t['perangkat'] !== null): ?>
                                <button class="btn btn-outline-warning btn-sm" type="button" title="Lepas kunci perangkat" data-post="<?= site_url('cek_soal/aksi/' . $t['id']) ?>" data-fields='{"aksi":"lepas"}' data-confirm-text="Lepas kunci perangkat tautan <?= esc($t['guru_nama'], 'attr') ?>? Perangkat berikutnya yang membuka akan terkunci."><i class="bi bi-phone"></i></button>
                            <?php endif ?>
                            <button class="btn btn-outline-danger btn-sm" type="button" title="Cabut tautan" data-post="<?= site_url('cek_soal/aksi/' . $t['id']) ?>" data-fields='{"aksi":"cabut"}' data-confirm-text="Cabut tautan <?= esc($t['guru_nama'], 'attr') ?>? Tautan langsung tidak bisa dibuka dan dihapus dari daftar."><i class="bi bi-x-circle"></i></button>
                        <?php else: ?>
                            <?php if ($st === 'selesai'): ?>
                                <a class="btn btn-outline-primary btn-sm" href="<?= site_url('cek_soal/hasil/' . $t['id']) ?>"><i class="bi bi-eye me-1"></i>Hasil</a>
                            <?php endif ?>
                            <button class="btn btn-outline-danger btn-sm" type="button" title="Hapus" data-post="<?= site_url('cek_soal/aksi/' . $t['id']) ?>" data-fields='{"aksi":"hapus"}' data-confirm-text="Hapus tautan <?= esc($t['guru_nama'], 'attr') ?> (<?= esc($t['mapel'], 'attr') ?>)<?= $st === 'selesai' ? ' beserta pernyataannya' : '' ?>? Setelah dihapus, tautan baru bisa dibuat ulang lewat panel atau /ceksoal."><i class="bi bi-trash"></i></button>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <form class="card h-100" method="post" action="<?= site_url('cek_soal/guru/unggah') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><i class="bi bi-file-earmark-excel me-1"></i>Unggah data guru</div>
            <div class="card-body">
                <p class="small mb-2">Bisa langsung memakai <strong>lampiran SK penulis soal</strong> (kolom Mata Pelajaran + kolom guru per kelas XII/XI/X), atau template 3 kolom: <strong>Nama Guru</strong>, <strong>Mapel</strong>, <strong>Tingkat</strong>. Nama mapel dicocokkan otomatis (mis. "Pend. Agama Islam" → AGAMA ISLAM, "Fisika Lanjutan" → FISIKA).</p>
                <input class="form-control mb-2" type="file" name="berkas" accept=".xlsx,.xls,.csv" required>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="ganti" value="1" id="gGanti" checked><label class="form-check-label" for="gGanti">Ganti semua data lama</label></div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-upload me-1"></i>Unggah</button>
                <a class="btn btn-outline-secondary" href="<?= site_url('cek_soal/guru/template') ?>"><i class="bi bi-download me-1"></i>Template</a>
            </div>
        </form>
    </div>
    <div class="col-lg-6">
        <form class="card h-100" method="post" action="<?= site_url('cek_soal/guru/simpan') ?>">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><i class="bi bi-person-plus me-1"></i>Tambah satu baris</div>
            <div class="card-body row g-2">
                <div class="col-12"><input class="form-control" name="nama" placeholder="Nama guru" required maxlength="120"></div>
                <div class="col-8"><input class="form-control" name="mapel" placeholder="Mapel" required maxlength="120"></div>
                <div class="col-4"><select class="form-select" name="tingkat"><option>X</option><option>XI</option><option>XII</option></select></div>
            </div>
            <div class="card-footer"><button class="btn btn-primary" type="submit">Tambah</button></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Nama Guru</th><th>Mapel</th><th>Tingkat</th><th>Cocok dengan mapel Moodle</th><th></th></tr></thead>
            <tbody>
            <?php if ($guru === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data guru. Unggah dari template.</td></tr>
            <?php endif ?>
            <?php foreach ($guru as $g): ?>
                <?php $kena = array_filter($mapel, static fn ($m) => in_array((int) $m['id'], $cocok[(int) $g['id']] ?? [], true)); ?>
                <tr>
                    <td><?= esc($g['nama']) ?></td>
                    <td><?= esc($g['mapel']) ?></td>
                    <td><?= esc($g['tingkat']) ?></td>
                    <td class="small">
                        <?php if ($kena === []): ?>
                            <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Tidak ada (periksa nama mapel)</span>
                        <?php else: ?>
                            <?= esc(implode(', ', array_map(static fn ($m) => $m['fullname'] . ' (' . $m['kategori'] . ')', $kena))) ?>
                        <?php endif ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-outline-danger btn-sm" type="button" data-post="<?= site_url('cek_soal/guru/hapus/' . $g['id']) ?>" data-confirm-text="Hapus <?= esc($g['nama'], 'attr') ?> (<?= esc($g['mapel'] . ' ' . $g['tingkat'], 'attr') ?>)?"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>
<?php // Formulir tersembunyi untuk tombol data-post (Cabut, Kirim ulang, Lepas kunci, Hapus guru) di panel.js. ?>
<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    // Salin pesan (untuk diteruskan ke WA guru).
    document.querySelectorAll('[data-salin]').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.querySelector(b.dataset.salin);
            var selesai = function () { b.innerHTML = '<i class="bi bi-check2"></i>'; };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(el.value).then(selesai); return; }
            el.classList.remove('d-none'); el.select(); document.execCommand('copy'); el.classList.add('d-none'); selesai();
        });
    });
    // Pilih mapel → guru pengampunya tampil tercentang.
    var pilih = document.getElementById('cMapel'), kotak = document.getElementById('cGuru');
    if (!pilih) { return; }
    var PETA = <?= json_encode((object) $peta) ?>, NAMA = <?= json_encode((object) $namaGuru) ?>;
    pilih.addEventListener('change', function () {
        var ids = PETA[pilih.value] || [];
        kotak.innerHTML = '';
        if (!pilih.value) { kotak.textContent = 'Pilih mapel dulu: guru pengampunya otomatis tercentang.'; return; }
        if (!ids.length) { kotak.textContent = 'Belum ada data guru untuk mapel ini (tab Data Guru), isi nama di bawah.'; return; }
        ids.forEach(function (id) {
            var lbl = document.createElement('label');
            lbl.className = 'btn btn-outline-primary btn-sm';
            var cb = document.createElement('input');
            cb.type = 'checkbox'; cb.name = 'guru[]'; cb.value = id; cb.checked = true; cb.className = 'form-check-input me-1';
            lbl.appendChild(cb); lbl.appendChild(document.createTextNode(NAMA[id]));
            kotak.appendChild(lbl);
        });
    });
})();
</script>
<?= $this->endSection() ?>
