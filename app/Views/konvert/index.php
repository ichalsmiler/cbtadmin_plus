<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php if (! $ada): ?>
    <div class="alert alert-warning">Aplikasi Konvert belum di-build. Di PC jalankan <code>bash deploy/build-konvert.sh</code>, lalu deploy ulang.</div>
<?php else: ?>
    <p class="text-body-secondary small mb-2">
        Jadwal → mapel, data peserta → template CBTAdmin, kelas moving, dan user Moodle. Semua berkas diproses di browser ini;
        hasilnya diimpor lewat <a href="<?= site_url('daftar_peserta/cbtadmin') ?>">Daftar Peserta</a>.
        <a href="<?= site_url('konvert/app') ?>" target="_blank" rel="noopener">Buka di tab baru</a>
    </p>
    <iframe class="konvert-frame" src="<?= site_url('konvert/app') ?>" title="Konvert User"></iframe>
<?php endif ?>
<?= $this->endSection() ?>
