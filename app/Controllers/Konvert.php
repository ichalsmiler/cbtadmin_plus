<?php

namespace App\Controllers;

/**
 * Konvert User (dulu repo web-ujian): jadwal → mapel, data peserta → template CBTAdmin, kelas moving,
 * user Moodle. Aplikasinya berkas HTML tunggal hasil build konvert/ (deploy/build-konvert.sh) dan memproses
 * semua data di browser. Hanya administrator (tidak ada di Guard::PROKTOR_PATHS).
 */
class Konvert extends PanelController
{
    private const BERKAS = APPPATH . 'Konvert/konvert.html';

    public function index(): string
    {
        return $this->render('konvert/index', [
            'title' => 'Konvert User',
            'ada'   => is_file(self::BERKAS),
        ]);
    }

    /** Isi bingkai: aplikasi Konvert apa adanya. */
    public function app()
    {
        if (! is_file(self::BERKAS)) {
            return $this->response->setStatusCode(404)->setBody('Konvert belum di-build. Jalankan deploy/build-konvert.sh.');
        }

        return $this->response
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'private, max-age=300')
            ->setHeader('X-Frame-Options', 'SAMEORIGIN')
            ->setBody((string) file_get_contents(self::BERKAS));
    }
}
