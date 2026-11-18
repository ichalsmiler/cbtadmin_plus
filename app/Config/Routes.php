<?php

use App\Filters\Guard;
use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->group('', ['filter' => Guard::class], static function ($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('dashboard/stats', 'Dashboard::stats');

    $routes->get('login', 'Auth::login');
    $routes->post('login', 'Auth::attempt');
    $routes->get('logout', 'Auth::logout');

    $routes->get('tools', 'Tools::index');
    $routes->post('tools', 'Tools::save');
    $routes->get('tools/gambar/(logo|logo2|ttd)', 'Tools::gambar/$1');
    $routes->post('tools/ujian', 'Tools::ujian');
    $routes->post('tools/password', 'Tools::gantiPassword');
    $routes->post('tools/cron', 'Tools::cron');
    $routes->post('tools/cache', 'Tools::cache');
    $routes->get('tools/test_manage', 'Tools::testManage');
    $routes->post('tools/test_manage/ujian', 'Tools::tambahUjian');
    $routes->post('tools/test_manage/mapel', 'Tools::tambahMapel');
    $routes->post('tools/test_manage/mapel/hapus', 'Tools::hapusMapel');
    $routes->post('tools/test_manage/reset', 'Tools::resetMoodle');
    $routes->post('tools/test_manage/reset/lanjut', 'Tools::resetLanjut');
    $routes->post('tools/test_manage/ujian/ubah', 'Tools::ubahUjian');
    $routes->post('tools/test_manage/hari', 'Tools::tambahHari');
    $routes->post('tools/test_manage/ujian/hapus', 'Tools::hapusUjian');
    $routes->post('tools/test_manage/mapel_json', 'Tools::mapelJson');
    $routes->post('tools/akun/simpan', 'Tools::simpanAkun');
    $routes->post('tools/akun/hapus/(:num)', 'Tools::hapusAkun/$1');
    $routes->get('tools/backup', 'Tools::backup');
    $routes->get('tools/jejak', 'Tools::jejak');
    $routes->get('tools/log_export', 'Tools::logExport');
    $routes->post('tools/bersihkan_sesi', 'Tools::bersihkanSesi');

    $routes->get('pengawas', 'PengawasController::index');
    $routes->post('pengawas/generate', 'PengawasController::generate');
    $routes->post('pengawas/reset', 'PengawasController::reset');
    $routes->post('pengawas/hapus/(:num)', 'PengawasController::hapus/$1');
    $routes->get('pengawas/export', 'PengawasController::export');

    $routes->get('daftar_ruang', 'Ruang::index');
    $routes->post('daftar_ruang/simpan', 'Ruang::save');
    $routes->post('daftar_ruang/hapus/(:num)', 'Ruang::delete/$1');
    $routes->post('daftar_ruang/lokasi', 'Ruang::lokasi');
    $routes->post('daftar_ruang/kode/(:num)', 'Ruang::kode/$1');
    $routes->post('daftar_ruang/tukar/(:num)', 'Ruang::tukar/$1');
    $routes->post('daftar_ruang/kembali', 'Ruang::kembali');
    $routes->get('daftar_ruang/cetak', 'Ruang::cetak');
    $routes->post('daftar_ruang/sesi/simpan', 'Ruang::saveSesi');
    $routes->post('daftar_ruang/sesi/hapus/(:num)', 'Ruang::deleteSesi/$1');

    $routes->get('daftar_peserta/cbtadmin', 'Peserta::cbtadmin');
    $routes->post('daftar_peserta/cbtadmin/simpan', 'Peserta::save');
    $routes->post('daftar_peserta/cbtadmin/hapus/(:num)', 'Peserta::delete/$1');
    $routes->post('daftar_peserta/cbtadmin/massal', 'Peserta::bulk');
    $routes->post('daftar_peserta/cbtadmin/import', 'Peserta::import');
    $routes->post('daftar_peserta/cbtadmin/impor_json', 'Peserta::imporJson');
    $routes->post('daftar_peserta/cbtadmin/reset', 'Peserta::reset');
    $routes->get('daftar_peserta/cbtadmin/template', 'Peserta::template');
    $routes->get('daftar_peserta/cbtadmin/export', 'Peserta::export');
    $routes->get('konvert', 'Konvert::index');
    $routes->get('jadwal_mapel', 'Konvert::jadwal');
    $routes->get('sesi_tingkat', 'SesiTingkat::index');
    $routes->post('sesi_tingkat/simpan', 'SesiTingkat::simpan');
    $routes->post('sesi_tingkat/json', 'SesiTingkat::json');
    $routes->get('konvert/app', 'Konvert::app');
    $routes->get('daftar_peserta/moodle', 'Peserta::moodle');
    $routes->post('daftar_peserta/moodle/tambah', 'Peserta::tambahMoodle');
    $routes->post('daftar_peserta/moodle/kirim_json', 'Peserta::kirimMoodleJson');
    $routes->post('daftar_peserta/moodle/reset_password', 'Peserta::resetPassword');

    $routes->get('status_tes', 'StatusTes::index');
    $routes->get('status_tes/token', 'StatusTes::token');
    $routes->get('token', 'StatusTes::tokenView');
    $routes->post('status_tes/kategori', 'StatusTes::kategori');
    $routes->post('status_tes/sesi', 'StatusTes::sesi');
    $routes->post('status_tes/release', 'StatusTes::release');
    $routes->post('status_tes/token_setting', 'StatusTes::tokenSetting');
    $routes->post('status_tes/reset', 'StatusTes::reset');
    $routes->post('status_tes/waktu', 'StatusTes::waktu');
    $routes->get('peserta_ruang', 'Peserta::ruang');
    $routes->get('status_peserta', 'StatusPeserta::index');
    $routes->post('status_peserta/aksi', 'StatusPeserta::aksi');
    $routes->get('hapus_attempt', 'HapusAttempt::index');
    $routes->post('hapus_attempt/hapus', 'HapusAttempt::hapus');
    $routes->get('status_peserta/login_ganda', 'StatusPeserta::loginGanda');
    $routes->get('status_peserta/(:segment)', 'StatusPeserta::index/$1');

    $routes->get('reset_login', 'ResetLogin::index');
    $routes->post('reset_login/reset', 'ResetLogin::reset');
    $routes->get('noattempt_check', 'NoAttempt::index');
    $routes->get('noattempt_check/export', 'NoAttempt::export');

    $routes->get('dokumen/cetak_doc', 'Dokumen::cetak');
    $routes->get('dokumen/cetak_doc/kartu', 'Dokumen::kartu');
    $routes->get('dokumen/cetak_doc/daftar_hadir', 'Dokumen::daftarHadir');
    $routes->get('kehadiran', 'Kehadiran::index');
    $routes->post('kehadiran/simpan', 'Kehadiran::simpan');
    $routes->get('blokir_siswa', 'BlokirSiswa::index');
    $routes->post('blokir_siswa/blokir', 'BlokirSiswa::blokir');
    $routes->post('blokir_siswa/buka', 'BlokirSiswa::buka');
    $routes->get('dokumen/absensi', 'Kehadiran::rekap');
    $routes->post('dokumen/absensi/buka', 'Kehadiran::buka');
    $routes->get('dokumen/absensi/export', 'Kehadiran::export');
    $routes->get('dokumen/laporan_kehadiran', 'Dokumen::kehadiran');
    $routes->get('dokumen/laporan_kehadiran/export', 'Dokumen::kehadiranExport');
    $routes->get('dokumen/laporan_kehadiran/berita_acara', 'Dokumen::beritaAcara');

    $routes->get('hasil_ujian', 'HasilUjian::index');
    $routes->get('hasil_ujian/export', 'HasilUjian::export');
    $routes->get('hasil_ujian/pdf', 'HasilUjian::pdf');
    $routes->get('hasil_ujian/export_soal', 'HasilUjian::exportSoal');
    $routes->get('hasil_ujian/export_analisis', 'HasilUjian::exportAnalisis');
});
