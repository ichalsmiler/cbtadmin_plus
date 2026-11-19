<?php
/** Menu sidebar: [label, ikon, path] atau [label, ikon, null, [[label, path], ...]]. */
$menu = [
    ['Dashboard', 'house', ''],
    ['Daftar Peserta', 'person', null, [['Data CBTAdmin', 'daftar_peserta/cbtadmin'], ['Data Moodle', 'daftar_peserta/moodle']]],
    ['Status Ujian', 'check-square', null, [['Setting Ujian', 'status_tes'], ['Token', 'token_ujian'], ['Hapus Attempt', 'hapus_attempt']]],
    ['Status Peserta', 'people', 'status_peserta'],
    ['Laporan Kehadiran', 'clipboard-check', 'kehadiran'],
    ['Blokir Siswa', 'slash-circle', 'blokir_siswa'],
    ['Daftar Ruang', 'door-open', 'daftar_ruang'],
    // Persiapan ujian di Moodle: konversi data user/jadwal dan pembuatan kategori (tes) + course (mapel).
    ['Admin Tools', 'mortarboard', null, [['Kategori Mapel', 'tools/test_manage'], ['Masukkan Soal', 'soal'], ['Jadwal', 'jadwal_mapel'], ['Konvert User', 'konvert'], ['Sesi', 'sesi_tingkat']]],
    ['Dokumen', 'file-text', null, [['Kartu / Daftar Hadir', 'dokumen/cetak_doc'], ['Laporan Kehadiran', 'dokumen/laporan_kehadiran']]],
    ['Hasil Ujian', 'award', 'hasil_ujian'],
    ['Tools', 'gear', null, [['Moodle Tools', 'tools/moodle'], ['Info Sekolah', 'tools/cbtadmin'], ['Panel', 'tools/lainnya']]],
    ['Keluar', 'box-arrow-right', 'logout'],
];
// Pengawas: Daftar Peserta = siswa di ruangnya (baca saja).
if ($adminRole !== 'admin') {
    $menu[1] = ['Daftar Peserta', 'person', 'peserta_ruang'];
}
// Lock login aktif (Tools): peserta yang masih tercatat login perlu di-reset pengawas.
if (\App\Libraries\Settings::get('lock_login', '0') === '1') {
    array_splice($menu, 5, 0, [['Reset Login Peserta', 'person-x', 'reset_login']]);
}
// Kategori Mapel (tools/test_manage) punya menunya sendiri, jadi menu Tools tidak ikut disorot di sana.
$isActive = static fn (string $path): bool => $path === '' ? $current === ''
    : ($current === $path || (str_starts_with($current, $path . '/') && ! ($path === 'tools' && str_starts_with($current, 'tools/test_manage'))));
$asset    = static fn (string $file): string => base_url('assets/' . $file);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> · CBT Admin</title>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= $asset('vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= $asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= $asset('vendor/dataTables.bootstrap5.min.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/panel.css') ?>?v=<?= esc($version) ?>">
</head>
<body>
<header class="topbar">
    <button class="btn btn-link btn-menu d-lg-none p-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Buka menu">
        <i class="bi bi-list fs-3"></i>
    </button>
    <?php // Logo sekolah yang diunggah di Info Sekolah (bila ada); tanpa logo cukup nama panel. ?>
    <a class="brand" href="<?= site_url('/') ?>">
        <?php if (\App\Libraries\School::logoPath() !== null): ?><img src="<?= site_url('tools/gambar/logo') ?>" alt=""><?php endif ?>
        <span class="brand-text">
            <span class="brand-name">CBT Admin</span>
            <?php if ($namaSekolah !== ''): ?><span class="brand-school d-none d-sm-block"><?= esc($namaSekolah) ?></span><?php endif ?>
        </span>
    </a>
    <?php if (session()->get('tukar_dari')): ?>
        <form class="ms-auto me-2" method="post" action="<?= site_url('daftar_ruang/kembali') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-warning btn-sm" type="submit" title="Kembali ke akun administrator">
                <i class="bi bi-arrow-left-right"></i><span class="d-none d-sm-inline ms-1">Kembali ke Admin</span>
            </button>
        </form>
    <?php endif ?>
    <span class="role d-none d-md-inline <?= session()->get('tukar_dari') ? '' : 'ms-auto' ?>"><?= $adminRole === 'admin' ? 'Administrator' : 'Pengawas' ?> · <strong><?= esc($adminNama) ?></strong></span>
    <button class="btn-tema <?= session()->get('tukar_dari') ? '' : 'ms-auto ms-md-0' ?>" type="button" data-tema-toggle aria-label="Ganti ke mode gelap" title="Ganti mode terang/gelap">
        <i class="bi bi-moon-stars"></i>
    </button>
</header>

<nav class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar">
    <ul class="nav flex-column">
        <?php foreach ($menu as $i => $item): ?>
            <?php
            // Administrator: semua menu kecuali Token (ada di Setting Ujian) dan Laporan Kehadiran
            // (rekapnya di Dokumen -> Absensi Ruang). Pengawas: hanya menu yang boleh dibukanya.
            if ($adminRole === 'admin' ? in_array($item[2], ['token', 'kehadiran'], true) : ($item[2] === null || ! \App\Filters\Guard::allowed($adminRole, $item[2]))) {
                continue;
            }
            ?>
            <?php if (isset($item[3])): ?>
                <?php $open = (bool) array_filter($item[3], static fn ($sub) => $isActive($sub[1])); ?>
                <li class="nav-item">
                    <a class="nav-link has-sub <?= $open ? '' : 'collapsed' ?>" data-bs-toggle="collapse" href="#sub<?= $i ?>" role="button" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                        <i class="bi bi-<?= $item[1] ?>"></i><span><?= esc($item[0]) ?></span><i class="bi bi-chevron-right caret"></i>
                    </a>
                    <ul class="nav flex-column submenu collapse <?= $open ? 'show' : '' ?>" id="sub<?= $i ?>">
                        <?php foreach ($item[3] as $sub): ?>
                            <li><a class="nav-link <?= $isActive($sub[1]) ? 'active' : '' ?>" href="<?= site_url($sub[1]) ?>"><?= esc($sub[0]) ?></a></li>
                        <?php endforeach ?>
                    </ul>
                </li>
            <?php else: ?>
                <li class="nav-item<?= $item[2] === 'logout' ? ' nav-keluar' : '' ?>">
                    <a class="nav-link <?= $isActive($item[2]) ? 'active' : '' ?>" href="<?= site_url($item[2] === '' ? '/' : $item[2]) ?>"<?= $isActive($item[2]) ? ' aria-current="page"' : '' ?>>
                        <i class="bi bi-<?= $item[1] ?>"></i><span><?= esc($item[0]) ?></span>
                    </a>
                </li>
            <?php endif ?>
        <?php endforeach ?>
    </ul>
</nav>

<main class="content">
    <h1 class="page-title"><?= esc($title) ?></h1>

    <?php if (! empty($sesiBelumPengawas) && ! in_array($current, ['', 'dashboard', 'kehadiran'], true)): ?>
        <div class="alert alert-warning" role="status">Sesi belum diaktifkan oleh proktor. Daftar siswa muncul setelah sesi ujian diaktifkan.</div>
    <?php endif ?>
    <?php foreach (['success' => 'success', 'error' => 'danger'] as $key => $class): ?>
        <?php if (session()->getFlashdata($key)): ?>
            <div class="alert alert-<?= $class ?> alert-dismissible fade show" role="alert">
                <?= esc(session()->getFlashdata($key)) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
            </div>
        <?php endif ?>
    <?php endforeach ?>

    <?= $this->renderSection('content') ?>

    <footer class="footer">
        <span>&copy; <?= date('Y') ?> CBT Admin</span>
        <a href="#" data-bs-toggle="modal" data-bs-target="#aboutModal">About</a>
    </footer>
</main>

<div class="modal fade" id="aboutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">CBT Admin</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <p class="mb-2">Versi <strong><?= esc($version) ?></strong></p>
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnCekUpdate">Cek Update</button>
                <p class="small text-muted mt-2 mb-0 d-none" id="updateInfo">Pengecekan update otomatis belum tersedia di versi ini.</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Konfirmasi</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body" id="confirmText"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="confirmOk">Ya, lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pdfModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header gap-2">
                <h5 class="modal-title me-auto"><i class="bi bi-file-earmark-pdf me-1"></i>Pratinjau PDF</h5>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="pdfPrint"><i class="bi bi-printer me-1"></i>Cetak</button>
                <a class="btn btn-primary btn-sm" id="pdfDownload" href="#"><i class="bi bi-download me-1"></i>Unduh</a>
                <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-0 position-relative" style="height: 80vh">
                <div class="position-absolute top-50 start-50 translate-middle text-center text-muted" id="pdfLoading">
                    <div class="spinner-border mb-2" role="status"></div><div class="small">Menyiapkan PDF…</div>
                </div>
                <iframe id="pdfFrame" title="Pratinjau PDF" class="w-100 h-100 border-0 position-relative"></iframe>
            </div>
        </div>
    </div>
</div>

<script src="<?= $asset('vendor/jquery.min.js') ?>"></script>
<script src="<?= $asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= $asset('vendor/dataTables.min.js') ?>"></script>
<script src="<?= $asset('vendor/dataTables.bootstrap5.min.js') ?>"></script>
<script src="<?= $asset('js/panel.js') ?>?v=<?= esc($version) ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
