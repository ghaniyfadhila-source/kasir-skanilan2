<?php

namespace App\Console\Commands;

use App\Services\SupabaseEnv;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Memeriksa apakah setup Supabase sudah benar dan lengkap.
 *
 * Semua yang dicek di sini pernah gagal, setidaknya sekali, dan gejalanya selalu
 * berupa error yang tidak menjelaskan penyebabnya:
 *
 *   - kunci API salah ketik -> "Invalid API key", padahal project-nya hidup
 *   - host pooler region salah -> "tenant/user not found"
 *   - tabel kurang -> aplikasi error saat query, jauh setelah setup selesai
 *
 * Daftar tabel yang diharapkan DIBACA DARI FILE MIGRATION, bukan ditulis manual.
 * Itu sebabnya `database/supabase/schema.sql` pernah tidak sinkron: daftar
 * tabelnya ditulis tangan sekali lalu tidak pernah diperbarui.
 */
class SupabaseCheck extends Command
{
    protected $signature = 'supabase:check
        {--skip-api : Lewati pengecekan kunci API lewat REST}
        {--skip-db : Lewati pengecekan koneksi database}';

    protected $description = 'Verifikasi konfigurasi Supabase: kunci API, database, tabel, realtime, dan bucket';

    /** @var string[] */
    private array $problems = [];

    /** @var string[] */
    private array $warnings = [];

    public function handle(SupabaseEnv $env): int
    {
        $this->components->info('Memeriksa setup Supabase');

        $url = $env->get('SUPABASE_URL');
        $ref = $env->projectRef();

        $this->section('Konfigurasi dasar');

        if (! is_string($url) || $url === '') {
            $this->bad('SUPABASE_URL belum diisi di .env.');

            return self::FAILURE;
        }

        $this->pass('Project URL', $url);

        if ($ref === null) {
            $this->bad('SUPABASE_URL tidak bisa diurai jadi project ref.');

            return self::FAILURE;
        }

        $this->pass('Project ref', $ref);

        if (! $this->option('skip-api')) {
            $this->checkApiKeys($env, $url, $ref);
        }

        if (! $this->option('skip-db')) {
            $this->checkDatabase($env, $ref);
        }

        $this->summarise();

        return $this->problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Cek setiap kunci API terhadap endpoint REST yang benar-benar ada datanya.
     *
     * Endpoint root `/rest/v1/` sengaja tidak dipakai: di Supabase modern
     * endpoint itu hanya menerima service_role, sehingga kunci yang sebenarnya
     * valid akan terlihat salah.
     */
    private function checkApiKeys(SupabaseEnv $env, string $url, string $ref): void
    {
        $this->section('Kunci API');

        $candidates = [
            'SUPABASE_ANON_KEY' => 'anon',
            'SUPABASE_PUBLISHABLE_KEY' => 'publishable',
            'SUPABASE_SECRET_KEY' => 'secret',
            'SUPABASE_SERVICE_ROLE_KEY' => 'service_role',
        ];

        $valid = [];

        foreach ($candidates as $key => $label) {
            $value = $env->get($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $jwtRef = $env->jwtRef($value);

            if ($jwtRef !== null && $jwtRef !== $ref) {
                // Key ditranskripsi ulang dengan urutan karakter tertukar.
                $this->bad($key, "claim ref `$jwtRef` tidak cocok dengan project `$ref`");
                $this->problems[] = "$key berasal dari project lain (salah ketik?)";
                $this->warnings[] = "Salin ulang $key dari Dashboard > Project Settings > API.";

                continue;
            }

            $status = $this->probeKey($url, $value);

            if ($status === 200 || $status === 206) {
                $this->pass($key, 'diterima ('.SupabaseEnv::keyKind($value).')');
                $valid[$label] = true;
            } elseif (in_array($status, [401, 403], true)) {
                $this->bad($key, "ditolak, HTTP {$status}");
                $this->problems[] = "$key ditolak oleh Supabase (HTTP {$status}).";
            } else {
                $this->bad($key, "respons tidak terduga, HTTP {$status}");
                $this->problems[] = "$key memberi respons HTTP {$status}.";
            }
        }

        if ($valid === []) {
            $this->problems[] = 'Tidak ada kunci API yang valid. Isi minimal SUPABASE_ANON_KEY di .env.';

            return;
        }

        // Untuk Realtime, browser Cashier memakai anon/publishable, backend memakai
        // secret/service_role. Kalau hanya satu yang ada, tandai sebagai peringatan, bukan kegagalan.
        if (! isset($valid['anon'], $valid['publishable'])) {
            $this->warnings[] = 'Kunci untuk browser (anon atau publishable) tidak ada; Realtime Cashier Web UI tidak akan bisa konek.';
        }

        if (! isset($valid['service_role'], $valid['secret'])) {
            $this->warnings[] = 'Kunci server (service_role atau secret) tidak ada; broadcast Realtime dan signed URL Storage tidak akan bisa jalan.';
        }
    }

    private function probeKey(string $url, string $key): int|string
    {
        try {
            // Uji ke `/auth/v1/health`, bukan ke endpoint tabel. Endpoint health
            // selalu ada dan tidak bergantung pada skema, sedangkan endpoint
            // tabel menjawab 404 selama migration belum dijalankan. Menguji ke
            // tabel membuat "kunci tidak valid" tercampur dengan "tabel belum ada".
            $response = Http::withHeaders([
                'apikey' => $key,
                'Authorization' => 'Bearer '.$key,
            ])->timeout(15)->get($url.'/auth/v1/health');
        } catch (ConnectionException $e) {
            $this->error('Tidak bisa menghubungi API Supabase: '.$e->getMessage());
            $this->problems[] = 'Koneksi HTTPS ke Supabase gagal.';

            return 'error';
        }

        return $response->status();
    }

    private function checkDatabase(SupabaseEnv $env, string $ref): void
    {
        $this->section('Database');

        $host = $env->get('SUPABASE_DB_HOST');
        $username = $env->get('SUPABASE_DB_USERNAME');
        $password = $env->get('SUPABASE_DB_PASSWORD');

        if (! is_string($host) || $host === '' || ! is_string($username) || $username === '') {
            $this->bad('SUPABASE_DB_HOST / USERNAME belum diisi.');
            $this->problems[] = 'Konfigurasi database belum ada. Jalankan `php artisan supabase:configure`.';

            return;
        }

        $this->pass('Host', $host);
        $this->pass('Username', $username);
        $this->pass('Password', is_string($password) && $password !== '' ? 'terisi' : 'KOSONG');

        if ($password === null || $password === '') {
            $this->problems[] = 'SUPABASE_DB_PASSWORD kosong. Password yang diawali `#` wajib diapit tanda kutip di .env.';

            return;
        }

        try {
            DB::connection('supabase')->getPdo();
        } catch (Throwable $e) {
            $this->reportConnectionFailure($e, $host, $username, $ref);

            return;
        }

        // Versi diambil lewat atribut PDO, bukan `select version()`, supaya
        // perintah ini juga bisa dijalankan terhadap SQLite saat diuji.
        $serverVersion = (string) DB::connection('supabase')->getPdo()
            ->getAttribute(PDO::ATTR_SERVER_VERSION);

        $this->pass('Koneksi', 'terbuka'.($serverVersion !== '' ? " (server {$serverVersion})" : ''));

        $this->checkTables();
        $this->checkRealtime();
        $this->checkBucket();
    }

    private function reportConnectionFailure(Throwable $e, string $host, string $username, string $ref): void
    {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage();
        $this->bad('Koneksi database gagal');
        $this->line('    '.$this->dedupeMessage($message));

        if (str_contains($message, 'tenant/user') || str_contains($message, 'ENOTFOUND')) {
            $this->line('');
            $this->line('    <fg=yellow>Tenant tidak ditemukan = prefix region pada hostname pooler tidak cocok</>');
            $this->line('    dengan region project yang sebenarnya.');
            $this->line('');

            // Username yang dipakai sudah benar, jadi jangan suggest yang sama.
            $expected = 'postgres.'.$ref;
            if ($username !== $expected) {
                $this->line('    Username saat ini : '.$username);
                $this->line('    Seharusnya        : <fg=cyan>'.$expected.'</>');
                $this->line('');
            } else {
                $this->line('    Username sudah benar (<fg=green>'.$username.'</>), jadi masalahnya di hostname.');
                $this->line('');
            }

            $this->line('    Host pooler per region:');
            foreach (SupabaseEnv::regions() as $code => $name) {
                $this->line('      <fg=gray>'.$code.'</>  '.$name.'  '.SupabaseEnv::poolerHost($code));
            }
            $this->line('');
            $this->line('    <fg=gray>Cara paling akurat: Dashboard > Database > Connection > tab Session Pooler,</>');
            $this->line('    <fg=gray>lalu salin URI-nya dan jalankan:</>');
            $this->line('    <fg=cyan>php artisan supabase:configure --uri="postgresql://..."</>');

            $this->problems[] = 'Hostname pooler tidak cocok dengan region project.';

            return;
        }

        if (str_contains($message, 'could not translate host name') || str_contains($message, 'getaddrinfo')) {
            $this->line('');
            $this->line('    <fg=yellow>Hostname tidak bisa di-resolve.</>');
            $this->line('    Untuk direct connection (`db.<ref>.supabase.co`) ini normal di jaringan tanpa IPv6,');
            $this->line('    karena host itu hanya punya AAAA record. Pakai connection pooler.');
            $this->problems[] = 'Hostname database tidak bisa di-resolve dari jaringan ini.';

            return;
        }

        if (str_contains($message, 'password authentication failed')) {
            $this->problems[] = 'Username atau password database salah.';

            return;
        }

        $this->problems[] = 'Koneksi database gagal: '.$message;
    }

    private function checkTables(): void
    {
        $this->section('Tabel');

        $expected = $this->expectedTables();

        // `getTableListing()` dipakai supaya perintahnya tidak perlu tahu driver:
        // PostgreSQL membaca dari `information_schema`, SQLite dari sqlite_master.
        // Untuk PostgreSQL, `search_path` di config sudah diset ke `public`.
        $existing = DB::connection('supabase')->getSchemaBuilder()->getTableListing();

        $missing = array_values(array_diff($expected, $existing));
        $extra = array_values(array_diff($existing, $expected));

        $this->pass('Tabel aplikasi', count($expected).'migration, '.count($existing).'ada di database');

        if ($missing !== []) {
            $this->bad('Tabel belum dibuat ('.count($missing).')', implode(', ', $missing));
            $this->problems[] = count($missing).' tabel belum ada: '.implode(', ', $missing);
            $this->line('    <fg=gray>Buat dengan:</> <fg=cyan>php artisan migrate --database=supabase --force</>');
        } else {
            $this->pass('Tabel aplikasi', 'lengkap');
        }

        if ($extra !== []) {
            $this->note('Tabel di luar daftar migration', implode(', ', $extra));
        }
    }

    private function checkRealtime(): void
    {
        $this->section('Realtime');

        // `pg_publication_tables` hanya ada di PostgreSQL.
        if (DB::connection('supabase')->getDriverName() !== 'pgsql') {
            $this->note('Lewati', 'hanya relevan untuk PostgreSQL');

            return;
        }

        $rows = DB::connection('supabase')->select(
            "select tablename from pg_publication_tables where pubname = 'supabase_realtime'"
        );
        $tables = array_column(array_map(fn ($r) => (array) $r, $rows), 'tablename');

        // Realtime hanya berguna untuk tabel yang berubah karena aksi kasir.
        $wanted = ['transactions', 'transaction_items', 'menu_items', 'consoles', 'rfid_cards'];
        $missing = array_values(array_diff($wanted, $tables));

        $this->pass('Tabel di publication', $tables === [] ? '(kosong)' : count($tables).' tabel');

        if ($tables === []) {
            $this->bad('Publication supabase_realtime tidak berisi tabel apa pun.');
            $this->problems[] = 'Tidak ada tabel yang terdaftar di supabase_realtime.';
            $this->line('    <fg=gray>Jalankan isi bagian realtime dari database/supabase/schema.sql.</>');

            return;
        }

        if ($missing !== []) {
            $this->note('Belum terdaftar', implode(', ', $missing));
            $this->warnings[] = count($missing).' tabel belum terdaftar di supabase_realtime.';
        } else {
            $this->pass('Tabel kunci', 'lengkap');
        }
    }

    private function checkBucket(): void
    {
        $this->section('Storage');

        // Bucket Storage hanya ada di Supabase/PostgreSQL.
        if (DB::connection('supabase')->getDriverName() !== 'pgsql') {
            $this->note('Lewati', 'hanya relevan untuk PostgreSQL');

            return;
        }

        $bucket = SupabaseEnv::FIRMWARE_BUCKET;

        try {
            $row = DB::connection('supabase')->selectOne(
                'select public from storage.buckets where id = ?',
                [$bucket]
            );
        } catch (Throwable) {
            $this->note('Tidak bisa membaca storage.buckets', 'lewati pengecekan bucket');

            return;
        }

        if ($row === null) {
            $this->bad("Bucket `$bucket` belum dibuat.");
            $this->problems[] = "Bucket Storage `$bucket` belum ada (dibutuhkan Ticket 14).";
            $this->line('    <fg=gray>Buat lewat Dashboard > Storage > New bucket, atau jalankan SQL di schema.sql.</>');

            return;
        }

        if ($row->public) {
            $this->bad("Bucket `$bucket` berstatus public.");
            $this->problems[] = "Bucket `$bucket` tidak boleh public; firmware ESP8266 hanya boleh lewat signed URL.";

            return;
        }

        $this->pass("Bucket `$bucket`", 'ada, private');
    }

    /**
     * PDO_server beberapa kali mengulang pesan yang sama setelah gagal connect,
     * jadi bagian duplikatnya dipangkas supaya output tetap terbaca.
     */
    private function dedupeMessage(string $message): string
    {
        $parts = array_filter(array_map('trim', explode('connection to server at', $message)));
        $unique = array_values(array_unique($parts));

        return implode(' | ', array_map(static fn (string $p): string => mb_substr($p, 0, 140), $unique));
    }

    /**
     * Daftar tabel yang harus ada, dibaca dari nama file migration.
     *
     * Mengambil dari migration, bukan daftar manual, supaya tabel baru yang
     * ditambahkan di ticket berikutnya otomatis ikut terperiksa.
     *
     * @return string[]
     */
    private function expectedTables(): array
    {
        $pattern = '/Schema::create\(\s*[\'"](?<table>[a-z0-9_]+)[\'"]/i';
        $tables = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            if (preg_match($pattern, (string) file_get_contents($file), $m) === 1) {
                $tables[$m['table']] = true;
            }
        }

        $names = array_keys($tables);
        sort($names);

        return $names;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>'.$title.'</>', '');
    }

    private function pass(string $label, string $value): void
    {
        $this->components->twoColumnDetail('  <fg=green>'.$label.'</>', $value);
    }

    private function note(string $label, string $value): void
    {
        $this->components->twoColumnDetail('  <fg=yellow>'.$label.'</>', $value);
    }

    private function bad(string $label, string $value = ''): void
    {
        $this->components->twoColumnDetail('  <fg=red>'.$label.'</>', $value);
    }

    private function summarise(): void
    {
        $this->newLine();

        if ($this->problems === [] && $this->warnings === []) {
            $this->components->info('Semua pengecekan lolos.');

            return;
        }

        foreach (array_unique($this->warnings) as $warning) {
            $this->components->warn($warning);
        }

        foreach (array_unique($this->problems) as $problem) {
            $this->components->error($problem);
        }

        if ($this->problems !== []) {
            $this->newLine();
            $this->line('  Lihat <fg=cyan>docs/supabase-setup.md</> untuk langkah perbaikan.');
        }
    }
}