<?php

namespace App\Commands;

use App\Libraries\Absensi;
use App\Libraries\ActiveTest;
use App\Libraries\ActivityLog;
use App\Libraries\LaporanSesi;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Dijalankan cron tiap menit (/etc/cron.d/cbtadmin):
 * release token bila sudah waktunya, dan pasang token di quiz baru pada tes aktif.
 */
class TokenCron extends BaseCommand
{
    protected $group       = 'CBT';
    protected $name        = 'cbt:token';
    protected $description = 'Release token otomatis dan samakan token di semua quiz tes aktif.';

    public function run(array $params)
    {
        // Server cadangan (deploy/ha) hanya menyalin data; token diurus server utama.
        if (trim((string) @file_get_contents('/etc/cbt-ha-peran')) === 'cadangan') {
            return EXIT_SUCCESS;
        }

        // Blokir siswa tidak hadir hanya berlaku sehari: buka blokir dari hari sebelumnya.
        try {
            $opened = Absensi::unblockPast();
            if ($opened > 0) {
                ActivityLog::add('buka_blokir_otomatis', $opened . ' siswa');
                CLI::write($opened . ' blokir siswa dari hari sebelumnya dibuka.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'cbt:token absensi: ' . $e->getMessage());
        }

        // Sampel kondisi server (CPU, RAM, PHP-FPM, DB, nginx, user aktif) untuk ringkasan server per sesi.
        try {
            \App\Libraries\ServerSampel::catat();
        } catch (\Throwable $e) {
            log_message('error', 'cbt:token sampel server: ' . $e->getMessage());
        }

        // Laporan evaluasi sesi ke Telegram 15 menit setelah sesi dimatikan.
        try {
            LaporanSesi::pantau();
        } catch (\Throwable $e) {
            log_message('error', 'cbt:token laporan sesi: ' . $e->getMessage());
        }

        try {
            if (ActiveTest::due()) {
                $token = ActiveTest::release();
                ActivityLog::add('release_token', 'otomatis: ' . $token);
                CLI::write('Token dirilis: ' . $token);

                return EXIT_SUCCESS;
            }
            $count = ActiveTest::sync();
            if ($count > 0) {
                CLI::write($count . ' quiz dipasangi token/password bawaan yang berlaku.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'cbt:token: ' . $e->getMessage());
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
