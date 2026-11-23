<?php

namespace App\Libraries;

/**
 * Mapel pilihan (kelas moving) kelas XI dan XII: course bershortname "<awalan>-<XI|XII>-<KODE>" dengan KODE dari
 * daftar di bawah (sama dengan MAPEL_PILIHAN di konvert/src/core/moodle.ts). Mapel pilihan siswa = pendaftarannya
 * (enrol) di course tersebut.
 */
class MapelPilihan
{
    public const KODE = [
        'FISIKA', 'KIMIA', 'BING LANJUT', 'BINDO LANJUT', 'EKONOMI',
        'GEOGRAFI', 'MATEMATIKA LANJUT', 'INFORMATIKA', 'BIOLOGI', 'SOSIOLOGI',
    ];

    public const TINGKAT = ['XI', 'XII'];

    /** Course mapel pilihan per tingkat: ['XI' => [id => ['id', 'shortname', 'fullname', 'kode']], 'XII' => …]. */
    public static function courses(): array
    {
        $pola  = '/-(XII|XI)-(' . implode('|', array_map(static fn ($k) => preg_quote($k, '/'), self::KODE)) . ')$/i';
        $hasil = array_fill_keys(self::TINGKAT, []);
        $rows  = Moodle::db()->table(Moodle::t('course'))->select('id, shortname, fullname')->where('id >', 1)->orderBy('shortname')->get()->getResultArray();
        foreach ($rows as $c) {
            if (preg_match($pola, trim((string) $c['shortname']), $m)) {
                $c['id']   = (int) $c['id'];
                $c['kode'] = strtoupper($m[2]);
                $hasil[strtoupper($m[1])][$c['id']] = $c;
            }
        }

        return $hasil;
    }

    /** Id course mapel pilihan yang diikuti tiap user: [userid => [courseid, …]]. */
    public static function terdaftar(array $courseIds): array
    {
        $courseIds = array_map('intval', $courseIds);
        if ($courseIds === []) {
            return [];
        }
        $rows = Moodle::db()->query(
            'SELECT DISTINCT ue.userid, e.courseid FROM ' . Moodle::t('user_enrolments') . ' ue
               JOIN ' . Moodle::t('enrol') . ' e ON e.id = ue.enrolid
              WHERE e.courseid IN (' . implode(',', $courseIds) . ')'
        )->getResultArray();
        $hasil = [];
        foreach ($rows as $r) {
            $hasil[(int) $r['userid']][] = (int) $r['courseid'];
        }

        return $hasil;
    }
}
