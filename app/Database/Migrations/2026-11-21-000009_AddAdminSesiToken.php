<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Satu akun pengawas = satu perangkat: token sesi login terakhir (+ IP dan waktunya untuk log).
 * Login di perangkat baru mengganti token, sesi lama ditolak Guard.
 */
class AddAdminSesiToken extends Migration
{
    public function up()
    {
        $kolom = [
            'sesi_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'sesi_ip'    => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'sesi_mulai' => ['type' => 'DATETIME', 'null' => true],
        ];
        foreach ($kolom as $nama => $def) {
            if (! $this->db->fieldExists($nama, 'admins')) {
                $this->forge->addColumn('admins', [$nama => $def]);
            }
        }
    }

    public function down()
    {
        foreach (['sesi_token', 'sesi_ip', 'sesi_mulai'] as $nama) {
            if ($this->db->fieldExists($nama, 'admins')) {
                $this->forge->dropColumn('admins', $nama);
            }
        }
    }
}
