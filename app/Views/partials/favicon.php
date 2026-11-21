<?php
// Favicon dari Tools → Info Sekolah (bila diunggah); ?v= berubah setiap berkas diganti supaya browser memuat ulang.
$faviconPath = \App\Libraries\School::faviconPath();
if ($faviconPath !== null): ?>
    <link rel="icon" href="<?= site_url('tools/gambar/favicon') ?>?v=<?= (int) @filemtime($faviconPath) ?>">
<?php endif ?>
