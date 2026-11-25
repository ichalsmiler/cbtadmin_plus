<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>
<?php $waktu = static fn (?string $d): string => $d ? date('d/m H:i', strtotime($d)) : ''; ?>
<?php if ($noRuang): ?>
    <div class="alert alert-warning">Akun ini belum terhubung ke ruang. Hubungi administrator.</div>
<?php else: ?>
<div class="alert alert-info d-flex gap-2" role="note">
    <i class="bi bi-info-circle-fill"></i>
    <div>
        Untuk siswa yang melanggar atau mengganggu. Siswa yang diblokir <strong>langsung dikeluarkan</strong> dari ujian,
        dan saat mencoba login muncul pesan <em>"User Anda terblokir. Silakan hubungi pengawas."</em>
        Jawaban yang sudah tersimpan tidak hilang. Blokir berlaku sampai dibuka di halaman ini.
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <?php if (! $isAdmin): ?><span class="fw-semibold">Siswa ruang <?= esc($ruangList[0]['nama'] ?? '') ?></span><?php endif ?>
            <form method="get" action="<?= site_url('blokir_siswa') ?>" class="d-flex flex-wrap gap-2 align-items-center">
                <?= view('partials/filter_tingkat', ['tingkat' => $tingkat, 'label' => false]) ?>
                <?php if ($isAdmin): ?>
                <select class="form-select form-select-sm w-auto" id="fRuang" name="ruang" onchange="this.form.submit()" aria-label="Ruang">
                    <option value="">Semua ruang</option>
                    <?php foreach ($ruangList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" data-kelas="<?= esc((string) $r['kelas'], 'attr') ?>" <?= $ruangId === (int) $r['id'] ? 'selected' : '' ?>><?= esc($r['nama']) ?></option>
                    <?php endforeach ?>
                </select>
                <?php endif ?>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="fAktif" name="aktif" value="1" <?= $hanyaAktif ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label small" for="fAktif">Hanya yang diblokir</label>
                </div>
            </form>
        <span class="badge text-bg-danger ms-auto"><?= (int) $jumlahAktif ?> diblokir</span>
    </div>
    <div class="card-body">
        <table class="table table-hover align-middle w-100 datatable tabel-hp" data-order-col="0">
            <thead>
            <tr>
                <th>No. Peserta</th><th>Nama</th><th>Kelas</th><?php if ($isAdmin): ?><th>Ruang</th><?php endif ?>
                <th>Status</th><th data-orderable="false" class="text-end"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $diblokir = $r['diblokir_at'] !== null; ?>
                <tr>
                    <td><?= esc($r['nomor_peserta'] ?: '–') ?></td>
                    <td><?= esc($r['nama']) ?></td>
                    <td><?= esc($r['kelas']) ?></td>
                    <?php if ($isAdmin): ?><td><?= esc($r['ruang_nama'] ?? '-') ?></td><?php endif ?>
                    <td data-order="<?= $diblokir ? 0 : 1 ?>">
                        <?php if ($diblokir): ?>
                            <span class="badge text-bg-danger">Diblokir</span>
                            <div class="small text-muted"><?= esc($r['alasan']) ?> · <?= esc($r['diblokir_oleh']) ?>, <?= $waktu($r['diblokir_at']) ?></div>
                        <?php else: ?>
                            <span class="badge text-bg-success">Aktif</span>
                        <?php endif ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($diblokir): ?>
                            <button class="btn btn-outline-success btn-sm" type="button"
                                    data-post="<?= site_url('blokir_siswa/buka') ?>"
                                    data-fields="<?= esc(json_encode(['peserta_id' => (int) $r['id']]), 'attr') ?>"
                                    data-confirm-text="Buka blokir <?= esc($r['nama'], 'attr') ?>? Siswa bisa login lagi.">
                                <i class="bi bi-unlock me-1"></i>Buka blokir
                            </button>
                        <?php else: ?>
                            <button class="btn btn-outline-danger btn-sm" type="button"
                                    data-fill-modal="#blokirModal"
                                    data-fill="<?= esc(json_encode(['peserta_id' => (int) $r['id'], 'nama_siswa' => $r['nama'] . ' (' . $r['username'] . ')', 'alasan' => '']), 'attr') ?>">
                                <i class="bi bi-slash-circle me-1"></i>Blokir
                            </button>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="blokirModal" tabindex="-1" aria-labelledby="blokirModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= site_url('blokir_siswa/blokir') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="peserta_id">
            <div class="modal-header">
                <h5 class="modal-title" id="blokirModalLabel">Blokir siswa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="bNama">Siswa</label>
                <input class="form-control-plaintext fw-semibold" id="bNama" name="nama_siswa" readonly tabindex="-1">
                <label class="form-label mt-2" for="bAlasan">Alasan</label>
                <input class="form-control" id="bAlasan" name="alasan" required minlength="3" maxlength="200" placeholder="mis. ribut di kelas, menyontek">
                <p class="small text-muted mt-2 mb-0">Siswa langsung dikeluarkan dari ujian dan tidak bisa login sampai blokir dibuka.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-slash-circle me-1"></i>Blokir</button>
            </div>
        </form>
    </div>
</div>
<form method="post" id="postForm" class="d-none"><?= csrf_field() ?></form>
<?php endif ?>
<?= $this->endSection() ?>
