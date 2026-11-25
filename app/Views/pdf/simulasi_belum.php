<?php
/** Siswa belum simulasi: satu halaman (atau lebih) per angkatan. $kelas = list per angkatan ['kelas' => X|XI|XII, 'total', 'siswa']. */
$namaAngkatan = static fn (string $t): string => in_array($t, \App\Libraries\Tingkat::LIST, true) ? 'KELAS ' . $t : strtoupper($t);
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8">
<?= view('pdf/_style') ?>
</head>
<body>
<?php if ($kelas === []): ?>
    <?= view('pdf/_kop', ['school' => $school]) ?>
    <h2>SISWA BELUM SIMULASI<?= $tingkat !== '' ? ' — KELAS ' . esc($tingkat) : '' ?></h2>
    <p class="center muted"><?= esc($judul) ?></p>
    <p class="center" style="margin-top:20px">Semua siswa<?= $tingkat !== '' ? ' Kelas ' . esc($tingkat) : '' ?> (<?= (int) $total['total'] ?> siswa) sudah ikut simulasi.</p>
<?php endif ?>
<?php foreach ($kelas as $i => $a): ?>
    <?= view('pdf/_kop', ['school' => $school]) ?>
    <h2>SISWA BELUM SIMULASI — <?= esc($namaAngkatan($a['kelas'])) ?></h2>
    <table class="meta" style="margin-top:6px">
        <tr><td>Ujian</td><td>: <?= esc($judul) ?></td></tr>
        <tr><td>Belum simulasi</td><td>: <?= count($a['siswa']) ?> dari <?= (int) $a['total'] ?> siswa</td></tr>
        <tr><td>Dicetak</td><td>: <?= date('d/m/Y H:i') ?></td></tr>
    </table>
    <table class="data">
        <thead><tr><th style="width:8mm">No</th><th style="width:20mm">Kelas</th><th style="width:26mm">No. Peserta</th><th>Nama</th><th style="width:22mm">Ruang</th><th style="width:42mm">Keterangan</th></tr></thead>
        <tbody>
        <?php foreach ($a['siswa'] as $n => $s): ?>
            <tr>
                <td class="center"><?= $n + 1 ?></td>
                <td><?= esc((string) $s['kelas']) ?></td>
                <td><?= esc((string) $s['nomor_peserta']) ?></td>
                <td><?= esc((string) $s['nama']) ?></td>
                <td><?= esc((string) ($s['ruang'] ?? '')) ?></td>
                <td><?= esc((string) $s['keterangan']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php if ($i < count($kelas) - 1): ?><div class="break"></div><?php endif ?>
<?php endforeach ?>
</body></html>
