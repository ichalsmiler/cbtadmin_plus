<?php
// Kartu kode login pengawas (seperti contoh AIOCBT): logo sekolah kiri, "KODE LOGIN PENGAWAS" + kegiatan + tahun pelajaran, logo kanan
// (logo2, sama dengan kartu peserta); kode login besar, password, ruang. 10 kartu per halaman A4.
$kegiatan  = mb_strtoupper($school['nama_kegiatan'] !== '' ? $school['nama_kegiatan'] : 'Ujian Berbasis Komputer');
$tahun     = $school['tahun_ajaran'] !== '' ? 'TAHUN PELAJARAN ' . mb_strtoupper($school['tahun_ajaran']) : '';
$logoFile  = \App\Libraries\School::logoPath();
$logo2File = \App\Libraries\School::logo2Path();
// Logo kiri dan kanan dimuat ke kotak persegi yang sama (proporsi tetap) agar tampak sama besar.
$gayaLogo  = \App\Libraries\School::gayaLogo($logoFile);
$gayaLogo2 = \App\Libraries\School::gayaLogo($logo2File);
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8">
<?= view('pdf/_style') ?>
<style>
    @page { margin: 8mm; }
    table.grid { width: 100%; border-collapse: separate; border-spacing: 3mm 3mm; }
    table.grid > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
    .id { border: 1px solid #555; }
    .id .head { width: 100%; border-collapse: collapse; border-bottom: 1px solid #555; }
    .id .head td { padding: 1.5mm 2mm; vertical-align: middle; }
    .id .head .logo { width: 13mm; }
    .id .head .logo img { vertical-align: middle; }
    .id .head .logo2 { width: 13mm; text-align: right; }
    .id .head .logo2 img { vertical-align: middle; }
    .id .head .judul { text-align: center; font-weight: bold; font-size: 9pt; line-height: 1.25; }
    .id .isi { text-align: center; height: 31mm; padding-top: 5mm; }
    .id .kode { font-size: 16pt; margin-bottom: 2mm; }
    .id .pass { font-size: 9.5pt; font-weight: bold; margin-bottom: 4mm; }
    .id .ruang { font-size: 9.5pt; }
</style>
</head>
<body>
<?php foreach (array_chunk($rows, 10) as $p => $page): ?>
    <?php if ($p > 0): ?><div class="break"></div><?php endif ?>
    <table class="grid">
        <?php foreach (array_chunk($page, 2) as $pair): ?>
            <tr>
                <?php foreach ($pair as $r): ?>
                    <?php
                    $lokasi = trim((string) $r['lokasi']);
                    $ruang  = 'RUANG ' . $r['nama'];
                    if ($lokasi !== '' && strcasecmp($lokasi, $ruang) !== 0 && strcasecmp($lokasi, (string) $r['nama']) !== 0) {
                        $ruang .= ' - ' . $lokasi;
                    }
                    ?>
                    <td>
                        <div class="id">
                            <table class="head"><tr>
                                <td class="logo"><?php if ($logoFile): ?><img src="<?= esc($logoFile, 'attr') ?>" style="<?= $gayaLogo ?>" alt=""><?php endif ?></td>
                                <td class="judul">KODE LOGIN PENGAWAS<br><?= esc($kegiatan) ?><?= $tahun !== '' ? '<br>' . esc($tahun) : '' ?></td>
                                <td class="logo2"><?php if ($logo2File): ?><img src="<?= esc($logo2File, 'attr') ?>" style="<?= $gayaLogo2 ?>" alt=""><?php endif ?></td>
                            </tr></table>
                            <div class="isi">
                                <div class="kode"><?= esc($r['username'] ?? '–') ?></div>
                                <div class="pass">Password : <?= esc((string) $r['password_plain']) ?></div>
                                <div class="ruang"><?= esc(mb_strtoupper($ruang)) ?></div>
                            </div>
                        </div>
                    </td>
                <?php endforeach ?>
                <?php if (count($pair) === 1): ?><td></td><?php endif ?>
            </tr>
        <?php endforeach ?>
    </table>
<?php endforeach ?>
</body></html>
