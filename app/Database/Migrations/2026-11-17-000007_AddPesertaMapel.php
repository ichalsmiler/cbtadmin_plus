<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Mapel (shortname course Moodle) per peserta, dipisah koma; dipakai Sinkron ke Moodle untuk mendaftarkan siswa. */
class AddPesertaMapel extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('mapel', 'peserta')) {
            $this->forge->addColumn('peserta', ['mapel' => ['type' => 'TEXT', 'null' => true, 'after' => 'sesi_id']]);
        }
    }

    public function down()
    {
        $this->forge->dropColumn('peserta', 'mapel');
    }
}
