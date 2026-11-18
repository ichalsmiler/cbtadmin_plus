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
