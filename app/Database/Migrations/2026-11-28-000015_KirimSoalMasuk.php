<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Kiriman soal guru yang sudah dimasukkan ke Moodle lewat Masukkan Soal: waktu + course tujuan. */
class KirimSoalMasuk extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('kirim_soal', [
            'masuk_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'kali'],
            'courseid' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'masuk_at'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('kirim_soal', ['masuk_at', 'courseid']);
    }
}
