<?php
/** Tab Rekap Kehadiran (Dokumen). Variabel: $tab ('pengawas' | 'ujian'). */
$base = site_url('dokumen/laporan_kehadiran');
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'pengawas' ? 'active' : '' ?>" href="<?= $base ?>?tab=pengawas"><i class="bi bi-clipboard-check me-1"></i>Absensi Pengawas</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'ujian' ? 'active' : '' ?>" href="<?= $base ?>?tab=ujian"><i class="bi bi-journal-check me-1"></i>Per Mapel (Ujian)</a>
    </li>
</ul>
