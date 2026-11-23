<?php
// Kartu login peserta (seperti contoh AIOCBT): logo sekolah, judul + kegiatan + tahun, logo kanan; nama, kelas,
// username, password, ruang / jam ujian per tingkatan; kotak catatan. 8 kartu per halaman A4,
// tiap ruang/kelas mulai di halaman baru dengan label kelompok di atasnya.
$pages = [];
foreach ($groups as $label => $rows) {
    foreach (array_chunk($rows, 8) as $i => $chunk) {
        $pages[] = ['label' => $label, 'part' => $i + 1, 'parts' => (int) ceil(count($rows) / 8), 'count' => count($rows), 'rows' => $chunk];
    }
}
$kegiatan = mb_strtoupper($school['nama_kegiatan'] !== '' ? $school['nama_kegiatan'] : 'Ujian Berbasis Komputer');
$tahun    = $school['tahun_ajaran'] !== '' ? 'TAHUN PELAJARAN ' . mb_strtoupper($school['tahun_ajaran']) : '';
// Logo dirujuk sebagai berkas (bukan data-URI) agar ribuan kartu tidak membengkakkan HTML; dompdf memuatnya sekali.
$logoFile  = \App\Libraries\School::logoPath();
$logo2File = \App\Libraries\School::logo2Path();
// Logo kiri dan kanan dimuat ke kotak persegi yang sama (proporsi tetap) agar tampak sama besar.
$gayaLogo  = \App\Libraries\School::gayaLogo($logoFile);
$gayaLogo2 = \App\Libraries\School::gayaLogo($logo2File);
// Tanda tangan kepala sekolah di kartu hanya bila namanya diisi (Tools → Info Sekolah).
$adaKepala = trim((string) $school['kepala_sekolah']) !== '';
$ttdFile   = $adaKepala ? \App\Libraries\School::ttdPath() : null;
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8">
<?= view('pdf/_style') ?>
<style>
    @page { margin: 8mm; }
    body { font-family: "DejaVu Sans", sans-serif; }
    table.grid { width: 100%; border-collapse: separate; border-spacing: 3mm 2.5mm; }
    table.grid > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
    /* Tinggi tetap + overflow hidden: isi yang kepanjangan tidak menimpa kartu di bawahnya (8 kartu per A4). */
    .kartu { border: 1px solid #555; border-radius: 2.5mm; height: 62mm; overflow: hidden; padding: 1.6mm 3mm; font-size: 8pt; }
    .kartu table { width: 100%; border-collapse: collapse; }
    .kartu .head { border-bottom: 1px solid #555; margin-bottom: 1.5mm; }
    .kartu .head td { vertical-align: middle; padding-bottom: 1mm; }
    .kartu .head .logo { width: 12mm; }
    .kartu .head .logo img { vertical-align: middle; }
    .kartu .head .qr { width: 14mm; text-align: right; }
    .kartu .head .qr img { vertical-align: middle; }
    .kartu .judul { text-align: center; font-weight: bold; font-size: 7.3pt; line-height: 1.15; }
    .kartu .isi td { padding: 0.35mm 0; vertical-align: top; }
    .kartu .isi .lbl { width: 25mm; }
    .kartu .isi .ttk { width: 4mm; }
    .kartu .akun { font-weight: bold; font-size: 9.5pt; }
    .kartu .catatan { border: 1px solid #333; margin-top: 1.5mm; }
    .kartu .catatan .judul-catatan { font-weight: bold; font-style: italic; border-bottom: 1px solid #333; padding: 0.6mm 1.5mm; }
    .kartu .catatan .teks { padding: 0.8mm 1.5mm; font-size: 7.6pt; }
    /* Baris bawah (seperti AIOCBT): catatan di kiri, tanda tangan kepala sekolah di kanan bila namanya diisi. */
    .kartu table.bawah { margin-top: 1.5mm; }
    .kartu .bawah td { vertical-align: top; padding: 0; }
    .kartu .bawah td.berbingkai { border: 1px solid #333; }
    .kartu .bawah .kiri .judul-catatan { font-weight: bold; font-style: italic; border-bottom: 1px solid #333; padding: 0.6mm 1.5mm; }
    .kartu .bawah .kiri .teks { padding: 0.8mm 1.5mm; font-size: 7.6pt; }
    .kartu .bawah .ttd-kartu { width: 50%; padding-left: 2mm; text-align: center; font-size: 6.8pt; line-height: 1.15; white-space: nowrap; }
    .kartu .ttd-kartu .jabatan { font-weight: bold; }
    .kartu .ttd-kartu .ruang-ttd { height: 6mm; }
    .kartu .ttd-kartu .ruang-ttd img { height: 6mm; width: auto; }
    .kartu .ttd-kartu .nama-kepala { font-weight: bold; }
    .kelompok { font-size: 8pt; font-weight: bold; margin: 0 3mm; border-bottom: 1px solid #999; padding-bottom: 0.5mm; }
    .kelompok span { font-weight: normal; color: #555; }
</style>
</head>
<body>
<?php foreach ($pages as $p => $page): ?>
    <div class="kelompok">
        <?= esc($page['label']) ?>
        <span>· <?= (int) $page['count'] ?> peserta<?= $page['parts'] > 1 ? ' · halaman ' . $page['part'] . '/' . $page['parts'] : '' ?></span>
    </div>
    <table class="grid">
        <?php foreach (array_chunk($page['rows'], 2) as $pair): ?>
            <tr>
                <?php foreach ($pair as $r): ?>
                    <td>
                        <div class="kartu">
                            <table class="head">
                                <tr>
                                    <td class="logo"><?php if ($logoFile): ?><img src="<?= esc($logoFile, 'attr') ?>" style="<?= $gayaLogo ?>" alt=""><?php endif ?></td>
                                    <td class="judul">
                                        KARTU LOGIN PESERTA<br>
                                        <?= esc($kegiatan) ?>
                                        <?= $tahun !== '' ? '<br>' . esc($tahun) : '' ?>
                                    </td>
                                    <td class="qr"><?php if ($logo2File): ?><img src="<?= esc($logo2File, 'attr') ?>" style="<?= $gayaLogo2 ?>" alt=""><?php endif ?></td>
                                </tr>
                            </table>
                            <table class="isi">
                                <tr><td class="lbl">Nama Peserta</td><td class="ttk">:</td><td><?= esc($r['nama']) ?></td></tr>
                                <tr><td class="lbl">Kelas</td><td class="ttk">:</td><td><?= esc($r['kelas']) ?></td></tr>
                                <tr><td class="lbl">Username Login</td><td class="ttk">:</td><td class="akun"><?= esc($r['username']) ?></td></tr>
                                <tr><td class="lbl">Password Login</td><td class="ttk">:</td><td class="akun"><?= esc((string) $r['password_plain']) ?></td></tr>
                                <tr><td class="lbl">Ruang Ujian</td><td class="ttk">:</td>
                                    <td><?= esc($r['ruang_nama'] ?? '-') ?><?= $r['jam'] !== '' ? ' / ' . esc($r['jam']) : ($r['sesi_nama'] ? ' / ' . esc($r['sesi_nama']) : '') ?></td></tr>
                            </table>
                            <?php if (trim($catatan) !== '' || $adaKepala): ?>
                                <table class="bawah"><tr>
                                    <?php // Bingkai catatan ada di sel tabel agar tingginya ikut blok tanda tangan (atas = jabatan, bawah = NIP). ?>
                                    <td class="kiri<?= trim($catatan) !== '' ? ' berbingkai' : '' ?>">
                                        <?php if (trim($catatan) !== ''): ?>
                                            <div class="judul-catatan">Catatan:</div>
                                            <div class="teks"><?= esc($catatan) ?></div>
                                        <?php endif ?>
                                    </td>
                                    <?php if ($adaKepala): ?>
                                        <td class="ttd-kartu">
                                            <div class="jabatan"><?= esc($school['jabatan']) ?></div>
                                            <div class="ruang-ttd"><?php if ($ttdFile): ?><img src="<?= esc($ttdFile, 'attr') ?>" alt=""><?php endif ?></div>
                                            <div class="nama-kepala"><?= esc($school['kepala_sekolah']) ?></div>
                                            <?php if ($school['nip_kepala'] !== ''): ?><div><?= esc($school['jenis_nomor']) ?>. <?= esc($school['nip_kepala']) ?></div><?php endif ?>
                                        </td>
                                    <?php endif ?>
                                </tr></table>
                            <?php endif ?>
                        </div>
                    </td>
                <?php endforeach ?>
                <?php if (count($pair) === 1): ?><td></td><?php endif ?>
            </tr>
        <?php endforeach ?>
    </table>
    <?php if ($p < count($pages) - 1): ?><div class="break"></div><?php endif ?>
<?php endforeach ?>
</body></html>
