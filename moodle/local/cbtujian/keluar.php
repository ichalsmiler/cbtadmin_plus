<?php
// Anti Newtab: catat siswa keluar dari halaman ujian (pindah aplikasi/tab), atau baca jumlahnya (catat=0).
// Dipanggil JS halaman attempt (sendBeacon saat keluar, fetch saat kembali). Jawaban: {n, maks, kunci}.
define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');

require_login(null, false);
require_sesskey();

$attempt = required_param('attempt', PARAM_INT);
$catat   = optional_param('catat', 0, PARAM_BOOL);

echo json_encode(\local_cbtujian\callbacks::keluar($attempt, (bool) $catat));
