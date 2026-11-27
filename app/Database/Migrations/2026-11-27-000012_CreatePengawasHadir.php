<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Nama pengawas yang diisi setelah login (akun pengawas dipakai bergantian), per tanggal, ruang, dan sesi. */
class CreatePengawasHadir extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'tanggal'    => ['type' => 'DATE'],
            'ruang_id'   => ['type' => 'INT', 'unsigned' => true],
            'sesi_id'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0], // 0 = tanpa sesi aktif
            'nama'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'admin_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tanggal', 'ruang_id', 'sesi_id', 'nama']);
        $this->forge->createTable('pengawas_hadir', true);
    }

    public function down()
    {
        $this->forge->dropTable('pengawas_hadir', true);
    }
}
