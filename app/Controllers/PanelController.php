<?php

namespace App\Controllers;

use App\Libraries\Settings;

abstract class PanelController extends BaseController
{
    public const VERSION = '2.6.18';

    protected $helpers = ['url', 'form'];

    protected function render(string $view, array $data = []): string
    {
        $session = session();

        return view($view, $data + [
            'title'       => 'CBT Admin',
            'current'     => trim($this->request->getPath(), '/'),
            'namaSekolah' => Settings::get('nama_sekolah', (string) env('cbt.namaSekolah', '')),
            'adminNama'   => (string) $session->get('admin_nama'),
            'adminRole'   => (string) $session->get('admin_role'),
            'version'     => self::VERSION,
            // Pengawas melihat peringatan di semua menu selama belum ada sesi aktif.
            'sesiBelumPengawas' => $this->myRuang() !== null && \App\Libraries\ActiveTest::sesiAktif() === null,
        ]);
    }

    /**
     * Pengawas ruang: username siswa ruangnya yang ikut sesi aktif ([] bila belum ada sesi aktif).
     * null = tanpa batas (administrator atau akun tanpa ruang).
     */
    protected function siswaSesi(): ?array
    {
        if ($this->myRuang() === null) {
            return null;
        }
        $sesi = \App\Libraries\ActiveTest::sesiAktif();

        return $sesi === null ? [] : \App\Libraries\Pengawas::usernamesInRuang($this->myRuang(), \App\Libraries\ActiveTest::sesiFilter());
    }

    /** Saring baris (berkolom username) menjadi siswa sesi aktif untuk pengawas; admin tidak disaring. */
    protected function saringSesi(array $rows, string $key = 'username'): array
    {
        $boleh = $this->siswaSesi();
        if ($boleh === null) {
            return $rows;
        }
        $boleh = array_flip($boleh);

        return array_values(array_filter($rows, static fn ($r) => isset($boleh[$r[$key] ?? ''])));
    }

    /** ID user Moodle siswa sesi aktif di ruang pengawas; null untuk admin. */
    protected function moodleIdsSesi(): ?array
    {
        $usernames = $this->siswaSesi();
        if ($usernames === null || $usernames === []) {
            return $usernames;
        }

        return array_map('intval', array_column(
            \App\Libraries\Moodle::db()->table(\App\Libraries\Moodle::t('user'))->select('id')->where('deleted', 0)->whereIn('username', $usernames)->get()->getResultArray(),
            'id'
        ));
    }

    /** "siswa: u1, u2, …" dari ID user Moodle, untuk detail log (dipakai jejak siswa di Tools → Log). */
    protected function daftarSiswaLog(array $moodleIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $moodleIds)));
        if ($ids === []) {
            return '';
        }
        $names = array_column(\App\Libraries\Moodle::db()->table(\App\Libraries\Moodle::t('user'))->select('username')
            ->whereIn('id', array_slice($ids, 0, 200))->get()->getResultArray(), 'username');

        return '; siswa: ' . implode(', ', $names) . (count($ids) > 200 ? ', … (' . count($ids) . ' siswa)' : '');
    }

    /** Ruang yang diawasi akun yang sedang login; null untuk administrator atau proktor tanpa ruang. */
    protected function myRuang(): ?int
    {
        $ruang = session()->get('admin_ruang');

        return $ruang === null ? null : (int) $ruang;
    }

    /**
     * Daftar ruang untuk pilihan filter: hanya ruangnya sendiri bagi pengawas ruang.
     * Kolom `kelas` = kelas siswa di ruang itu ("X-1|X-2"), untuk data-kelas (Ruang mengikuti Kelas/Tingkatan).
     */
    protected function ruangList(): array
    {
        $builder = db_connect()->table('ruang')
            ->select("ruang.*, (SELECT GROUP_CONCAT(DISTINCT p.kelas ORDER BY p.kelas SEPARATOR '|') FROM peserta p WHERE p.ruang_id = ruang.id) AS kelas", false)
            ->orderBy('LENGTH(nama)', '', false)->orderBy('nama');
        if ($this->myRuang() !== null) {
            $builder->where('id', $this->myRuang());
        }

        return $builder->get()->getResultArray();
    }
}
