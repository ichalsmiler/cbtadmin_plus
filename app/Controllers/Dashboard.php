<?php

namespace App\Controllers;

use App\Libraries\ActiveTest;
use App\Libraries\Moodle;
use App\Libraries\PantauRuang;
use App\Libraries\Pengawas;
use App\Libraries\Settings;
use App\Libraries\SysInfo;

class Dashboard extends PanelController
{
    public function index(): string
    {
        // Kartu token di atas dashboard: besar untuk pengawas (terkunci sampai absensi ruangnya hari ini
        // disimpan), ringkas untuk admin (dengan tautan ke Setting Ujian).
        $mapel = '';
        try {
            $mapel = ActiveTest::mapelAktif();
        } catch (\Throwable $e) {
            log_message('error', 'Token dashboard: ' . $e->getMessage());
        }
        // Pengawas: token disembunyikan selama sesi belum diaktifkan, lalu sampai absensi ruangnya disimpan.
        $sesiBelum = ActiveTest::sesiBelumAktifUntuk($this->myRuang());
        $kunci     = $sesiBelum || ActiveTest::terkunciUntuk($this->myRuang());
        $token     = [
            'mapel'     => $mapel,
            'enabled'   => ActiveTest::tokenEnabled(),
            'menit'     => ActiveTest::tokenMinutes(),
            'token'     => $kunci ? '' : ActiveTest::token(),
            'next'      => $kunci ? 0 : ActiveTest::nextReleaseAt(),
            'kunci'     => $kunci,
            'sesiBelum' => $sesiBelum,
            'ringkas' => $this->isAdmin(),
            'sesiNonaktif' => ActiveTest::sesiId() === 0,
        ];

        return $this->render('dashboard/index', [
            'token'        => $token,
            'title'        => 'Dashboard',
            'isAdmin'      => $this->isAdmin(),
            'myRuang'      => $this->myRuang(),
            'sesiAktif'    => ActiveTest::sesiAktif(),
            'stats'        => $this->collect(),
            'namaKegiatan' => Settings::get('nama_kegiatan'),
            'npsn'         => Settings::get('npsn', (string) env('cbt.npsn', '')),
            'serverIp'     => $this->request->getServer('SERVER_ADDR') ?: '-',
            'webminUrl'    => Settings::get('webmin_url', (string) env('cbt.webminUrl', '')),
            'refresh'      => max(5, (int) Settings::get('refresh_interval', '30')),
        ]);
    }

    public function stats()
    {
        return $this->response->setJSON($this->collect());
    }

    private function isAdmin(): bool
    {
        return session()->get('admin_role') === 'admin';
    }

    private function collect(): array
    {
        $data  = ['ruang' => null, 'users' => null, 'aktif' => null, 'belumLogin' => null, 'belumMulai' => null, 'belum' => null, 'sudah' => null, 'pantau' => null, 'moodleError' => false];
        $ruang = $this->myRuang();

        try {
            if ($ruang !== null) {
                // Pengawas ruang: sesi yang sedang ujian dan jumlah siswa ruang ini di sesi itu
                // (tanpa sesi aktif: semua siswa di ruang).
                $sesi          = ActiveTest::sesiAktif();
                $data['ruang'] = $sesi !== null ? 'Sesi ' . $sesi['nama'] : '–';
                $data['users'] = count(Pengawas::usernamesInRuang($ruang, $sesi['id'] ?? null));
            } else {
                $data['ruang'] = db_connect()->table('ruang')->countAllResults();
            }
        } catch (\Throwable $e) {
            log_message('error', 'Dashboard ruang: ' . $e->getMessage());
        }

        try {
            // Hitungan submit mengikuti tes aktif (Setting Ujian); tanpa tes aktif, semua quiz.
            $catId   = ActiveTest::categoryId() ?: null;
            $userIds = $ruang !== null ? Pengawas::moodleIdsInRuang($ruang, ActiveTest::sesiId() ?: null) : null;
            if ($ruang === null) {
                $minutes       = max(1, (int) Settings::get('active_minutes', '5'));
                $data['users'] = Moodle::totalUsers();
                $data['aktif'] = Moodle::activeUsers($minutes);
            }
            if ($ruang !== null) {
                // Pengawas: tiap siswa masuk satu kelompok (belum login → belum mulai → mengerjakan → sudah submit).
                $pantau          = PantauRuang::get($ruang, ActiveTest::sesiId() ?: null);
                $data['pantau']  = $pantau;
                $data['belumLogin'] = count($pantau['belumLogin']);
                $data['belumMulai'] = count($pantau['belumMulai']);
                $data['belum']   = count($pantau['mengerjakan']);
                $data['sudah']   = count($pantau['sudah']);
            } else {
                $data['belum'] = Moodle::unsubmittedAttempts($catId, $userIds);
                $data['sudah'] = Moodle::finishedAttempts($catId, $userIds);
            }
        } catch (\Throwable $e) {
            $data['moodleError'] = true;
            log_message('error', 'Dashboard Moodle: ' . $e->getMessage());
        }

        // Informasi server hanya untuk administrator.
        return $this->isAdmin() ? $data + ['sys' => SysInfo::get()] : $data;
    }
}
