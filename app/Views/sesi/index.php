<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <div class="col-lg-6">
        <form class="card" method="post" action="<?= site_url('sesi_tingkat/simpan') ?>">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Sesi per tingkatan</div>
            <div class="card-body">
                <p class="small text-body-secondary">Tentukan nomor sesi dan jam ujian untuk setiap tingkatan. Nomor sesi dipakai bersama menu
                    Jadwal (urutan mapel per hari) dan Konvert User (kolom Kel. Sesi). Jam ujian dicetak di kartu peserta
                    (<em>Ruang Ujian : 1 / 08:00 - 10:00</em>), daftar hadir, dan berita acara; kosongkan bila tidak dipakai.</p>
                <?php if (! $tersimpan): ?>
                    <div class="alert alert-info small py-2">Belum pernah disimpan; yang tampil adalah bawaan sekolah.</div>
                <?php endif ?>
                <table class="table align-middle mb-3">
                    <thead><tr><th>Tingkatan</th><th style="width:6rem">Sesi</th><th>Jam ujian</th><th class="text-end">Peserta</th></tr></thead>
                    <tbody>
                    <?php foreach (\App\Libraries\Tingkat::LIST as $t): ?>
                        <tr>
                            <td><label class="form-label mb-0" for="sesi_<?= esc($t) ?>">Kelas <?= esc($t) ?></label></td>
                            <td><input class="form-control" type="number" min="1" max="20" required id="sesi_<?= esc($t) ?>" name="sesi[<?= esc($t) ?>]" value="<?= (int) $nilai[$t] ?>"></td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <input class="form-control" type="time" name="jam[<?= esc($t) ?>][mulai]" value="<?= esc($jam[$t]['mulai'], 'attr') ?>" aria-label="Kelas <?= esc($t) ?> jam mulai">
                                    <span class="text-muted">–</span>
                                    <input class="form-control" type="time" name="jam[<?= esc($t) ?>][selesai]" value="<?= esc($jam[$t]['selesai'], 'attr') ?>" aria-label="Kelas <?= esc($t) ?> jam selesai">
                                </div>
                            </td>
                            <td class="text-end"><?= (int) ($ringkas[$t]['jumlah'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="terapkan" value="1" id="sesi_terapkan">
                    <label class="form-check-label" for="sesi_terapkan">Terapkan juga ke sesi semua peserta di Data CBTAdmin (sesi bernama "1", "2", … dibuat bila belum ada)</label>
                </div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary" type="submit">Simpan</button></div>
        </form>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-people me-1"></i>Sesi peserta saat ini (Data CBTAdmin)</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Tingkatan</th><th>Sesi tercatat</th></tr></thead>
                    <tbody>
                    <?php if ($ringkas === []): ?>
                        <tr><td colspan="2" class="text-center text-muted py-4">Belum ada peserta.</td></tr>
                    <?php endif ?>
                    <?php foreach (\App\Libraries\Tingkat::LIST as $t): ?>
                        <?php if (! isset($ringkas[$t])) { continue; } ?>
                        <?php ksort($ringkas[$t]['sesi'], SORT_NATURAL); ?>
                        <tr>
                            <td>Kelas <?= esc($t) ?></td>
                            <td><?= esc(implode(' · ', array_map(static fn ($s, $n) => "Sesi $s: $n siswa", array_keys($ringkas[$t]['sesi']), $ringkas[$t]['sesi']))) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body small text-body-secondary border-top">Sesi peserta diambil dari kolom sesi peserta, atau sesi ruangnya bila kosong.</div>
        </div>
    </div>

</div>
<?= $this->endSection() ?>
