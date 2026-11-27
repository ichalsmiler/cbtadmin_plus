<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Sampel kondisi server tiap menit (cron cbt:token) untuk ringkasan server per sesi; disimpan 7 hari. */
class CreateServerSampel extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'waktu'    => ['type' => 'INT', 'unsigned' => true],                         // unix
            'cpu'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],     // % semua inti
            'ram'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],     // %
            'disk'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],     // % partisi /
            'beban'    => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],  // load average 1 menit
            'fpm'      => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],    // proses pekerja PHP-FPM
            'db'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],    // koneksi MariaDB
            'http'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],         // permintaan nginx menit ini
            'galat'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],         // jawaban 5xx menit ini
            'aktif'    => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],    // user Moodle aktif 2 menit
            'pengguna' => ['type' => 'MEDIUMTEXT', 'null' => true],                     // id user aktif (koma)
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('waktu');
        $this->forge->createTable('server_sampel', true);
    }

    public function down()
    {
        $this->forge->dropTable('server_sampel', true);
    }
}
