<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cek Soal: guru memeriksa beberapa soal mapelnya lewat tautan tanpa login.
 * cek_guru = data guru per mapel (diunggah admin); cek_tugas = satu tautan cek untuk satu guru.
 */
class CreateCekSoal extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nama'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'mapel'      => ['type' => 'VARCHAR', 'constraint' => 120],
            'tingkat'    => ['type' => 'VARCHAR', 'constraint' => 5],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tingkat', 'mapel']);
        $this->forge->createTable('cek_guru', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'token'       => ['type' => 'CHAR', 'constraint' => 32],
            'guru_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'guru_nama'   => ['type' => 'VARCHAR', 'constraint' => 120],
            'courseid'    => ['type' => 'INT', 'unsigned' => true],
            'mapel'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'soal'        => ['type' => 'TEXT'],                          // JSON [{id, slot}]
            'batas'       => ['type' => 'DATETIME'],
            'perangkat'   => ['type' => 'CHAR', 'constraint' => 64, 'null' => true], // sha256 cookie perangkat pertama
            'dibuka_at'   => ['type' => 'DATETIME', 'null' => true],
            'ip'          => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'akses'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'ditolak'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0], // dibuka dari perangkat lain
            'hasil'       => ['type' => 'TEXT', 'null' => true],          // JSON {qid: {ok: bool, catatan}}
            'selesai_at'  => ['type' => 'DATETIME', 'null' => true],
            'dicabut_at'  => ['type' => 'DATETIME', 'null' => true],
            'dibuat_oleh' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token');
        $this->forge->addKey('courseid');
        $this->forge->createTable('cek_tugas', true);
    }

    public function down()
    {
        $this->forge->dropTable('cek_tugas', true);
        $this->forge->dropTable('cek_guru', true);
    }
}
