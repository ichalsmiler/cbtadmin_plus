<?php

namespace App\Libraries;

/**
 * Ringkasan server per sesi ujian ke Telegram admin, JEDA detik setelah sesi dimatikan (Nonaktifkan sesi atau pindah
 * ke sesi lain). Dipanggil cron cbt:token tiap menit: pantau() mencatat sesi yang sedang aktif (setelan lapor_sesi:
 * id, label, mulai, kategori) dan saat sesi berganti/mati memasukkannya ke antrean (lapor_antrian) untuk dikirim.
 * Isi (tanpa nilai/soal): 1 beban server, 2 kesehatan web (dari sampel ServerSampel), 3 login (log Moodle + Reset
 * Login panel), 4 gangguan saat mengerjakan (macet, ditutup otomatis, belum terkirim), 5 saran otomatis.
 */
class LaporanSesi
{
    public const JEDA = 900;

    /** Siswa dianggap macet/terputus bila tidak ada permintaan ke Moodle selama sekian menit berturut saat ujian berjalan. */
    private const MACET = 10;

    public static function pantau(): void
    {
        $kini  = time();
        $id    = ActiveTest::sesiId();
        $catat = json_decode(Settings::get('lapor_sesi', '{}'), true) ?: [];

        if (isset($catat['id']) && (int) $catat['id'] !== $id) {
            $antri   = json_decode(Settings::get('lapor_antrian', '[]'), true) ?: [];
            $antri[] = $catat + ['selesai' => $kini, 'kirim' => $kini + self::JEDA];
            Settings::set('lapor_antrian', json_encode(array_slice($antri, -10)));
            Settings::set('lapor_sesi', '{}');
            $catat = [];
        }
        if ($id !== 0 && ! isset($catat['id'])) {
            $sesi = ActiveTest::sesiAktif();
            Settings::set('lapor_sesi', json_encode([
                'id' => $id, 'label' => $sesi['label'] ?? 'Sesi', 'mulai' => $kini, 'kategori' => ActiveTest::categoryId(),
            ]));
        }

        $antri = json_decode(Settings::get('lapor_antrian', '[]'), true) ?: [];
        $sisa  = [];
        foreach ($antri as $item) {
            if ((int) $item['kirim'] > $kini) {
                $sisa[] = $item;

                continue;
            }
            if (! Telegram::siap()) {
                continue;  // bot belum diatur: laporan dibuang, tidak menumpuk
            }
            try {
                $hasil = Telegram::kirimAdmin(self::susun($item));
                if (! ($hasil['ok'] ?? false) && (int) $item['kirim'] > $kini - 3600) {
                    $sisa[] = $item;  // Telegram gagal: coba lagi menit berikutnya, paling lama 1 jam
                }
            } catch (\Throwable $e) {
                log_message('error', 'Laporan sesi: ' . $e->getMessage());
            }
        }
        if (count($sisa) !== count($antri)) {
            Settings::set('lapor_antrian', json_encode($sisa));
        }
    }

    /** Ringkasan server satu sesi. $item: id, label, mulai, selesai (timestamp). */
    public static function susun(array $item): string
    {
        $mulai   = (int) $item['mulai'];
        $selesai = (int) $item['selesai'];
        $jam     = static fn (int $t): string => date('H.i', $t);

        $teks = '🖥 Ringkasan server ' . $item['label'] . "\n" . CekSoal::tanggal(date('Y-m-d H:i:s', $mulai)) . '–' . $jam($selesai)
            . ' WITA (' . max(1, (int) round(($selesai - $mulai) / 60)) . ' mnt)';

        $attempt = self::attempt($mulai, $selesai);
        $teks   .= "\n👥 Peserta " . count(self::peserta((int) $item['id'])) . ' · mengerjakan ' . count(array_unique(array_column($attempt, 'username')));

        $sampel = db_connect()->table('server_sampel')->where('waktu >=', $mulai)->where('waktu <=', $selesai + 60)
            ->orderBy('waktu')->get()->getResultArray();
        $saran  = [];

        $teks .= "\n\n▌1. BEBAN SERVER";
        if ($sampel === []) {
            $teks .= "\nBelum ada sampel (pencatat server belum berjalan saat sesi ini).";
        } else {
            $puncak = static function (string $kolom) use ($sampel): array {
                $p = $sampel[0];
                foreach ($sampel as $s) {
                    if ((float) $s[$kolom] > (float) $p[$kolom]) {
                        $p = $s;
                    }
                }

                return [(float) $p[$kolom], (int) $p['waktu']];
            };
            $rata = static fn (string $kolom): float => array_sum(array_column($sampel, $kolom)) / count($sampel);
            [$cpu, $tCpu]   = $puncak('cpu');
            [$ram, $tRam]   = $puncak('ram');
            [$beban]        = $puncak('beban');
            [$aktif, $tAkt] = $puncak('aktif');
            $disk      = (int) $sampel[count($sampel) - 1]['disk'];
            $inti      = max(1, (int) (SysInfo::get()['cpu']['cores'] ?? 1));
            $cpuTinggi = count(array_filter($sampel, static fn ($s) => (int) $s['cpu'] >= 85));
            $teks .= "\nCPU puncak " . (int) $cpu . '% (' . $jam($tCpu) . '), rata-rata ' . round($rata('cpu')) . '%'
                . ($cpuTinggi > 0 ? ', ≥85% selama ' . $cpuTinggi . ' mnt' : '')
                . "\nRAM puncak " . (int) $ram . '% (' . $jam($tRam) . ') · beban puncak ' . $beban . ' (' . $inti . ' inti) · disk ' . $disk . '%'
                . "\nSiswa aktif bersamaan puncak " . (int) $aktif . ' (' . $jam($tAkt) . ')';
            if ($cpuTinggi >= 5 || $cpu >= 95) {
                $saran[] = 'CPU tinggi (' . (int) $cpu . '%, ≥85% selama ' . $cpuTinggi . ' mnt): kurangi siswa per sesi atau tambah inti CPU.';
            }
            if ($ram >= 90) {
                $saran[] = 'RAM hampir penuh (' . (int) $ram . '%): tambah RAM atau turunkan pm.max_children.';
            }
            if ($disk >= 85) {
                $saran[] = 'Disk ' . $disk . '% terisi: bersihkan log/cadangan lama.';
            }

            $maksFpm = ServerSampel::maksFpm();
            $maksDb  = ServerSampel::maksDb();
            [$fpm, $tFpm] = $puncak('fpm');
            [$dbc]        = $puncak('db');
            [$rpm, $tRpm] = $puncak('http');
            $penuh  = $maksFpm > 0 ? count(array_filter($sampel, static fn ($s) => (int) $s['fpm'] >= $maksFpm - 1)) : 0;
            $galat  = (int) array_sum(array_column($sampel, 'galat'));
            $mGalat = count(array_filter($sampel, static fn ($s) => (int) $s['galat'] > 0));
            $http   = (int) array_sum(array_column($sampel, 'http'));
            $teks  .= "\n\n▌2. KESEHATAN WEB"
                . "\nPHP-FPM puncak " . (int) $fpm . ($maksFpm > 0 ? '/' . $maksFpm : '') . ' pekerja (' . $jam($tFpm) . ')'
                . ($penuh > 0 ? ', penuh ' . $penuh . ' mnt' : '')
                . "\nKoneksi database puncak " . (int) $dbc . ($maksDb > 0 ? '/' . $maksDb : '')
                . "\nPermintaan " . number_format($http, 0, ',', '.') . ' (puncak ' . number_format((int) $rpm, 0, ',', '.') . '/mnt pukul ' . $jam($tRpm) . ')'
                . "\nGalat server (5xx): " . ($galat === 0 ? 'tidak ada ✅' : $galat . ' dalam ' . $mGalat . ' mnt ⚠️');
            if ($penuh > 0) {
                $saran[] = 'PHP-FPM penuh ' . $penuh . ' mnt (siswa menunggu/lambat): naikkan pm.max_children bila RAM cukup.';
            }
            if ($maksDb > 0 && $dbc >= $maksDb * 0.9) {
                $saran[] = 'Koneksi database hampir habis (' . (int) $dbc . '/' . $maksDb . '): naikkan max_connections MariaDB.';
            }
            if ($galat > 0) {
                $saran[] = 'Ada ' . $galat . ' galat 5xx: cek /var/log/nginx/error.log dan log PHP-FPM pada menit itu.';
            }
        }

        $teks .= "\n\n▌3. LOGIN" . self::login($mulai, $selesai, $saran);
        $teks .= "\n\n▌4. GANGGUAN SAAT MENGERJAKAN" . self::gangguan($attempt, $sampel, $selesai, $saran);
        $teks .= "\n\n▌5. SARAN\n" . ($saran === [] ? '✅ Server aman, tidak ada yang perlu ditindaklanjuti.' : '• ' . implode("\n• ", $saran));

        return mb_substr($teks, 0, 4000);
    }

    /** Attempt yang dimulai selama sesi (5 menit sebelum sesi tercatat ikut, karena pantau() berjalan per menit). */
    private static function attempt(int $mulai, int $selesai): array
    {
        return Moodle::db()->query(
            'SELECT a.id, a.userid, a.state, a.timestart, a.timefinish, q.timelimit, u.username, u.firstname
               FROM ' . Moodle::t('quiz_attempts') . ' a JOIN ' . Moodle::t('quiz') . ' q ON q.id = a.quiz
               JOIN ' . Moodle::t('user') . ' u ON u.id = a.userid
              WHERE a.preview = 0 AND a.timestart BETWEEN ? AND ?',
            [$mulai - 300, $selesai]
        )->getResultArray();
    }

    /** Peserta sesi (0 / Semua Sesi = semua peserta): username => [username, nama, ruang]. */
    private static function peserta(int $sesiId): array
    {
        $b = db_connect()->table('peserta p')->select('p.username, p.nama, r.nama AS ruang')->join('ruang r', 'r.id = p.ruang_id', 'left');
        if ($sesiId > 0) {
            $b->where('COALESCE(p.sesi_id, r.sesi_id)', $sesiId, false);
        }

        return array_column($b->get()->getResultArray(), null, 'username');
    }

    /** Nama siswa singkat untuk daftar: "Nama (R.5)". */
    private static function namaSiswa(string $username, string $cadangan = ''): string
    {
        static $semua = null;
        $semua ??= self::peserta(0);
        $p = $semua[$username] ?? null;
        if (! $p) {
            return $cadangan !== '' ? $cadangan : $username;
        }

        return mb_strimwidth((string) $p['nama'], 0, 28, '…') . ($p['ruang'] ? ' (R.' . $p['ruang'] . ')' : '');
    }

    /** Daftar nama maksimal 10, sisanya "+N lainnya". */
    private static function daftar(array $nama): string
    {
        return "\n   " . implode(', ', array_slice($nama, 0, 10)) . (count($nama) > 10 ? ' +' . (count($nama) - 10) . ' lainnya' : '');
    }

    private static function login(int $mulai, int $selesai, array &$saran): string
    {
        $log   = Moodle::t('logstore_standard_log');
        $db    = Moodle::db();
        $ok    = $db->query("SELECT userid, COUNT(*) AS n FROM {$log} WHERE eventname = ? AND timecreated BETWEEN ? AND ? GROUP BY userid",
            ['\\core\\event\\user_loggedin', $mulai, $selesai])->getResultArray();
        $gagal = $db->query("SELECT other FROM {$log} WHERE eventname = ? AND timecreated BETWEEN ? AND ?",
            ['\\core\\event\\user_login_failed', $mulai, $selesai])->getResultArray();

        $alasan = [1 => 'username tidak ada', 2 => 'akun diblokir', 3 => 'password salah', 4 => 'akun terkunci', 5 => 'tidak diizinkan'];
        $per    = [];   // kode alasan => [username => kali]
        foreach ($gagal as $g) {
            $o = json_decode((string) $g['other'], true);
            if (! is_array($o)) {
                $o = @unserialize((string) $g['other'], ['allowed_classes' => false]) ?: [];
            }
            $kode = (int) ($o['reason'] ?? 0);
            $un   = (string) ($o['username'] ?? '?');
            $per[$kode][$un] = ($per[$kode][$un] ?? 0) + 1;
        }
        $ulang = count(array_filter($ok, static fn ($r) => (int) $r['n'] > 1));
        $teks  = "\nBerhasil " . array_sum(array_column($ok, 'n')) . ' kali (' . count($ok) . ' siswa)'
            . ($ulang > 0 ? ' · ' . $ulang . ' siswa login lebih dari sekali' : '')
            . "\nGagal " . count($gagal) . ' kali';
        $siswaGagal = [];
        foreach ($per as $kode => $user) {
            $teks .= "\n • " . ($alasan[$kode] ?? 'lainnya') . ': ' . array_sum($user) . ' kali, ' . count($user) . ' akun';
            foreach ($user as $un => $n) {
                $siswaGagal[$un] = ($siswaGagal[$un] ?? 0) + $n;
            }
        }
        if ($siswaGagal !== []) {
            arsort($siswaGagal);
            $teks .= "\n Paling sering gagal:" . self::daftar(array_map(
                static fn ($un, $n) => self::namaSiswa((string) $un) . ' ' . $n . 'x',
                array_keys($siswaGagal),
                $siswaGagal
            ));
        }
        if (count($per[3] ?? []) >= 10) {
            $saran[] = count($per[3]) . ' akun salah password: periksa kartu login / huruf besar-kecil password.';
        }

        $reset = db_connect()->table('activity_log')->select('detail')->where('aksi', 'reset_login')
            ->where('created_at >=', date('Y-m-d H:i:s', $mulai))->where('created_at <=', date('Y-m-d H:i:s', $selesai))
            ->get()->getResultArray();
        if ($reset !== []) {
            $n = 0;
            foreach ($reset as $r) {
                $n += preg_match('/: (\d+) peserta/', (string) $r['detail'], $m) ? (int) $m[1] : 0;
            }
            $teks .= "\nReset login oleh pengawas/admin: " . count($reset) . ' kali (' . $n . ' siswa)';
        }

        return $teks;
    }

    /**
     * Macet: attempt masih berjalan tetapi user tidak tampak aktif (tidak ada permintaan ke Moodle) ≥ MACET menit
     * berturut menurut sampel server. Ditutup otomatis: selesai tepat di batas waktu. Belum terkirim saat laporan dibuat.
     */
    private static function gangguan(array $attempt, array $sampel, int $selesai, array &$saran): string
    {
        if ($attempt === []) {
            return "\nTidak ada siswa yang mengerjakan selama sesi ini.";
        }
        $aktif = [];
        foreach ($sampel as $s) {
            $aktif[] = [(int) $s['waktu'], array_flip(array_filter(explode(',', (string) $s['pengguna'])))];
        }
        $macet = $otomatis = $buka = $ruangMacet = [];
        foreach ($attempt as $a) {
            $nama  = self::namaSiswa((string) $a['username'], (string) $a['firstname']);
            $akhir = $a['state'] === 'finished' ? (int) $a['timefinish'] : $selesai;
            if ($a['state'] !== 'finished') {
                $buka[] = $nama;
            } elseif ((int) $a['timelimit'] > 0 && (int) $a['timefinish'] >= (int) $a['timestart'] + (int) $a['timelimit'] - 2) {
                $otomatis[] = $nama;
            }
            $hilang = $terlama = 0;
            foreach ($aktif as [$t, $ids]) {
                if ($t < (int) $a['timestart'] + 120 || $t > $akhir) {
                    continue;
                }
                $hilang  = isset($ids[(string) $a['userid']]) ? 0 : $hilang + 1;
                $terlama = max($terlama, $hilang);
            }
            if ($terlama >= self::MACET) {
                $macet[] = $nama . ' ' . $terlama . 'mnt';
                $ruang   = preg_match('/\(R\.([^)]+)\)/', $nama, $m) ? $m[1] : '?';
                $ruangMacet[$ruang] = ($ruangMacet[$ruang] ?? 0) + 1;
            }
        }
        $teks = "\nTidak ada aktivitas ≥" . self::MACET . ' mnt saat ujian berjalan (macet/terputus): ' . count($macet)
            . ($macet !== [] ? self::daftar($macet) : '')
            . "\nDitutup otomatis karena waktu habis (tidak menekan Selesai): " . count($otomatis)
            . ($otomatis !== [] ? self::daftar($otomatis) : '')
            . ($buka !== [] ? "\nBelum terkirim saat laporan dibuat: " . count($buka) . self::daftar($buka) : '');
        if ($sampel === []) {
            $teks .= "\n(Deteksi macet perlu sampel server.)";
        }

        // Anti Newtab: keluar dari aplikasi/halaman ujian (mis. keluar SEB di HP untuk mencari jawaban).
        try {
            $keluar = KeluarAplikasi::daftar(array_map('intval', array_column($attempt, 'id')));
        } catch (\Throwable $e) {
            $keluar = [];
        }
        if ($keluar !== []) {
            $kunci = array_filter($keluar, static fn ($k) => $k['total'] > KeluarAplikasi::maks());
            $teks .= "\nKeluar dari aplikasi ujian: " . count($keluar) . ' siswa, ' . array_sum(array_column($keluar, 'total')) . ' kali'
                . ($kunci !== [] ? ', ' . count($kunci) . ' sempat terkunci' : '')
                . self::daftar(array_map(static fn ($k) => self::namaSiswa($k['username'], $k['nama']) . ' ' . $k['total'] . 'x', $keluar));
            if (count($kunci) >= 3) {
                $saran[] = count($kunci) . ' siswa terkunci karena keluar aplikasi: pastikan SEB memakai mode ujian (iPhone terkunci di SEB) dan pengawas berkeliling.';
            }
        }
        arsort($ruangMacet);
        foreach ($ruangMacet as $ruang => $n) {
            if ($n >= 3) {
                $saran[] = $n . ' siswa macet di Ruang ' . $ruang . ': cek jaringan/Wi-Fi ruang itu.';
            }
        }
        if (count($otomatis) >= max(5, (int) (count($attempt) * 0.1))) {
            $saran[] = count($otomatis) . ' ujian ditutup otomatis: ingatkan siswa menekan Selesai, atau cek apakah halaman sempat hang.';
        }

        return $teks;
    }
}
