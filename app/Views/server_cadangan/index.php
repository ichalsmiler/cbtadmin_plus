<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$s      = $status ?? [];
$peran  = (string) ($s['peran'] ?? '');
// Jenis = dipasang sebagai utama atau cadangan (tetap); peran = keadaan sekarang. Helper lama belum mengirim jenis.
$jenis  = (string) ($s['jenis'] ?? ($peran === 'cadangan' ? 'cadangan' : 'utama'));
$utamaNonaktif = $jenis === 'utama' && ($peran === 'nonaktif' || $peran === 'kembali');
$detik  = static function ($d): string {
    if ($d === null) {
        return '–';
    }
    $d = (int) $d;

    return $d < 60 ? $d . ' detik lalu' : ($d < 3600 ? floor($d / 60) . ' menit lalu' : floor($d / 3600) . ' jam lalu');
};
$ukuran = static fn ($b): string => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : round($b / 1048576, 1) . ' MB';
$repl   = $s['replikasi'] ?? null;
// Terhubung = server pasangan menjawab ping lewat terowongan sekarang (helper baru). Helper lama: umur handshake
// WireGuard (diperbarui ±2 menit sekali) maksimal 3 menit.
$kontakHidup = array_key_exists('terhubung', $s)
    ? (bool) $s['terhubung']
    : isset($s['kontak_detik']) && $s['kontak_detik'] !== null && (int) $s['kontak_detik'] <= 180;
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
                        <?php if ($jenis === 'utama' && $peran === 'utama'): ?>
                            <span class="badge text-bg-success fs-6 fw-normal"><i class="bi bi-broadcast me-1"></i>Server UTAMA</span>
                        <?php elseif ($utamaNonaktif): ?>
                            <span class="badge text-bg-warning fs-6 fw-normal"><i class="bi bi-pause-circle me-1"></i>Server UTAMA (nonaktif)</span>
                            <span class="d-block small text-muted mt-2">Server cadangan sudah aktif dan dipakai ujian, jadi server ini berhenti melayani siswa.
                                Data terbaru ada di server cadangan.</span>
                        <?php elseif ($peran === 'utama'): ?>
                            <span class="badge text-bg-danger fs-6 fw-normal"><i class="bi bi-lightning-charge me-1"></i>Server CADANGAN (sedang dipakai ujian)</span>
                            <span class="d-block small text-muted mt-2">Server ini dipindah menjadi utama. Setelah server utama normal dan ujian selesai,
                                buka panel server utama → Server Cadangan → <strong>Kembalikan</strong>.</span>
                        <?php elseif ($peran === 'nonaktif'): ?>
                            <span class="badge text-bg-warning fs-6 fw-normal"><i class="bi bi-arrow-repeat me-1"></i>Server CADANGAN (data dikembalikan)</span>
                            <span class="d-block small text-muted mt-2">Server ini berhenti melayani siswa; data sedang dikembalikan ke server utama, lalu server ini
                                otomatis dipasang ulang sebagai cadangan (beberapa menit).</span>
                        <?php else: ?>
                            <span class="badge text-bg-info fs-6 fw-normal"><i class="bi bi-shield-check me-1"></i>Server CADANGAN (siaga)</span>
                        <?php endif ?>
                    </p>
                    <table class="table table-sm align-middle mb-0">
                        <tr><th class="fw-normal text-muted" style="width:42%">Alamat utama</th><td><?= esc($s['url_utama'] ?: '–') ?> <span class="text-muted small">(<?= esc($s['utama_ip']) ?>)</span></td></tr>
                        <tr><th class="fw-normal text-muted">Alamat cadangan</th><td><?= esc($s['url_cadangan'] ?: '–') ?> <span class="text-muted small">(<?= esc($s['cadangan_ip']) ?>)</span></td></tr>
                        <tr><th class="fw-normal text-muted">Hubungan ke server <?= $jenis === 'cadangan' ? 'utama' : 'cadangan' ?></th>
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
                        <div class="alert alert-warning small py-2">Server utama <strong>masih terhubung</strong>, jadi tombol Aktifkan dikunci supaya jawaban siswa tidak bercabang. Matikan server utama atau putuskan jaringannya dulu.</div>
                    <?php endif ?>
                    <label class="form-label" for="konfirmasiPindah">Ketik <code>PINDAH</code> untuk mengonfirmasi</label>
                    <div class="input-group">
                        <input class="form-control" id="konfirmasiPindah" name="konfirmasi" autocomplete="off" required>
                        <button class="btn btn-danger" type="submit" <?= ! empty($s['pindah_berjalan']) || $kontakHidup ? 'disabled' : '' ?>><i class="bi bi-lightning-charge me-1"></i>Aktifkan</button>
                    </div>
                </div>
            </form>
        <?php elseif ($utamaNonaktif): ?>
            <form class="card h-100 border-success" method="post" action="<?= site_url('server_cadangan/kembalikan') ?>" data-no-dirty>
                <?= csrf_field() ?>
                <div class="card-header fw-semibold text-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Kembalikan ujian ke server ini</div>
                <div class="card-body">
                    <p class="small mb-2">Pakai setelah server ini normal lagi, <strong>di luar jam ujian</strong>. Server cadangan menolak bila masih ada siswa mengerjakan atau sesi ujian masih aktif, kecuali dipaksa.</p>
                    <ol class="small mb-3">
                        <li>Server cadangan berhenti menerima ujian, lalu menyiapkan salinan data terbaru.</li>
                        <li>Database dan berkas disalin ke server ini (data lama dibackup dulu), lalu server ini melayani ujian lagi di <strong><?= esc($s['url_utama'] ?: '–') ?></strong>.</li>
                        <li>Server cadangan otomatis dipasang ulang sebagai cadangan (beberapa menit). Cek statusnya di halaman ini.</li>
                    </ol>
                    <?php if (! $kontakHidup): ?>
                        <div class="alert alert-warning small py-2">Server cadangan <strong>tidak terhubung</strong>. Pastikan server cadangan hidup lalu muat ulang halaman ini.</div>
                    <?php endif ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="paksa" value="1" id="paksaKembali">
                        <label class="form-check-label small" for="paksaKembali"><strong>Paksa walau ada yang mengerjakan</strong> (darurat):
                            siswa melihat halaman tunggu ±2–4 menit, lalu memuat ulang dan lanjut tanpa login ulang. Jawaban yang sudah tersimpan aman.</label>
                    </div>
                    <label class="form-label" for="konfirmasiKembali">Ketik <code>KEMBALIKAN</code> untuk mengonfirmasi</label>
                    <div class="input-group">
                        <input class="form-control" id="konfirmasiKembali" name="konfirmasi" autocomplete="off" required>
                        <button class="btn btn-success" type="submit" <?= ! empty($s['pindah_berjalan']) || ! $kontakHidup ? 'disabled' : '' ?>><i class="bi bi-arrow-counterclockwise me-1"></i>Kembalikan</button>
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

<?php // Proses berhasil: kartu dilipat (seperti "Sambungkan ulang"), cukup satu baris ringkas; gagal / berjalan: terbuka. ?>
<details class="mt-4 <?= ! empty($s['pindah_berjalan']) ? '' : 'd-none' ?>" id="proses" open>
    <summary class="small text-muted" id="prosesRingkas">Proses server cadangan</summary>
    <div class="card mt-2">
        <div class="card-header fw-semibold"><i class="bi bi-terminal me-1"></i>Proses server cadangan <span class="spinner-border spinner-border-sm ms-1" id="prosesSpin"></span></div>
        <div class="card-body pb-0 d-none" id="prosesHasil"><div class="alert mb-0" role="status" aria-live="polite"></div></div>
        <pre class="card-body small mb-0" id="prosesLog" style="max-height:24rem;overflow:auto;white-space:pre-wrap"></pre>
    </div>
</details>

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
        <hr>
        <h6 class="fw-semibold"><i class="bi bi-lightning-charge me-1"></i>Pindah otomatis</h6>
        <p class="small text-muted">Server cadangan memeriksa server utama tiap menit. Bila server utama tidak terhubung selama waktu di bawah
            (mis. mati lampu), server cadangan <strong>pindah sendiri</strong> menjadi utama, lalu domain <strong><?= esc($nilai['ha_domain_utama'] ?: 'alamat ujian utama') ?></strong>
            diarahkan ke IP server cadangan lewat API Cloudflare. Siswa memakai alamat yang sama: muat ulang lalu lanjutkan quiz (sesi ikut pindah).
            Kembali ke server utama lewat tombol <strong>Kembalikan</strong>, atau otomatis bila <strong>Kembali otomatis</strong> aktif (domain ikut kembali).
            Siswa harus membuka ujian lewat domain itu (bukan IP lokal / DNS rewrite di jaringan sekolah), supaya semua siswa ikut pindah.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="ha_pindah_otomatis" name="ha_pindah_otomatis" value="1" <?= $nilai['ha_pindah_otomatis'] === 'ya' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ha_pindah_otomatis">Aktifkan pindah otomatis</label>
                </div>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="ha_kembali_otomatis" name="ha_kembali_otomatis" value="1" <?= $nilai['ha_kembali_otomatis'] === 'ya' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ha_kembali_otomatis">Kembali otomatis</label>
                    <div class="form-text">Setelah server utama hidup lagi dan stabil 10 menit, ujian dikembalikan sendiri ke server utama bila tidak ada siswa
                        mengerjakan 15 menit terakhir (biasanya setelah ujian hari itu selesai).</div>
                </div>
            </div>
            <div class="col-6 col-md-3"><label class="form-label" for="ha_pindah_menit">Setelah tidak terhubung (menit)</label>
                <input class="form-control" type="number" min="2" max="30" id="ha_pindah_menit" name="ha_pindah_menit" value="<?= esc($nilai['ha_pindah_menit'], 'attr') ?>"></div>
            <div class="col-md-6"><label class="form-label" for="ha_ip_publik_utama">Tujuan domain saat server utama aktif</label>
                <input class="form-control" id="ha_ip_publik_utama" name="ha_ip_publik_utama" value="<?= esc($nilai['ha_ip_publik_utama'], 'attr') ?>" placeholder="IP publik MikroTik atau <id>.cfargotunnel.com">
                <div class="form-text">IP publik MikroTik (mis. 191.101.190.252) atau alamat Cloudflare Tunnel di server utama (<code>&lt;id&gt;.cfargotunnel.com</code>).
                    Dipakai saat Kembalikan. Saat pindah, domain diarahkan ke IP server cadangan. Bukan IP VPS.</div></div>
            <div class="col-md-6"><label class="form-label" for="ha_cf_token">Token API Cloudflare</label>
                <input class="form-control font-monospace" type="password" id="ha_cf_token" name="ha_cf_token" value="" placeholder="<?= $nilai['ha_cf_token'] !== '' ? 'tersimpan — kosongkan untuk tetap memakai' : 'belum diisi' ?>">
                <div class="form-text">Cloudflare → My Profile → API Tokens → Create Token → template <strong>Edit zone DNS</strong>, zone domain sekolah.
                    <?php if ($nilai['ha_cf_token'] !== ''): ?><label class="ms-1"><input type="checkbox" name="hapus_cf_token" value="1"> hapus token</label><?php endif ?></div></div>
            <div class="col-md-6"><label class="form-label" for="ha_cf_zone">Zone ID Cloudflare (opsional)</label>
                <input class="form-control font-monospace" id="ha_cf_zone" name="ha_cf_zone" value="<?= esc($nilai['ha_cf_zone'], 'attr') ?>" placeholder="kosong = dicari otomatis">
                <div class="form-text">Di halaman Overview domain, kolom kanan "Zone ID". Isi bila Cek DNS gagal menemukan zone.</div></div>
        </div>
        <h6 class="fw-semibold mt-4"><i class="bi bi-telegram me-1"></i>Pemberitahuan Telegram</h6>
        <p class="small text-body-secondary mb-2">Pesan ke HP admin saat server utama hilang, pindah ke cadangan, hidup lagi, dan saat Kembalikan selesai/gagal.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="ha_tg_token">Token bot Telegram</label>
                <input class="form-control font-monospace" type="password" id="ha_tg_token" name="ha_tg_token" value="" autocomplete="off" placeholder="<?= $nilai['ha_tg_token'] !== '' ? 'tersimpan — kosongkan untuk tetap memakai' : 'belum diisi' ?>">
                <div class="form-text">Telegram → @BotFather → <code>/newbot</code>. Lalu buka bot itu dan kirim pesan apa saja (atau masukkan bot ke grup admin), baru klik <strong>Kirim pesan uji</strong>.
                    <?php if ($nilai['ha_tg_token'] !== ''): ?><label class="ms-1"><input type="checkbox" name="hapus_tg_token" value="1"> hapus token</label><?php endif ?></div></div>
            <div class="col-md-6"><span class="form-label d-block">Tujuan pesan</span>
                <?php if ($nilai['ha_tg_chat'] !== ''): ?>
                    <span class="badge text-bg-success fw-normal"><i class="bi bi-check-circle me-1"></i>terhubung (chat <?= esc($nilai['ha_tg_chat']) ?>)</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary fw-normal">belum terhubung</span>
                <?php endif ?>
                <div class="form-text">Ditemukan otomatis dari pesan terakhir yang diterima bot saat Kirim pesan uji.</div></div>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2">
        <button class="btn btn-success" type="submit"><i class="bi bi-save me-1"></i>Simpan</button>
        <a class="btn btn-outline-secondary" href="<?= site_url('server_cadangan/dns') ?>" title="Lihat ke mana domain ujian diarahkan sekarang (perlu token API Cloudflare)"><i class="bi bi-globe me-1"></i>Cek DNS</a>
        <button class="btn btn-outline-secondary" type="submit" formaction="<?= site_url('server_cadangan/telegram') ?>" title="Simpan token bot (bila diketik), cari chat tujuan, lalu kirim pesan uji"><i class="bi bi-telegram me-1"></i>Kirim pesan uji</button>
        <a class="btn btn-outline-primary ms-auto" href="<?= site_url('server_cadangan/unduh') ?>"><i class="bi bi-download me-1"></i>Unduh ha.conf</a>
    </div>
</form>

<?php
// Sudah tersambung (utama terhubung ke VPS, atau server ini sendiri cadangan): langkah sambungkan tidak perlu
// tampil lagi; tetap bisa dibuka lewat tautan kecil untuk menyambung ulang / mengganti VPS cadangan.
$tersambung = $peran === 'cadangan' || ($peran === 'utama' && ($s['kontak_detik'] ?? null) !== null);
?>
<?php if ($tersambung): ?>
<details class="mt-4">
    <summary class="small text-muted">Sambungkan ulang / ganti server cadangan</summary>
<?php endif ?>
<?php
// Tombol per server: mode sinkron hanya di server utama (baru atau sudah utama); tempel KODE hanya di server
// baru atau server cadangan (server utama yang aktif tidak boleh ditimpa data server lain).
$bisaUtama    = $jenis === 'utama' && in_array($peran, ['', 'utama'], true);
$bisaCadangan = in_array($peran, ['', 'cadangan'], true);
?>
<div class="card mt-<?= $tersambung ? '2' : '4' ?>" id="sambung">
    <div class="card-header fw-semibold"><i class="bi bi-list-ol me-1"></i>Sambungkan ke cadangan (sekali)</div>
    <div class="card-body">
        <ol class="small mb-3">
            <li>VPS sudah dipasang dengan perintah yang sama seperti server utama (<em>pasang-cbt.sh</em>, lihat tutorial), port <strong>UDP 51820</strong> dibuka.</li>
            <li>Di panel server utama: isi pengaturan di atas lalu <strong>Simpan</strong>.</li>
            <li>Di panel <strong>server utama</strong>: pilih <strong>Server utama</strong> → <strong>Aktifkan mode sinkron</strong>, tunggu catatannya selesai, lalu salin <strong>KODE</strong>.</li>
            <li>Di panel <strong>server cadangan</strong> (VPS) → Server Cadangan: pilih <strong>Server cadangan</strong>, tempel KODE lalu klik <strong>Jadikan server cadangan</strong>.</li>
            <li>Muat ulang halaman ini di kedua server: utama berstatus <em>UTAMA</em>, VPS berstatus <em>CADANGAN</em> dengan salinan database <em>Berjalan</em>.</li>
        </ol>

        <?php $pilih = $bisaUtama && $bisaCadangan; // server baru: pilih dulu jadi utama atau cadangan ?>
        <?php if ($pilih): ?>
            <p class="fw-semibold mb-2 mt-4">Server ini akan dijadikan:</p>
            <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Pilih peran server ini">
                <button class="btn btn-outline-primary" type="button" data-peran-pilih="peranUtama" aria-pressed="false"><i class="bi bi-broadcast me-1"></i>Server utama</button>
                <button class="btn btn-outline-primary" type="button" data-peran-pilih="peranCadangan" aria-pressed="false"><i class="bi bi-shield-check me-1"></i>Server cadangan</button>
            </div>
        <?php endif ?>

        <?php if ($bisaUtama): ?>
          <div id="peranUtama" class="<?= $pilih ? 'd-none' : '' ?>">
            <h6 class="fw-semibold mt-4"><i class="bi bi-broadcast me-1"></i>Server utama (server ini)</h6>
            <?php if ($kode === null): ?>
                <div class="alert alert-warning py-2">Isi IP server utama, IP server cadangan, dan pengaturan lainnya, lalu Simpan.</div>
            <?php else: ?>
                <form method="post" action="<?= site_url('server_cadangan/sinkron') ?>" class="mb-3" data-no-dirty
                      data-confirm="Aktifkan mode sinkron di server ini? Pemasangan bisa me-restart database sebentar, jadi jangan dilakukan saat ujian berlangsung.">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Aktifkan mode sinkron</button>
                </form>
                <label class="form-label" for="kodeCadangan">KODE untuk server cadangan (berisi kunci rahasia, jangan dibagikan)</label>
                <div class="input-group mb-1">
                    <input class="form-control font-monospace" readonly value="<?= esc($kode, 'attr') ?>" id="kodeCadangan">
                    <button class="btn btn-outline-secondary" type="button" data-salin="#kodeCadangan"><i class="bi bi-clipboard me-1"></i>Salin</button>
                </div>
                <div class="form-text mb-3">Tanpa panel di VPS: jalankan <code>cbt-ha cadangan KODE</code> di konsol VPS.</div>
            <?php endif ?>
          </div>
        <?php endif ?>

        <?php if ($bisaCadangan): ?>
          <div id="peranCadangan" class="<?= $pilih ? 'd-none' : '' ?>">
            <form method="post" action="<?= site_url('server_cadangan/jadi_cadangan') ?>" id="jadi-cadangan" class="<?= $pilih ? '' : 'border-top pt-3 ' ?>mt-3" autocomplete="off" data-no-dirty>
                <?= csrf_field() ?>
                <h6 class="fw-semibold"><i class="bi bi-shield-check me-1"></i>Jadikan server ini cadangan</h6>
                <p class="small text-muted mb-3">Hanya di server cadangan (VPS). Database Moodle dan panel di server ini <strong>diganti salinan dari server utama</strong>.
                    Setelah selesai, login panel memakai akun admin server utama.</p>
                <label class="form-label" for="kodeTempel">KODE dari panel server utama</label>
                <textarea class="form-control font-monospace mb-2" id="kodeTempel" name="kode" rows="3" required spellcheck="false"
                          placeholder="Tempel KODE di sini"></textarea>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <input class="form-control" style="max-width:14rem" name="konfirmasi" required placeholder="Ketik CADANGAN" autocomplete="off">
                    <button class="btn btn-outline-danger" type="submit"><i class="bi bi-shield-check me-1"></i>Jadikan server cadangan</button>
                </div>
            </form>
          </div>
        <?php endif ?>
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
    // Server baru: tombol Server utama / Server cadangan menampilkan bagiannya (yang lain disembunyikan).
    var pilihPeran = function (id) {
        document.querySelectorAll('[data-peran-pilih]').forEach(function (b) {
            var aktif = b.dataset.peranPilih === id;
            b.classList.toggle('btn-primary', aktif);
            b.classList.toggle('btn-outline-primary', !aktif);
            b.setAttribute('aria-pressed', aktif ? 'true' : 'false');
            var bagian = document.getElementById(b.dataset.peranPilih);
            if (bagian) { bagian.classList.toggle('d-none', !aktif); }
        });
    };
    document.querySelectorAll('[data-peran-pilih]').forEach(function (b) {
        b.addEventListener('click', function () { pilihPeran(b.dataset.peranPilih); });
    });
    // Kembali dari tombol (pesan galat) di bagian tertentu: bagian itu langsung terpilih.
    if (location.hash === '#jadi-cadangan') { pilihPeran('peranCadangan'); }
    if (location.hash === '#peranUtama') { pilihPeran('peranUtama'); }
    // Pesan dari tombol di dalam <details> yang tertutup (#sambung / #jadi-cadangan): buka supaya terlihat.
    var tuju = location.hash && document.querySelector(location.hash);
    if (tuju && tuju.closest('details')) { tuju.closest('details').open = true; tuju.scrollIntoView(); }
    // Tombol Salin (KODE / perintah konsol).
    document.querySelectorAll('[data-salin]').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.querySelector(b.dataset.salin);
            el.select();
            var selesai = function () { b.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersalin'; };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(el.value).then(selesai); }
            else { document.execCommand('copy'); selesai(); }
        });
    });
    // Catatan proses (pindah / kembalikan / mode sinkron / jadikan cadangan): diperbarui tiap 3 detik selama berjalan,
    // lalu ditutup dengan pemberitahuan BERHASIL atau GAGAL (dari kode keluar skrip + pemeriksaan sambungan).
    var kartu = document.getElementById('proses'), pre = document.getElementById('prosesLog'), spin = document.getElementById('prosesSpin');
    var kotakHasil = document.getElementById('prosesHasil'), alertHasil = kotakHasil.querySelector('.alert');
    var JUDUL = {
        cadangan:   ['Berhasil: server ini sudah menjadi server cadangan dan terhubung ke server utama (salinan database berjalan).',
                     'Gagal menjadikan server cadangan'],
        utama:      ['Berhasil: mode sinkron aktif. Lanjutkan: salin KODE lalu tempel di panel server cadangan.', 'Gagal mengaktifkan mode sinkron'],
        pindah:     ['Berhasil: server ini sekarang melayani ujian sebagai server utama.', 'Gagal memindahkan ujian ke server ini'],
        kembalikan: ['Berhasil: ujian dikembalikan ke server ini.', 'Gagal mengembalikan ujian ke server ini']
    };
    // Langkah yang dijalankan skrip = baris "==> …" di catatan (mis. pindah: 1/3 replikasi, 2/3 database, 3/3 Moodle).
    var daftarLangkah = function (log) {
        var ul = document.createElement('ul');
        ul.className = 'small mb-0 mt-2 ps-3';
        (log || '').split('\n').forEach(function (b) {
            var m = b.match(/^==>\s*(.+)$/);
            if (!m) { return; }
            var li = document.createElement('li');
            li.textContent = m[1].trim();
            ul.appendChild(li);
        });
        return ul.children.length ? ul : null;
    };
    var tampilHasil = function (h, log) {
        var j = JUDUL[h.aksi] || ['Proses selesai dengan berhasil.', 'Proses gagal'];
        kotakHasil.classList.remove('d-none');
        alertHasil.className = 'alert mb-0 ' + (h.ok ? 'alert-success' : 'alert-danger');
        alertHasil.innerHTML = '';
        var ikon = document.createElement('i');
        ikon.className = 'bi me-1 ' + (h.ok ? 'bi-check-circle-fill' : 'bi-x-octagon-fill');
        var teks = document.createElement('strong');
        teks.textContent = h.ok ? j[0] : j[1] + '.';
        alertHasil.append(ikon, teks);
        // Berhasil: kartu dilipat, ringkasannya di baris lipatan. Gagal: tetap terbuka supaya alasannya terbaca.
        // Pindah / kembalikan (peristiwa penting saat ujian) tetap terbuka walau berhasil, beserta langkah-langkahnya.
        var penting = h.aksi === 'pindah' || h.aksi === 'kembalikan';
        var ringkas = document.getElementById('prosesRingkas');
        ringkas.textContent = h.ok ? 'Proses server cadangan terakhir: berhasil (klik untuk melihat catatan)' : 'Proses server cadangan';
        ringkas.className = 'small ' + (h.ok ? 'text-success' : 'text-muted');
        kartu.open = !h.ok || penting;
        if (!h.ok) {
            var rinci = document.createElement('div');
            rinci.className = 'small mt-1';
            rinci.textContent = (h.pesan || 'Lihat catatan di bawah.') + (h.kode ? ' (kode ' + h.kode + ')' : '');
            alertHasil.appendChild(rinci);
        }
        var langkah = daftarLangkah(log);
        if (langkah) {
            var jl = document.createElement('div');
            jl.className = 'small mt-2 fw-semibold';
            jl.textContent = h.ok ? 'Yang dilakukan:' : 'Langkah yang sempat dijalankan:';
            alertHasil.append(jl, langkah);
        }
    };
    var sesiPutus = function () {
        // Jadikan cadangan mengganti database panel dengan salinan server utama, jadi sesi login di server ini berakhir.
        kartu.classList.remove('d-none');
        kartu.open = true;
        kotakHasil.classList.remove('d-none');
        spin.classList.add('d-none');
        alertHasil.className = 'alert alert-warning mb-0';
        alertHasil.innerHTML = '<i class="bi bi-box-arrow-in-right me-1"></i><strong>Sesi panel berakhir</strong>'
            + '<div class="small mt-1">Biasanya karena database server ini sedang diganti salinan server utama. Tunggu 1–2 menit, lalu '
            + '<a href="<?= site_url('login') ?>">login lagi</a> memakai akun admin <strong>server utama</strong> untuk melihat hasilnya.</div>';
    };
    var gagalBeruntun = 0;
    var ambil = function () {
        fetch('<?= site_url('server_cadangan/log') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) {
                var json = (r.headers.get('content-type') || '').indexOf('json') !== -1;
                if (r.status === 401 || r.redirected || !json) { return { putus: true }; }
                return r.ok ? r.json() : null;
            })
            .then(function (d) {
                if (d && d.putus) { sesiPutus(); return; }
                if (!d || !d.ok) { throw new Error('jawaban tidak terbaca'); }
                gagalBeruntun = 0;
                if (d.log) { kartu.classList.remove('d-none'); pre.textContent = d.log; pre.scrollTop = pre.scrollHeight; }
                spin.classList.toggle('d-none', !d.berjalan);
                if (d.berjalan) { kartu.open = true; setTimeout(ambil, 3000); return; }
                if (d.hasil) { kartu.classList.remove('d-none'); tampilHasil(d.hasil, d.log); }
            })
            .catch(function () {
                // Server sibuk (database diganti / layanan dimulai ulang): coba lagi, tapi jangan menggantung tanpa kabar.
                gagalBeruntun++;
                if (gagalBeruntun >= 20) { sesiPutus(); return; }
                setTimeout(ambil, 5000);
            });
    };
<?php $hasilBaru = ! empty($s['hasil']['waktu']) && time() - (int) $s['hasil']['waktu'] < 3600; ?>
    if (location.hash === '#proses' || <?= ! empty($s['pindah_berjalan']) || $hasilBaru ? 'true' : 'false' ?>) { ambil(); }
})();
</script>
<?= $this->endSection() ?>
