<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php // Jejak satu siswa pada satu tanggal: event Moodle + catatan panel, urut waktu (Tools → Log → klik nama siswa). ?>
<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold"><i class="bi bi-signpost-split me-1"></i>Jejak Siswa</span>
        <form method="get" action="<?= site_url('tools/jejak') ?>" class="d-flex flex-wrap align-items-center gap-2 ms-auto">
            <label class="visually-hidden" for="jUser">Username</label>
            <input class="form-control form-control-sm" style="width:11rem" id="jUser" name="u" value="<?= esc($username, 'attr') ?>" placeholder="Username siswa" required>
            <label class="visually-hidden" for="jTgl">Tanggal</label>
            <input class="form-control form-control-sm w-auto" type="date" id="jTgl" name="log_tgl" value="<?= esc($tanggal, 'attr') ?>">
            <button class="btn btn-primary btn-sm" type="submit">Tampilkan</button>
        </form>
    </div>
    <div class="card-body">
        <?php if ($peserta): ?>
            <p class="mb-3"><strong><?= esc($peserta['nama']) ?></strong>
                <span class="text-muted">· <?= esc($peserta['kelas']) ?> · Ruang <?= esc($peserta['ruang'] ?? '–') ?> · <code><?= esc($username) ?></code> · <?= esc(\App\Libraries\School::tanggal(strtotime($tanggal))) ?></span></p>
        <?php elseif ($username !== ''): ?>
            <div class="alert alert-warning py-2">Username <code><?= esc($username) ?></code> tidak ada di daftar peserta panel.</div>
        <?php endif ?>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2">Gagal membaca log Moodle. Lihat log panel untuk rinciannya.</div>
        <?php elseif ($username !== '' && $items === []): ?>
            <p class="text-muted mb-0">Tidak ada kejadian untuk siswa ini pada tanggal tersebut.</p>
        <?php elseif ($items !== []): ?>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th style="width:6rem">Jam</th><th>Kejadian</th><th>Keterangan</th><th style="width:5rem">Sumber</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td class="text-nowrap fw-semibold"><?= date('H:i:s', $i['waktu']) ?></td>
                        <td><i class="bi bi-<?= esc($i['ikon'], 'attr') ?> text-<?= esc($i['warna'], 'attr') ?> me-1"></i><?= esc($i['label']) ?></td>
                        <td class="small"><?= esc($i['ket']) ?></td>
                        <td class="small text-muted"><?= esc($i['sumber']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </div>
</div>
<p class="small text-muted mt-2"><a href="<?= site_url('tools/lainnya') . '?' . http_build_query(['log_tab' => 'siswa', 'log_tgl' => $tanggal]) ?>#log"><i class="bi bi-arrow-left me-1"></i>Kembali ke log</a></p>
<?= $this->endSection() ?>
