<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$query = http_build_query(array_filter(['quiz' => $quizId ?: '', 'kelas' => $kelas, 'tingkat' => $tingkat], static fn ($v) => $v !== ''));
$num   = static fn ($v) => $v === null ? '–' : number_format($v, 2, ',', '.');
$badge = ['finished' => 'success', 'inprogress' => 'primary', 'notstarted' => 'secondary'];
?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-body">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('hasil_ujian') ?>">
            <div class="flex-grow-1" style="min-width: 240px">
                <label class="form-label small mb-1" for="fQuiz">Quiz</label>
                <select class="form-select form-select-sm" id="fQuiz" name="quiz" onchange="this.form.submit()">
                    <option value="">Semua mapel (rekap nilai)</option>
                    <?php foreach ($quizzes as $q): ?>
                        <option value="<?= (int) $q['id'] ?>" <?= (int) $q['id'] === $quizId ? 'selected' : '' ?>><?= esc($q['course_short'] . ' · ' . $q['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
            <div>
                <label class="form-label small mb-1" for="fKelas">Kelas</label>
                <select class="form-select form-select-sm" id="fKelas" name="kelas" onchange="this.form.submit()">
                    <option value="">Semua kelas</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= esc($k, 'attr') ?>" <?= $k === $kelas ? 'selected' : '' ?>><?= esc($k) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($quiz && $stats): ?>
    <div class="row g-3 mb-3">
        <?php foreach ([
            ['Selesai', $stats['selesai'] . ' / ' . $stats['peserta']],
            ['Rata-rata', $num($stats['rata'])],
            ['Terendah', $num($stats['min'])],
            ['Tertinggi', $num($stats['maks'])],
        ] as [$label, $value]): ?>
            <div class="col-6 col-lg-3">
                <div class="stat-card"><div class="stat-value fs-3"><?= $value ?></div><div class="stat-label"><?= $label ?></div></div>
            </div>
        <?php endforeach ?>
    </div>

    <ul class="nav nav-tabs mb-0">
        <li class="nav-item"><a class="nav-link <?= $tab === 'semua' ? 'active' : '' ?>" href="<?= site_url('hasil_ujian') . '?' . $query ?>">Semua peserta</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'cepat' ? 'active' : '' ?>" href="<?= site_url('hasil_ujian') . '?' . $query . '&tab=cepat' ?>">
            <i class="bi bi-lightning-charge me-1"></i>Selesai terlalu cepat <span class="badge text-bg-<?= $cepat ? 'danger' : 'light' ?>"><?= count($cepat) ?></span></a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'analisis' ? 'active' : '' ?>" href="<?= site_url('hasil_ujian') . '?' . $query . '&tab=analisis' ?>">
            <i class="bi bi-bar-chart me-1"></i>Analisis Soal</a></li>
    </ul>

    <?php if ($tab === 'analisis'): ?>
    <div class="card border-top-0 rounded-top-0">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold"><?= esc($quiz['name']) ?></span>
            <?php if ($analisis): ?>
                <span class="small text-muted"><?= (int) $analisis['n'] ?> siswa selesai dianalisis</span>
                <span class="badge text-bg-success">Mudah <?= $analisis['ringkas']['Mudah'] ?></span>
                <span class="badge text-bg-warning">Sedang <?= $analisis['ringkas']['Sedang'] ?></span>
                <span class="badge text-bg-danger">Sukar <?= $analisis['ringkas']['Sukar'] ?></span>
            <?php endif ?>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hasil_ujian/export_analisis') . '?' . $query ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel analisis</a>
            </div>
        </div>
        <div class="card-body">
            <?php if (! $analisis || $analisis['n'] === 0): ?>
                <p class="text-muted mb-0">Belum ada siswa yang selesai mengerjakan quiz ini (sesuai filter).</p>
            <?php else: ?>
                <table class="table table-hover align-middle w-100 datatable" data-order-col="0">
                    <thead><tr><th>No</th><th>Soal</th><th class="text-end">Benar</th><th class="text-end">Salah</th><th class="text-end">Kosong</th>
                        <th class="text-end">Indeks (P)</th><th>Tingkat</th><th class="text-end">Daya beda (D)</th><th>Kualitas</th></tr></thead>
                    <tbody>
                    <?php foreach ($analisis['butir'] as $b): ?>
                        <tr>
                            <td><?= (int) $b['no'] ?></td>
                            <td class="small" style="max-width: 420px"><?= esc($b['teks'] !== '' ? $b['teks'] : $b['nama']) ?></td>
                            <td class="text-end"><?= (int) $b['benar'] ?></td>
                            <td class="text-end"><?= (int) $b['salah'] ?></td>
                            <td class="text-end"><?= (int) $b['kosong'] ?></td>
                            <td class="text-end fw-semibold" data-order="<?= $b['p'] ?? -1 ?>"><?= $num($b['p']) ?></td>
                            <td data-order="<?= $b['p'] ?? -1 ?>"><?php if ($b['tingkat'] !== ''): ?><span class="badge text-bg-<?= ['Mudah' => 'success', 'Sedang' => 'warning', 'Sukar' => 'danger'][$b['tingkat']] ?>"><?= $b['tingkat'] ?></span><?php endif ?></td>
                            <td class="text-end" data-order="<?= $b['d'] ?? -9 ?>"><?= $num($b['d']) ?></td>
                            <td class="small <?= $b['d'] !== null && $b['d'] < 0.20 ? 'text-danger' : '' ?>"><?= esc($b['kualitas']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            <?php endif ?>
            <p class="small text-muted mt-3 mb-0">
                <strong>Indeks kesukaran (P)</strong> = rata-rata skor soal (proporsi siswa yang menjawab benar; kosong dihitung salah):
                0,00–0,30 sukar, 0,31–0,70 sedang, 0,71–1,00 mudah.
                <strong>Daya beda (D)</strong> = P kelompok atas − P kelompok bawah (27% siswa dengan total skor tertinggi dan terendah; minimal 4 siswa):
                ≥ 0,40 sangat baik, 0,30–0,39 baik, 0,20–0,29 cukup, 0,00–0,19 jelek, negatif sebaiknya dibuang.
                Hasil makin bisa dipercaya bila siswa yang dianalisis makin banyak. Soal acak dari bank soal dianalisis per nomor (slot).
            </p>
        </div>
    </div>
    <?php elseif ($tab === 'cepat'): ?>
    <div class="card border-top-0 rounded-top-0">
        <div class="card-body">
            <form class="d-flex flex-wrap gap-2 align-items-end small mb-3" method="get" action="<?= site_url('hasil_ujian') ?>">
                <?php foreach (['quiz' => $quizId, 'kelas' => $kelas, 'tingkat' => $tingkat, 'tab' => 'cepat'] as $k => $val): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= esc((string) $val, 'attr') ?>">
                <?php endforeach ?>
                <span>Ditandai bila durasi kurang dari</span>
                <input class="form-control form-control-sm" style="width:4.5rem" type="number" min="1" max="100" name="pw" value="<?= (int) $param['pw'] ?>" aria-label="Persen batas waktu"><span>% batas waktu</span>
                <span>atau kurang dari</span>
                <input class="form-control form-control-sm" style="width:4.5rem" type="number" min="1" max="100" name="pm" value="<?= (int) $param['pm'] ?>" aria-label="Persen median"><span>% median durasi;</span>
                <span>curiga <strong>tinggi</strong> bila nilainya ≥</span>
                <input class="form-control form-control-sm" style="width:4.5rem" type="number" min="0" max="100" name="pn" value="<?= (int) $param['pn'] ?>" aria-label="Persen nilai"><span>% nilai maksimal.</span>
                <button class="btn btn-outline-primary btn-sm" type="submit">Terapkan</button>
            </form>
            <p class="small text-muted">
                Batas waktu quiz: <?= (int) $quiz['timelimit'] > 0 ? \App\Controllers\HasilUjian::durasi((int) $quiz['timelimit']) : 'tidak ada' ?> ·
                Median durasi peserta selesai: <?= $median ? \App\Controllers\HasilUjian::durasi((int) $median) : 'belum cukup data (minimal 5 peserta selesai)' ?>.
                Ini petunjuk untuk diperiksa (bandingkan dengan nilai harian, tanyakan pengawas), bukan bukti kecurangan.
            </p>
            <table class="table table-hover align-middle w-100 datatable" data-order-col="0">
                <thead><tr><th>Tingkat</th><th>Nama</th><th>Kelas</th><th>Ruang</th><th>Mulai</th><th>Selesai</th><th>Durasi</th><th class="text-end">vs median</th><th class="text-end">Nilai</th></tr></thead>
                <tbody>
                <?php foreach ($cepat as $r): ?>
                    <tr>
                        <td data-order="<?= $r['curiga'] === 'tinggi' ? 0 : 1 ?>"><span class="badge text-bg-<?= $r['curiga'] === 'tinggi' ? 'danger' : 'warning' ?>"><?= $r['curiga'] === 'tinggi' ? 'Tinggi' : 'Sedang' ?></span></td>
                        <td><?= esc($r['nama']) ?><div class="small text-muted"><?= esc($r['nomor_peserta']) ?></div></td>
                        <td><?= esc($r['kelas']) ?></td>
                        <td><?= esc($r['ruang'] ?? '-') ?></td>
                        <td><?= date('d/m H:i', $r['timestart']) ?></td>
                        <td><?= date('d/m H:i', $r['timefinish']) ?></td>
                        <td data-order="<?= (int) $r['durasi'] ?>"><?= \App\Controllers\HasilUjian::durasi((int) $r['durasi']) ?></td>
                        <td class="text-end"><?= $median ? round($r['durasi'] / $median * 100) . '%' : '–' ?></td>
                        <td class="text-end fw-semibold" data-order="<?= $r['nilai'] ?? -1 ?>"><?= $num($r['nilai']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-top-0 rounded-top-0">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold"><?= esc($quiz['name']) ?></span>
            <span class="small text-muted">Nilai skala 0–<?= $num($stats['skala']) ?></span>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hasil_ujian/export') . '?' . $query ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hasil_ujian/export_soal') . '?' . $query ?>" title="Benar/salah tiap nomor soal per siswa"><i class="bi bi-grid-3x3 me-1"></i>Excel per soal</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hasil_ujian/pdf') . '?' . $query ?>" target="_blank" data-pdf><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
            </div>
        </div>
        <div class="card-body">
            <table class="table table-hover align-middle w-100 datatable" data-order-col="2">
                <thead><tr><th>No. Peserta</th><th>Username</th><th>Nama</th><th>Kelas</th><th>Ruang</th><th>Status</th><th class="text-end">Nilai</th><th>Mulai</th><th>Selesai</th><th>Durasi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= esc($r['nomor_peserta']) ?></td>
                        <td><code><?= esc($r['username']) ?></code></td>
                        <td><?= esc($r['nama']) ?></td>
                        <td><?= esc($r['kelas']) ?></td>
                        <td><?= esc($r['ruang'] ?? '-') ?></td>
                        <td><span class="badge text-bg-<?= $badge[$r['status']] ?>"><?= \App\Controllers\HasilUjian::label($r['status']) ?></span></td>
                        <td class="text-end fw-semibold" data-order="<?= $r['nilai'] ?? -1 ?>"><?= $num($r['nilai']) ?></td>
                        <td data-order="<?= $r['timestart'] ?>"><?= $r['timestart'] ? date('d/m H:i', $r['timestart']) : '–' ?></td>
                        <td data-order="<?= $r['timefinish'] ?>"><?= $r['timefinish'] ? date('d/m H:i', $r['timefinish']) : '–' ?></td>
                        <td data-order="<?= $r['durasi'] ?? -1 ?>"><?= $r['durasi'] !== null ? \App\Controllers\HasilUjian::durasi($r['durasi']) : '–' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">Nilai diambil dari nilai akhir quiz di Moodle (sesuai metode penilaian quiz). Peserta yang sedang mengerjakan belum punya nilai.</p>
    <?php endif ?>
<?php elseif (! $quiz && $rekap !== null): ?>
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold">Rekap nilai semua mapel</span>
            <span class="small text-muted"><?= (int) $rekap['siswa'] ?> siswa · <?= count($rekap['quiz']) ?> mapel bernilai</span>
            <?php if ($rekap['quiz'] !== []): ?>
                <div class="ms-auto">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('hasil_ujian/zip') . ($query !== '' ? '?' . $query : '') ?>" title="PDF dan Excel tiap mapel dalam satu ZIP (bisa sampai 1 menit)"><i class="bi bi-file-earmark-zip me-1"></i>Unduh ZIP per mapel</a>
                </div>
            <?php endif ?>
        </div>
        <div class="card-body">
            <?php if ($rekap['quiz'] === []): ?>
                <p class="text-muted mb-0">Belum ada nilai untuk siswa ini (sesuai filter).</p>
            <?php else: ?>
                <div class="table-responsive">
                <?php
                // Judul kolom mapel dipendekkan: awalan yang sama di semua mapel (mis. "USBK-X-") dibuang; nama lengkap di tooltip.
                $singkat = array_column($rekap['quiz'], 'course_short');
                $awalan  = count($singkat) > 1 ? (string) array_reduce($singkat, static function ($a, $s) {
                    $n = 0;
                    while ($n < strlen($a) && $n < strlen($s) && $a[$n] === $s[$n]) { $n++; }
                    return substr($a, 0, $n);
                }, $singkat[0]) : '';
                $p      = strrpos($awalan, '-');  // potong sampai tanda "-" terakhir supaya tidak memotong di tengah kata
                $awalan = $p === false ? '' : substr($awalan, 0, $p + 1);
                ?>
                <table class="table table-hover table-sm align-middle w-100 datatable tabel-rekap" data-order-col="0">
                    <thead><tr><th class="kolom-beku">Nama</th><th>Kelas</th>
                        <?php foreach ($rekap['quiz'] as $q): ?>
                            <th class="text-end judul-mapel" title="<?= esc($q['course_name'] . ' · ' . $q['name'], 'attr') ?>"><?= esc($awalan !== '' ? substr($q['course_short'], strlen($awalan)) : $q['course_short']) ?></th>
                        <?php endforeach ?>
                        <th class="text-end">Rata-rata</th></tr></thead>
                    <tbody>
                    <?php foreach ($rekap['rows'] as $r): ?>
                        <tr>
                            <td class="kolom-beku" data-order="<?= esc($r['nama'], 'attr') ?>"><?= esc($r['nama']) ?><div class="small text-muted"><?= esc($r['nomor_peserta']) ?></div></td>
                            <td class="text-nowrap"><?= esc($r['kelas']) ?></td>
                            <?php foreach ($rekap['quiz'] as $q): $v = $r['nilai'][(int) $q['id']] ?? null; ?>
                                <td class="text-end" data-order="<?= $v ?? -1 ?>"><?= $num($v) ?></td>
                            <?php endforeach ?>
                            <td class="text-end fw-semibold" data-order="<?= $r['rata'] ?? -1 ?>"><?= $num($r['rata']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                </div>
            <?php endif ?>
        </div>
    </div>
    <p class="small text-muted mt-2">Nilai akhir tiap quiz di Moodle. Arahkan kursor ke judul kolom untuk nama mapel lengkap. Quiz simulasi tidak ikut rekap. Pilih satu quiz untuk status, durasi, dan analisis soal.</p>
<?php endif ?>
<?= $this->endSection() ?>
