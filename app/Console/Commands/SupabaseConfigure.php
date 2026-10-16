<?php

namespace App\Console\Commands;

use App\Services\SupabaseEnv;
use Illuminate\Console\Command;
use Throwable;

/**
 * Menulis konfigurasi koneksi Supabase ke `.env`.
 *
 * Digestinya sengaja accept both cara: paste URI dari Dashboard (paling aman,
 * karena Dashboard sudah menyusun username pooler yang benar), atau sebutkan
 * project URL + region kalau lebih reps.
 *
 * Perintah ini hanya menulis blok `SUPABASE_DB_*`. Kunci API tetap ditulis
 * manual oleh pengguna dengan paste dari Dashboard, lalu diverifikasi oleh
 * `php artisan supabase:check`. Alasannya: secret yang lewat argumen CLI akan
 * tersimpan di shell history, dan tidak ada yang lebih baik daripada paste
 * langsung ke file yang memang sudah di-`.gitignore`.
 */
class SupabaseConfigure extends Command
{
    protected $signature = 'supabase:configure
        {--uri= : Connection string dari Dashboard > Database > Connection (tab Session Pooler)}
        {--url= : Project URL, misal https://abcdefghijklmnopqrst.supabase.co}
        {--region= : Kode region, misal ap-southeast-1}
        {--password= : Password database}
        {--key=* : Pasangan ENVKEY=nilai untuk kunci API (opsional, lebih aman pakai paste manual)}
        {--show : Tampilkan hasil tanpa menulis ke .env}';

    protected $description = 'Tulis konfigurasi koneksi Supabase ke .env dari URI atau project URL';

    /**
     * Banner penanda blok yang ditulis perintah ini, supaya bisa dibersihkan
     * dan ditulis ulang tanpa menumpuk saat dijalankan berkali-kali.
     */
    private const BANNER = 'Supabase (Ticket 09/10/14) - ditulis oleh php artisan supabase:configure';

    public function handle(SupabaseEnv $env): int
    {
        try {
            $db = $this->resolveDatabaseSettings($env);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->newLine();
            $this->line('Isi dengan salah satu cara:');
            $this->newLine();
            $this->line('  <fg=cyan>php artisan supabase:configure --uri="postgresql://postgres.<ref>:<password>@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres"</>');
            $this->newLine();
            $this->line('atau:');
            $this->newLine();
            $this->line('  <fg=cyan>php artisan supabase:configure --url=https://<ref>.supabase.co --region=ap-southeast-1 --password="<password>"</>');

            return self::FAILURE;
        }

        $keys = $this->resolveKeySettings();

        if ($this->option('show')) {
            $this->newLine();
            $this->line('Pratinjau (nilai disembunyikan):');
            $this->renderSettings([...$db, ...$keys]);

            return self::SUCCESS;
        }

        $env->setWithBanner(self::BANNER, [...$db, ...$keys]);

        $this->components->info('Konfigurasi ditulis ke .env');
        $this->renderSettings([...$db, ...$keys], reveal: true);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Langkah berikutnya</>', 'php artisan supabase:check');
        $this->line('  Perintah itu memverifikasi kunci API, koneksi database, tabel, realtime, dan bucket.');
        $this->line('  Aplikasi sendiri masih memakai koneksi lamanya sampai DB_CONNECTION diganti ke <info>supabase</info>.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function resolveDatabaseSettings(SupabaseEnv $env): array
    {
        $uri = $this->option('uri');

        if (is_string($uri) && $uri !== '') {
            $parsed = $env->parseUri($uri);

            if ($parsed['username'] === '' || $parsed['password'] === '') {
                throw new \InvalidArgumentException(
                    'Username atau password kosong pada URI. Pastikan formatnya '.
                    '`postgresql://USER:PASSWORD@HOST:5432/postgres`.'
                );
            }

            $this->warn(sprintf(
                'URI memakai host %s (%s).',
                $parsed['host'],
                $parsed['via_pooler'] ? 'connection pooler, IP v4' : 'direct, hanya IPv6'
            ));

            return [
                'SUPABASE_DB_HOST' => $parsed['host'],
                'SUPABASE_DB_PORT' => $parsed['port'],
                'SUPABASE_DB_DATABASE' => $parsed['database'],
                'SUPABASE_DB_USERNAME' => $parsed['username'],
                'SUPABASE_DB_PASSWORD' => $parsed['password'],
            ];
        }

        $url = $this->option('url');
        $region = $this->option('region');
        $password = $this->option('password');

        if (! is_string($url) || $url === '' || ! is_string($region) || $region === '' || ! is_string($password)) {
            throw new \InvalidArgumentException('Opsi `--uri` atau kombinasi `--url`, `--region`, dan `--password` wajib diisi.');
        }

        return $env->buildPoolerSettings($url, $region, $password);
    }

    /**
     * @return array<string, string>
     */
    private function resolveKeySettings(): array
    {
        $keys = [];

        foreach ((array) $this->option('key') as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                $this->warn("Diabaikan karena tidak berbentuk ENVKEY=nilai: {$pair}");

                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $keys[trim($key)] = trim($value);
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function renderSettings(array $settings, bool $reveal = false): void
    {
        $sensitive = ['SUPABASE_DB_PASSWORD'];

        foreach ($settings as $key => $value) {
            $shown = $reveal || ! in_array($key, $sensitive, true)
                ? $value
                : str_repeat('*', 12);

            $this->components->twoColumnDetail($key, $shown);
        }
    }
}