<?php

use App\Filters\Guard;
use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->group('', ['filter' => Guard::class], static function ($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('dashboard/stats', 'Dashboard::stats');
    $routes->get('dashboard/sesi', 'Dashboard::sesi');

    $routes->get('login', 'Auth::login');
    $routes->get('simulasi', 'SimulasiPublik::index');
    $routes->post('tools/simulasi_publik', 'SimulasiPublik::setel');
    $routes->post('login', 'Auth::attempt');
    $routes->get('logout', 'Auth::logout');

    $routes->get('tools', 'Tools::awal');
    $routes->get('tools/(moodle|cbtadmin|lainnya)', 'Tools::index/$1');
    $routes->post('tools', 'Tools::save');
    $routes->get('tools/gambar/(logo|logo2|ttd|logologin|favicon)', 'Tools::gambar/$1');
    $routes->post('tools/ujian', 'Tools::ujian');
    $routes->post('tools/password', 'Tools::gantiPassword');
    $routes->post('tools/cron', 'Tools::cron');
    $routes->post('tools/cache', 'Tools::cache');
    $routes->get('tools/test_manage', 'Tools::testManage');
    $routes->post('tools/test_manage/ujian', 'Tools::tambahUjian');
    $routes->post('tools/test_manage/mapel', 'Tools::tambahMapel');
    $routes->post('tools/test_manage/mapel/hapus', 'Tools::hapusMapel');
    $routes->post('tools/test_manage/mapel/pindah', 'Tools::pindahMapel');
    $routes->post('tools/test_manage/mapel/status', 'Tools::statusMapel');
    $routes->post('tools/test_manage/reset', 'Tools::resetMoodle');
    $routes->post('tools/test_manage/durasi', 'Tools::durasi');
    $routes->post('tools/test_manage/reset/lanjut', 'Tools::resetLanjut');
    $routes->post('tools/test_manage/ujian/ubah', 'Tools::ubahUjian');
    $routes->post('tools/test_manage/hari', 'Tools::tambahHari');
    $routes->post('tools/test_manage/ujian/hapus', 'Tools::hapusUjian');
    $routes->post('tools/test_manage/mapel_json', 'Tools::mapelJson');
    $routes->post('tools/akun/simpan', 'Tools::simpanAkun');
    $routes->post('tools/akun/hapus/(:num)', 'Tools::hapusAkun/$1');
    $routes->get('tools/jejak', 'Tools::jejak');
    $routes->get('tools/log_export', 'Tools::logExport');

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

    $routes->get('daftar_peserta/cbtadmin', 'Peserta::cbtadmin');
    $routes->post('daftar_peserta/cbtadmin/simpan', 'Peserta::save');
    $routes->post('daftar_peserta/cbtadmin/hapus/(:num)', 'Peserta::delete/$1');
    $routes->post('daftar_peserta/cbtadmin/massal', 'Peserta::bulk');
    $routes->post('daftar_peserta/cbtadmin/import', 'Peserta::import');
    $routes->post('daftar_peserta/cbtadmin/impor_json', 'Peserta::imporJson');
    $routes->post('daftar_peserta/cbtadmin/reset', 'Peserta::reset');
    $routes->get('daftar_peserta/cbtadmin/template', 'Peserta::template');
    $routes->get('konvert', 'Konvert::index');
    $routes->get('jadwal_mapel', 'Konvert::jadwal');
    // Kirim Soal guru (tanpa login, Guard::terbuka) + dashboard Kiriman Soal.
    $routes->get('kirim_soal', 'KirimSoal::index');
    $routes->get('kirim_soal/status', 'KirimSoal::statusPublik');
    $routes->get('kirim_soal/api/guru', 'KirimSoal::guru');
    $routes->match(['get', 'post'], 'kirim_soal/api/pengaturan', 'KirimSoal::pengaturan');
    $routes->get('kirim_soal/api/kirim/status', 'KirimSoal::status');
    $routes->post('kirim_soal/api/kirim', 'KirimSoal::kirim');
    $routes->get('kirim_soal/(:segment)', 'KirimSoal::berkas/$1');
    $routes->get('kirim_soal/(:segment)/(:segment)', 'KirimSoal::berkas/$1/$2');
    $routes->get('kiriman_soal', 'KirimanSoal::index');
    $routes->post('kiriman_soal/aktifkan', 'KirimanSoal::aktifkan');
    $routes->post('kiriman_soal/tutup', 'KirimanSoal::tutup');
    $routes->post('kiriman_soal/ta', 'KirimanSoal::ta');
    $routes->post('kiriman_soal/peta/hapus', 'KirimanSoal::hapusPeta');
    $routes->get('kiriman_soal/unduh/(:num)/(rtf|asli)', 'KirimanSoal::unduh/$1/$2');
    $routes->get('kiriman_soal/zip', 'KirimanSoal::zip');
    $routes->get('kiriman_soal/bandingkan/(:num)', 'KirimanSoal::bandingkan/$1');
    $routes->get('kiriman_soal/folder', 'KirimanSoal::folder');
    $routes->post('kiriman_soal/hapus/(:num)', 'KirimanSoal::hapus/$1');
    $routes->get('soal', 'Soal::index');
    $routes->get('soal/(edit|review)', 'Soal::index/$1');
    $routes->post('soal/periksa', 'Soal::periksa');
    $routes->post('soal/ai_setelan', 'Soal::aiSetelan');
    $routes->post('soal/ai_cek', 'Soal::aiCek');
    $routes->post('soal/kirim', 'Soal::kirim');
    $routes->get('soal/baca', 'Soal::baca');
    $routes->post('soal/ubah', 'Soal::ubah');
    $routes->get('pengawas_nama', 'PengawasNama::index');
    $routes->post('pengawas_nama', 'PengawasNama::simpan');
    $routes->get('acak_soal', 'AcakSoal::index');
    $routes->post('acak_soal/simpan', 'AcakSoal::simpan');
    // Cek Soal: menu admin/kurikulum + halaman guru /cek/<token> (tanpa login, lihat Guard).
    $routes->get('cek_soal', 'CekSoalPanel::index');
    $routes->post('cek_soal/buat', 'CekSoalPanel::buat');
    $routes->post('cek_soal/aksi/(:num)', 'CekSoalPanel::aksi/$1');
    $routes->get('cek_soal/hasil/(:num)', 'CekSoalPanel::hasil/$1');
    $routes->post('cek_soal/setelan', 'CekSoalPanel::setelan');
    $routes->get('cek_soal/guru/template', 'CekSoalPanel::template');
    $routes->post('cek_soal/guru/unggah', 'CekSoalPanel::unggah');
    $routes->post('cek_soal/guru/simpan', 'CekSoalPanel::simpanGuru');
    $routes->post('cek_soal/guru/hapus/(:num)', 'CekSoalPanel::hapusGuru/$1');
    // Pesan lama yang alamatnya sempat berisi ".../login/" (sebelum CekSoal::asal) tetap terbuka.
    $routes->addRedirect('login/cek/([0-9a-f]{32})', 'cek/$1');
    $routes->get('cek/([0-9a-f]{32})', 'CekGuru::index/$1');
    $routes->post('cek/([0-9a-f]{32})/buka', 'CekGuru::buka/$1');
    $routes->post('cek/([0-9a-f]{32})/kirim', 'CekGuru::kirim/$1');
    $routes->get('sesi_tingkat', 'SesiTingkat::index');
    $routes->get('server_cadangan', 'ServerCadanganPanel::index');
    $routes->post('server_cadangan/simpan', 'ServerCadanganPanel::simpan');
    $routes->get('server_cadangan/unduh', 'ServerCadanganPanel::unduh');
    $routes->post('server_cadangan/pindah', 'ServerCadanganPanel::pindah');
    $routes->post('server_cadangan/kembalikan', 'ServerCadanganPanel::kembalikan');
    $routes->get('server_cadangan/log', 'ServerCadanganPanel::log');
    $routes->post('server_cadangan/telegram', 'ServerCadanganPanel::telegram');
    $routes->get('server_cadangan/dns','ServerCadanganPanel::dns');
    $routes->post('server_cadangan/sinkron', 'ServerCadanganPanel::sinkron');
    $routes->post('server_cadangan/jadi_cadangan', 'ServerCadanganPanel::jadiCadangan');
    $routes->post('sesi_tingkat/simpan', 'SesiTingkat::simpan');
    $routes->post('sesi_tingkat/json', 'SesiTingkat::json');
    $routes->get('konvert/app', 'Konvert::app');
    $routes->get('daftar_peserta/moodle', 'Peserta::moodle');
    $routes->post('daftar_peserta/moodle/tambah', 'Peserta::tambahMoodle');
    $routes->post('daftar_peserta/moodle/kirim_json', 'Peserta::kirimMoodleJson');
    $routes->post('daftar_peserta/moodle/reset_password', 'Peserta::resetPassword');
    $routes->post('daftar_peserta/moodle/mapel_pilihan', 'Peserta::mapelPilihan');

    $routes->get('status_tes', 'StatusTes::index');
    $routes->get('token_ujian', 'StatusTes::halamanToken');
    $routes->get('status_tes/token', 'StatusTes::token');
    $routes->get('token', 'StatusTes::tokenView');
    $routes->post('status_tes/kategori', 'StatusTes::kategori');
    $routes->post('status_tes/sesi', 'StatusTes::sesi');
    $routes->post('status_tes/release', 'StatusTes::release');
    $routes->post('status_tes/token_setting', 'StatusTes::tokenSetting');
    $routes->get('peserta_ruang', 'Peserta::ruang');
    $routes->get('status_peserta', 'StatusPeserta::index');
    $routes->post('status_peserta/aksi', 'StatusPeserta::aksi');
    $routes->get('hapus_attempt', 'HapusAttempt::index');
    $routes->post('hapus_attempt/hapus', 'HapusAttempt::hapus');
    $routes->get('status_peserta/login_ganda', 'StatusPeserta::loginGanda');
    $routes->get('status_peserta/export_belum', 'StatusPeserta::exportBelum');
    $routes->get('status_peserta/(:segment)', 'StatusPeserta::index/$1');

    $routes->get('reset_login', 'ResetLogin::index');
    $routes->post('reset_login/reset', 'ResetLogin::reset');
    $routes->get('lock_peserta', 'LockPeserta::index');
    $routes->post('lock_peserta/buka', 'LockPeserta::buka');

    $routes->get('dokumen/cetak_doc', 'Dokumen::cetak');
    $routes->get('dokumen/cetak_doc/kartu', 'Dokumen::kartu');
    $routes->get('dokumen/cetak_doc/kartu_zip', 'Dokumen::kartuZip');
    $routes->get('dokumen/cetak_doc/zip_status', 'Dokumen::zipStatus');
    $routes->get('dokumen/rekap_simulasi', 'RekapSimulasi::index');
    $routes->get('dokumen/rekap_simulasi/export', 'RekapSimulasi::export');
    $routes->get('dokumen/rekap_simulasi/belum', 'RekapSimulasi::belum');
    $routes->get('dokumen/cetak_doc/daftar_hadir', 'Dokumen::daftarHadir');
    $routes->get('kehadiran', 'Kehadiran::index');
    $routes->post('kehadiran/simpan', 'Kehadiran::simpan');
    $routes->get('blokir_siswa', 'BlokirSiswa::index');
    $routes->post('blokir_siswa/blokir', 'BlokirSiswa::blokir');
    $routes->post('blokir_siswa/buka', 'BlokirSiswa::buka');
    // Laporan Siswa: form siswa /lapor (tanpa login, tautan bertanda tangan dari Moodle) + menu Proktor.
    $routes->get('lapor', 'Lapor::index');
    $routes->post('lapor/kirim', 'Lapor::kirim');
    $routes->get('laporan_siswa', 'LaporanSiswaPanel::index');
    $routes->get('laporan_siswa/jumlah', 'LaporanSiswaPanel::jumlah');
    $routes->get('laporan_siswa/foto/(:num)', 'LaporanSiswaPanel::foto/$1');
    $routes->post('laporan_siswa/status', 'LaporanSiswaPanel::status');
    $routes->post('laporan_siswa/setelan', 'LaporanSiswaPanel::setelan');
    $routes->get('dokumen/absensi', 'Kehadiran::rekap');
    $routes->post('dokumen/absensi/buka', 'Kehadiran::buka');
    $routes->get('dokumen/absensi/export', 'Kehadiran::export');
    $routes->get('dokumen/laporan_kehadiran', 'Dokumen::kehadiran');
    $routes->get('dokumen/laporan_kehadiran/export', 'Dokumen::kehadiranExport');
    $routes->get('dokumen/laporan_kehadiran/berita_acara', 'Dokumen::beritaAcara');

    $routes->get('hasil_ujian', 'HasilUjian::index');
    $routes->get('hasil_ujian/export', 'HasilUjian::export');
    $routes->get('hasil_ujian/pdf', 'HasilUjian::pdf');
    $routes->get('hasil_ujian/zip', 'HasilUjian::zip');
    $routes->get('hasil_ujian/export_soal', 'HasilUjian::exportSoal');
    $routes->get('hasil_ujian/export_analisis', 'HasilUjian::exportAnalisis');
});
