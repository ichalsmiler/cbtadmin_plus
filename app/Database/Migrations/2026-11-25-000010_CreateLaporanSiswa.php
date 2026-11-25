<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Laporan Siswa: laporan kecurangan / kendala teknis dari siswa (tombol Laporkan di Moodle), dibaca Proktor/admin. */
class CreateLaporanSiswa extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'jenis'         => ['type' => 'VARCHAR', 'constraint' => 10],       // curang | kendala
            'kategori'      => ['type' => 'VARCHAR', 'constraint' => 40],
            'pelapor_id'    => ['type' => 'INT', 'unsigned' => true],
            'terlapor_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ruang_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'sesi_id'       => ['type' => 'INT', 'null' => true],
            'keterangan'    => ['type' => 'TEXT', 'null' => true],
            'foto'          => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'baru'], // baru | tindak | tidak
            'catatan'       => ['type' => 'TEXT', 'null' => true],
            'ditangani_oleh' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'ditangani_at'  => ['type' => 'DATETIME', 'null' => true],
            'ip'            => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => ''],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'jenis']);
        $this->forge->addKey(['pelapor_id', 'created_at']);
        $this->forge->createTable('laporan_siswa', true);
    }

    public function down()
    {
        $this->forge->dropTable('laporan_siswa', true);
    }
}
