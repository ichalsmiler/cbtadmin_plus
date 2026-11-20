<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Menu Admin Tools → Server Cadangan: membaca status HA dan menjalankan "pindah" lewat
 * jembatan root /usr/local/sbin/cbt-ha-panel (sudo tanpa sandi, hanya status | pindah | log;
 * dipasang install.sh). Pengaturan ha.conf disimpan di tabel settings (ha_*).
 */
class ServerCadangan
{
    public const HELPER = '/usr/local/sbin/cbt-ha-panel';

    /** Kunci pengaturan panel → nama variabel di ha.conf. */
    public const CONF = [
        'ha_domain_utama'    => 'DOMAIN_UTAMA',
        'ha_domain_cadangan' => 'DOMAIN_CADANGAN',
        'ha_utama_ip'        => 'UTAMA_IP',
        'ha_cadangan_ip'     => 'CADANGAN_IP',
        'ha_secret'          => 'HA_SECRET',
        'ha_backup_hari'     => 'BACKUP_KEEP_DAYS',
        'ha_backup_jam'      => 'BACKUP_JAM_UJIAN',
        'ha_https'           => 'PAKAI_HTTPS',   // ya = https lewat Cloudflare (Tunnel/Proxied, sertifikat Origin opsional); tidak = http (DNS only)
    ];

    /**
     * Server ini cadangan yang masih siaga (database hanya-baca, siswa belum boleh ujian di sini)?
     * Dibaca dari /etc/cbt-ha-peran yang ditulis skrip HA (cadangan → utama setelah PINDAH).
     */
    public static function siaga(): bool
    {
        static $hasil = null;

        return $hasil ??= is_readable('/etc/cbt-ha-peran') && trim((string) @file_get_contents('/etc/cbt-ha-peran')) === 'cadangan';
    }

    public static function tersedia(): bool
    {
        return is_file(self::HELPER);
    }

    /** @return array<string,mixed> */
    public static function jalankan(string $aksi): array
    {
        if (! in_array($aksi, ['status', 'pindah', 'log'], true)) {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
        if (! self::tersedia()) {
            throw new RuntimeException('Jembatan Server Cadangan belum terpasang. Jalankan deploy ulang panel di server ini.');
        }
        $proc = proc_open(['sudo', '-n', self::HELPER, $aksi], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('Jembatan Server Cadangan tidak bisa dijalankan.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $data = json_decode(trim($out), true);
        if (! is_array($data)) {
            throw new RuntimeException('Jawaban jembatan tidak terbaca: ' . trim($err !== '' ? $err : $out));
        }
        if (empty($data['ok'])) {
            throw new RuntimeException((string) ($data['error'] ?? 'Gagal.'));
        }

        return $data;
    }

    /** Nilai pengaturan HA tersimpan, dengan bawaan. */
    public static function nilai(): array
    {
        $v = [];
        foreach (array_keys(self::CONF) as $k) {
            $v[$k] = Settings::get($k);
        }
        $v['ha_backup_hari'] = $v['ha_backup_hari'] !== '' ? $v['ha_backup_hari'] : '7';
        $v['ha_backup_jam']  = $v['ha_backup_jam'] !== '' ? $v['ha_backup_jam'] : '6-17';
        $v['ha_https']       = $v['ha_https'] === 'ya' ? 'ya' : 'tidak';

        return $v;
    }

    /**
     * KODE untuk perintah "cbt-ha cadangan KODE" di konsol VPS: ha.conf dalam base64 (satu baris, mudah disalin).
     * null bila pengaturan belum lengkap.
     */
    public static function kodeCadangan(): ?string
    {
        $v = self::nilai();
        if ($v['ha_utama_ip'] === '' || $v['ha_cadangan_ip'] === '' || $v['ha_secret'] === '') {
            return null;
        }

        return base64_encode(self::haConf());
    }

    /** Isi berkas ha.conf dari pengaturan panel (sama untuk kedua server). */
    public static function haConf(): string
    {
        $v   = self::nilai();
        $out = "# Konfigurasi CBT HA (dibuat panel CBT Admin, " . date('Y-m-d H:i') . ").\n"
            . "# Salin ke folder deploy/ha di KEDUA server dengan nama ha.conf. Isinya harus sama persis.\n";
        foreach (self::CONF as $k => $var) {
            $out .= $var . '="' . str_replace(['"', "\n", '$', '`'], '', (string) $v[$k]) . "\"\n";
        }

        return $out;
    }
}
