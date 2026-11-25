<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (! $nonaktif): ?><meta http-equiv="refresh" content="60"><?php endif ?>
    <title>Dashboard Simulasi<?= $sekolah !== '' ? ' · ' . esc($sekolah) : '' ?></title>
    <?= view('partials/favicon') ?>
    <?= view('partials/tema_awal') ?>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/panel.css') ?>?v=<?= esc($version) ?>">
</head>
<body class="publik-page">
<main class="container-xl py-3 py-md-4">
    <header class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Dashboard Simulasi</h1>
            <?php if ($sekolah !== ''): ?><div class="text-muted"><?= esc($sekolah) ?></div><?php endif ?>
        </div>
        <?php if (! $nonaktif): ?>
            <div class="small text-muted">Diperbarui <?= date('H:i:s', $diperbarui) ?> · memuat ulang tiap 1 menit</div>
        <?php endif ?>
    </header>

<?php if ($nonaktif): ?>
    <div class="alert alert-info">Dashboard simulasi sedang tidak dibuka.</div>
<?php else: ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">Data belum bisa dibaca. Coba lagi sebentar lagi.</div>
    <?php endif ?>

    <?php
    $katId  = $kategori !== null ? (int) $kategori['id'] : 0;
    $quizId = $quiz !== null ? (int) $quiz['id'] : 0;
    ?>
    <form class="card card-body mb-3" method="get" action="<?= site_url('simulasi') ?>">
        <?php if ($tingkat !== ''): ?><input type="hidden" name="tingkat" value="<?= esc($tingkat, 'attr') ?>"><?php endif ?>
        <div class="row g-2 align-items-end">
            <div class="col-12">
                <?php if ($kunciKat): ?>
                    <div class="form-label small mb-1">Kategori ujian</div>
                    <div class="fw-semibold py-1"><i class="bi bi-lock me-1 text-muted" aria-hidden="true"></i><?= esc($kategori['name'] ?? '-') ?></div>
                <?php else: ?>
                    <label class="form-label small mb-1" for="fKat">Kategori ujian</label>
                    <select class="form-select form-select-sm" id="fKat" name="kat" onchange="this.form.submit()">
                        <?php if ($kategori === null): ?><option value="">Pilih kategori</option><?php endif ?>
                        <?php foreach ($kategoriList as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] === $katId ? 'selected' : '' ?>><?= esc($c['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                <?php endif ?>
            </div>
            <?php // Pilihan Mapel disembunyikan (25 Nov 2026): bawaan semua mapel, atau mapel yang dikunci admin (judul tabel). ?>
        </div>
        <?php if ($angkatan !== []): ?>
            <?php // Kotak per angkatan menggantikan pilihan Tingkatan: klik = saring tabel ke angkatan itu, klik lagi = semua. ?>
            <div class="row g-2 mt-1">
                <?php // Kotak pertama "Semua angkatan" (tingkat kosong), lalu X/XI/XII. ?>
                <?php foreach (['' => $semua] + $angkatan as $t => $a): ?>
                    <?php $t = (string) $t; $dipilih = $tingkat === $t; ?>
                    <div class="col-6 col-md-3">
                        <a class="angkatan-kotak d-block h-100 rounded border p-2 text-reset text-decoration-none<?= $dipilih ? ' border-primary border-2 bg-primary-subtle' : '' ?>"
                           <?= $dipilih ? 'aria-current="true"' : '' ?> title="<?= $t === '' ? 'Tampilkan semua angkatan' : ($dipilih ? 'Tampilkan semua angkatan' : 'Tampilkan kelas angkatan ini') ?>"
                           href="<?= site_url('simulasi') . '?' . http_build_query(array_filter(['kat' => $katId ?: '', 'quiz' => $quizId ?: '', 'tingkat' => $dipilih ? '' : $t], static fn ($v) => $v !== '')) ?>">
                            <div class="d-flex justify-content-between align-items-baseline gap-1">
                                <span class="fw-semibold"><?= $t === '' ? 'Semua angkatan' : 'Kelas ' . esc($t) ?></span>
                                <span class="fw-semibold <?= $a['persen'] === 100 ? 'text-success' : '' ?>"><?= $a['persen'] ?>%</span>
                            </div>
                            <div class="progress my-1" style="height:.4rem" role="progressbar" aria-label="Sudah ikut <?= $t === '' ? 'semua angkatan' : 'Kelas ' . esc($t, 'attr') ?>" aria-valuenow="<?= $a['persen'] ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar <?= $a['persen'] >= 100 ? 'ok' : ($a['persen'] >= 70 ? 'warn' : 'crit') ?>" style="width: <?= $a['persen'] ?>%"></div>
                            </div>
                            <div class="small text-muted"><?= $a['selesai'] ?>/<?= $a['total'] ?> sudah<span class="d-none d-sm-inline"> · <?= $a['belum'] ?> belum ikut</span></div>
                        </a>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </form>

    <?php if ($kategori === null): ?>
        <div class="alert alert-info">Belum ada kategori ujian simulasi.</div>
    <?php elseif ($kelas === []): ?>
        <div class="alert alert-info">Belum ada data siswa untuk tingkatan ini.</div>
    <?php else: ?>
        <?php
        // Daftar nama (dipakai tabel layar lebar dan kartu HP).
        $daftarSudah = static function (array $k): string {
            if ($k['sudah'] === []) {
                return '<span class="text-muted">–</span>';
            }
            $li = '';
            foreach ($k['sudah'] as $s) {
                $waktu = $s['waktu'] ? ' <span class="text-muted">(' . date('d/m H:i', $s['waktu']) . ')</span>' : '';
                $li   .= '<li>' . esc($s['nama']) . $waktu . '</li>';
            }

            return '<details><summary class="text-success">' . count($k['sudah']) . ' siswa sudah ikut</summary>'
                . '<ol class="mb-0 mt-1 ps-3">' . $li . '</ol></details>';
        };
        $daftarBelum = static function (array $k): string {
            if ($k['kurang'] === []) {
                return '<span class="text-success"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Semua sudah ikut</span>';
            }
            $li = '';
            foreach ($k['kurang'] as $s) {
                $tanda = $s['proses'] ? ' <span class="badge text-bg-warning ms-1">mengerjakan</span>' : '';
                $li   .= '<li>' . esc($s['nama']) . $tanda . '</li>';
            }

            return '<details><summary>' . count($k['kurang']) . ' siswa belum</summary>'
                . '<ol class="mb-0 mt-1 ps-3">' . $li . '</ol></details>';
        };
        ?>
        <div class="row g-2 g-md-3 mb-3">
            <?php foreach ([['Jumlah siswa', $total['total'], 'stat-netral'], ['Sudah ikut', $total['selesai'] . ' (' . $total['persen'] . '%)', 'stat-ok'],
                            ['Mengerjakan', $total['proses'], 'stat-proses'], ['Belum ikut', $total['belum'], 'stat-awas']] as [$lbl, $nilai, $kls]): ?>
                <div class="col-6 col-lg-3">
                    <div class="stat-card <?= $kls ?>">
                        <div class="stat-label"><?= $lbl ?></div>
                        <div class="stat-value"><?= esc((string) $nilai) ?></div>
                    </div>
                </div>
            <?php endforeach ?>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">
                <?= esc($quiz !== null ? $quiz['name'] : $kategori['name'] . ' (semua mapel)') ?>
                <span class="small text-muted fw-normal ms-1"><?= $tingkat !== '' ? 'Kelas ' . esc($tingkat) : 'Semua tingkatan' ?></span>
            </div>

            <!-- Layar lebar: tabel -->
            <div class="table-responsive d-none d-md-block">
                <table class="table align-middle mb-0">
                    <thead><tr>
                        <th>Kelas</th><th class="text-center">Jumlah</th><th class="text-center">Sudah ikut</th>
                        <th class="text-center">Mengerjakan</th><th class="text-center">Belum ikut</th><th style="min-width:9rem">Persentase</th><?php if ($tampilSudah): ?><th>Yang sudah</th><?php endif ?><th>Yang belum</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($kelas as $k): ?>
                        <tr>
                            <td class="fw-semibold"><?= esc($k['kelas']) ?></td>
                            <td class="text-center"><?= $k['total'] ?></td>
                            <td class="text-center text-success fw-semibold"><?= $k['selesai'] ?></td>
                            <td class="text-center"><?= $k['proses'] ?: '–' ?></td>
                            <td class="text-center <?= $k['belum'] ? 'text-danger fw-semibold' : '' ?>"><?= $k['belum'] ?: '–' ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:.5rem" role="progressbar" aria-label="Sudah ikut kelas <?= esc($k['kelas'], 'attr') ?>" aria-valuenow="<?= $k['persen'] ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar <?= $k['persen'] >= 100 ? 'ok' : ($k['persen'] >= 70 ? 'warn' : 'crit') ?>" style="width: <?= $k['persen'] ?>%"></div>
                                    </div>
                                    <span class="small"><?= $k['persen'] ?>%</span>
                                </div>
                            </td>
                            <?php if ($tampilSudah): ?><td class="small"><?= $daftarSudah($k) ?></td><?php endif ?>
                            <td class="small"><?= $daftarBelum($k) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold">
                        <td>Total</td><td class="text-center"><?= $total['total'] ?></td><td class="text-center text-success"><?= $total['selesai'] ?></td>
                        <td class="text-center"><?= $total['proses'] ?></td><td class="text-center"><?= $total['belum'] ?></td><td><?= $total['persen'] ?>%</td><?php if ($tampilSudah): ?><td></td><?php endif ?><td></td>
                    </tr></tfoot>
                </table>
            </div>

            <!-- HP: satu kartu per kelas -->
            <ul class="list-group list-group-flush d-md-none">
                <?php foreach ($kelas as $k): ?>
                    <li class="list-group-item py-3">
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="fw-semibold"><?= esc($k['kelas']) ?></span>
                            <span class="fw-semibold <?= $k['persen'] === 100 ? 'text-success' : '' ?>"><?= $k['persen'] ?>%</span>
                        </div>
                        <div class="progress my-2" style="height:.5rem" role="progressbar" aria-label="Sudah ikut kelas <?= esc($k['kelas'], 'attr') ?>" aria-valuenow="<?= $k['persen'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar <?= $k['persen'] >= 100 ? 'ok' : ($k['persen'] >= 70 ? 'warn' : 'crit') ?>" style="width: <?= $k['persen'] ?>%"></div>
                        </div>
                        <div class="small d-flex flex-wrap column-gap-3">
                            <span class="text-success">Sudah <strong><?= $k['selesai'] ?></strong></span>
                            <?php if ($k['proses']): ?><span>Mengerjakan <strong><?= $k['proses'] ?></strong></span><?php endif ?>
                            <span class="<?= $k['belum'] ? 'text-danger' : 'text-muted' ?>">Belum <strong><?= $k['belum'] ?></strong></span>
                            <span class="text-muted ms-auto">dari <?= $k['total'] ?></span>
                        </div>
                        <div class="small mt-2"><?= $daftarBelum($k) ?></div>
                        <?php if ($tampilSudah && $k['sudah'] !== []): ?><div class="small mt-1"><?= $daftarSudah($k) ?></div><?php endif ?>
                    </li>
                <?php endforeach ?>
                <li class="list-group-item fw-semibold d-flex justify-content-between">
                    <span>Total</span><span><?= $total['selesai'] ?> / <?= $total['total'] ?> sudah ikut (<?= $total['persen'] ?>%)</span>
                </li>
            </ul>
        </div>
        <p class="small text-muted mt-2 mb-0"><strong>Sudah ikut</strong> = ada percobaan yang terkirim; <strong>Mengerjakan</strong> = mulai tetapi belum/tidak terkirim;
            <strong>Belum ikut</strong> = belum mulai. Bilah: merah di bawah 70%, oranye 70–99%, hijau 100%. Dengan "Semua mapel", siswa dihitung sudah ikut bila mengerjakan salah satu mapel.</p>
    <?php endif ?>
<?php endif ?>
</main>
</body>
</html>
