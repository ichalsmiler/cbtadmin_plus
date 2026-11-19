<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php
$query = static function (array $extra = []) use ($quiz, $ruangId, $tingkat): string {
    $params = array_filter(['quiz' => $quiz['id'] ?? '', 'ruang' => $ruangId, 'tingkat' => $tingkat] + $extra, static fn ($v) => $v !== '' && $v !== null);

    return $params === [] ? '' : '?' . http_build_query($params);
};
// Lama pengerjaan dalam format j:mm:dd (sama dengan hitungan berjalan di panel.js).
$durasi = static function (int $detik): string {
    $detik = max(0, $detik);
    $j     = intdiv($detik, 3600);
    $m     = intdiv($detik % 3600, 60);

    return ($j > 0 ? $j . ':' . sprintf('%02d', $m) : (string) $m) . ':' . sprintf('%02d', $detik % 60);
};
$badges = [
    'inprogress' => ['primary', 'Sedang Mengerjakan'],
    'finished'   => ['success', 'Selesai'],
    'notstarted' => ['secondary', 'Belum Mulai'],
];
$fmt = static fn ($ts) => $ts > 0 ? date('d/m H:i', $ts) : '–';
?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert">Database Moodle tidak bisa dibaca. Periksa pengaturan <code>moodle.*</code> di berkas <code>.env</code>.</div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-body">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= site_url('status_peserta' . ($filter !== '' ? '/' . $filter : '')) ?>">
            <div class="flex-grow-1" style="min-width: 240px">
                <label class="form-label small mb-1" for="fQuiz">Mapel</label>
                <select class="form-select form-select-sm" id="fQuiz" name="quiz" onchange="this.form.submit()">
                    <option value="">Semua mapel (hanya yang sudah mulai)</option>
                    <?php foreach ($quizzes as $q): ?>
                        <option value="<?= (int) $q['id'] ?>" <?= $quiz && (int) $quiz['id'] === (int) $q['id'] ? 'selected' : '' ?>>
                            <?= esc($q['course_short'] . ' · ' . $q['name']) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <?= view('partials/filter_tingkat', ['tingkat' => $tingkat]) ?>
            <div>
                <label class="form-label small mb-1" for="fRuang">Ruang</label>
                <select class="form-select form-select-sm" id="fRuang" name="ruang" onchange="this.form.submit()" <?= $lockRuang ? 'disabled' : '' ?>>
                    <?php if (! $lockRuang): ?>
                        <option value="">Semua ruang</option>
                        <option value="0" <?= $ruangId === '0' ? 'selected' : '' ?>>(tanpa ruang)</option>
                    <?php endif ?>
                    <?php foreach ($ruangList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" data-kelas="<?= esc((string) $r['kelas'], 'attr') ?>" <?= (string) $r['id'] === $ruangId ? 'selected' : '' ?>><?= esc($r['nama']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </form>

        <ul class="nav nav-pills mt-3 small">
            <li class="nav-item"><a class="nav-link <?= $filter === '' ? 'active' : '' ?>" href="<?= site_url('status_peserta') . $query() ?>">Semua <span class="badge text-bg-light"><?= array_sum($counts) ?></span></a></li>
            <?php foreach ($badges as $key => [$cls, $label]): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === $key ? 'active' : '' ?>" href="<?= site_url('status_peserta/' . $key) . $query() ?>">
                        <?= $label ?> <span class="badge text-bg-light"><?= (int) $counts[$key] ?></span>
                    </a>
                </li>
            <?php endforeach ?>
            <li class="nav-item ms-auto">
                <a class="nav-link text-danger" href="<?= site_url('status_peserta/login_ganda') . ($ruangId !== '' && ! $lockRuang ? '?ruang=' . (int) $ruangId : '') ?>">
                    <i class="bi bi-people me-1"></i>Login ganda
                </a>
            </li>
        </ul>
        <?php if (! $quiz && $filter === 'notstarted'): ?>
            <p class="small text-muted mt-2 mb-0">Pilih mapel dulu untuk melihat peserta yang belum mulai.</p>
        <?php endif ?>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= site_url('status_peserta/aksi') ?>" data-bulk>
        <?= csrf_field() ?>
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="small text-muted me-1">Untuk yang dicentang:</span>
            <button class="btn btn-outline-primary btn-sm" type="submit" name="aksi" value="submit"
                    data-confirm-button="Paksa submit attempt peserta yang dicentang? Jawaban yang sudah tersimpan dinilai, dan peserta tidak bisa melanjutkan.">
                <i class="bi bi-send-check me-1"></i>Paksa submit
            </button>
            <div class="input-group input-group-sm w-auto">
                <input class="form-control" type="number" name="menit" value="10" min="1" max="600" style="width: 5rem" aria-label="Menit tambahan">
                <span class="input-group-text">menit</span>
                <button class="btn btn-outline-primary" type="submit" name="aksi" value="waktu"
                        data-confirm-button="Tambah waktu untuk peserta yang dicentang? Batas waktu dan waktu tutup mapel mereka diperpanjang.">
                    <i class="bi bi-plus-circle me-1"></i>Tambah waktu
                </button>
            </div>
        </div>
        <div class="card-body">
            <table class="table table-hover align-middle w-100 datatable" data-order-col="1" data-live="<?= (int) $refresh ?>">
                <thead>
                <tr>
                    <th data-orderable="false" style="width:1%"><input class="form-check-input" type="checkbox" data-check-all aria-label="Centang semua"></th>
                    <th>Nama</th><th>Kelas</th><th>Ruang</th>
                    <?php if (! $quiz): ?><th>Mapel</th><?php endif ?>
                    <th>Status</th><th>Mulai</th><th>Selesai</th><th title="Lama mengerjakan mapel ini (Selesai − Mulai); yang masih mengerjakan terus berjalan">Waktu Pengerjaan</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php [$cls, $label] = $badges[$r['status']]; ?>
                    <tr>
                        <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= esc($r['key'], 'attr') ?>" aria-label="Pilih <?= esc($r['nama'], 'attr') ?>"></td>
                        <td><?= esc($r['nama']) ?></td>
                        <td><?= esc($r['kelas']) ?></td>
                        <td><?= esc($r['ruang'] ?? '-') ?></td>
                        <?php if (! $quiz): ?><td><?= esc($r['quiz_name']) ?></td><?php endif ?>
                        <td>
                            <span class="badge text-bg-<?= $cls ?>"><?= $label ?></span>
                            <?php if ($r['overdue']): ?><span class="badge text-bg-warning">Lewat waktu</span><?php endif ?>
                            <?php if ($r['attempt_no'] > 1): ?><small class="text-muted">ke-<?= $r['attempt_no'] ?></small><?php endif ?>
                        </td>
                        <td data-order="<?= $r['timestart'] ?>"><?= $fmt($r['timestart']) ?></td>
                        <td data-order="<?= $r['timefinish'] ?>"><?= $fmt($r['timefinish']) ?></td>
                        <?php
                        // Lama pengerjaan: selesai − mulai; yang masih mengerjakan dihitung berjalan oleh panel.js (data-mulai).
                        $lama = $r['timestart'] > 0 ? ($r['timefinish'] > 0 ? $r['timefinish'] : time()) - $r['timestart'] : -1;
                        ?>
                        <td data-order="<?= $lama ?>">
                            <?php if ($r['timestart'] <= 0): ?>–
                            <?php elseif ($r['status'] === 'inprogress'): ?>
                                <span data-mulai="<?= (int) $r['timestart'] ?>"><?= $durasi($lama) ?></span>
                            <?php else: ?><?= $durasi($lama) ?><?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    CbtLive.start(<?= (int) $now ?>, <?= (int) $refresh ?>);
</script>
<?= $this->endSection() ?>
