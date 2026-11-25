<?php

namespace App\Libraries;

use App\Controllers\Peserta;

/**
 * Akun pengawas (role proktor) otomatis: satu akun untuk setiap ruang.
 * Password disimpan juga apa adanya supaya bisa dibagikan/dicetak, sama seperti password peserta.
 */
class Pengawas
{
    /**
     * Buat akun untuk ruang yang belum punya pengawas.
     *
     * @return int jumlah akun yang dibuat
     */
    public static function sync(): int
    {
        $db      = db_connect();
        $covered = array_map('intval', array_column(
            $db->table('admins')->select('ruang_id')->where('role', 'proktor')->where('ruang_id IS NOT NULL')->get()->getResultArray(),
            'ruang_id'
        ));
        $created = 0;
        foreach ($db->table('ruang')->orderBy('id')->get()->getResultArray() as $ruang) {
            if (in_array((int) $ruang['id'], $covered, true)) {
                continue;
            }
            $password = self::randomPassword();
            $db->table('admins')->insert([
                'username'       => self::usernameFor($ruang),
                'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                'password_plain' => $password,
                'nama'           => mb_substr(trim((string) $ruang['pengawas']) ?: 'Pengawas Ruang ' . $ruang['nama'], 0, 120),
                'role'           => 'proktor',
                'ruang_id'       => (int) $ruang['id'],
                'aktif'          => 1,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Kode login pengawas seperti AIOCBT: NPSN-<huruf ruang><nomor ruang><2 huruf acak>,
     * mis. ruang 1 -> 40311889-A1HT, ruang 2 -> 40311889-B2VR. Unik.
     */
    public static function usernameFor(array $ruang): string
    {
        $npsn  = preg_replace('/[^0-9A-Za-z]/', '', Settings::get('npsn', (string) env('cbt.npsn', ''))) ?: 'CBT';
        $nomor = ctype_digit(trim((string) $ruang['nama'])) ? (int) trim((string) $ruang['nama']) : (int) $ruang['id'];
        $huruf = chr(65 + max(0, $nomor - 1) % 26);
        $abjad = 'ABCDEFGHJKLMNPQRSTUVWXYZ';  // tanpa I dan O supaya tidak tertukar dengan 1 dan 0
        $db    = db_connect();
        do {
            $kode = $npsn . '-' . $huruf . $nomor . $abjad[random_int(0, 23)] . $abjad[random_int(0, 23)];
        } while ($db->table('admins')->where('username', $kode)->countAllResults() > 0);

        return $kode;
    }

    /** Password pengawas: 4 huruf kapital acak (tanpa I dan O), mis. HKYT. */
    public static function randomPassword(): string
    {
        $abjad = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $pass  = '';
        for ($i = 0; $i < 4; $i++) {
            $pass .= $abjad[random_int(0, 23)];
        }

        return $pass;
    }

    /** Apakah username sudah berformat kode login dengan NPSN sekarang. */
    public static function formatSesuai(string $username): bool
    {
        $npsn = preg_replace('/[^0-9A-Za-z]/', '', Settings::get('npsn', (string) env('cbt.npsn', ''))) ?: 'CBT';

        return (bool) preg_match('/^' . preg_quote($npsn, '/') . '-[A-Z]\d+[A-Z]{2}$/', $username);
    }

    /**
     * Ubah akun pengawas yang belum berformat kode login (mis. "pengawas1") ke format baru.
     * Password tidak diubah.
     *
     * @return int jumlah akun yang diubah
     */
    public static function rapikanKode(): int
    {
        $db    = db_connect();
        $count = 0;
        $rows  = $db->table('admins a')->select('a.id, a.username, r.id AS rid, r.nama')
            ->join('ruang r', 'r.id = a.ruang_id')
            ->where('a.role', 'proktor')->get()->getResultArray();
        foreach ($rows as $row) {
            if (! self::formatSesuai($row['username'])) {
                $db->table('admins')->where('id', $row['id'])->update(['username' => self::usernameFor(['id' => $row['rid'], 'nama' => $row['nama']])]);
                $count++;
            }
        }

        return $count;
    }

    /** Generate Kode: kode login dan password baru untuk satu akun pengawas. */
    public static function kodeBaru(int $adminId): array
    {
        $db  = db_connect();
        $row = $db->table('admins a')->select('a.id, r.id AS rid, r.nama')
            ->join('ruang r', 'r.id = a.ruang_id')
            ->where('a.id', $adminId)->where('a.role', 'proktor')->get()->getRowArray();
        if (! $row) {
            throw new \RuntimeException('Akun pengawas tidak ditemukan.');
        }
        $username = self::usernameFor(['id' => $row['rid'], 'nama' => $row['nama']]);
        $password = self::randomPassword();
        $db->table('admins')->where('id', $adminId)->update([
            'username'       => $username,
            'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
            'password_plain' => $password,
        ]);
        \App\Controllers\Auth::bukaKunci($username);

        return ['username' => $username, 'password' => $password];
    }

    /** Password baru untuk akun pengawas tertentu (semua bila $ids kosong). */
    public static function resetPasswords(array $ids = []): int
    {
        $db      = db_connect();
        $builder = $db->table('admins')->select('id, username')->where('role', 'proktor')->where('ruang_id IS NOT NULL');
        if ($ids !== []) {
            $builder->whereIn('id', $ids);
        }
        $count = 0;
        foreach ($builder->get()->getResultArray() as $row) {
            $password = self::randomPassword();
            $db->table('admins')->where('id', $row['id'])->update([
                'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                'password_plain' => $password,
            ]);
            \App\Controllers\Auth::bukaKunci((string) $row['username']);
            $count++;
        }

        return $count;
    }

    /** Username peserta di ruang tertentu. */
    public static function usernamesInRuang(int $ruangId, ?int $sesiId = null): array
    {
        $builder = db_connect()->table('peserta p')->select('p.username')->where('p.ruang_id', $ruangId);
        if ($sesiId !== null) {
            // Sesi peserta, atau sesi ruangnya bila peserta tidak punya sesi sendiri.
            $builder->join('ruang r', 'r.id = p.ruang_id', 'left')->where('COALESCE(p.sesi_id, r.sesi_id)', $sesiId, false);
        }

        return array_column($builder->get()->getResultArray(), 'username');
    }

    /** ID user Moodle untuk peserta di ruang tertentu (opsional: hanya sesi tertentu). */
    public static function moodleIdsInRuang(int $ruangId, ?int $sesiId = null): array
    {
        $usernames = self::usernamesInRuang($ruangId, $sesiId);
        if ($usernames === []) {
            return [];
        }

        return array_map('intval', array_column(
            Moodle::db()->table(Moodle::t('user'))->select('id')->where('deleted', 0)->whereIn('username', $usernames)->get()->getResultArray(),
            'id'
        ));
    }
}
