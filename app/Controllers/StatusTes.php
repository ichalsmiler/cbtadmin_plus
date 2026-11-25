<?php

namespace App\Controllers;

use App\Libraries\ActiveTest;
use App\Libraries\ActivityLog;
use App\Libraries\Moodle;
use App\Libraries\MoodleBridge;
use App\Libraries\Settings;

/**
 * Setting Ujian: pilih kategori kursus Moodle sebagai tes aktif, atur mata pelajaran
 * yang dibuka, dan kelola token bersama untuk semua quiz di kategori itu.
 */
class StatusTes extends PanelController
{
    public function index(): string
    {
        $categories = [];
        $courses    = [];
        $error      = false;
        $category   = null;  // tes aktif (tersimpan)
        $shown      = null;  // kategori yang mapelnya sedang ditampilkan
        $catId      = ActiveTest::categoryId();
        $pick       = (int) $this->request->getGet('kat');

        try {
            $categories = Moodle::categories();
            foreach ($categories as $c) {
                if ((int) $c['id'] === $catId) {
                    $category = $c;
                }
                if ((int) $c['id'] === $pick) {
                    $shown = $c;
                }
            }
            $shown ??= $category;
            if ($shown) {
                $courses = Moodle::coursesInCategory((int) $shown['id']);
            }
        } catch (\Throwable $e) {
            $error = true;
            log_message('error', 'Setting Ujian: ' . $e->getMessage());
        }

        return $this->render('status/tes', [
            'title'        => 'Setting Ujian',
            'categories'   => $categories,
            'category'     => $category,
            'shown'        => $shown,
            'courses'      => $courses,
            'moodleError'  => $error,
            'sesiList'     => ActiveTest::sesiPilihan(),
            'sesiAktif'    => ActiveTest::sesiId(),
            // Pilihan sesi ikut terbawa saat ganti tes (halaman dimuat ulang dengan ?kat=&sesi=).
            'sesiPilih'    => $this->request->getGet('sesi') !== null ? (int) $this->request->getGet('sesi') : ActiveTest::sesiId(),
        ]);
    }

    /** Status Ujian → Token: token tes aktif, Release, dan pengaturan token (admin). */
    public function halamanToken(): string
    {
        $nama = '';
        try {
            foreach (Moodle::categories() as $c) {
                if ((int) $c['id'] === ActiveTest::categoryId()) {
                    $nama = (string) $c['name'];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Token: ' . $e->getMessage());
        }

        return $this->render('status/token', [
            'title'        => 'Token',
            'catId'        => ActiveTest::categoryId(),
            'tesAktif'     => $nama,
            'tokenEnabled' => ActiveTest::tokenEnabled(),
            'tokenMinutes' => ActiveTest::tokenMinutes(),
            'token'        => ActiveTest::token(),
            'nextRelease'  => ActiveTest::nextReleaseAt(),
            'sesiAktif'    => ActiveTest::sesiId(),
        ]);
    }

    /** Alamat lama halaman Token pengawas: token kini tampil di Dashboard. */
    public function tokenView()
    {
        return redirect()->to(site_url('/'));
    }

    /** Token yang berlaku, untuk diperbarui halaman tanpa muat ulang. */
    public function token()
    {
        $sesiBelum = ActiveTest::sesiBelumAktifUntuk($this->myRuang());
        if ($sesiBelum || ActiveTest::terkunciUntuk($this->myRuang())) {
            return $this->response->setJSON(['token' => '', 'enabled' => true, 'kunci' => true, 'sesiBelum' => $sesiBelum, 'next' => 0, 'now' => time()]);
        }

        return $this->response->setJSON([
            'token'   => ActiveTest::token(),
            'enabled' => ActiveTest::tokenEnabled(),
            'next'    => ActiveTest::nextReleaseAt(),
            'now'     => time(),
        ]);
    }

    /**
     * Simpan tes aktif (kategori) beserta mapel yang dibuka, lalu pasang token di quiz-quiznya.
     */
    public function kategori()
    {
        $catId  = (int) $this->request->getPost('kat_id');
        $sesiId = (int) $this->request->getPost('sesi_id');
        $back   = site_url('status_tes') . '?kat=' . $catId . '&sesi=' . $sesiId;
        $name   = null;

        // Ujian hanya bisa diaktifkan untuk satu sesi yang dipilih.
        $sesi = null;
        foreach (ActiveTest::sesiPilihan() as $s) {
            if ($s['id'] === $sesiId) {
                $sesi = $s;
            }
        }
        if ($sesi === null) {
            return redirect()->to($back)->with('error', 'Pilih sesi terlebih dahulu sebelum mengaktifkan ujian.');
        }
        try {
            foreach (Moodle::categories() as $c) {
                if ((int) $c['id'] === $catId) {
                    $name = $c['name'];
                }
            }
        } catch (\Throwable $e) {
            return redirect()->to(site_url('status_tes'))->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        if ($name === null) {
            return redirect()->to(site_url('status_tes'))->with('error', 'Pilih tes (kategori) terlebih dahulu.');
        }
        if (ActiveTest::isNonaktif($name)) {
            return redirect()->to($back)->with('error', ActiveTest::NAMA_NONAKTIF . ' tidak berisi ujian dan dipilih otomatis saat sesi dinonaktifkan. Pilih tes (hari ujian) lain.');
        }

        // Hanya mapel yang dicentang di kategori ini yang tampil di Moodle; semua course lain,
        // termasuk di kategori lain, disembunyikan dari peserta.
        $chosen = array_map('intval', (array) $this->request->getPost('ids'));
        if ($chosen === [] && Moodle::coursesInCategory($catId) !== []) {
            return redirect()->to($back)->with('error', 'Harus ada minimal 1 mata pelajaran yang aktif.');
        }
        try {
            // Kategori lain disembunyikan dulu (Moodle ikut menyembunyikan course di dalamnya),
            // lalu status tampil tiap course disamakan dengan pilihan mapel.
            $catChanged = array_filter(
                MoodleBridge::call('category_visibility', ['active' => $catId]),
                static fn ($item) => ($item['status'] ?? '') === 'disembunyikan'
            );
            $changes = [];
            $opened  = [];
            $hidden  = 0;
            $courses = Moodle::coursesInCategory($catId);
            $inCat   = array_map('intval', array_column($courses, 'id'));
            foreach (Moodle::allCourses() as $c) {
                $visible = in_array((int) $c['id'], $inCat, true) && in_array((int) $c['id'], $chosen, true);
                if ($visible) {
                    $opened[] = $c['shortname'];
                }
                if ((bool) (int) $c['visible'] !== $visible) {
                    $changes[] = ['id' => (int) $c['id'], 'visible' => $visible];
                    $hidden += $visible ? 0 : 1;
                }
            }
            if ($courses !== [] && $opened === []) {
                return redirect()->to($back)->with('error', 'Harus ada minimal 1 mata pelajaran yang aktif.');
            }
            $failed = $changes === [] ? [] : array_filter(
                MoodleBridge::call('course_visibility', ['courses' => $changes]),
                static fn ($item) => isset($item['error'])
            );
        } catch (\Throwable $e) {
            return redirect()->to($back)->with('error', 'Gagal memperbarui mata pelajaran: ' . $e->getMessage());
        }
        if ($failed !== []) {
            return redirect()->to($back)->with('error', count($failed) . ' mata pelajaran gagal diubah: ' . reset($failed)['error']);
        }

        Settings::set('tes_kategori', (string) $catId);
        Settings::set('sesi_aktif', (string) $sesiId);
        // Pesan ringkas: course lain disembunyikan, quiz lain dikunci, dan token dipasang tetap dikerjakan tanpa disebut.
        $message = 'Tes aktif: ' . $name . ' untuk ' . $sesi['label'] . ', ' . count($opened) . ' dari ' . count($courses) . ' mapel dibuka.';
        try {
            // Quiz tes lain (termasuk tes yang sebelumnya aktif) kembali ke password bawaan.
            ActiveTest::applyDefault();
            if (ActiveTest::tokenEnabled()) {
                if (ActiveTest::token() === '') {
                    ActiveTest::release();
                } else {
                    ActiveTest::sync();
                }
            }
        } catch (\Throwable $e) {
            $message .= ' Tetapi token gagal dipasang: ' . $e->getMessage();
        }
        ActivityLog::add('tes_aktif', $name . ' (kategori ' . $catId . '), ' . $sesi['label'] . '; mapel aktif: ' . implode(', ', $opened));

        return redirect()->to(site_url('status_tes'))->with('success', $message);
    }

    /**
     * Aktifkan sesi terpilih atau nonaktifkan sesi (semua sesi tertutup): tanpa sesi aktif semua quiz dikunci
     * password bawaan, token pengawas disembunyikan, dan pengawas tidak melihat daftar siswa.
     * Tes aktif pindah ke SESI NONAKTIF: semua mapel tersembunyi, jadi siswa yang sedang mengerjakan langsung terhenti
     * (jawaban tersimpan tetap ada; attempt dikirim otomatis oleh cron Moodle saat waktunya habis).
     */
    /** Tombol Laporkan di Moodle ikut sesi aktif (LaporanSiswa); gagal diam-diam supaya aktivasi sesi tetap jalan. */
    private static function sinkronLapor(): void
    {
        if (! \App\Libraries\LaporanSiswa::aktif()) {
            return;  // fitur mati: tombol sudah dimatikan saat sakelar diubah
        }
        try {
            \App\Libraries\LaporanSiswa::sinkronMoodle();
        } catch (\Throwable $e) {
            log_message('error', 'Laporan Siswa sinkron Moodle: ' . $e->getMessage());
        }
    }

    public function sesi()
    {
        $aksi = (string) $this->request->getPost('aksi');
        if ($aksi === 'nonaktifkan') {
            $lama = ActiveTest::sesiAktif();
            Settings::set('sesi_aktif', '');
            Settings::set('token_nilai', '');  // token lama tidak berlaku; sesi berikutnya mendapat token baru
            $message = 'Sesi ujian dinonaktifkan; semua sesi tertutup dan token dihapus.';
            // Tes aktif pindah ke kategori SESI NONAKTIF (tanpa ujian): kategori lain beserta mapelnya disembunyikan.
            try {
                $nonaktif = ActiveTest::kategoriNonaktif();
                Settings::set('tes_kategori', (string) $nonaktif);
                MoodleBridge::call('category_visibility', ['active' => $nonaktif]);
                $message .= ' Tes aktif: ' . ActiveTest::NAMA_NONAKTIF . ' (tanpa ujian); semua kategori ujian disembunyikan.';
            } catch (\Throwable $e) {
                $message .= ' Tetapi kategori ' . ActiveTest::NAMA_NONAKTIF . ' gagal dipilih: ' . $e->getMessage();
            }
            try {
                $n = ActiveTest::applyDefault();
                $message .= $n > 0 ? ' ' . $n . ' quiz dikunci dengan password bawaan.' : ' Semua quiz sudah berpassword bawaan.';
            } catch (\Throwable $e) {
                $message .= ' Tetapi penguncian quiz gagal: ' . $e->getMessage();
            }
            ActivityLog::add('sesi_nonaktif', $lama['label'] ?? '-');
            self::sinkronLapor();

            return redirect()->to(site_url('status_tes'))->with('success', $message . ' Siswa yang sedang mengerjakan langsung terhenti; jawaban yang sudah tersimpan tetap ada dan dikirim otomatis saat waktunya habis.');
        }

        $sesiId = (int) $this->request->getPost('sesi_id');
        $sesi   = null;
        foreach (ActiveTest::sesiPilihan() as $s) {
            if ($s['id'] === $sesiId) {
                $sesi = $s;
            }
        }
        if ($sesi === null) {
            return redirect()->to(site_url('status_tes'))->with('error', 'Pilih sesi yang akan diaktifkan.');
        }
        $tesAktif = null;
        try {
            foreach (Moodle::categories() as $c) {
                if ((int) $c['id'] === ActiveTest::categoryId()) {
                    $tesAktif = $c;
                }
            }
        } catch (\Throwable $e) {
            return redirect()->to(site_url('status_tes'))->with('error', 'Database Moodle tidak bisa dibaca.');
        }
        if ($tesAktif === null || ActiveTest::isNonaktif($tesAktif['name'])) {
            return redirect()->to(site_url('status_tes') . '?sesi=' . $sesiId)->with('error', 'Belum ada tes aktif. Pilih tes (hari ujian) dan mapel, lalu klik Simpan.');
        }
        Settings::set('sesi_aktif', (string) $sesiId);
        $message = $sesi['label'] . ' diaktifkan.';
        try {
            if (ActiveTest::tokenEnabled()) {
                $message .= ActiveTest::token() === '' ? ' Token: ' . ActiveTest::release() . '.' : ' Token ' . ActiveTest::token() . ' dipasang (' . ActiveTest::sync() . ' quiz diperbarui).';
            }
        } catch (\Throwable $e) {
            $message .= ' Tetapi token gagal dipasang: ' . $e->getMessage();
        }
        ActivityLog::add('sesi_aktif', $sesi['label']);
        self::sinkronLapor();

        return redirect()->to(site_url('status_tes'))->with('success', $message);
    }

    /** Release token baru sekarang. */
    public function release()
    {
        if (! ActiveTest::tokenEnabled()) {
            return redirect()->to(site_url('token_ujian'))->with('error', 'Token sedang dinonaktifkan. Aktifkan lewat tombol Setting Token.');
        }
        if (ActiveTest::categoryId() === 0) {
            return redirect()->to(site_url('token_ujian'))->with('error', 'Pilih dan simpan tes terlebih dahulu.');
        }
        if (ActiveTest::sesiId() === 0) {
            return redirect()->to(site_url('token_ujian'))->with('error', 'Sesi nonaktif: token tidak bisa direlease. Aktifkan sesi dulu.');
        }
        try {
            $token = ActiveTest::release();
        } catch (\Throwable $e) {
            return redirect()->to(site_url('token_ujian'))->with('error', 'Token gagal direlease: ' . $e->getMessage());
        }
        ActivityLog::add('release_token', $token);

        return redirect()->to(site_url('token_ujian'))->with('success', 'Token direlease: ' . $token);
    }

    /** Pengaturan token: aktif/nonaktif dan jarak release otomatis. */
    public function tokenSetting()
    {
        $wasEnabled = ActiveTest::tokenEnabled();
        $enabled    = (bool) $this->request->getPost('aktif');
        $minutes    = (int) $this->request->getPost('menit');
        if ($minutes < 1 || $minutes > 1440) {
            return redirect()->to(site_url('token_ujian'))->with('error', 'Timer token harus 1–1440 menit.');
        }

        Settings::set('token_menit', (string) $minutes);
        Settings::set('token_aktif', $enabled ? '1' : '0');
        $message = 'Pengaturan token disimpan.';
        try {
            if ($wasEnabled && ! $enabled) {
                // Token dimatikan: lepas password dari semua quiz kategori aktif.
                ActiveTest::apply('');
                Settings::set('token_nilai', '');
                $message .= ' Token dinonaktifkan dan dilepas dari semua quiz.';
            } elseif ($enabled && ActiveTest::categoryId() > 0 && ActiveTest::sesiId() !== 0 && ActiveTest::token() === '') {
                $message .= ' Token: ' . ActiveTest::release() . '.';
            }
        } catch (\Throwable $e) {
            $message .= ' Tetapi quiz gagal diperbarui: ' . $e->getMessage();
        }
        ActivityLog::add('setting_token', ($enabled ? 'aktif' : 'nonaktif') . ', ' . $minutes . ' menit');

        return redirect()->to(site_url('token_ujian'))->with('success', $message);
    }
}
