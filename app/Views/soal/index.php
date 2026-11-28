<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
// Pilihan mapel dikelompokkan per kategori (ujian); dipakai kedua tab.
$pilihMapel = static function (array $mapel, int $dipilih): string {
    $html = '<option value="">Pilih mapel…</option>';
    $kat  = null;
    foreach ($mapel as $m) {
        if ($m['kategori'] !== $kat) {
            $html .= ($kat !== null ? '</optgroup>' : '') . '<optgroup label="' . esc($m['kategori'], 'attr') . '">';
            $kat = $m['kategori'];
        }
        $html .= '<option value="' . (int) $m['id'] . '"' . ((int) $m['id'] === $dipilih ? ' selected' : '') . '>'
            . esc($m['fullname']) . ' (' . esc($m['shortname']) . ')' . ((int) $m['soal'] > 0 ? ' · ' . (int) $m['soal'] . ' soal' : '') . '</option>';
    }

    return $html . ($kat !== null ? '</optgroup>' : '');
};
?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<?php if ($tab === 'masuk'): ?>
<div class="row g-4">
    <div class="col-lg-7">
        <form class="card" method="post" action="<?= site_url('soal/periksa') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><i class="bi bi-file-earmark-arrow-up me-1"></i>Masukkan soal ke Moodle</div>
            <div class="card-body">
                <?php if ($kiriman): ?>
                    <label class="form-label" for="s_kiriman">Soal kiriman guru</label>
                    <select class="form-select" id="s_kiriman" name="kiriman">
                        <option value="">— unggah berkas sendiri —</option>
                        <?php foreach (\App\Libraries\KirimSoal::TINGKAT as $t): ?>
                            <optgroup label="Kelas <?= $t ?>">
                                <?php foreach ($kiriman as $k): if ($k['tingkat'] !== $t) { continue; } ?>
                                    <option value="<?= (int) $k['id'] ?>" data-mapel="<?= (int) $k['course'] ?>" <?= (int) $k['id'] === $kirimanId ? 'selected' : '' ?>><?= esc($k['mapel'] . ' – ' . $k['guru'] . ' (' . (int) $k['jumlah_soal'] . ' soal)') ?><?= $k['masuk_at'] ? ' ✓ sudah di Moodle' : '' ?><?= ! $k['pasti'] ? ' · mapel tebakan' : '' ?></option>
                                <?php endforeach ?>
                            </optgroup>
                        <?php endforeach ?>
                    </select>
                    <div class="form-text mb-3">Mapel tujuan dipilih otomatis dari nama mapel kiriman; periksa lagi sebelum lanjut.</div>
                <?php endif ?>
                <label class="form-label" for="s_mapel">Mapel tujuan</label>
                <select class="form-select" id="s_mapel" name="mapel" required><?= $pilihMapel($mapel, $dipilih) ?></select>
                <div id="s_berkasGrup">
                    <label class="form-label mt-3" for="s_berkas">Berkas soal (.rtf)</label>
                    <input class="form-control" type="file" id="s_berkas" name="berkas" accept=".rtf,.doc" required>
                </div>
                <p class="small text-body-secondary mt-2 mb-0">Berkas belum dikirim ke Moodle: langkah berikutnya menampilkan pratinjau soal dan kuncinya untuk diperiksa dulu.</p>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary" type="submit">Periksa soal</button></div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Format berkas</div>
            <div class="card-body small">
                <ul class="ps-3 mb-2">
                    <li>Soal pilihan ganda: teks bacaan/pertanyaan (boleh bergambar), lalu opsi berupa <strong>daftar berhuruf</strong> (a, b, c, d, e).</li>
                    <li>Di bawah setiap soal ada baris kunci <code>ANS: C</code>.</li>
                    <li>Judul bagian (<em>Multiple choice</em>) dan baris info (PTS:, DIF:, …) diabaikan. Soal essay/benar-salah dilewati.</li>
                    <li>Simpan dari Word sebagai <strong>Rich Text Format (.rtf)</strong>; gambar JPEG/PNG ikut terbawa.</li>
                </ul>
                <p class="mb-0 text-body-secondary">Soal masuk ke quiz mapel itu (quiz dibuat otomatis bila belum ada), opsi diacak, setiap soal bernilai sama, nilai maksimal 100.</p>
            </div>
        </div>
        <?php if ($admin && \App\Libraries\Ai::tampil()): $aiAda =\App\Libraries\Ai::siap(); $aiProv = \App\Libraries\Ai::provider(); ?>
        <form class="card mt-3" method="post" action="<?= site_url('soal/ai_setelan') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-stars"></i>Cek soal dengan AI
                <span class="badge ms-auto <?= $aiAda ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $aiAda ? 'Aktif' : 'Belum aktif' ?></span></div>
            <div class="card-body small">
                <?php if (! \App\Libraries\Ai::tersedia()): ?>
                    <div class="alert alert-warning py-2">Pustaka HTTP/AI belum terpasang di server: deploy ulang panel.</div>
                <?php endif ?>
                <p class="text-body-secondary">Di halaman Periksa muncul tombol <b>Cek dengan AI</b>: kunci salah, opsi ganda/kembar, soal ambigu, salah ketik. Isi soal dikirim ke provider AI saat tombol itu ditekan.</p>
                <label class="form-label" for="aiProv">Provider</label>
                <select class="form-select form-select-sm" id="aiProv" name="provider">
                    <?php foreach (\App\Libraries\Ai::PROVIDER as $id => $p): ?>
                        <option value="<?= esc($id, 'attr') ?>" data-url="<?= esc($p[1], 'attr') ?>" data-contoh="<?= esc($p[2], 'attr') ?>" <?= $aiProv === $id ? 'selected' : '' ?>><?= esc($p[0]) ?></option>
                    <?php endforeach ?>
                </select>
                <div data-ai="lain">
                    <label class="form-label mt-2" for="aiUrl">Alamat API</label>
                    <input class="form-control form-control-sm" id="aiUrl" name="url" value="<?= esc(\App\Libraries\Ai::alamat(), 'attr') ?>" placeholder="https://…/v1">
                    <label class="form-label mt-2" for="aiModelBebas">Nama model</label>
                    <input class="form-control form-control-sm" id="aiModelBebas" name="model_bebas" value="<?= esc(\App\Libraries\Ai::model() !== '' && $aiProv !== 'anthropic' ? \App\Libraries\Ai::model() : '', 'attr') ?>" placeholder="<?= esc(\App\Libraries\Ai::PROVIDER[$aiProv][2], 'attr') ?>">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="aiGambar" name="gambar" value="1" <?= \App\Libraries\Settings::get('ai_gambar') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aiGambar">Kirim gambar soal (hanya bila model mendukung gambar/vision)</label>
                    </div>
                </div>
                <div data-ai="anthropic">
                    <label class="form-label mt-2" for="aiModel">Model</label>
                    <select class="form-select form-select-sm" id="aiModel" name="model">
                        <?php foreach (\App\Libraries\Ai::MODEL as $id => $nama): ?>
                            <option value="<?= esc($id, 'attr') ?>" <?= \App\Libraries\Settings::get('ai_model') === $id ? 'selected' : '' ?>><?= esc($nama) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <label class="form-label mt-2" for="aiKey">API key</label>
                <input class="form-control form-control-sm" type="password" id="aiKey" name="key" placeholder="<?= \App\Libraries\Ai::ada() ? 'Tersimpan ••••' . esc(\App\Libraries\Ai::ekor(), 'attr') . ' (kosongkan bila tidak diganti)' : 'tempel API key' ?>">
                <div class="form-text">Key disimpan terenkripsi di server dan tidak pernah ditampilkan lagi.</div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary btn-sm" name="aksi" value="simpan">Simpan &amp; tes</button>
                <?php if (\App\Libraries\Ai::ada()): ?>
                    <button class="btn btn-outline-danger btn-sm ms-auto" name="aksi" value="hapus" formnovalidate>Hapus key</button>
                <?php endif ?>
            </div>
        </form>
        <script>
        // Provider dipilih → tampilkan isian yang sesuai, isi alamat API bawaan dan contoh model.
        (function () {
            var prov = document.getElementById('aiProv'), url = document.getElementById('aiUrl'), model = document.getElementById('aiModelBebas');
            var bawaan = [].map.call(prov.options, function (o) { return o.dataset.url; });
            var atur = function (ubah) {
                var o = prov.selectedOptions[0], anth = prov.value === 'anthropic';
                document.querySelectorAll('[data-ai="lain"]').forEach(function (el) { el.hidden = anth; });
                document.querySelectorAll('[data-ai="anthropic"]').forEach(function (el) { el.hidden = !anth; });
                model.placeholder = o.dataset.contoh || 'nama model dari provider';
                if (ubah && (url.value === '' || bawaan.indexOf(url.value) !== -1)) { url.value = o.dataset.url; }
            };
            prov.addEventListener('change', function () { atur(true); });
            atur(false);
        })();
        </script>
        <?php endif ?>
    </div>
</div>
<?php else: ?>
<form class="card mb-4" method="get" action="<?= site_url('soal/' . $tab) ?>">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <div class="flex-grow-1" style="max-width: 36rem">
            <label class="form-label small mb-1" for="e_mapel">Mapel</label>
            <select class="form-select" id="e_mapel" name="mapel" required onchange="this.form.submit()"><?= $pilihMapel($mapel, $dipilih) ?></select>
        </div>
    </div>
</form>

<?php if ($gagal !== null): ?>
    <div class="alert alert-danger" role="alert">Soal tidak bisa dibaca dari Moodle: <?= esc($gagal) ?></div>
<?php elseif ($daftar !== null && $daftar['quiz'] === null): ?>
    <div class="alert alert-warning" role="status">Mapel ini belum punya quiz. Masukkan soalnya dulu di menu <a class="alert-link" href="<?= site_url('soal') ?>?mapel=<?= $dipilih ?>">Masukkan Soal</a></div>
<?php elseif ($daftar !== null): ?>
    <?php // Review & Edit: semua soal lengkap (jembatan soal_semua: clean_text Moodle, gambar data URI) + tombol Edit (modal). ?>
    <?php if ((int) $daftar['sedang'] > 0): ?>
        <div class="alert alert-info d-flex gap-2" role="status">
            <i class="bi bi-pencil-square"></i>
            <span><strong><?= (int) $daftar['sedang'] ?> siswa sedang mengerjakan.</strong> Perbaikan teks langsung tampil saat mereka pindah nomor atau memuat ulang halaman; jawaban yang sudah dipilih tetap.</span>
        </div>
    <?php endif ?>
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold me-auto"><?= esc($daftar['quiz']['nama']) ?></span>
            <span class="small text-body-secondary"><?= count($daftar['soal']) ?> soal · kunci ditandai hijau · <?= (int) $daftar['sedang'] ?> sedang mengerjakan · <?= (int) $daftar['selesai'] ?> selesai</span>
            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Cetak</button>
        </div>
        <div class="card-body review-soal">
            <?php if ($daftar['soal'] === []): ?>
                <p class="text-center text-muted py-4 mb-0">Quiz ini belum berisi soal.</p>
            <?php endif ?>
            <?php foreach ($daftar['soal'] as $s): ?>
                <article class="review-soal-item" id="soal-<?= (int) $s['slot'] ?>">
                    <div class="d-flex gap-3">
                        <div class="review-soal-no font-monospace"><?= (int) $s['slot'] ?>.</div>
                        <div class="flex-grow-1 min-w-0 soal-pratinjau">
                            <?= $s['teks'] !== '' ? $s['teks'] : '<p class="text-body-secondary">(tanpa teks)</p>' ?>
                            <?php if ($s['opsi'] !== []): ?>
                                <ol type="A" class="mb-0">
                                    <?php foreach ($s['opsi'] as $o): ?>
                                        <li class="<?= $o['kunci'] ? 'review-kunci' : '' ?>"><?= $o['teks'] ?><?= $o['kunci'] ? ' <i class="bi bi-check-circle-fill text-success" aria-label="kunci"></i>' : '' ?></li>
                                    <?php endforeach ?>
                                </ol>
                            <?php elseif ($s['qtype'] !== 'multichoice'): ?>
                                <p class="small text-body-secondary mb-0">Tipe soal: <?= esc($s['qtype']) ?></p>
                            <?php endif ?>
                        </div>
                        <button class="btn btn-outline-primary btn-sm align-self-start text-nowrap d-print-none" type="button" data-edit-soal="<?= (int) $s['id'] ?>" data-nomor="<?= (int) $s['slot'] ?>"><i class="bi bi-pencil me-1"></i>Edit</button>
                    </div>
                </article>
            <?php endforeach ?>
        </div>
        <div class="card-body small text-body-secondary border-top d-print-none">
            Urutan opsi seperti di bank soal (siswa melihatnya diacak). Perubahan langsung mengganti soal di Moodle (tanpa versi baru). Opsi tidak bisa ditambah atau dihapus di sini karena urutan acak opsi tiap siswa memakai opsi yang ada.
            Mengubah kunci menilai ulang jawaban siswa yang sudah selesai bila dicentang; siswa yang masih mengerjakan dinilai dengan kunci baru saat mengirim jawaban.
        </div>
    </div>

    <div class="modal fade" id="editSoalModal" tabindex="-1" aria-labelledby="editSoalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form class="modal-content" method="post" action="<?= site_url('soal/ubah') ?>" id="editSoalForm">
                <?= csrf_field() ?>
                <input type="hidden" name="mapel" value="<?= $dipilih ?>">
                <input type="hidden" name="id" id="es_id">
                <input type="hidden" name="nomor" id="es_nomor">
                <input type="hidden" name="teks" id="es_teks">
                <div class="modal-header"><h5 class="modal-title" id="editSoalLabel">Edit soal nomor <span id="es_judul"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
                <div class="modal-body">
                    <div class="text-center text-body-secondary py-5" id="es_muat"><div class="spinner-border mb-2" role="status"></div><div class="small">Membuka soal…</div></div>
                    <div class="alert alert-danger d-none" id="es_gagal" role="alert"></div>
                    <div id="es_isi" hidden>
                        <div class="editor-alat mb-2" role="toolbar" aria-label="Format teks">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-perintah="bold" title="Tebal"><i class="bi bi-type-bold"></i></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-perintah="italic" title="Miring"><i class="bi bi-type-italic"></i></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-perintah="underline" title="Garis bawah"><i class="bi bi-type-underline"></i></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-perintah="superscript" title="Pangkat (x²)">x<sup>2</sup></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-perintah="subscript" title="Indeks (x₂)">x<sub>2</sub></button>
                        </div>
                        <label class="form-label small mb-1" for="es_soal">Teks soal</label>
                        <div class="editor-soal" id="es_soal" contenteditable="true" role="textbox" aria-multiline="true"></div>
                        <div id="es_opsi_wrap">
                            <div class="d-flex align-items-baseline mt-3 mb-1">
                                <span class="form-label small mb-0 me-auto">Opsi jawaban</span>
                                <span class="small text-body-secondary">Pilih bulatan untuk kunci</span>
                            </div>
                            <div id="es_opsi"></div>
                        </div>
                        <p class="small text-body-secondary mt-2 mb-0 d-none" id="es_lain">Tipe soal ini hanya bisa diubah teks soalnya.</p>
                        <div class="form-check mt-3 d-none" id="es_ulang_wrap">
                            <input class="form-check-input" type="checkbox" name="nilai_ulang" value="1" id="es_ulang" checked>
                            <label class="form-check-label" for="es_ulang">Nilai ulang <?= (int) $daftar['selesai'] ?> jawaban siswa yang sudah selesai dengan kunci baru</label>
                        </div>
                        <p class="small text-body-secondary mt-3 mb-0">Gambar yang sudah ada tetap tersimpan; gambar baru tidak bisa ditempel di sini.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="es_simpan" disabled>Simpan</button>
                </div>
            </form>
        </div>
    </div>
<?php endif ?>
<?php endif ?>
<?= $this->endSection() ?>

<?php if ($tab === 'masuk' && $kiriman): ?>
<?= $this->section('scripts') ?>
<script>
// Kiriman guru dipilih → berkas tidak perlu diunggah, mapel tujuan diisi tebakan (bila ketemu).
(function () {
    var pilih = document.getElementById('s_kiriman'), grup = document.getElementById('s_berkasGrup'),
        berkas = document.getElementById('s_berkas'), mapel = document.getElementById('s_mapel');
    var atur = function (ubahMapel) {
        var opt = pilih.options[pilih.selectedIndex], ada = pilih.value !== '';
        grup.hidden = ada;
        berkas.required = !ada;
        if (ada) { berkas.value = ''; }
        if (ada && ubahMapel && opt.dataset.mapel !== '0') { mapel.value = opt.dataset.mapel; }
    };
    pilih.addEventListener('change', function () { atur(true); });
    atur(false);
})();
</script>
<?= $this->endSection() ?>
<?php endif ?>

<?php if ($tab === 'edit' && ! empty($daftar['quiz'])): ?>
<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    // Edit Soal: buka soal lewat soal/baca (JSON), ubah di editor, kembalikan gambar ke @@PLUGINFILE@@ sebelum dikirim.
    var modal = document.getElementById('editSoalModal');
    var form = document.getElementById('editSoalForm');
    var soal = document.getElementById('es_soal');
    var opsi = document.getElementById('es_opsi');
    var simpan = document.getElementById('es_simpan');
    var kunciAwal = 0;
    var selesai = <?= (int) $daftar['selesai'] ?>;
    var url = '<?= site_url('soal/baca') ?>?mapel=<?= $dipilih ?>&id=';
    var el = function (id) { return document.getElementById(id); };

    var editor = function (html) {
        var div = document.createElement('div');
        div.className = 'editor-soal';
        div.contentEditable = 'true';
        div.setAttribute('role', 'textbox');
        div.innerHTML = html;
        return div;
    };
    var keHtml = function (div) {
        var salin = div.cloneNode(true);
        salin.querySelectorAll('img[data-berkas]').forEach(function (img) {
            img.setAttribute('src', img.getAttribute('data-berkas'));
            img.removeAttribute('data-berkas');
        });
        return salin.innerHTML.trim();
    };
    var kunciBerubah = function () {
        var pilih = opsi.querySelector('input[name="kunci"]:checked');
        el('es_ulang_wrap').classList.toggle('d-none', !(pilih && parseInt(pilih.value, 10) !== kunciAwal && selesai > 0));
    };

    document.addEventListener('click', function (event) {
        var tombol = event.target.closest('[data-edit-soal]');
        if (!tombol) { return; }
        el('es_id').value = tombol.dataset.editSoal;
        el('es_nomor').value = tombol.dataset.nomor;
        el('es_judul').textContent = tombol.dataset.nomor;
        el('es_muat').hidden = false;
        el('es_isi').hidden = true;
        el('es_gagal').classList.add('d-none');
        simpan.disabled = true;
        bootstrap.Modal.getOrCreateInstance(modal).show();
        fetch(url + encodeURIComponent(tombol.dataset.editSoal), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.error || 'Soal tidak bisa dibuka.'); } return d; }); })
            .then(function (d) {
                soal.innerHTML = d.teks;
                opsi.innerHTML = '';
                kunciAwal = 0;
                d.opsi.forEach(function (o, i) {
                    var baris = document.createElement('div');
                    baris.className = 'opsi-baris';
                    var radio = document.createElement('input');
                    radio.type = 'radio';
                    radio.name = 'kunci';
                    radio.value = o.id;
                    radio.className = 'form-check-input';
                    radio.checked = o.kunci;
                    radio.disabled = !d.single;
                    radio.setAttribute('aria-label', 'Kunci ' + String.fromCharCode(65 + i));
                    if (o.kunci) { kunciAwal = o.id; }
                    var huruf = document.createElement('span');
                    huruf.className = 'opsi-huruf';
                    huruf.textContent = String.fromCharCode(65 + i);
                    var isi = editor(o.teks);
                    isi.dataset.opsi = o.id;
                    isi.setAttribute('aria-label', 'Opsi ' + huruf.textContent);
                    baris.append(radio, huruf, isi);
                    opsi.append(baris);
                });
                el('es_opsi_wrap').hidden = d.opsi.length === 0;
                el('es_lain').classList.toggle('d-none', d.opsi.length > 0);
                kunciBerubah();
                el('es_muat').hidden = true;
                el('es_isi').hidden = false;
                simpan.disabled = false;
            })
            .catch(function (e) {
                el('es_muat').hidden = true;
                el('es_gagal').textContent = e.message;
                el('es_gagal').classList.remove('d-none');
            });
    });
    opsi.addEventListener('change', kunciBerubah);
    // Tempel dari Word/web sebagai teks biasa, supaya gaya asing tidak ikut masuk ke soal. Gambar (tangkapan layar,
    // "Salin gambar") ditempel sebagai gambar: dikecilkan di sini (lebar maks 1000 px), lalu dikecilkan lagi di server
    // dan disimpan sebagai berkas soal Moodle. Salinan Word berisi teks + gambar pratinjau: yang dipakai teksnya.
    var tempelGambar = function (berkas) {
        var baca = new FileReader();
        baca.onload = function () {
            var img = new Image();
            img.onload = function () {
                var src = baca.result;
                if (img.width > 1000 || berkas.size > 300 * 1024) {
                    var s = Math.min(1, 1000 / img.width), c = document.createElement('canvas');
                    c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
                    var g = c.getContext('2d');
                    g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
                    g.drawImage(img, 0, 0, c.width, c.height);
                    src = c.toDataURL('image/jpeg', 0.85);
                }
                document.execCommand('insertHTML', false, '<img src="' + src + '" alt="">');
            };
            img.src = baca.result;
        };
        baca.readAsDataURL(berkas);
    };
    modal.addEventListener('paste', function (event) {
        if (!event.target.closest('.editor-soal')) { return; }
        event.preventDefault();
        var data = event.clipboardData || window.clipboardData;
        var teks = data.getData('text/plain');
        var gambar = Array.prototype.filter.call(data.items || [], function (it) { return it.kind === 'file' && /^image\//.test(it.type); });
        if (gambar.length && teks.trim() === '') {
            gambar.forEach(function (it) { tempelGambar(it.getAsFile()); });
            return;
        }
        document.execCommand('insertText', false, teks);
    });
    // Tab di editor = jarak tab (4 spasi tetap, tidak menyusut di HTML) seperti di Word, bukan pindah ke kolom lain.
    // Shift+Tab menghapus jarak tab tepat sebelum kursor.
    modal.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey || !event.target.closest('.editor-soal')) { return; }
        event.preventDefault();
        if (!event.shiftKey) {
            document.execCommand('insertText', false, '    ');
            return;
        }
        var sel = window.getSelection();
        if (!sel.rangeCount || !sel.isCollapsed) { return; }
        var node = sel.anchorNode, pos = sel.anchorOffset;
        if (node.nodeType !== 3) { return; }
        var awal = pos;
        while (awal > 0 && pos - awal < 4 && /[  ]/.test(node.data.charAt(awal - 1))) { awal--; }
        if (awal < pos) {
            var r = document.createRange();
            r.setStart(node, awal); r.setEnd(node, pos);
            sel.removeAllRanges(); sel.addRange(r);
            document.execCommand('delete');
        }
    });
    document.querySelectorAll('[data-perintah]').forEach(function (b) {
        b.addEventListener('mousedown', function (event) { event.preventDefault(); });
        b.addEventListener('click', function () { document.execCommand(b.dataset.perintah); });
    });
    form.addEventListener('submit', function (event) {
        if (soal.textContent.trim() === '' && !soal.querySelector('img')) {
            event.preventDefault();
            alert('Teks soal tidak boleh kosong.');
            return;
        }
        form.querySelectorAll('input[data-opsi-isi]').forEach(function (i) { i.remove(); });
        el('es_teks').value = keHtml(soal);
        opsi.querySelectorAll('[data-opsi]').forEach(function (div) {
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'opsi[' + div.dataset.opsi + ']';
            i.value = keHtml(div);
            i.setAttribute('data-opsi-isi', '');
            form.append(i);
        });
        simpan.disabled = true;
    });
})();
</script>
<?= $this->endSection() ?>
<?php endif ?>
