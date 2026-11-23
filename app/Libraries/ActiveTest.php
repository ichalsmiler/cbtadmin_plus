<?php

namespace App\Libraries;

/**
 * Tes yang sedang aktif: satu kategori kursus Moodle, dan satu token bersama
 * yang dipasang sebagai password di semua quiz kategori itu.
 *
 * Disimpan di tabel `settings`:
 *   tes_kategori  id kategori Moodle (0 = belum dipilih)
 *   token_aktif   1 = pakai token dan release otomatis, 0 = tanpa token
 *   token_menit   jarak release otomatis (menit)
 *   token_nilai   token yang sedang berlaku
 *   token_waktu   waktu token terakhir di-release (unix time)
 */
class ActiveTest
{
    /** Huruf tanpa I dan O supaya tidak tertukar dengan 1 dan 0. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const TOKEN_LENGTH = 6;

    /** Password bawaan semua quiz yang tidak termasuk tes aktif (diganti token saat tesnya diaktifkan). */
    public const DEFAULT_PASSWORD = '123456';

    public static function categoryId(): int
    {
        return (int) Settings::get('tes_kategori', '0');
    }

    /** Kategori Moodle tanpa ujian: jadi tes aktif saat semua sesi nonaktif; disisakan saat Reset Moodle. */
    public const NAMA_NONAKTIF = 'SESI NONAKTIF';

    public static function isNonaktif(?string $nama): bool
    {
        return mb_strtoupper(trim((string) $nama)) === self::NAMA_NONAKTIF;
    }

    /** Id kategori SESI NONAKTIF di Moodle (dibuat jembatan bila belum ada). */
    public static function kategoriNonaktif(): int
    {
        return (int) MoodleBridge::call('kategori_nonaktif', [])[0]['id'];
    }

    /** Pilihan "Semua Sesi" di Setting Ujian: semua peserta boleh ujian sekaligus. */
    public const SEMUA = -1;

    /** Sesi yang sedang ujian (wajib dipilih saat mengaktifkan tes), 0 = belum dipilih, -1 = semua sesi. */
    public static function sesiId(): int
    {
        return (int) Settings::get('sesi_aktif', '0');
    }

    /** Id sesi untuk menyaring siswa: null bila tanpa sesi aktif atau Semua Sesi (tidak disaring). */
    public static function sesiFilter(): ?int
    {
        $id = self::sesiId();

        return $id > 0 ? $id : null;
    }

    /** sesiList() ditambah pilihan Semua Sesi di paling bawah (untuk Setting Ujian). */
    public static function sesiPilihan(): array
    {
        $list = self::sesiList();
        if ($list !== []) {
            $list[] = [
                'id'      => self::SEMUA,
                'nama'    => 'Semua',
                'jumlah'  => db_connect()->table('peserta')->countAllResults(),
                'tingkat' => '',
                'label'   => 'Semua Sesi',
            ];
        }

        return $list;
    }

    /**
     * Daftar sesi beserta jumlah peserta dan tingkatannya (sesi peserta, atau sesi ruangnya bila kosong).
     *
     * @return list<array{id:int, nama:string, jumlah:int, tingkat:string, label:string}>
     */
    public static function sesiList(): array
    {
        $db    = db_connect();
        $hasil = [];
        foreach ($db->table('sesi')->orderBy('nama')->get()->getResultArray() as $s) {
            $kelas = array_column($db->table('peserta p')->select('p.kelas')
                ->join('ruang r', 'r.id = p.ruang_id', 'left')
                ->where('COALESCE(p.sesi_id, r.sesi_id)', (int) $s['id'], false)
                ->get()->getResultArray(), 'kelas');
            $tingkat = array_values(array_intersect(Tingkat::LIST, array_unique(array_map([Tingkat::class, 'of'], $kelas))));
            $hasil[] = [
                'id'      => (int) $s['id'],
                'nama'    => (string) $s['nama'],
                'jumlah'  => count($kelas),
                'tingkat' => implode('/', $tingkat),
                'label'   => 'Sesi ' . $s['nama'] . ($tingkat !== [] ? ' (Kelas ' . implode('/', $tingkat) . ')' : ''),
            ];
        }

        return $hasil;
    }

    /** Token disembunyikan dari pengawas ($ruangId bukan null) selama belum ada sesi yang diaktifkan. */
    public static function sesiBelumAktifUntuk(?int $ruangId): bool
    {
        return $ruangId !== null && self::sesiAktif() === null;
    }

    /** Sesi aktif (lihat sesiList), null bila belum dipilih atau sesinya sudah dihapus. */
    public static function sesiAktif(): ?array
    {
        $id = self::sesiId();
        if ($id === 0) {
            return null;
        }
        foreach (self::sesiPilihan() as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    public static function tokenEnabled(): bool
    {
        return Settings::get('token_aktif', '1') === '1';
    }

    public static function tokenMinutes(): int
    {
        return max(1, (int) Settings::get('token_menit', '15'));
    }

    public static function token(): string
    {
        // Tanpa sesi aktif tidak ada token (semua quiz berpassword bawaan); token baru dirilis saat sesi diaktifkan.
        return self::tokenEnabled() && self::sesiId() !== 0 ? Settings::get('token_nilai') : '';
    }

    public static function releasedAt(): int
    {
        return (int) Settings::get('token_waktu', '0');
    }

    /** Waktu release otomatis berikutnya, 0 bila token tidak aktif atau belum ada kategori. */
    public static function nextReleaseAt(): int
    {
        if (! self::tokenEnabled() || self::categoryId() === 0 || self::token() === '') {
            return 0;
        }

        return self::releasedAt() + self::tokenMinutes() * 60;
    }

    /** Sudah waktunya release otomatis? */
    public static function due(): bool
    {
        if (! self::tokenEnabled() || self::categoryId() === 0 || self::sesiId() === 0) {
            return false;
        }

        return self::token() === '' || time() >= self::nextReleaseAt();
    }

    /** Nama mapel yang dibuka (kursus tampil di kategori aktif), dipisah koma; '' bila belum ada tes. */
    public static function mapelAktif(): string
    {
        if (self::categoryId() === 0) {
            return '';
        }
        $kursus = array_filter(Moodle::coursesInCategory(self::categoryId()), static fn ($c) => (int) $c['visible'] === 1);

        return implode(', ', array_column($kursus, 'fullname'));
    }

    /**
     * Token disembunyikan dari pengawas ruang ini sampai absensi ruangnya hari ini disimpan
     * (minimal satu siswa sesi aktif). Admin ($ruangId null) tidak dikunci.
     */
    public static function terkunciUntuk(?int $ruangId): bool
    {
        if ($ruangId === null || ! self::tokenEnabled()) {
            return false;
        }

        $builder = db_connect()->table('absensi a')->where('a.ruang_id', $ruangId)->where('a.tanggal', date('Y-m-d'));
        if (($sesi = self::sesiFilter()) !== null) {
            // Absensi sesi aktif: siswa sesi sebelumnya yang sudah diabsen tidak membuka token sesi ini.
            $builder->join('peserta p', 'p.id = a.peserta_id')->join('ruang r', 'r.id = p.ruang_id', 'left')
                ->where('COALESCE(p.sesi_id, r.sesi_id)', $sesi, false);
        }

        return $builder->countAllResults() === 0;
    }

    public static function newToken(): string
    {
        $token = '';
        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $token;
    }

    /** Buat token baru dan pasang di semua quiz kategori aktif. */
    public static function release(): string
    {
        $token = self::newToken();
        self::apply($token);
        Settings::set('token_nilai', $token);
        Settings::set('token_waktu', (string) time());

        return $token;
    }

    /**
     * Pasang password di quiz kategori aktif yang password-nya belum sama.
     *
     * @return int jumlah quiz yang diubah
     */
    public static function apply(string $password, ?int $categoryId = null): int
    {
        $categoryId ??= self::categoryId();
        // Sesi dinonaktifkan: token tidak dipasang; semua quiz tetap berpassword bawaan (applyDefault).
        if ($categoryId === 0 || self::sesiId() === 0) {
            return 0;
        }

        $ids = [];
        foreach (Moodle::quizzes($categoryId) as $quiz) {
            if ((string) $quiz['password'] !== $password) {
                $ids[] = (int) $quiz['id'];
            }
        }
        if ($ids === []) {
            return 0;
        }

        $failed = array_filter(
            MoodleBridge::call('quiz_password', ['quizids' => $ids, 'password' => $password]),
            static fn ($item) => isset($item['error'])
        );
        if ($failed !== []) {
            throw new \RuntimeException('Token gagal dipasang di ' . count($failed) . ' quiz: ' . reset($failed)['error']);
        }

        return count($ids);
    }

    /**
     * Quiz di luar kategori aktif (termasuk quiz baru dan tes yang sudah tidak aktif) dikunci
     * dengan password bawaan, supaya hanya tes yang diaktifkan CBT Admin yang bisa dikerjakan.
     *
     * @return int jumlah quiz yang diubah
     */
    public static function applyDefault(): int
    {
        $categoryId = self::categoryId();
        // Tanpa sesi aktif semua sesi tertutup: quiz tes aktif ikut dikunci password bawaan.
        $aktif      = $categoryId > 0 && self::sesiId() !== 0 ? array_map('intval', array_column(Moodle::quizzes($categoryId), 'id')) : [];
        $ids        = [];
        foreach (Moodle::quizzes() as $quiz) {
            if ((string) $quiz['password'] !== self::DEFAULT_PASSWORD && ! in_array((int) $quiz['id'], $aktif, true)) {
                $ids[] = (int) $quiz['id'];
            }
        }
        if ($ids === []) {
            return 0;
        }

        $failed = array_filter(
            MoodleBridge::call('quiz_password', ['quizids' => $ids, 'password' => self::DEFAULT_PASSWORD]),
            static fn ($item) => isset($item['error'])
        );
        if ($failed !== []) {
            throw new \RuntimeException('Password bawaan gagal dipasang di ' . count($failed) . ' quiz: ' . reset($failed)['error']);
        }

        return count($ids);
    }

    /**
     * Pastikan quiz di luar tes aktif memakai password bawaan, dan semua quiz kategori aktif memakai
     * token yang berlaku (termasuk quiz yang baru dibuat setelah token di-release).
     */
    public static function sync(): int
    {
        $token = self::token();
        $count = self::applyDefault();

        return $count + (self::tokenEnabled() && $token !== '' ? self::apply($token) : 0);
    }
}
