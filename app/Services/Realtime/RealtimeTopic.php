<?php

namespace App\Services\Realtime;

/**
 * Penamaan channel Realtime.
 *
 * Nama channel adalah satu-satunya "kunci" keamanan di desain ini: channel
 * broadcast Supabase bersifat publik, jadi apa pun yang bisa menebak nama topic
 * bisa berlangganan. Karena itu `channel_secret` dari `.env` ditambahkan ke
 * setiap nama topic, dan nilainya hanya dikirim ke halaman yang sudah
 * terautentikasi.
 *
 * Drivenya dipisah ke kelas ini supaya nama channel yang sama dipakai oleh
 * backend (saat publish) dan frontend (saat subscribe). Kalau dipisah, keduanya
 * bisa tidak sinkron dan gejalanya sulit dilacak: publish berhasil, tapi
 * browser tidak pernah menerima apa pun.
 */
class RealtimeTopic
{
    /** Channel untuk semua browser admin yang sedang terbuka. */
    public const ADMIN_DASHBOARD = 'admin:dashboard';

    /** Prefix channel Cashier Web UI, diikuti device id. */
    public const CASHIER_PREFIX = 'cashier';

    /**
     * Prefix channel konsol.
     *
     * Firmware ESP8266 sendiri TIDAK berlangganan lewat WebSocket. Klien
     * Phoenix Channels terlalu berat untuk_chip yang cuma punya ~40 KB heap
     * bebas, dan membuat kasir bergantung pada Supabase reachable berarti
     * kasir mati saat internet putus - padahal itu justru skenario yang paling
     * sering terjadi di kantin. Firmware memakai REST (`/api/console/*`) dan
     * antrean lokal; lihat firmware/src/network.cpp.
     *
     * Channel ini tetap ada supaya backend punya satu tempat untuk
     *Maintenance, termasuk ke firmware Ticket 10+.
     */
    public const CONSOLE_PREFIX = 'console';

    /**
     * Topic dashboard admin, misal `admin:dashboard:8f2c...`.
     */
    public static function adminDashboard(): string
    {
        return self::ADMIN_DASHBOARD.':'.self::secret();
    }

    /**
     * Topic Cashier Web UI untuk satu konsol.
     */
    public static function cashier(string $deviceId): string
    {
        return self::CASHIER_PREFIX.':'.$deviceId.':'.self::secret();
    }

    /**
     * Topic untuk satu konsol (firmware / tooling).
     */
    public static function console(string $deviceId): string
    {
        return self::CONSOLE_PREFIX.':'.$deviceId.':'.self::secret();
    }

    /**
     * Semua topic milik satu konsol, untuk keperluan pengujian.
     *
     * @return string[]
     */
    public static function forDevice(string $deviceId): array
    {
        return [self::cashier($deviceId), self::console($deviceId)];
    }

    /**
     * Apakah Realtime sudah siap dipakai?
     *
     * Dipanggil `supabase:check` dan oleh publisher sebelum mencoba mengirim,
     * supaya instalasi yang belum dikonfigurasi tidak menghasilkan error di
     * setiap request.
     */
    public static function isConfigured(): bool
    {
        return self::missingConfiguration() === [];
    }

    /**
     * Daftar bagian konfigurasi yang belum terisi, kosong kalau semuanya siap.
     *
     * @return string[]
     */
    public static function missingConfiguration(): array
    {
        $missing = [];

        foreach ([
            'realtime.url' => config('realtime.url'),
            'realtime.client_key' => config('realtime.client_key'),
            'realtime.server_key' => config('realtime.server_key'),
        ] as $name => $value) {
            if (! is_string($value) || $value === '') {
                $missing[] = $name;
            }
        }

        if (self::secret() === '') {
            $missing[] = 'realtime.channel_secret (isi di .env, buat dengan php artisan supabase:configure)';
        }

        return $missing;
    }

    /**
     * Konfigurasi yang dikirim ke browser.
     *
     * Hanya berisi kunci yang memang harus ada di sisi klien, ditambah nama
     * topic yang sudah dirangkai. Kunci server tidak pernah ikut di sini.
     *
     * @return array{url: string, key: string, topics: array<string, string>, enabled: bool}
     */
    public static function clientConfig(?string $deviceId = null): array
    {
        return [
            'url' => (string) config('realtime.url'),
            'key' => (string) config('realtime.client_key'),
            'topics' => [
                'adminDashboard' => self::adminDashboard(),
                'cashier' => $deviceId !== null ? self::cashier($deviceId) : null,
            ],
            'enabled' => self::isConfigured(),
        ];
    }

    /**
     * Lengkapi nama topic yang diberikan pengguna dengan suffix rahasia.
     *
     * Dipakai `realtime:probe` dan `realtime:listen` supaya keduanya bisa
     * menerima topic yang diketik setengah jadi (`admin:dashboard`) maupun yang
     * sudah lengkap. Kalau suffix-nya sudah ada, tidak ditambah dua kali -
     * kalau tidak, listen dan probe akan menyangkut di topic berbeda dan tidak
     * akan pernah bertemu.
     */
    public static function attachSecret(string $topic): string
    {
        $secret = self::secret();

        if ($secret === '') {
            return trim($topic, ':');
        }

        $topic = trim($topic, ':');

        return str_ends_with($topic, $secret) ? $topic : $topic.':'.$secret;
    }

    /**
     * Rahasia channel, atau string kosong kalau belum diatur.
     *
     * Nilai kosong sengaja TIDAK diganti dengan nilai bawaan yang bisa ditebak.
     * Kalau diam-diam memakai nilai tetap, instalasi yang lupa mengisi `.env`
     * akan terlihat berjalan padahal topic-nya sebenarnya bisa ditebak siapa saja.
     */
    private static function secret(): string
    {
        $secret = config('realtime.channel_secret');

        return is_string($secret) ? trim($secret) : '';
    }
}