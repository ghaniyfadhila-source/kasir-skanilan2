<?php

namespace App\Console\Commands;

use App\Services\SupabaseEnv;
use Illuminate\Console\Command;
use PDO;
use PDOException;
use Throwable;

/**
 * Menentukan region Supabase sebuah project tanpa perlu menebak.
 *
 * Connection pooler mengecek nama tenant SEBELUM memvalidasi password. Jadi
 * tanpa password yang benar pun, galat yang muncul berbeda menurut region:
 *
 *   region salah  ->  FATAL: (ENOTFOUND) tenant/user postgres.<ref> not found
 *   region benar  ->  FATAL: password authentication failed
 *
 * Password yang dipakai di sini sengaja ngawur dan tidak pernah berhasil
 * masuk; yang dibutuhkan hanya sampai tahap penentuan tenant.
 *
 * Perintah ini menyelesaikan masalah yang tidak ada petunjuknya: pesan
 * `tenant/user not found` tidak memberi petunjuk sama sekali bahwa penyebabnya
 * hostname pooler salah region.
 */
class SupabaseDetectRegion extends Command
{
    protected $signature = 'supabase:detect-region
        {--url= : Project URL; default diambil dari SUPABASE_URL di .env}
        {--configure : Langsung tulis hasil ke .env}
        {--timeout=8 : Timeout koneksi tiap region, dalam detik}';

    protected $description = 'Deteksi region Supabase dengan membaca urutan error dari connection pooler';

    /**
     * Seluruh region Supabase, tidak hanya yang relevan untuk Indonesia.
     *
     * Daftar sengaja lebih lengkap daripada {@see SupabaseEnv::REGIONS}: perintah
     * ini harus bisa membedakan "region ini salah" dari "region ini tidak pernah
     * dipakai", dan daftar yang terlalu pendek akan membuat jawaban menyesatkan.
     *
     * @var array<string, string>
     */
    private const ALL_REGIONS = [
        'ap-southeast-1' => 'Singapore',
        'ap-southeast-2' => 'Sydney',
        'ap-northeast-1' => 'Tokyo',
        'ap-northeast-2' => 'Seoul',
        'ap-east-1' => 'Hong Kong',
        'ap-south-1' => 'Mumbai',
        'us-east-1' => 'US East (Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-1' => 'US West (Oregon)',
        'us-west-2' => 'US West (California)',
        'eu-west-1' => 'West EU (Ireland)',
        'eu-west-2' => 'London',
        'eu-west-3' => 'Paris',
        'eu-central-1' => 'Central EU (Frankfurt)',
        'eu-central-2' => 'Zurich',
        'ca-central-1' => 'Canada (Montreal)',
        'sa-east-1' => 'South America (Sao Paulo)',
    ];

    /**
     * Password ngawur. Tujuannya hanya sampai pengecekan tenant.
     */
    private const PROBE_PASSWORD = 'region-probe';

    public function handle(SupabaseEnv $env): int
    {
        $url = $this->option('url') ?: $env->get('SUPABASE_URL');
        $ref = $this->extractRef(is_string($url) ? $url : '');

        if ($ref === null) {
            $this->error('Project URL tidak terbaca. Isi SUPABASE_URL di .env atau pakai --url.');
            $this->line('  Contoh: <fg=cyan>--url=https://abcdefghijklmnopqrst.supabase.co</>');

            return self::FAILURE;
        }

        $this->components->info("Mendeteksi region project `$ref`");
        $this->line('');
        $this->line('  <fg=gray>Password ngawur dipakai supaya pooler berhenti di pengecekan tenant,</>');
        $this->line('  <fg=gray>bukan di validasi password.</>');
        $this->line('');

        $timeout = max(2, (int) $this->option('timeout'));
        $match = null;
        $checked = 0;

        foreach (self::ALL_REGIONS as $region => $name) {
            // Progress bar sengaja tidak dipakai: saat output di-pipe (misal ke
            // Select-Object atau file log) Symfony merender ulangannya berantakan.
            // Region cuma belasan, jadi satu baris per region sudah cukup jelas.
            $verdict = $this->probe($region, $ref, $timeout);

            if ($verdict === 'match') {
                $match = [$region, $name];
                $this->line(sprintf('    <fg=green>%-16s %-22s tenant ditemukan</>', $region, $name));

                break;
            }

            if ($verdict === 'unknown') {
                $this->line(sprintf('    <fg=gray>%-16s %-22s dilewati (hostname tak terjangkau)</>', $region, $name));

                continue;
            }

            $checked++;
            $this->line(sprintf('    <fg=gray>%-16s %-22s tenant tidak ada di sini</>', $region, $name));
        }

        $this->newLine();

        if ($match === null) {
            $this->error('Region tidak ditemukan di '.$checked.' region yang diperiksa.');
            $this->line('');
            $this->line('  Kemungkinan project memakai region yang tidak ada di daftar,');
            $this->line('  atau hostname pooler tidak bisa dijangkau dari jaringan ini.');
            $this->line('');
            $this->line('  <fg=gray>Cara paling akurat: Dashboard > Database > Connection > tab "Session Pooler",</>');
            $this->line('  <fg=gray>lalu salin URI-nya dan jalankan:</>');
            $this->line('  <fg=cyan>php artisan supabase:configure --uri="postgresql://..."</>');

            return self::FAILURE;
        }

        [$region, $name] = $match;

        $this->components->twoColumnDetail('Region', "<fg=green>{$region}</> ({$name})");
        $this->components->twoColumnDetail('Host pooler', SupabaseEnv::poolerHost($region));
        $this->components->twoColumnDetail('Username', "postgres.{$ref}");

        if (! $this->option('configure')) {
            $this->newLine();
            $this->line('  <fg=gray>Tulis ke .env sekaligus dengan password database:</>');
            $this->line('  <fg=cyan>php artisan supabase:configure --url='.($url).'</>');
            $this->line('  <fg=cyan>    --region='.$region.' --password="<password>"</>');

            return self::SUCCESS;
        }

        $env->setWithBanner(
            'Supabase (Ticket 09/10/14) - ditulis oleh php artisan supabase:configure',
            [
                'SUPABASE_DB_HOST' => SupabaseEnv::poolerHost($region),
                'SUPABASE_DB_PORT' => '5432',
                'SUPABASE_DB_DATABASE' => 'postgres',
                'SUPABASE_DB_USERNAME' => "postgres.{$ref}",
            ]
        );

        $this->newLine();
        $this->components->info("Host dan username ditulis ke .env untuk region {$region}.");
        $this->line('  <fg=yellow>SUPABASE_DB_PASSWORD belum diisi.</> Jalankan:');
        $this->line('  <fg=cyan>php artisan supabase:configure --url='.($url)." --region={$region} --password=\"<password>\"</>");

        return self::SUCCESS;
    }

    /**
     * Balasannya: `match`, `not-this-region`, atau `unknown`.
     *
     * Dipisah dari loop supaya logikanya bisa diuji tanpa jaringan.
     */
    public static function classify(string $errorMessage): string
    {
        $message = preg_replace('/\s+/', ' ', $errorMessage) ?? $errorMessage;

        return match (true) {
            str_contains($message, 'tenant/user') => 'not-this-region',
            str_contains($message, 'password authentication failed'),
            str_contains($message, 'no password supplied') => 'match',
            default => 'unknown',
        };
    }

    private function probe(string $region, string $ref, int $timeout): string
    {
        $host = SupabaseEnv::poolerHost($region);

        // Pooler yang tidak punya record IPv4 berarti region itu memang tidak
        // dilayani di shard ini, sehingga tidak perlu dicoba.
        if (! $this->hasIpv4($host)) {
            return 'unknown';
        }

        try {
            new PDO(
                "pgsql:host={$host};port=5432;dbname=postgres;sslmode=require;connect_timeout={$timeout}",
                "postgres.{$ref}",
                self::PROBE_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => $timeout]
            );

            return 'unknown';
        } catch (PDOException $e) {
            return self::classify($e->getMessage());
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function hasIpv4(string $host): bool
    {
        static $cache = [];

        if (! array_key_exists($host, $cache)) {
            try {
                $cache[$host] = (bool) dns_get_record($host, DNS_A);
            } catch (Throwable) {
                $cache[$host] = false;
            }
        }

        return $cache[$host];
    }

    private function extractRef(string $url): ?string
    {
        if (preg_match('~^https?://([a-z0-9]+)\.supabase\.(?:co|in)/?~i', trim($url), $m) === 1) {
            return $m[1];
        }

        return null;
    }
}