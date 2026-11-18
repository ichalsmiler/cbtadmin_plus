<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Fitur mapel per peserta + Sinkron ke Moodle dihapus: buang kolom peserta.mapel bila masih ada. */
class DropPesertaMapel extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('mapel', 'peserta')) {
            $this->forge->dropColumn('peserta', 'mapel');
        }
    }

    public function down()
    {
    }
}
