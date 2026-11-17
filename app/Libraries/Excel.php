<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Excel
{
    /**
     * Buat berkas .xlsx di memori. Semua sel ditulis sebagai teks supaya
     * nomor peserta dan username seperti "00123" tidak berubah menjadi angka.
     */
    public static function build(array $headers, array $rows): string
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        foreach (array_merge([$headers], $rows) as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . ($r + 1);
                $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $last = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        for ($c = 1; $c <= count($headers); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /** Judul kolom template peserta (baris 9, seperti template AIOCBT) sebelum kolom Mapel 1..n. */
    public const KOLOM_PESERTA = ['Nama Peserta', 'Nama Rombel', 'Kelas / Jurusan', 'Ruang', 'Kel. Sesi', 'Username', 'Password', 'No. Peserta'];

    /**
     * Template tunggal peserta (juga dipakai Export): lembar "Peserta" berformat template AIOCBT
     * (judul, petunjuk, judul kolom di baris 9) ditambah kolom Mapel 1..n berisi shortname course Moodle,
     * dan lembar "Daftar Mapel" berisi shortname yang ada di Moodle sebagai acuan pengisian.
     * Berkas ini bisa langsung diimpor kembali di Daftar Peserta.
     *
     * @param list<list<string>> $rows  isi kolom KOLOM_PESERTA, lalu mapel (sisa elemen)
     * @param list<array{shortname:string, nama:string, tes:string}> $mapel
     */
    public static function templatePeserta(array $rows, array $mapel): string
    {
        $jumlahMapel = 20;
        foreach ($rows as $r) {
            $jumlahMapel = max($jumlahMapel, count($r) - count(self::KOLOM_PESERTA));
        }
        $headers = self::KOLOM_PESERTA;
        for ($i = 1; $i <= $jumlahMapel; $i++) {
            $headers[] = 'Mapel ' . $i;
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Peserta');
        $sheet->setCellValue('A1', '[[ Template Upload User CBTAdmin ]]');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A3', 'Petunjuk Singkat pengisian template :');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $petunjuk = [
            ['Nama Rombel / Kelas', ': rombel siswa, mis. X-1 (dipakai untuk kelas dan tingkatan X/XI/XII)'],
            ['Ruang / Kel. Sesi', ': diisi angka saja (1 / 2 / 3 dst.); ruang atau sesi yang belum ada dibuat otomatis'],
            ['Username / Password', ': boleh dikosongi (dibuat otomatis); username yang sudah ada diperbarui, bukan digandakan'],
            ['Mapel 1, 2, dst.', ': shortname mapel (course) Moodle, satu per kolom; lihat lembar "Daftar Mapel". Kosongkan kolom yang tidak dipakai'],
            ['Setelah impor', ': klik "Sinkron ke Moodle" di Daftar Peserta untuk membuat akun dan mendaftarkan mapelnya'],
        ];
        foreach ($petunjuk as $i => [$a, $b]) {
            $sheet->setCellValue('A' . ($i + 4), $a);
            $sheet->setCellValue('B' . ($i + 4), $b);
        }

        foreach (array_merge([$headers], $rows) as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . ($r + 9), (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $head = $sheet->getStyle("A9:{$lastCol}9");
        $head->getFont()->setBold(true);
        $head->getFill()->setFillType('solid')->getStartColor()->setRGB('DCFCE7');
        // Kolom data ditulis sebagai teks (format "@") agar nomor seperti "01-26-0001" atau "007" tidak berubah.
        $sheet->getStyle('A10:' . $lastCol . max(10, count($rows) + 9 + 500))->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('B10');
        foreach ([1 => 30, 2 => 12, 3 => 14, 4 => 8, 5 => 9, 6 => 14, 7 => 12, 8 => 14] as $c => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($w);
        }
        for ($c = count(self::KOLOM_PESERTA) + 1; $c <= count($headers); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(26);
        }

        $daftar = $book->createSheet()->setTitle('Daftar Mapel');
        $daftar->fromArray(['Shortname (salin ke kolom Mapel)', 'Nama mapel', 'Tes / kategori'], null, 'A1');
        $daftar->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (array_values($mapel) as $i => $m) {
            $daftar->setCellValueExplicit('A' . ($i + 2), $m['shortname'], DataType::TYPE_STRING);
            $daftar->setCellValue('B' . ($i + 2), $m['nama']);
            $daftar->setCellValue('C' . ($i + 2), $m['tes']);
        }
        foreach (['A' => 36, 'B' => 36, 'C' => 20] as $col => $w) {
            $daftar->getColumnDimension($col)->setWidth($w);
        }
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Baca lembar pertama menjadi daftar baris berkunci nama kolom
     * (huruf kecil, selain huruf/angka menjadi "_", mis. "Kel. Sesi" -> "kel_sesi").
     *
     * Baris judul kolom adalah baris pertama, atau (bila $markers diisi) baris pertama
     * dalam 20 baris awal yang memuat salah satu nama kolom itu. Dengan begitu template
     * yang diawali petunjuk pengisian (mis. template AIOCBT) tetap terbaca.
     *
     * @return list<array<string, string>>
     */
    public static function read(string $path, array $markers = []): array
    {
        $raw = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        if ($raw === []) {
            return [];
        }

        $clean = static function ($value): string {
            if (is_float($value) && floor($value) === $value) {
                $value = (int) $value;
            }

            return trim((string) ($value ?? ''));
        };
        $key = static fn ($h): string => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($clean($h))), '_');

        if ($markers !== []) {
            foreach (array_slice($raw, 0, 20, true) as $i => $line) {
                if (array_intersect(array_map($key, $line), $markers) !== []) {
                    $raw = array_slice($raw, $i);
                    break;
                }
            }
        }

        $headers = array_map($key, array_shift($raw));
        $rows    = [];

        foreach ($raw as $line) {
            $row = [];
            foreach ($headers as $i => $name) {
                if ($name !== '') {
                    $row[$name] = $clean($line[$i] ?? '');
                }
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
