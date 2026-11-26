<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Akses ke database Moodle (koneksi kedua, diatur lewat .env `moodle.*`). */
class Moodle
{
    public static function db(): BaseConnection
    {
        return Database::connect([
            'DSN'      => '',
            'hostname' => (string) env('moodle.hostname', 'localhost'),
            'username' => (string) env('moodle.username', ''),
            'password' => (string) env('moodle.password', ''),
            'database' => (string) env('moodle.database', 'moodle'),
            'DBDriver' => 'MySQLi',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug'  => true,
            'charset'  => 'utf8mb4',
            'DBCollat' => 'utf8mb4_unicode_ci',
            'swapPre'  => '',
            'encrypt'  => false,
            'compress' => false,
            'strictOn' => false,
            'failover' => [],
            'port'     => (int) env('moodle.port', 3306),
        ]);
    }

    /** Nama tabel Moodle lengkap dengan prefix, mis. t('user') = mdl_user. */
    public static function t(string $name): string
    {
        return preg_replace('/[^a-z0-9_]/i', '', (string) env('moodle.prefix', 'mdl_') . $name);
    }

    /** Alamat Moodle, mis. url('/mod/quiz/view.php?id=5'). */
    public static function url(string $path = ''): string
    {
        return rtrim((string) env('moodle.url', ''), '/') . $path;
    }

    /**
     * Daftar quiz beserta kursus dan modul kursusnya.
     * Dengan $categoryId: hanya quiz di kategori itu dan subkategorinya.
     */
    public static function quizzes(?int $categoryId = null): array
    {
        $q  = self::t('quiz');
        $c  = self::t('course');
        $cc = self::t('course_categories');
        $cm = self::t('course_modules');
        $m  = self::t('modules');

        $where = '';
        $binds = [];
        if ($categoryId !== null) {
            $where = 'WHERE (cc.id = ? OR cc.path LIKE ?)';
            $binds = [$categoryId, '%/' . $categoryId . '/%'];
        }

        return self::db()->query(
            "SELECT q.id, q.name, q.course, q.timeopen, q.timeclose, q.timelimit, q.password, q.grade, q.sumgrades,
                    c.fullname AS course_name, c.shortname AS course_short, c.visible AS course_visible, cc.name AS category_name,
                    cm.id AS cmid, cm.visible
               FROM {$q} q
               JOIN {$c} c ON c.id = q.course
               JOIN {$cc} cc ON cc.id = c.category
               JOIN {$m} m ON m.name = 'quiz'
               JOIN {$cm} cm ON cm.module = m.id AND cm.instance = q.id AND cm.deletioninprogress = 0
              {$where}
              ORDER BY q.timeopen DESC, c.shortname, q.name",
            $binds
        )->getResultArray();
    }

    /** Kategori kursus Moodle, berurutan seperti di Moodle. */
    public static function categories(): array
    {
        // Jumlah course dihitung langsung; kolom coursecount Moodle bisa tertinggal setelah course dihapus.
        return self::db()->table(self::t('course_categories') . ' cc')
            ->select('cc.id, cc.name, cc.parent, cc.path, cc.depth, cc.visible, (SELECT COUNT(*) FROM ' . self::t('course') . ' c WHERE c.category = cc.id) AS coursecount', false)
            ->orderBy('cc.sortorder')
            ->get()->getResultArray();
    }

    /** Kursus (mata pelajaran) di kategori tertentu, termasuk subkategorinya. */
    public static function coursesInCategory(int $categoryId): array
    {
        $c  = self::t('course');
        $cc = self::t('course_categories');

        return self::db()->query(
            "SELECT c.id, c.fullname, c.shortname, c.visible, c.category, cc.name AS category_name
               FROM {$c} c
               JOIN {$cc} cc ON cc.id = c.category
              WHERE c.id > 1 AND (cc.id = ? OR cc.path LIKE ?)
              ORDER BY cc.sortorder, c.sortorder",
            [$categoryId, '%/' . $categoryId . '/%']
        )->getResultArray();
    }

    /** Semua kursus (kecuali halaman depan situs) beserta status tampilnya. */
    public static function allCourses(): array
    {
        return self::db()->table(self::t('course'))
            ->select('id, shortname, visible, category')
            ->where('id >', 1)
            ->get()->getResultArray();
    }

    /** ID user yang bukan peserta: site admin dan guest. */
    public static function nonParticipantIds(): array
    {
        $db  = self::db();
        $ids = [];
        $row = $db->table(self::t('config'))->select('value')->where('name', 'siteadmins')->get()->getRowArray();
        if ($row) {
            $ids = array_map('intval', array_filter(explode(',', (string) $row['value'])));
        }
        $guest = $db->table(self::t('user'))->select('id')->where('username', 'guest')->get()->getRowArray();
        if ($guest) {
            $ids[] = (int) $guest['id'];
        }

        return $ids ?: [0];
    }

    public static function totalUsers(): int
    {
        return self::db()->table(self::t('user'))
            ->where('deleted', 0)
            ->whereNotIn('id', self::nonParticipantIds())
            ->countAllResults();
    }

    /**
     * Peserta yang aktif dalam N menit terakhir, dihitung dari mdl_user.lastaccess.
     * Sengaja tidak memakai mdl_sessions: di server ini sesi disimpan di Redis.
     */
    public static function activeUsers(int $minutes): int
    {
        return self::db()->table(self::t('user'))
            ->where('deleted', 0)
            ->where('lastaccess >', time() - $minutes * 60)
            ->whereNotIn('id', self::nonParticipantIds())
            ->countAllResults();
    }

    /**
     * Attempt yang masih dikerjakan (belum submit).
     * $categoryId: hanya quiz di kategori itu; $userIds: hanya user itu (mis. siswa satu ruang).
     */
    public static function unsubmittedAttempts(?int $categoryId = null, ?array $userIds = null): int
    {
        return self::countAttempts(['inprogress', 'overdue'], $categoryId, $userIds);
    }

    /** Attempt yang sudah submit; parameter sama dengan unsubmittedAttempts(). */
    public static function finishedAttempts(?int $categoryId = null, ?array $userIds = null): int
    {
        return self::countAttempts(['finished'], $categoryId, $userIds);
    }

    private static function countAttempts(array $states, ?int $categoryId, ?array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }
        $builder = self::db()->table(self::t('quiz_attempts'))
            ->whereIn('state', $states)
            ->where('preview', 0);
        if ($userIds !== null) {
            $builder->whereIn('userid', $userIds);
        }
        if ($categoryId !== null) {
            $ids = array_map('intval', array_column(self::quizzes($categoryId), 'id'));
            if ($ids === []) {
                return 0;
            }
            $builder->whereIn('quiz', $ids);
        }

        return $builder->countAllResults();
    }
}
