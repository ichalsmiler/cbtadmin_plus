<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Kiriman soal guru (halaman /kirim_soal): satu baris per TA + semester + tingkat + guru + mapel. */
class CreateKirimSoal extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'ta'          => ['type' => 'VARCHAR', 'constraint' => 9],
            'semester'    => ['type' => 'VARCHAR', 'constraint' => 6],
            'tingkat'     => ['type' => 'VARCHAR', 'constraint' => 5],
            'guru'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'mapel'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'berkas'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'asli'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'nama_asli'   => ['type' => 'VARCHAR', 'constraint' => 200, 'default' => ''],
            'jumlah_soal' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'perlu_dicek' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'ukuran'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'kali'        => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'ip'          => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => ''],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['ta', 'semester', 'tingkat']);
        $this->forge->createTable('kirim_soal', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('kirim_soal', true);
    }
}
