<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$s      = $status ?? [];
$peran  = (string) ($s['peran'] ?? '');
$detik  = static function ($d): string {
    if ($d === null) {
        return '–';
    }
    $d = (int) $d;

    return $d < 60 ? $d . ' detik lalu' : ($d < 3600 ? floor($d / 60) . ' menit lalu' : floor($d / 3600) . ' jam lalu');
};
$ukuran = static fn ($b): string => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : round($b / 1048576, 1) . ' MB';
$repl   = $s['replikasi'] ?? null;
// WireGuard memperbarui kontak (handshake) ±2 menit sekali selama tersambung; lebih dari 3 menit = putus.
$kontakHidup = isset($s['kontak_detik']) && $s['kontak_detik'] !== null && (int) $s['kontak_detik'] <= 180;
$replOk = $repl && $repl['io'] === 'Yes' && $repl['sql'] === 'Yes';
?>
<?php if ($error !== ''): ?>
    <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= esc($error) ?></div>
<?php endif ?>

<div class="row g-4">
    <div class="col-xxl-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-hdd-network me-1"></i>Status server ini</div>
            <div class="card-body">
                <?php if ($status === null): ?>
                    <p class="text-muted mb-0">Status belum bisa dibaca.</p>
                <?php elseif (! $s['terpasang'] || $peran === ''): ?>
                    <p class="mb-2"><span class="badge text-bg-secondary fs-6 fw-normal">Belum dipasang</span></p>
                    <p class="text-muted small mb-0">Server ini belum disiapkan sebagai server utama maupun cadangan. Isi pengaturan di bawah, lalu ikuti langkah pemasangan.</p>
                <?php else: ?>
                    <p class="mb-3">
                        <?php if ($peran === 'utama'): ?>
                            <span class="badge text-bg-success fs-6 fw-normal"><i class="bi bi-broadcast me-1"></i>Server UTAMA</span>
                        <?php elseif ($peran === 'nonaktif'): ?>
                            <span class="badge text-bg-warning fs-6 fw-normal"><i class="bi bi-pause-circle me-1"></i>Server UTAMA (nonaktif)</span>
                            <span class="d-block small text-muted mt-2">Server cadangan sudah aktif dan dipakai ujian, jadi server ini berhenti melayani siswa
                                (siswa diarahkan ke alamat cadangan). Data terbaru ada di server cadangan.</span>
                        <?php else: ?>
                            <span class="badge text-bg-info fs-6 fw-normal"><i class="bi bi-shield-check me-1"></i>Server CADANGAN (siaga)</span>
                        <?php endif ?>
                    </p>
                    <table class="table table-sm align-middle mb-0">
                        <tr><th class="fw-normal text-muted" style="width:42%">Alamat utama</th><td><?= esc($s['url_utama'] ?: '–') ?> <span class="text-muted small">(<?= esc($s['utama_ip']) ?>)</span></td></tr>
                        <tr><th class="fw-normal text-muted">Alamat cadangan</th><td><?= esc($s['url_cadangan'] ?: '–') ?> <span class="text-muted small">(<?= esc($s['cadangan_ip']) ?>)</span></td></tr>
                        <tr><th class="fw-normal text-muted">Hubungan ke server <?= $peran === 'cadangan' ? 'utama' : 'cadangan' ?></th>
                            <td><?= $kontakHidup
                                    ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Terhubung</span> <span class="text-muted small">(' . $detik($s['kontak_detik']) . ')</span>'
                                    : '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Tidak terhubung</span>'
                                      . ($s['kontak_detik'] !== null ? ' <span class="text-muted small">(kontak terakhir ' . $detik($s['kontak_detik']) . ')</span>' : '') ?></td></tr>
                        <?php if ($peran === 'cadangan'): ?>
                            <tr><th class="fw-normal text-muted">Salinan database</th>
                                <td><?php if (! $repl): ?><span class="text-danger">Tidak aktif</span>
                                    <?php elseif ($replOk): ?><span class="text-success"><i class="bi bi-check-circle me-1"></i>Berjalan</span> <span class="text-muted small">(tertinggal <?= esc($repl['lag'] !== '' ? $repl['lag'] : '?') ?> detik)</span>
                                    <?php else: ?><span class="text-danger"><i class="bi bi-x-circle me-1"></i>Berhenti</span><div class="small text-danger"><?= esc($repl['error']) ?></div><?php endif ?></td></tr>
                            <tr><th class="fw-normal text-muted">Salinan file (moodledata)</th><td><?= esc($detik($s['sync_detik'])) ?></td></tr>
                            <tr><th class="fw-normal text-muted">Backup terbaru</th>
                                <td><?php if ($s['backup'] === []): ?>–<?php else: ?>
                                    <?php foreach ($s['backup'] as $b): ?><div class="small"><?= esc(date('d/m H:i', (int) $b['waktu'])) ?> · <?= esc($ukuran((int) $b['ukuran'])) ?></div><?php endforeach ?>
                                <?php endif ?>
                                <?php if ($s['disk_kosong'] !== null): ?><div class="small text-muted">Ruang kosong <?= esc($ukuran((int) $s['disk_kosong'])) ?></div><?php endif ?></td></tr>
                        <?php endif ?>
                    </table>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-xxl-6">
        <?php if ($peran === 'cadangan'): ?>
            <form class="card h-100 border-danger" method="post" action="<?= site_url('server_cadangan/pindah') ?>" data-no-dirty>
                <?= csrf_field() ?>
                <div class="card-header fw-semibold text-danger"><i class="bi bi-lightning-charge me-1"></i>Aktifkan server ini sebagai utama</div>
                <div class="card-body">
                    <p class="small mb-2">Pakai <strong>hanya bila server utama mati</strong> (mis. mati lampu di sekolah) dan tidak bisa segera hidup lagi.</p>
                    <ol class="small mb-3">
                        <li>Pastikan siswa <strong>tidak</strong> lagi mengerjakan di server utama. Bila dua server sama-sama menerima jawaban, datanya bercabang.</li>
                        <li>Data terakhir yang sudah tersalin dipakai; jawaban siswa tetap tersimpan.</li>
                        <li>Setelah selesai, umumkan alamat ujian baru ke siswa: <strong><?= esc($s['url_cadangan'] ?: '–') ?></strong>. Siswa login lagi lalu melanjutkan.</li>
                    </ol>
                    <?php if ($kontakHidup): ?>
                        <div class="alert alert-warning small py-2">Server utama <strong>masih terhubung</strong>. Matikan dulu server utama sebelum memindahkan.</div>
                    <?php endif ?>
                    <label class="form-label" for="konfirmasiPindah">Ketik <code>PINDAH</code> untuk mengonfirmasi</label>
                    <div class="input-group">
                        <input class="form-control" id="konfirmasiPindah" name="konfirmasi" autocomplete="off" required>
                        <button class="btn btn-danger" type="submit" <?= ! empty($s['pindah_berjalan']) ? 'disabled' : '' ?>><i class="bi bi-lightning-charge me-1"></i>Aktifkan</button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Saat server utama mati</div>
                <div class="card-body small">
                    <ol class="mb-0">
                        <li>Buka panel di <strong>server cadangan (VPS)</strong>: <?= esc(($s['url_cadangan'] ?? '') !== '' ? $s['url_cadangan'] . '/cbtadmin/' : 'alamat cadangan/cbtadmin/') ?></li>
                        <li>Login admin (akun sama dengan sekolah); panel langsung membuka Server Cadangan. Ketik <code>PINDAH</code>, klik <strong>Aktifkan</strong>.
                            <div class="text-muted">Panel tidak bisa dibuka? Di konsol VPS jalankan <code>cbt-ha pindah</code>.</div></li>
                        <li>Umumkan alamat ujian cadangan ke siswa. Siswa login lagi dan melanjutkan; jawaban yang sudah tersimpan tetap ada.</li>
                    </ol>
                    <p class="text-muted mt-2 mb-0">Panel di server cadangan memakai akun admin yang sama (data panel ikut tersalin).</p>
                </div>
            </div>
        <?php endif ?>
    </div>
</div>

<div class="card mt-4 <?= ! empty($s['pindah_berjalan']) ? '' : 'd-none' ?>" id="proses">
    <div class="card-header fw-semibold"><i class="bi bi-terminal me-1"></i>Proses pindah <span class="spinner-border spinner-border-sm ms-1" id="prosesSpin"></span></div>
    <pre class="card-body small mb-0" id="prosesLog" style="max-height:24rem;overflow:auto;white-space:pre-wrap"></pre>
</div>

<form class="card mt-4" id="pengaturan" method="post" action="<?= site_url('server_cadangan/simpan') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Pengaturan (ha.conf)</div>
    <div class="card-body">
        <p class="small text-muted">Utama di sekolah (Proxmox, akses publik lewat MikroTik), cadangan di VPS. Server sekolah yang menghubungi VPS lewat WireGuard,
            jadi tidak perlu membuka port baru di MikroTik; VPS cukup membuka UDP 51820. Isinya harus <strong>sama</strong> di kedua server.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="ha_domain_utama">Alamat ujian utama</label>
                <input class="form-control" id="ha_domain_utama" name="ha_domain_utama" value="<?= esc($nilai['ha_domain_utama'], 'attr') ?>" placeholder="IP publik MikroTik atau domain">
                <div class="form-text">Tanpa http://. IP → http, domain Cloudflare → https.</div></div>
            <div class="col-md-6"><label class="form-label" for="ha_domain_cadangan">Alamat ujian cadangan</label>
                <input class="form-control" id="ha_domain_cadangan" name="ha_domain_cadangan" value="<?= esc($nilai['ha_domain_cadangan'], 'attr') ?>" placeholder="IP publik VPS atau domain">
                <div class="form-text">Alamat yang diumumkan ke siswa saat pindah.</div></div>
            <div class="col-md-6"><label class="form-label" for="ha_utama_ip">IP server utama</label>
                <input class="form-control" id="ha_utama_ip" name="ha_utama_ip" value="<?= esc($nilai['ha_utama_ip'], 'attr') ?>" placeholder="IP LAN, mis. 172.16.32.157"></div>
            <div class="col-md-6"><label class="form-label" for="ha_cadangan_ip">IP server cadangan</label>
                <input class="form-control" id="ha_cadangan_ip" name="ha_cadangan_ip" value="<?= esc($nilai['ha_cadangan_ip'], 'attr') ?>" placeholder="IP publik VPS"></div>
            <div class="col-md-6"><label class="form-label" for="ha_secret">Kunci rahasia bersama</label>
                <input class="form-control font-monospace" type="password" id="ha_secret" name="ha_secret" value="<?= esc($nilai['ha_secret'], 'attr') ?>" placeholder="dibuat otomatis bila kosong">
                <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="secret_baru" value="1" id="secret_baru">
                    <label class="form-check-label small" for="secret_baru">Buat kunci baru (pasang ulang kedua server setelahnya)</label></div></div>
            <div class="col-6 col-md-3"><label class="form-label" for="ha_backup_hari">Simpan backup (hari)</label>
                <input class="form-control" type="number" min="1" max="90" id="ha_backup_hari" name="ha_backup_hari" value="<?= esc($nilai['ha_backup_hari'], 'attr') ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="ha_backup_jam">Jam backup 15 menitan</label>
                <input class="form-control" id="ha_backup_jam" name="ha_backup_jam" value="<?= esc($nilai['ha_backup_jam'], 'attr') ?>" placeholder="6-17"></div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="ha_https" name="ha_https" value="1" <?= $nilai['ha_https'] === 'ya' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ha_https">Pakai HTTPS lewat Cloudflare (Tunnel atau Proxied / awan oranye)</label>
                </div>
                <div class="form-text">Nyalakan bila alamat dibuka lewat <strong>https</strong> (Cloudflare Tunnel atau Proxied): alamat Moodle tetap https
                    setelah <code>cbt-ha</code> dijalankan. Sertifikat Cloudflare Origin di <code>/etc/ssl/cbt/</code> opsional (untuk mode Full (strict)).
                    Matikan hanya bila DNS diatur <strong>DNS only</strong> (awan abu-abu) dan alamat memakai http biasa.</div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2">
        <button class="btn btn-success" type="submit"><i class="bi bi-save me-1"></i>Simpan</button>
        <a class="btn btn-outline-primary ms-auto" href="<?= site_url('server_cadangan/unduh') ?>"><i class="bi bi-download me-1"></i>Unduh ha.conf</a>
    </div>
</form>

<?php
// Sudah tersambung (utama terhubung ke VPS, atau server ini sendiri cadangan): langkah sambungkan tidak perlu
// tampil lagi; tetap bisa dibuka lewat tautan kecil untuk menyambung ulang / mengganti VPS cadangan.
$tersambung = $peran === 'cadangan' || ($peran === 'utama' && ($s['kontak_detik'] ?? null) !== null);
?>
<?php if ($tersambung): ?>
<details class="mt-4 small text-muted">
    <summary>Sambungkan ulang / ganti server cadangan</summary>
<?php endif ?>
<div class="card mt-<?= $tersambung ? '2' : '4' ?>">
    <div class="card-header fw-semibold"><i class="bi bi-list-ol me-1"></i>Sambungkan ke cadangan (sekali)</div>
    <div class="card-body small">
        <ol class="mb-3">
            <li>VPS sudah dipasang dengan perintah yang sama seperti server utama (<em>pasang-cbt.sh</em>, lihat tutorial), port <strong>UDP 51820</strong> dibuka.</li>
            <li>Isi pengaturan di atas lalu <strong>Simpan</strong>.</li>
            <li>Di <strong>konsol server utama</strong> (login root), jalankan:</li>
        </ol>
        <div class="input-group mb-3">
            <input class="form-control font-monospace" readonly value="cbt-ha utama" id="cmdUtama">
            <button class="btn btn-outline-secondary" type="button" data-salin="#cmdUtama"><i class="bi bi-clipboard me-1"></i>Salin</button>
        </div>
        <ol class="mb-3" start="4">
            <li>Di <strong>konsol VPS cadangan</strong> (login root), jalankan perintah ini (berisi kunci rahasia, jangan dibagikan):</li>
        </ol>
        <?php if ($kode !== null): ?>
            <div class="input-group mb-3">
                <input class="form-control font-monospace" readonly value="<?= esc('cbt-ha cadangan ' . $kode, 'attr') ?>" id="cmdCadangan">
                <button class="btn btn-outline-secondary" type="button" data-salin="#cmdCadangan"><i class="bi bi-clipboard me-1"></i>Salin</button>
            </div>
        <?php else: ?>
            <div class="alert alert-warning py-2">Perintah muncul setelah IP server utama, IP server cadangan, dan pengaturan disimpan.</div>
        <?php endif ?>
        <ol class="mb-0" start="5">
            <li>Muat ulang halaman ini di kedua server: utama berstatus <em>UTAMA</em>, VPS berstatus <em>CADANGAN</em> dengan salinan database <em>Berjalan</em>.</li>
        </ol>
    </div>
</div>
<?php if ($tersambung): ?>
</details>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    // Tombol Salin perintah konsol.
    document.querySelectorAll('[data-salin]').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.querySelector(b.dataset.salin);
            el.select();
            var selesai = function () { b.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersalin'; };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(el.value).then(selesai); }
            else { document.execCommand('copy'); selesai(); }
        });
    });
    // Catatan proses pindah: diperbarui tiap 3 detik selama berjalan.
    var kartu = document.getElementById('proses'), pre = document.getElementById('prosesLog'), spin = document.getElementById('prosesSpin');
    var ambil = function () {
        fetch('<?= site_url('server_cadangan/log') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) { return; }
                if (d.log) { kartu.classList.remove('d-none'); pre.textContent = d.log; pre.scrollTop = pre.scrollHeight; }
                spin.classList.toggle('d-none', !d.berjalan);
                if (d.berjalan) { setTimeout(ambil, 3000); }
            })
            .catch(function () { setTimeout(ambil, 5000); });
    };
    if (location.hash === '#proses' || <?= ! empty($s['pindah_berjalan']) ? 'true' : 'false' ?>) { ambil(); }
})();
</script>
<?= $this->endSection() ?>
