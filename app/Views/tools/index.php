<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$v      = static fn (string $k, string $def = ''): string => (string) ($values[$k] ?? $def);
$on     = static fn (string $k): bool => ($values[$k] ?? '0') === '1';
$antiSubmit = (int) $v('anti_submit', '0');
/** Satu bagian halaman (selalu terbuka): [grup, id anchor, ikon, judul, isi]. */
$item = static function (string $grup, string $id, string $ikon, string $judul, string $isi): string {
    return '<section class="card mb-4" id="' . $id . '" style="scroll-margin-top:5rem">'
        . '<div class="card-header fw-semibold"><i class="bi bi-' . $ikon . ' me-2 text-primary"></i>' . esc($judul) . '</div>'
        . '<div class="card-body">' . $isi . '</div></section>';
};
$csrf = csrf_field();
$input = static fn (string $name, string $label, string $value, string $ph = '', string $col = 'col-md-6', string $type = 'text'): string =>
    '<div class="' . $col . '"><label class="form-label" for="f_' . $name . '">' . esc($label) . '</label>'
    . '<input class="form-control" type="' . $type . '" id="f_' . $name . '" name="' . $name . '" maxlength="255" value="' . esc($value, 'attr') . '" placeholder="' . esc($ph, 'attr') . '"></div>';
?>

<?php if ($grup === 'moodle'): ?>
    <div id="moodleTools">
        <?= $item('moodleTools', 'cron', 'gear', 'Cron Task',
            '<p class="small text-muted">Menjalankan cron task Moodle sekarang (mis. menutup attempt yang lewat waktu, mengirim pekerjaan tertunda). Cron juga berjalan otomatis tiap menit.</p>'
            . '<button class="btn btn-info btn-sm" type="button" data-post="' . site_url('tools/cron') . '" data-confirm-text="Jalankan cron task Moodle sekarang? Bisa memakan waktu hingga beberapa menit."><i class="bi bi-rocket-takeoff me-1"></i>Cron</button>') ?>
        <?= $item('moodleTools', 'cache', 'layers', 'Bersihkan / Hapus Cache',
            '<p class="small text-muted">Membersihkan berkas cache Moodle di server. <strong>Bersihkan</strong> = cara biasa (Purge Moodle); <strong>Hapus</strong> = hapus seluruh folder cache lalu dibangun ulang (dipakai bila tampilan Moodle bermasalah). Halaman pertama sesudahnya akan lebih lambat.</p>'
            . '<button class="btn btn-warning btn-sm me-1" type="button" data-post="' . site_url('tools/cache') . '" data-fields=\'{"jenis":"purge"}\' data-confirm-text="Bersihkan cache Moodle sekarang?"><i class="bi bi-arrow-repeat me-1"></i>Bersihkan</button>'
            . '<button class="btn btn-danger btn-sm" type="button" data-post="' . site_url('tools/cache') . '" data-fields=\'{"jenis":"delete"}\' data-confirm-text="Hapus semua cache Moodle? Jangan dilakukan saat ujian berlangsung."><i class="bi bi-trash3 me-1"></i>Hapus</button>') ?>
        <?= $item('moodleTools', 'antisubmit', 'hourglass-split', 'Anti Submit',
            '<p class="small text-muted">Mencegah peserta menyelesaikan ujian sebelum waktunya. Contoh: anti submit 10 menit dan durasi ujian 120 menit, peserta baru bisa submit setelah mengerjakan 110 menit. Isi <strong>0</strong> untuk menonaktifkan.</p>'
            . '<form method="post" action="' . site_url('tools/ujian') . '" class="d-flex flex-wrap align-items-center gap-2">' . $csrf
            . '<input type="hidden" name="bagian" value="antisubmit">'
            . '<span class="small">Peserta bisa submit</span><input class="form-control form-control-sm" style="width:6rem" type="number" min="0" max="1000" name="anti_submit" value="' . $antiSubmit . '" aria-label="Menit anti submit">'
            . '<span class="small">menit sebelum waktu ujian selesai.</span>'
            . '<button class="btn btn-success btn-sm" type="submit"><i class="bi bi-save me-1"></i>Simpan</button>'
            . ($antiSubmit > 0 && $antiSubmit < 1000 ? '<button class="btn btn-outline-danger btn-sm" type="submit" name="nonaktif" value="1"><i class="bi bi-x-circle me-1"></i>Nonaktifkan</button>' : '')
            . '</form>'
            . '<p class="small mt-2 mb-0">Status: ' . ($antiSubmit > 0 && $antiSubmit < 1000 ? '<span class="badge text-bg-success">aktif, ' . $antiSubmit . ' menit</span>' : '<span class="badge text-bg-secondary">nonaktif</span>') . '</p>') ?>
        <?= $item('moodleTools', 'lockres', 'shield-lock', 'Lock login / Resume timer / Anti Newtab',
            '<form method="post" action="' . site_url('tools/ujian') . '">' . $csrf . '<input type="hidden" name="bagian" value="aturan">'
            . '<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="f_lock" name="lock_login" value="1"' . ($on('lock_login') ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="f_lock"><strong>Lock login</strong> — peserta harus meminta reset ke pengawas bila masih berstatus login di perangkat lain. Bila mati, login di perangkat baru otomatis mengeluarkan perangkat lama.</label></div>'
            . '<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="f_resume" name="resume_timer" value="1"' . ($on('resume_timer') ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="f_resume"><strong>Resume timer</strong> — peserta yang keluar (komputer mati, pindah komputer) dapat melanjutkan ujian dengan sisa waktunya; waktu selama keluar ditambahkan saat ia login lagi, selama waktu ujiannya belum habis.</label></div>'
            . '<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="f_newtab" name="anti_newtab" value="1"' . ($on('anti_newtab') ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="f_newtab"><strong>Anti Newtab</strong> — peserta otomatis keluar bila membuka tab baru atau aplikasi lain saat mengerjakan quiz. Jawaban yang sudah tersimpan tetap aman.</label></div>'
            . '<button class="btn btn-success btn-sm" type="submit"><i class="bi bi-save me-1"></i>Simpan</button></form>') ?>
    </div>
<?php endif ?>

<?php if ($grup === 'cbtadmin'): ?>
    <div id="cbtTools">
        <?php
        $logoPrev = $hasLogo ? '<img src="' . site_url('tools/gambar/logo') . '?t=' . time() . '" alt="Logo" class="border rounded p-1 bg-white me-2" style="height:48px">' : '';
        $ttdPrev  = $hasTtd ? '<img src="' . site_url('tools/gambar/ttd') . '?t=' . time() . '" alt="Tanda tangan" class="border rounded p-1 bg-white me-2" style="height:48px">' : '';
        $logo2Prev = $hasLogo2 ? '<img src="' . site_url('tools/gambar/logo2') . '?t=' . time() . '" alt="Logo kanan" class="border rounded p-1 bg-white me-2" style="height:48px">' : '';
        $loginPrev = $hasLogoLogin ? '<img src="' . site_url('tools/gambar/logologin') . '?t=' . time() . '" alt="Logo login" class="border rounded p-1 bg-white me-2" style="height:48px">' : '';
        $hapus    = static fn (string $j, bool $ada): string => $ada ? '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="h_' . $j . '" name="hapus_' . $j . '" value="1"><label class="form-check-label small" for="h_' . $j . '">Hapus</label></div>' : '';
        ?>
        <?= $item('cbtTools', 'sekolah', 'building', 'Info Sekolah',
            '<form method="post" action="' . site_url('tools') . '" enctype="multipart/form-data">' . $csrf
            . '<input type="hidden" name="bagian" value="sekolah"><div class="row g-3">'
            . $input('nama_sekolah', 'Nama sekolah', $v('nama_sekolah'), '', 'col-md-8')
            . $input('npsn', 'NPSN', $v('npsn'), '', 'col-md-4')
            . '<div class="col-12 small text-muted mb-n2">Profil pimpinan: jabatan, nama, dan nomor pegawai.</div>'
            . $input('jabatan_pimpinan', 'Jabatan', $v('jabatan_pimpinan', 'Kepala Sekolah'), 'Kepala Sekolah / Kepala Madrasah', 'col-md-6')
            . $input('kepala_sekolah', 'Nama pimpinan', $v('kepala_sekolah'), 'Nama Pimpinan', 'col-md-6')
            . $input('jenis_nomor_pimpinan', 'Jenis nomor', $v('jenis_nomor_pimpinan', 'NIP'), 'NIP / NIY / dsb', 'col-md-6')
            . $input('nip_kepala', 'Nomor', $v('nip_kepala'), '12341234 123123 1 123', 'col-md-6')
            . '<div class="col-md-6"><label class="form-label" for="f_logo">Logo sekolah</label><div class="d-flex align-items-center">' . $logoPrev
            . '<input class="form-control form-control-sm" type="file" id="f_logo" name="logo" accept="image/png,image/jpeg"></div>' . $hapus('logo', $hasLogo) . '</div>'
            . '<div class="col-md-6"><label class="form-label" for="f_logo2">Logo kanan kartu peserta</label><div class="d-flex align-items-center">' . $logo2Prev
            . '<input class="form-control form-control-sm" type="file" id="f_logo2" name="logo2" accept="image/png,image/jpeg"></div>' . $hapus('logo2', $hasLogo2) . '</div>'
            . '<div class="col-md-6"><label class="form-label" for="f_ttd">Tanda tangan pimpinan</label><div class="d-flex align-items-center">' . $ttdPrev
            . '<input class="form-control form-control-sm" type="file" id="f_ttd" name="ttd" accept="image/png,image/jpeg"></div>' . $hapus('ttd', $hasTtd) . '</div>'
            . '<div class="col-md-6"><label class="form-label" for="f_logologin">Logo halaman login ujian</label><div class="d-flex align-items-center">' . $loginPrev
            . '<input class="form-control form-control-sm" type="file" id="f_logologin" name="logologin" accept="image/png,image/jpeg"></div>' . $hapus('logologin', $hasLogoLogin)
            . '<div class="form-text">Tampil di halaman login Moodle (dipasang ke Site administration → Appearance → Logos).</div></div>'
            . '<div class="col-md-6"><label class="form-label" for="f_favicon">Favicon panel (ikon tab browser; Moodle tetap ikon Moodle)</label><div class="d-flex align-items-center">'
            . ($hasFavicon ? '<img src="' . site_url('tools/gambar/favicon') . '?t=' . time() . '" alt="Favicon" class="border rounded p-1 bg-white me-2" style="height:40px;width:40px;object-fit:contain">' : '')
            . '<input class="form-control form-control-sm" type="file" id="f_favicon" name="favicon" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico,image/jpeg"></div>' . $hapus('favicon', $hasFavicon)
            . '<div class="form-text">Ikon kecil di tab browser untuk panel CBT Admin dan Moodle. PNG persegi 64×64 atau 32×32 piksel, atau berkas .ico.</div></div>'
            . '<div class="col-12 small text-muted">Tips: logo sekolah dan logo kanan kartu (mis. logo pemda / dinas) sekitar 100×100 piksel, tanda tangan sekitar 125×45 piksel (PNG berlatar transparan lebih rapi). Maksimal 1 MB.</div>'
            . '</div><button class="btn btn-success btn-sm mt-3" type="submit"><i class="bi bi-save me-1"></i>Simpan</button></form>') ?>
        <?= $item('cbtTools', 'kegiatan', 'calendar-event', 'Info Kegiatan',
            '<p class="small text-muted">Nama kegiatan yang dilaksanakan (contoh: Penilaian Akhir Tahun, Penilaian Akhir Semester, Ujian Sekolah). Tampil di kartu peserta dan dokumen.</p>'
            . '<form method="post" action="' . site_url('tools') . '">' . $csrf . '<input type="hidden" name="bagian" value="kegiatan"><div class="row g-3">'
            . $input('nama_kegiatan', 'Nama kegiatan', $v('nama_kegiatan'), 'Nama Kegiatan', 'col-md-8')
            . $input('tahun_ajaran', 'Tahun pelajaran', $v('tahun_ajaran'), 'Cth: 2026/2027', 'col-md-4')
            . '<div class="col-12 small text-muted">Jam ujian per tingkatan (dicetak di kartu peserta) diatur di Admin Tools → <a href="' . site_url('sesi_tingkat') . '">Sesi</a>.</div>'
            . $input('catatan_kartu', 'Catatan di kartu peserta', $v('catatan_kartu', 'Kartu peserta wajib dibawa selama ujian berlangsung.'), 'Kartu peserta wajib dibawa selama ujian berlangsung.', 'col-12')
            . '</div><button class="btn btn-success btn-sm mt-3" type="submit"><i class="bi bi-save me-1"></i>Simpan</button></form>') ?>
        <?= $item('cbtTools', 'password', 'key', 'Ganti Password',
            '<p class="small text-muted">Mengganti password akun Anda. Password pengawas diganti lewat menu Daftar Ruang (Generate Kode).</p>'
            . '<form method="post" action="' . site_url('tools/password') . '" class="d-flex flex-wrap gap-2" autocomplete="off">' . $csrf
            . '<input class="form-control form-control-sm" style="max-width:16rem" type="password" name="password" minlength="8" required placeholder="Password baru (min. 8 karakter)" autocomplete="new-password" aria-label="Password baru">'
            . '<button class="btn btn-success btn-sm" type="submit"><i class="bi bi-save me-1"></i>Simpan</button></form>'
            . '<p class="small text-danger mt-2 mb-0">Peringatan! Catat password baru untuk menghindari lupa password.</p>') ?>
    </div>
<?php endif ?>

<?php if ($grup === 'lainnya'): ?>
    <div id="lainTools">
        <?php ob_start(); ?>
        <div class="d-flex mb-2">
            <span class="small text-muted">Akun administrator dan pengawas panel. Akun pengawas ruang dibuat otomatis di Daftar Ruang.</span>
            <button class="btn btn-primary btn-sm ms-auto" type="button" data-fill-modal="#akunModal"
                    data-fill='{"id":"","username":"","nama":"","role":"admin","aktif":"1","ruang_id":"","password":""}'><i class="bi bi-person-plus me-1"></i>Tambah akun</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Status</th><th>Login terakhir</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($admins as $a): ?>
                    <tr>
                        <td class="text-nowrap"><code><?= esc($a['username']) ?></code><?= (int) $a['id'] === $meId ? ' <span class="badge text-bg-light">Anda</span>' : '' ?></td>
                        <td><?= esc($a['nama']) ?></td>
                        <td><?= esc(\App\Filters\Guard::namaPeran((string) $a['role'])) ?>
                            <?php if ($a['role'] === 'demo'): ?>
                                <?php $rd = array_values(array_filter($ruangDemo, static fn ($r) => (int) $r['id'] === (int) $a['ruang_id']))[0] ?? null; ?>
                                <span class="small text-muted">· data Ruang <?= esc($rd['nama'] ?? '?') ?> · banyak perangkat</span>
                            <?php endif ?></td>
                        <td><?= $a['aktif'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>' ?></td>
                        <td class="text-nowrap"><?= $a['last_login'] ? esc(date('d/m/Y H:i', strtotime($a['last_login']))) : '–' ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-outline-secondary btn-sm" type="button" title="Ubah" data-fill-modal="#akunModal"
                                    data-fill="<?= esc(json_encode(['id' => $a['id'], 'username' => $a['username'], 'nama' => $a['nama'], 'role' => $a['role'], 'aktif' => (string) $a['aktif'], 'ruang_id' => (string) ($a['ruang_id'] ?? ''), 'password' => '']), 'attr') ?>"><i class="bi bi-pencil"></i></button>
                            <?php if ((int) $a['id'] !== $meId): ?>
                                <button class="btn btn-outline-danger btn-sm" type="button" title="Hapus" data-post="<?= site_url('tools/akun/hapus/' . $a['id']) ?>"
                                        data-confirm-text="Hapus akun <?= esc($a['username'], 'attr') ?>? Akun ini tidak bisa login lagi."><i class="bi bi-trash"></i></button>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php $isiAkun = (string) ob_get_clean(); $hanyaLog = $adminRole === 'operator'; // Proktor: hanya Log aktivitas ?>
        <?= $hanyaLog ? '' : $item('lainTools', 'akun', 'people', 'Akun panel', $isiAkun) ?>

        <?= $hanyaLog ? '' : $item('lainTools', 'panel', 'sliders', 'Pengaturan panel',
            '<form method="post" action="' . site_url('tools') . '">' . $csrf . '<input type="hidden" name="bagian" value="panel"><div class="row g-3">'
            . $input('refresh_interval', 'Interval refresh Status Peserta (detik)', $v('refresh_interval', '30'), '30', 'col-md-4', 'number')
            . $input('active_minutes', 'User dianggap aktif dalam (menit)', $v('active_minutes', '5'), '5', 'col-md-4', 'number')
            . $input('webmin_url', 'URL Webmin', $v('webmin_url'), '', 'col-md-4')
            . '</div><button class="btn btn-success btn-sm mt-3" type="submit"><i class="bi bi-save me-1"></i>Simpan</button></form>') ?>


        <?php ob_start(); ?>
        <?php
        // Tab log: Panel (semua), Pengawas (login + jam absen + tindakan), Siswa (jam mulai), Login siswa, Belum mulai.
        $tabLog = static fn (string $id, string $label) => '<li class="nav-item" role="presentation"><button class="nav-link' . ($logTab === $id ? ' active' : '') . '" data-bs-toggle="tab" data-bs-target="#log_' . $id . '" type="button" role="tab">' . $label . '</button></li>';
        // Baris filter tanggal (+ batas telat untuk tab Siswa) dan tombol Excel, sama di tiap tab.
        $filterLog = static function (string $tab, string $info, array $excel, string $ekstra = '') use ($logTgl): string {
            $tombol = '';
            foreach ($excel as $jenis => $label) {
                $tombol .= '<a class="btn btn-outline-success btn-sm" href="' . site_url('tools/log_export') . '?' . http_build_query(['jenis' => $jenis, 'log_tgl' => $logTgl]) . '"><i class="bi bi-file-earmark-excel me-1"></i>' . $label . '</a>';
            }

            return '<form method="get" action="' . site_url('tools/lainnya') . '#log" class="d-flex flex-wrap align-items-center gap-2 mb-2">'
                . '<input type="hidden" name="log_tab" value="' . $tab . '">'
                . '<label class="small text-muted" for="logTgl_' . $tab . '">Tanggal</label>'
                . '<input class="form-control form-control-sm w-auto" type="date" id="logTgl_' . $tab . '" name="log_tgl" value="' . esc($logTgl, 'attr') . '" onchange="this.form.submit()">'
                . $ekstra . '<span class="small text-muted ms-auto">' . $info . '</span>' . $tombol . '</form>';
        };
        $jejak = static fn (string $username, string $teks) => '<a href="' . site_url('tools/jejak') . '?' . http_build_query(['u' => $username, 'log_tgl' => $logTgl]) . '" target="_blank" title="Lihat jejak siswa ini">' . $teks . '</a>';
        ?>
        <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
            <?= $tabLog('panel', 'Panel')
                . $tabLog('pengawas', '<i class="bi bi-person-badge me-1"></i>Pengawas')
                . $tabLog('siswa', '<i class="bi bi-play-circle me-1"></i>Mulai ujian')
                . $tabLog('login', '<i class="bi bi-box-arrow-in-right me-1"></i>Login siswa')
                . $tabLog('belum', '<i class="bi bi-hourglass me-1"></i>Belum mulai' . ($logBelum !== [] ? ' <span class="badge text-bg-warning">' . count($logBelum) . '</span>' : '')) ?>
        </ul>
        <div class="tab-content">
        <div class="tab-pane fade<?= $logTab === 'pengawas' ? ' show active' : '' ?>" id="log_pengawas" role="tabpanel">
            <?= $filterLog('pengawas', count(array_filter($logPengawas, static fn ($r) => $r['pertama'] !== null)) . ' dari ' . count($logPengawas) . ' ruang sudah mengisi absensi', ['pengawas' => 'Excel']) ?>
            <table class="table table-sm align-middle w-100 datatable" data-order-col="3">
                <thead><tr><th>Ruang</th><th>Pengawas</th><th>Login panel</th><th>Jam absensi</th><th>Terakhir diubah</th><th>Hasil</th></tr></thead>
                <tbody>
                <?php foreach ($logPengawas as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= esc($r['ruang']) ?></td>
                        <td class="small"><?= esc($r['pengawas'] !== '' ? $r['pengawas'] : '–') ?><?php if ($r['oleh'] !== '' || $r['akun'] !== ''): ?><br><code><?= esc($r['oleh'] !== '' ? $r['oleh'] : $r['akun']) ?></code><?php endif ?></td>
                        <td data-order="<?= $r['login'] ? esc(substr($r['login']['jam'], 11), 'attr') : '99' ?>" class="text-nowrap small">
                            <?= $r['login'] ? esc(substr($r['login']['jam'], 11)) . '<br><span class="text-muted">' . esc($r['login']['ip']) . ($r['login']['kali'] > 1 ? ' · ' . $r['login']['kali'] . '×' : '') . '</span>' : '<span class="text-muted">Belum login</span>' ?></td>
                        <td data-order="<?= $r['pertama'] ? esc(substr($r['pertama'], 11), 'attr') : '99' ?>" class="text-nowrap">
                            <?= $r['pertama'] ? '<strong>' . esc(substr($r['pertama'], 11)) . '</strong>' : '<span class="badge text-bg-warning">Belum mengisi absensi</span>' ?></td>
                        <td class="text-nowrap small"><?= $r['kali'] > 1 ? esc(substr((string) $r['terakhir'], 11)) . ' <span class="text-muted">(' . $r['kali'] . '×)</span>' : '–' ?></td>
                        <td class="small"><?= esc($r['detail']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            <div class="d-flex align-items-center mt-4 mb-2">
                <span class="fw-semibold">Tindakan pengawas</span>
                <span class="small text-muted ms-2">blokir, buka blokir, reset login, paksa submit, tambah waktu, cetak, dll.</span>
                <a class="btn btn-outline-success btn-sm ms-auto" href="<?= site_url('tools/log_export') . '?' . http_build_query(['jenis' => 'tindakan', 'log_tgl' => $logTgl]) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            </div>
            <table class="table table-sm align-middle w-100 datatable" data-order-col="0" data-order-dir="desc">
                <thead><tr><th>Waktu</th><th>Pengawas</th><th>Aksi</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($logTindakan as $t): ?>
                    <tr>
                        <td class="text-nowrap"><?= esc(substr($t['waktu'], 11)) ?></td>
                        <td class="small"><?= esc($t['nama']) ?><br><span class="text-muted">Ruang <?= esc($t['ruang'] !== '' ? $t['ruang'] : '–') ?> · <code><?= esc($t['akun']) ?></code></span></td>
                        <td><code><?= esc($t['aksi']) ?></code></td>
                        <td class="small"><?= esc($t['detail']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div class="tab-pane fade<?= $logTab === 'siswa' ? ' show active' : '' ?>" id="log_siswa" role="tabpanel">
            <?php $jumlahTelat = count(array_filter($logSiswa, static fn ($s) => $s['telat'] !== null && $s['telat'] > $logTelat)); ?>
            <?= $filterLog('siswa', count($logSiswa) . ' kali mulai' . ($jumlahTelat ? ' · <span class="text-warning-emphasis">' . $jumlahTelat . ' telat</span>' : ''), ['siswa' => 'Excel'],
                '<label class="small text-muted ms-2" for="logTelat">Telat bila lewat</label><input class="form-control form-control-sm" style="width:4.5rem" type="number" min="0" max="240" id="logTelat" name="telat" value="' . $logTelat . '" onchange="this.form.submit()"><span class="small text-muted">menit</span>') ?>
            <p class="small text-muted mb-2">Jam mulai = saat siswa menekan "Mulai" dan soal nomor 1 tampil. Telat dihitung dari jam ujian tingkatannya di Info Kegiatan. Klik nama untuk melihat jejaknya.</p>
            <table class="table table-sm align-middle w-100 datatable" data-order-col="0" data-order-dir="desc">
                <thead><tr><th>Jam mulai</th><th>Siswa</th><th>Ruang</th><th>Mapel</th><th>Selesai</th></tr></thead>
                <tbody>
                <?php foreach ($logSiswa as $s): ?>
                    <?php $telat = $s['telat'] !== null && $s['telat'] > $logTelat; ?>
                    <tr<?= $telat ? ' class="table-warning"' : '' ?>>
                        <td data-order="<?= $s['mulai'] ?>" class="text-nowrap"><strong><?= date('H:i:s', $s['mulai']) ?></strong>
                            <?= $telat ? '<br><span class="badge text-bg-warning">Telat ' . $s['telat'] . ' mnt</span>' : '' ?></td>
                        <td class="small"><?= $jejak($s['username'], esc($s['nama'])) ?><br><span class="text-muted"><?= esc($s['kelas']) ?> · <code><?= esc($s['username']) ?></code></span></td>
                        <td class="text-nowrap"><?= esc($s['ruang'] !== '' ? $s['ruang'] : '–') ?></td>
                        <td class="small" title="<?= esc($s['ujian'], 'attr') ?>"><?= esc($s['mapel']) ?></td>
                        <td class="text-nowrap small"><?= $s['selesai'] ? date('H:i:s', $s['selesai']) : ($s['state'] === 'inprogress' ? '<span class="badge text-bg-info">Mengerjakan</span>' : '–') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div class="tab-pane fade<?= $logTab === 'login' ? ' show active' : '' ?>" id="log_login" role="tabpanel">
            <?php $pindah = count(array_filter($logLogin, static fn ($s) => count($s['ips']) > 1)); ?>
            <?= $filterLog('login', count($logLogin) . ' siswa login' . ($pindah ? ' · <span class="text-warning-emphasis">' . $pindah . ' dari &gt;1 IP</span>' : ''), ['login' => 'Excel']) ?>
            <p class="small text-muted mb-2">Login siswa di Moodle. Baris kuning = login dari lebih dari satu IP (pindah komputer atau akun dipakai orang lain).</p>
            <table class="table table-sm align-middle w-100 datatable" data-order-col="2">
                <thead><tr><th>Siswa</th><th>Ruang</th><th>Login pertama</th><th>Terakhir</th><th>IP</th><th>Logout</th></tr></thead>
                <tbody>
                <?php foreach ($logLogin as $s): ?>
                    <tr<?= count($s['ips']) > 1 ? ' class="table-warning"' : '' ?>>
                        <td class="small"><?= $jejak($s['username'], esc($s['nama'])) ?><br><span class="text-muted"><?= esc($s['kelas']) ?> · <code><?= esc($s['username']) ?></code></span></td>
                        <td class="text-nowrap"><?= esc($s['ruang'] !== '' ? $s['ruang'] : '–') ?></td>
                        <td data-order="<?= $s['pertama'] ?>" class="text-nowrap"><strong><?= date('H:i:s', $s['pertama']) ?></strong></td>
                        <td class="text-nowrap small"><?= $s['kali'] > 1 ? date('H:i:s', $s['terakhir']) . ' <span class="text-muted">(' . $s['kali'] . '×)</span>' : '–' ?></td>
                        <td class="small"><?= esc(implode(', ', $s['ips'])) ?></td>
                        <td class="text-nowrap small"><?= $s['logout'] ? date('H:i:s', $s['logout']) : '–' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div class="tab-pane fade<?= $logTab === 'belum' ? ' show active' : '' ?>" id="log_belum" role="tabpanel">
            <?= $filterLog('belum', count($logBelum) . ' siswa hadir belum mulai', ['belum' => 'Excel']) ?>
            <p class="small text-muted mb-2">Siswa yang ditandai <strong>hadir</strong> oleh pengawas pada tanggal ini tetapi belum memulai ujian apa pun hari itu.</p>
            <table class="table table-sm align-middle w-100 datatable" data-order-col="1">
                <thead><tr><th>Siswa</th><th>Ruang</th><th>Diabsen</th><th>Login</th></tr></thead>
                <tbody>
                <?php foreach ($logBelum as $s): ?>
                    <tr>
                        <td class="small"><?= $jejak($s['username'], esc($s['nama'])) ?><br><span class="text-muted"><?= esc($s['kelas']) ?> · <code><?= esc($s['username']) ?></code></span></td>
                        <td class="text-nowrap"><?= esc($s['ruang'] !== '' ? $s['ruang'] : '–') ?></td>
                        <td class="small text-nowrap"><?= esc($s['jam_absen']) ?> <span class="text-muted">oleh <?= esc($s['dicatat_oleh']) ?></span></td>
                        <td><?= $s['login'] ? '<span class="badge text-bg-info">Sudah login</span>' : '<span class="badge text-bg-secondary">Belum login</span>' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div class="tab-pane fade<?= $logTab === 'panel' ? ' show active' : '' ?>" id="log_panel" role="tabpanel">
        <table class="table table-sm align-middle w-100 datatable" data-order-col="0">
            <thead><tr><th>Waktu</th><th>Akun</th><th>Aksi</th><th>Detail</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td data-order="<?= (int) $l['id'] ?>" class="text-nowrap"><?= esc(date('d/m H:i:s', strtotime((string) $l['created_at']))) ?></td>
                    <td><?= esc($l['username']) ?></td>
                    <td><code><?= esc($l['aksi']) ?></code></td>
                    <td class="small"><?= esc((string) $l['detail']) ?></td>
                    <td class="small"><?= esc($l['ip']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        </div>
        <?= $item('lainTools', 'log', 'journal-text', 'Log aktivitas', (string) ob_get_clean()) ?>
    </div>
<?php endif ?>

<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>

<div class="modal fade" id="akunModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('tools/akun/simpan') ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="id">
            <div class="modal-header"><h5 class="modal-title">Akun panel</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-6"><label class="form-label">Username</label><input class="form-control" name="username" maxlength="60" pattern="[A-Za-z0-9._\-]{3,60}" required></div>
                <div class="col-sm-6"><label class="form-label">Nama</label><input class="form-control" name="nama" maxlength="120" required></div>
                <div class="col-sm-6"><label class="form-label">Role</label>
                    <select class="form-select" name="role" id="akunRole"><option value="admin">Administrator</option><option value="operator">Proktor</option><option value="proktor">Pengawas</option><option value="demo">Demo (pengawas contoh)</option><option value="kurikulum">Kurikulum (hanya Hasil Ujian)</option></select>
                </div>
                <div class="col-sm-6"><label class="form-label">Status</label>
                    <select class="form-select" name="aktif"><option value="1">Aktif</option><option value="0">Nonaktif</option></select>
                </div>
                <div class="col-12 d-none" id="akunRuangDemo"><label class="form-label" for="akunRuang">Pakai data ruang</label>
                    <select class="form-select" name="ruang_id" id="akunRuang">
                        <option value="">– pilih ruang –</option>
                        <?php foreach ($ruangDemo as $r): ?>
                            <option value="<?= (int) $r['id'] ?>">Ruang <?= esc($r['nama']) ?><?= trim((string) $r['lokasi']) !== '' ? ' · ' . esc($r['lokasi']) : '' ?></option>
                        <?php endforeach ?>
                    </select>
                    <div class="form-text">Akun demo melihat siswa ruang ini seperti pengawasnya, dan boleh login di banyak perangkat sekaligus.
                        Tindakan (absensi tidak hadir, blokir) berlaku ke siswa sungguhan.</div>
                </div>
                <div class="col-12"><label class="form-label">Password</label><input class="form-control" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="minimal 8 karakter; kosongkan bila tidak diganti"></div>
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
    // Gulir ke bagian sesuai #anchor (mis. setelah menyimpan). Anchor dari alamat lama /tools#bagian yang
    // ada di halaman grup lain dialihkan ke halaman grup itu.
    var grup = <?= json_encode(array_map(static fn ($g) => $g[1], \App\Controllers\Tools::GRUP)) ?>;
    var buka = function () {
        var id = location.hash.replace('#', '');
        if (!id) { return; }
        var el = document.getElementById(id);
        if (el) {
            el.scrollIntoView({ block: 'start', behavior: 'smooth' });
            return;
        }
        Object.keys(grup).forEach(function (g) {
            if (grup[g].indexOf(id) !== -1) { location.replace('<?= site_url('tools') ?>/' + g + '#' + id); }
        });
    };
    window.addEventListener('hashchange', buka);
    buka();
})();
// Form akun: pilihan ruang hanya untuk role Demo (wajib diisi).
(function () {
    var role = document.getElementById('akunRole'), kotak = document.getElementById('akunRuangDemo'), ruang = document.getElementById('akunRuang');
    if (!role || !kotak) { return; }
    function atur() {
        var demo = role.value === 'demo';
        kotak.classList.toggle('d-none', !demo);
        ruang.required = demo;
    }
    role.addEventListener('change', atur);
    var modal = document.getElementById('akunModal');
    if (modal) { modal.addEventListener('shown.bs.modal', atur); modal.addEventListener('show.bs.modal', function () { setTimeout(atur, 0); }); }
    atur();
})();
</script>
<?= $this->endSection() ?>
