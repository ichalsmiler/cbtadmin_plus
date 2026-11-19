<?php

namespace App\Controllers;

use App\Libraries\ActivityLog;
use App\Libraries\Settings;
use App\Libraries\Tingkat;

/**
 * Admin Tools → Sesi: nomor sesi untuk setiap tingkatan (mis. Sesi 1 = XII, Sesi 2 = X, Sesi 3 = XI).
 * Disimpan di setting `sesi_tingkat` (JSON) dan dipakai bersama Konvert (tab Jadwal & User → CBTAdmin) lewat
 * meta `cbt-sesi-tingkat`. Bisa langsung diterapkan ke sesi peserta di Data CBTAdmin.
 */
class SesiTingkat extends PanelController
{
    /** Bawaan sekolah: XII sesi 1, X sesi 2, XI sesi 3. */
    private const BAWAAN = ['XII' => 1, 'X' => 2, 'XI' => 3];

    /** Nomor sesi per tingkatan; null bila belum pernah disimpan. */
    public static function tersimpan(): ?array
    {
        $data = json_decode(Settings::get('sesi_tingkat'), true);
        if (! is_array($data)) {
            return null;
        }
        $hasil = [];
        foreach (Tingkat::LIST as $t) {
            $n = (int) ($data[$t] ?? 0);
            if ($n >= 1 && $n <= 20) {
                $hasil[$t] = $n;
            }
        }

        return $hasil === [] ? null : $hasil;
    }

    public function index(): string
    {
        $db    = db_connect();
        $nilai = (self::tersimpan() ?? []) + self::BAWAAN;
        // Peserta per tingkatan dan sesi yang sekarang tercatat di Data CBTAdmin.
        $rows  = $db->query(
            'SELECT p.kelas, COALESCE(s.nama, rs.nama) AS sesi FROM peserta p
               LEFT JOIN sesi s ON s.id = p.sesi_id
               LEFT JOIN ruang r ON r.id = p.ruang_id LEFT JOIN sesi rs ON rs.id = r.sesi_id'
        )->getResultArray();
        $ringkas = [];
        foreach ($rows as $r) {
            $t = Tingkat::of($r['kelas']);
            if ($t === '') {
                continue;
            }
            $ringkas[$t]['jumlah'] = ($ringkas[$t]['jumlah'] ?? 0) + 1;
            $nama                  = (string) ($r['sesi'] ?? '');
            $ringkas[$t]['sesi'][$nama === '' ? '–' : $nama] = ($ringkas[$t]['sesi'][$nama === '' ? '–' : $nama] ?? 0) + 1;
        }

        return $this->render('sesi/index', [
            'title'     => 'Sesi',
            'nilai'     => $nilai,
            'tersimpan' => self::tersimpan() !== null,
            'ringkas'   => $ringkas,
            // Jam ujian per tingkatan (dulu di Tools → Info Kegiatan).
            'jam'       => array_combine(Tingkat::LIST, array_map(static fn (string $t): array => [
                'mulai'   => Settings::get('jam_' . $t . '_mulai'),
                'selesai' => Settings::get('jam_' . $t . '_selesai'),
            ], Tingkat::LIST)),
        ]);
    }

    public function simpan()
    {
        $back  = site_url('sesi_tingkat');
        $sesi  = $this->bacaSesi((array) $this->request->getPost('sesi'));
        if ($sesi === null) {
            return redirect()->to($back)->with('error', 'Isi nomor sesi 1–20 untuk setiap tingkatan.');
        }
        Settings::set('sesi_tingkat', json_encode($sesi));
        // Jam ujian per tingkatan (HH:MM atau kosong) untuk kartu peserta, daftar hadir, dan berita acara.
        $jam = (array) $this->request->getPost('jam');
        foreach (Tingkat::LIST as $t) {
            foreach (['mulai', 'selesai'] as $k) {
                $v = trim((string) ($jam[$t][$k] ?? ''));
                Settings::set('jam_' . $t . '_' . $k, preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '');
            }
        }
        $pesan = 'Sesi dan jam ujian disimpan: ' . $this->teks($sesi) . '. Konvert (Jadwal & User → CBTAdmin) memakai pengaturan ini.';

        if ($this->request->getPost('terapkan') === '1') {
            $db     = db_connect();
            $peta   = array_change_key_case(array_column($db->table('sesi')->get()->getResultArray(), 'id', 'nama'));
            $ubah   = 0;
            foreach ($sesi as $t => $n) {
                if (! isset($peta[(string) $n])) {
                    $db->table('sesi')->insert(['nama' => (string) $n]);
                    $peta[(string) $n] = $db->insertID();
                }
                $db->table('peserta')->where('kelas REGEXP ' . $db->escape(Tingkat::regexp($t)), null, false)
                    ->update(['sesi_id' => (int) $peta[(string) $n], 'updated_at' => date('Y-m-d H:i:s')]);
                $ubah += $db->affectedRows();
            }
            $pesan .= " Sesi $ubah peserta di Data CBTAdmin diperbarui.";
        }
        ActivityLog::add('sesi_tingkat', $this->teks($sesi) . ($this->request->getPost('terapkan') === '1' ? ' (diterapkan ke peserta)' : ''));

        return redirect()->to($back)->with('success', $pesan);
    }

    /** Dipanggil Konvert saat pengaturan sesi diubah di sana (POST JSON + header CSRF): simpan saja. */
    public function json()
    {
        $in   = $this->request->getJSON(true) ?? [];
        $sesi = $this->bacaSesi((array) ($in['sesi'] ?? []), true);
        $res  = service('response');
        if ($sesi === null) {
            return $res->setStatusCode(400)->setJSON(['ok' => false, 'csrf' => csrf_hash()]);
        }
        $lama = self::tersimpan();
        $baru = ($lama ?? []) + self::BAWAAN;
        $baru = array_merge($baru, $sesi);
        if ($lama !== $baru) {
            Settings::set('sesi_tingkat', json_encode($baru));
            ActivityLog::add('sesi_tingkat', $this->teks($baru) . ' (dari Konvert)');
        }

        return $res->setJSON(['ok' => true, 'csrf' => csrf_hash()]);
    }

    /** Nomor sesi per tingkatan dari masukan; null bila tidak sah. `sebagian`: tingkatan boleh tidak lengkap. */
    private function bacaSesi(array $masuk, bool $sebagian = false): ?array
    {
        $hasil = [];
        foreach (Tingkat::LIST as $t) {
            if (! isset($masuk[$t]) && $sebagian) {
                continue;
            }
            $n = filter_var($masuk[$t] ?? null, FILTER_VALIDATE_INT);
            if ($n === false || $n < 1 || $n > 20) {
                return null;
            }
            $hasil[$t] = $n;
        }

        return $hasil === [] ? null : $hasil;
    }

    private function teks(array $sesi): string
    {
        asort($sesi);

        return implode(', ', array_map(static fn ($t, $n) => "Sesi $n = Kelas $t", array_keys($sesi), $sesi));
    }
}
