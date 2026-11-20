<?php

namespace Config;

/**
 * Tambahan konfigurasi CodeIgniter (dibaca otomatis lewat "registrars").
 *
 * Panel bisa dibuka lewat IP lokal maupun domain (mis. setelah cbt-ha / cbt-alamat mengganti app.baseURL ke domain).
 * Supaya CSS, JS, dan tautan selalu dimuat dari alamat yang SEDANG dibuka, host permintaan ini didaftarkan ke
 * allowedHostnames; CodeIgniter lalu memakai host itu untuk base_url()/site_url() (skema dan path tetap dari baseURL).
 */
class Registrar
{
    public static function App(): array
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host === '' || ! preg_match('/^[a-z0-9.-]{1,253}$/', $host)) {
            return [];
        }

        return ['allowedHostnames' => [$host]];
    }
}
