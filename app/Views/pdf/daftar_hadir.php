<?php $keys = array_keys($groups); ?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8">
<?= view('pdf/_style') ?>
<style>
    table.data td { height: 7.5mm; }
    td.ttd-kiri { padding-left: 2mm; }
    td.ttd-kanan { padding-left: 22mm; }
</style>
</head>
<body>
<?php foreach ($groups as $key => $rows): ?>
    <?php $first = $rows[0]; ?>
    <?= view('pdf/_kop', ['school' => $school]) ?>
    <h2>DAFTAR HADIR PESERTA</h2>
    <table class="meta" style="margin-top:6px">
        <tr><td>Ruang</td><td>: <?= esc($first['ruang_nama'] ?? '(tanpa ruang)') ?></td><td style="padding-left:30px">Sesi</td><td>: <?= esc($first['sesi_nama'] ?? '........') ?><?php $jamUjian = \App\Libraries\Tingkat::jam(\App\Libraries\Tingkat::of($first['kelas'] ?? ''), '–'); ?><?= $jamUjian !== '' ? ' (' . esc($jamUjian) . ')' : '' ?></td></tr>
        <tr><td>Mata pelajaran</td><td>: <?= $mapel !== '' ? esc($mapel) : '..............................................' ?></td><td style="padding-left:30px">Hari/Tanggal</td><td>: ..............................</td></tr>
    </table>
    <table class="data">
        <thead><tr><th style="width:8mm">No</th><th style="width:28mm">No. Peserta</th><th>Nama</th><th style="width:24mm">Kelas</th><th colspan="2" style="width:52mm">Tanda Tangan</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td class="center"><?= $i + 1 ?></td>
                <td><?= esc($r['nomor_peserta']) ?></td>
                <td><?= esc($r['nama']) ?></td>
                <td><?= esc($r['kelas']) ?></td>
                <?php if ($i % 2 === 0): ?>
                    <td class="ttd-kiri" style="border-right:0"><?= $i + 1 ?>.</td><td style="border-left:0"></td>
                <?php else: ?>
                    <td style="border-right:0"></td><td style="border-left:0"><?= $i + 1 ?>.</td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <table class="meta" style="margin-top:8px">
        <tr><td>Jumlah peserta seharusnya</td><td>: <?= count($rows) ?> orang</td></tr>
        <tr><td>Jumlah hadir / tidak hadir</td><td>: ........ / ........ orang</td></tr>
    </table>
    <table class="ttd">
        <tr>
            <td></td>
            <td>
                <?= esc($school['kota'] !== '' ? $school['kota'] . ', ' : '') ?>..............................<br>
                Pengawas Ruang
                <div class="space"></div>
                <strong><?= esc(($first['pengawas'] ?? '') !== '' ? $first['pengawas'] : '..............................') ?></strong><br>
                NIP ..............................
            </td>
        </tr>
    </table>
    <?php if ($key !== end($keys)): ?><div class="break"></div><?php endif ?>
<?php endforeach ?>
</body></html>
