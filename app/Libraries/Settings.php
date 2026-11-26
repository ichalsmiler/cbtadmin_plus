<?php

namespace App\Libraries;

/** Pengaturan panel (tabel `settings`, pasangan kunci-nilai). */
class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (db_connect()->table('settings')->get()->getResultArray() as $row) {
                    self::$cache[$row['skey']] = (string) $row['svalue'];
                }
            } catch (\Throwable $e) {
                log_message('error', 'Settings gagal dibaca: ' . $e->getMessage());
            }
        }

        return self::$cache;
    }

    /** Buang cache supaya setelan dibaca ulang dari database (proses yang berjalan lama, mis. cbt:telegram). */
    public static function segarkan(): void
    {
        self::$cache = null;
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = self::all()[$key] ?? '';

        return $value !== '' ? $value : $default;
    }

    public static function set(string $key, string $value): void
    {
        $db = db_connect();
        if ($db->table('settings')->where('skey', $key)->countAllResults() > 0) {
            $db->table('settings')->where('skey', $key)->update(['svalue' => $value]);
        } else {
            $db->table('settings')->insert(['skey' => $key, 'svalue' => $value]);
        }
        self::$cache = null;
    }
}
