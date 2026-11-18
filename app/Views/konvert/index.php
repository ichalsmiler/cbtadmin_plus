<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php if (! $ada): ?>
    <div class="alert alert-warning">Aplikasi Konvert belum di-build. Di PC jalankan <code>bash deploy/build-konvert.sh</code>, lalu deploy ulang.</div>
<?php else: ?>
    <?php $src = site_url('konvert/app') . '?bagian=' . $bagian; ?>
    <p class="text-body-secondary small mb-2">
        <?php if ($bagian === 'jadwal'): ?>
            Unggah berkas jadwal, periksa pratinjau mapel, lalu buat mapelnya di Moodle (kategori H1, H2, … dari
            <a href="<?= site_url('tools/test_manage') ?>">Kategori Mapel</a>). Nama mapel di jadwal dikenali lewat tab
            Kamus Mapel. Berkas diproses di browser ini.
        <?php else: ?>
            Data peserta → template CBTAdmin, kelas moving, dan user Moodle. Semua berkas diproses di browser ini;
            hasilnya diimpor lewat <a href="<?= site_url('daftar_peserta/cbtadmin') ?>">Daftar Peserta</a>.
        <?php endif ?>
        <a href="<?= esc($src, 'attr') ?>" target="_blank" rel="noopener">Buka di tab baru</a>
    </p>
    <iframe class="konvert-frame" src="<?= esc($src, 'attr') ?>" title="<?= esc($title, 'attr') ?>"></iframe>
<?php endif ?>
<?= $this->endSection() ?>
