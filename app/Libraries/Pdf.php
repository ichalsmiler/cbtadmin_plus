<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;
use Dompdf\Dompdf;
use Dompdf\Options;

class Pdf
{
    /** Render view menjadi PDF A4 dan kirim ke browser (dibuka di tab, bisa dicetak/diunduh). */
    public static function respond(string $view, array $data, string $filename, string $orientation = 'portrait'): ResponseInterface
    {
        if (service('request')->getGet('cetak')) {
            return self::cetakBrowser(view($view, $data + ['school' => School::info()]), $orientation);
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);

        return service('response')
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', (service('request')->getGet('unduh') ? 'attachment' : 'inline') . '; filename="' . $safe . '"')
            ->setBody(self::render($view, $data, $orientation));
    }

    /** Render view menjadi isi berkas PDF A4 (string). */
    public static function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '384M');

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('tempDir', WRITEPATH . 'cache');
        $options->set('fontCache', WRITEPATH . 'cache');
        $options->setChroot([WRITEPATH . 'uploads']);  // gambar (logo) boleh dibaca dari berkas unggahan

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data + ['school' => School::info()]));
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * ?cetak=1: kirim HTML dokumen yang sama apa adanya untuk dicetak dari browser (Ctrl+P), seperti AIOCBT.
     * Tanpa dompdf, jadi ringan di server dan tanpa batas jumlah kartu. Gambar berkas diganti URL tools/gambar/*.
     */
    private static function cetakBrowser(string $html, string $orientation): ResponseInterface
    {
        foreach (['logo' => School::logoPath(), 'logo2' => School::logo2Path(), 'ttd' => School::ttdPath()] as $jenis => $path) {
            if ($path !== null) {
                $html = str_replace('src="' . esc($path, 'attr') . '"', 'src="' . site_url('tools/gambar/' . $jenis) . '"', $html);
            }
        }
        $tambahan = '<style>'
            . '@page { size: A4 ' . $orientation . '; }'
            . 'body { font-family: "DejaVu Sans", Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }'
            . 'tr, img, .kartu, .id { page-break-inside: avoid; break-inside: avoid; }'
            . '@media screen { html { background: #e9ecef; } body { background: #fff; width: ' . ($orientation === 'landscape' ? '297mm' : '210mm') . '; margin: 0 auto; padding: 8mm; box-sizing: border-box; }'
            . '.cbt-cetak { position: sticky; top: 0; z-index: 9; display: flex; gap: 8px; align-items: center; margin: -8mm -8mm 6mm; padding: 8px 12px; background: #212529; color: #fff; font: 14px system-ui, sans-serif; }'
            . '.cbt-cetak button { font: inherit; padding: 4px 14px; border: 0; border-radius: 4px; cursor: pointer; } .cbt-cetak .utama { background: #0d6efd; color: #fff; } }'
            . '@media print { .cbt-cetak { display: none; } }'
            . '</style>';
        $bar = '<div class="cbt-cetak"><button class="utama" onclick="window.print()">Cetak</button><button onclick="window.close()">Tutup</button>'
            . '<span style="margin-left:8px">Atur kertas A4, skala 100%, matikan header/footer browser.</span></div>'
            . '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>';
        $html = preg_replace('~</head>~i', $tambahan . '</head>', $html, 1);
        $html = preg_replace('~<body([^>]*)>~i', '<body$1>' . $bar, $html, 1);

        return service('response')->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($html);
    }
}
