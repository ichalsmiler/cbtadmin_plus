<?php

namespace Config;

/**
 * Tambahan konfigurasi CodeIgniter (dibaca otomatis lewat "registrars").
 *
 * Panel bisa dibuka lewat IP lokal (http) maupun domain (mis. https lewat Cloudflare Tunnel) walau app.baseURL hanya
 * berisi satu alamat. Supaya CSS, JS, dan tautan selalu dimuat dari alamat yang SEDANG dibuka, baseURL disusun ulang
 * dari skema + host permintaan ini (path tetap dari app.baseURL), dan host itu didaftarkan ke allowedHostnames.
 * Skema https dikenali dari HTTPS server atau header X-Forwarded-Proto (Cloudflare / cloudflared).
 */
class Registrar
{
    public static function App(): array
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '' || ! preg_match('/^[a-z0-9.-]{1,253}(:\d{1,5})?$/', $host)) {
            return [];
        }
        $https = (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $path = (string) (parse_url((string) env('app.baseURL', '/cbtadmin/'), PHP_URL_PATH) ?: '/cbtadmin/');
        $path = '/' . trim($path, '/') . '/';
        $base = ($https ? 'https' : 'http') . '://' . $host . $path;

        // Nilai .env dibaca CodeIgniter SETELAH registrar, jadi app.baseURL di .env akan menimpa nilai di bawah.
        // Untuk permintaan ini saja, nilai lingkungan itu diganti dengan alamat yang sedang dibuka.
        foreach (['app.baseURL', 'Config\App.baseURL'] as $k) {
            $_ENV[$k] = $_SERVER[$k] = $base;
            putenv($k . '=' . $base);
        }

        return [
            'baseURL'          => $base,
            'allowedHostnames' => [preg_replace('/:\d+$/', '', $host)],
        ];
    }
}
