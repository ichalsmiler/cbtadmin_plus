<?php

namespace App\Libraries;

/**
 * Nama pengawas yang sedang bertugas. Akun pengawas per ruang dipakai bergantian, jadi setelah login (dan setiap
 * berganti hari/sesi) pengawas wajib mengisi namanya; nama tersimpan di tabel `pengawas_hadir` per tanggal, ruang,
 * dan sesi, lalu tampil di kolom Pengawas Rekap Kehadiran (Dokumen).
 */
class PengawasHadir
{
    /** Kunci hari + sesi aktif; nama perlu diisi ulang bila kunci ini berubah. */
    public static function kunci(): string
    {
        return date('Y-m-d') . '|' . self::sesiId();
    }

    private static function sesiId(): int
    {
        return max(0, (int) (ActiveTest::sesiAktif()['id'] ?? 0));
    }

    /** Pengawas di sesi login ini belum mengisi nama untuk hari/sesi sekarang. */
    public static function perlu(): bool
    {
        $s = session();

        return trim((string) $s->get('pengawas_nama')) === '' || $s->get('pengawas_nama_kunci') !== self::kunci();
    }

    public static function simpan(string $nama, int $ruangId, int $adminId): void
    {
        $tanggal = date('Y-m-d');
        $sesi    = self::sesiId();
        $db      = db_connect();
        $ada     = $db->table('pengawas_hadir')->where(['tanggal' => $tanggal, 'ruang_id' => $ruangId, 'sesi_id' => $sesi, 'nama' => $nama])->countAllResults() > 0;
        $row     = ['admin_id' => $adminId, 'updated_at' => date('Y-m-d H:i:s')];
        if ($ada) {
            $db->table('pengawas_hadir')->where(['tanggal' => $tanggal, 'ruang_id' => $ruangId, 'sesi_id' => $sesi, 'nama' => $nama])->update($row);
        } else {
            $db->table('pengawas_hadir')->insert($row + ['tanggal' => $tanggal, 'ruang_id' => $ruangId, 'sesi_id' => $sesi, 'nama' => $nama]);
        }
        session()->set(['pengawas_nama' => $nama, 'pengawas_nama_kunci' => self::kunci()]);
    }

    /** Nama pengawas per ruang pada satu tanggal: ruang_id => "Nama A, Nama B" (urut sesi, tanpa nama ganda). */
    public static function perRuang(string $tanggal): array
    {
        try {
            $rows = db_connect()->table('pengawas_hadir')
                ->select('ruang_id, nama')
                ->where('tanggal', $tanggal)
                ->orderBy('sesi_id')->orderBy('updated_at')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return []; // tabel belum dibuat (migrasi belum jalan)
        }
        $per = [];
        foreach ($rows as $r) {
            $per[(int) $r['ruang_id']][$r['nama']] = true;
        }

        return array_map(static fn (array $nama): string => implode(', ', array_keys($nama)), $per);
    }
}
