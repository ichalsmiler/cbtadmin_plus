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
            'title'  => 'Konvert User',
            'ada'    => is_file(self::BERKAS),
            'bagian' => 'user',
        ]);
    }

    /** Menu Jadwal: aplikasi yang sama, hanya tab Jadwal (→ mapel) dan Kamus Mapel. */
    public function jadwal(): string
    {
        return $this->render('konvert/index', [
            'title'  => 'Jadwal',
            'ada'    => is_file(self::BERKAS),
            'bagian' => 'jadwal',
        ]);
    }

    /**
     * Isi bingkai: aplikasi Konvert, ditambah meta alamat panel + token CSRF supaya tab Jadwal → Mapel bisa
     * langsung membuat mapel di Moodle (Tools::mapelJson). Tidak di-cache karena tokennya berganti.
     */
    public function app()
    {
        if (! is_file(self::BERKAS)) {
            return $this->response->setStatusCode(404)->setBody('Konvert belum di-build. Jalankan deploy/build-konvert.sh.');
        }
        $meta = '<meta name="cbt-mapel-url" content="' . esc(site_url('tools/test_manage/mapel_json'), 'attr') . '">'
            . '<meta name="cbt-kategori-url" content="' . esc(site_url('tools/test_manage'), 'attr') . '">'
            . '<meta name="cbt-peserta-url" content="' . esc(site_url('daftar_peserta/cbtadmin/impor_json'), 'attr') . '">'
            . '<meta name="cbt-peserta-halaman" content="' . esc(site_url('daftar_peserta/cbtadmin'), 'attr') . '">'
            . '<meta name="cbt-moodle-url" content="' . esc(site_url('daftar_peserta/moodle/kirim_json'), 'attr') . '">'
            . '<meta name="cbt-moodle-halaman" content="' . esc(site_url('daftar_peserta/moodle'), 'attr') . '">'
            . '<meta name="cbt-sesi-url" content="' . esc(site_url('sesi_tingkat/json'), 'attr') . '">'
            . '<meta name="cbt-sesi-halaman" content="' . esc(site_url('sesi_tingkat'), 'attr') . '">'
            // Pengaturan sesi per tingkatan dari menu Sesi (kosong bila belum pernah disimpan).
            . '<meta name="cbt-sesi-tingkat" content="' . esc(json_encode(SesiTingkat::tersimpan() ?? new \stdClass()), 'attr') . '">'
            . '<meta name="cbt-csrf-header" content="' . esc(config('Security')->headerName, 'attr') . '">'
            . '<meta name="cbt-csrf" content="' . esc(csrf_hash(), 'attr') . '">';
        $html = preg_replace('~<head>~i', '<head>' . $meta, (string) file_get_contents(self::BERKAS), 1);

        return $this->response
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Frame-Options', 'SAMEORIGIN')
            ->setBody($html);
    }
}
