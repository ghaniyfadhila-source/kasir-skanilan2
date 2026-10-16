<?php

namespace Tests\Feature;

use App\Services\SupabaseEnv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test untuk `supabase:check` dan `supabase:configure`.
 *
 * Nilai yang paling penting diuji adalah deteksi tabel kurang: daftar tabel
 * dibaca dari file migration, jadi test ini juga membuktikan bahwa tabel baru
 * yang ditambahkan di ticket berikutnya otomatis ikut dianggap wajib ada.
 */
class SupabaseCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = tempnam(sys_get_temp_dir(), 'supabase-cmd-').'.env';
        file_put_contents($this->envPath, "APP_NAME=Kasir\nDB_CONNECTION=sqlite\n");

        // Arahkan SupabaseEnv ke file sementara supaya test tidak menulis .env asli.
        $this->app->instance('supabase.env.path', $this->envPath);
        $this->app->forgetInstance(SupabaseEnv::class);

        $this->useInMemorySupabaseConnection();
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);

        parent::tearDown();
    }

    public function test_check_fails_when_project_url_is_missing(): void
    {
        $this->artisan('supabase:check')
            ->expectsOutputToContain('SUPABASE_URL belum diisi')
            ->assertExitCode(1);
    }

    public function test_check_reports_a_valid_api_key_as_accepted(): void
    {
        $this->writeEnv([
            'SUPABASE_URL' => 'https://abcdefghijklmnopqrst.supabase.co',
            'SUPABASE_ANON_KEY' => 'sb_publishable_abc',
        ]);

        Http::fake(['*/rest/v1/*' => Http::response([], 200)]);

        $this->artisan('supabase:check --skip-db')
            ->expectsOutputToContain('diterima (publishable)')
            ->assertExitCode(0);
    }

    /**
     * Kunci yang claim `ref`-nya tidak cocok berarti disalin dari project lain.
     * Gejalanya "Invalid API key" yang menyesatkan, jadi harus ditangkap lebih dulu.
     */
    public function test_check_flags_a_key_belonging_to_another_project(): void
    {
        $this->writeEnv([
            'SUPABASE_URL' => 'https://abcdefghijklmnopqrst.supabase.co',
            'SUPABASE_SERVICE_ROLE_KEY' => $this->makeJwt(['ref' => 'zzzzzzzzzzzzzzzzzzzz']),
        ]);

        $this->artisan('supabase:check --skip-db')
            ->expectsOutputToContain('tidak cocok dengan project')
            ->assertExitCode(1);
    }

    public function test_check_reports_missing_tables_derived_from_migrations(): void
    {
        $this->writeEnv([
            'SUPABASE_URL' => 'https://abcdefghijklmnopqrst.supabase.co',
            'SUPABASE_ANON_KEY' => 'sb_publishable_abc',
            'SUPABASE_DB_HOST' => 'aws-0-ap-southeast-1.pooler.supabase.com',
            'SUPABASE_DB_PORT' => '5432',
            'SUPABASE_DB_DATABASE' => 'postgres',
            'SUPABASE_DB_USERNAME' => 'postgres.abcdefghijklmnopqrst',
            'SUPABASE_DB_PASSWORD' => 'sandi',
        ]);

        Http::fake(['*/rest/v1/*' => Http::response([], 200)]);

        // Hanya sebagian tabel yang dibuat; sisanya harus dilaporkan hilang.
        $this->createTable('users');
        $this->createTable('transactions');

        $this->artisan('supabase:check')
            ->expectsOutputToContain('Tabel belum dibuat')
            ->expectsOutputToContain('menu_items')
            ->expectsOutputToContain('php artisan migrate --database=supabase --force')
            ->assertExitCode(1);
    }

    public function test_check_passes_the_table_stage_when_every_migration_table_exists(): void
    {
        $this->writeEnv([
            'SUPABASE_URL' => 'https://abcdefghijklmnopqrst.supabase.co',
            'SUPABASE_ANON_KEY' => 'sb_publishable_abc',
            'SUPABASE_DB_HOST' => 'aws-0-ap-southeast-1.pooler.supabase.com',
            'SUPABASE_DB_PORT' => '5432',
            'SUPABASE_DB_DATABASE' => 'postgres',
            'SUPABASE_DB_USERNAME' => 'postgres.abcdefghijklmnopqrst',
            'SUPABASE_DB_PASSWORD' => 'sandi',
        ]);

        Http::fake(['*/rest/v1/*' => Http::response([], 200)]);

        foreach ($this->migrationTables() as $table) {
            $this->createTable($table);
        }

        // Semua tabel hasil migration sudah ada, jadi tidak boleh ada tabel
        // yang dilaporkan hilang. Pengecekan realtime/bucket dilewati karena
        // koneksi uji bukan PostgreSQL.
        $this->artisan('supabase:check')
            ->doesntExpectOutputToContain('Tabel belum dibuat')
            ->expectsOutputToContain('Tabel aplikasi')
            ->assertExitCode(0);
    }

    public function test_configure_writes_settings_from_a_pooler_uri(): void
    {
        $this->artisan('supabase:configure --uri=postgresql://postgres.abcdefghijklmnopqrst:sandi@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres')
            ->assertExitCode(0);

        $env = new SupabaseEnv($this->envPath);

        $this->assertSame('aws-0-ap-southeast-1.pooler.supabase.com', $env->get('SUPABASE_DB_HOST'));
        $this->assertSame('postgres.abcdefghijklmnopqrst', $env->get('SUPABASE_DB_USERNAME'));
        $this->assertSame('sandi', $env->get('SUPABASE_DB_PASSWORD'));
    }

    public function test_configure_quotes_a_password_starting_with_hash(): void
    {
        $this->artisan('supabase:configure --url=https://abcdefghijklmnopqrst.supabase.co --region=ap-southeast-1 --password=#rahasia')
            ->assertExitCode(0);

        $this->assertStringContainsString(
            'SUPABASE_DB_PASSWORD="#rahasia"',
            (string) file_get_contents($this->envPath)
        );

        $this->assertSame('#rahasia', (new SupabaseEnv($this->envPath))->get('SUPABASE_DB_PASSWORD'));
    }

    public function test_configure_shows_a_preview_without_writing(): void
    {
        $before = (string) file_get_contents($this->envPath);

        $this->artisan('supabase:configure --url=https://abcdefghijklmnopqrst.supabase.co --region=ap-southeast-1 --password=sandi --show')
            ->assertExitCode(0);

        $this->assertSame($before, (string) file_get_contents($this->envPath));
    }

    public function test_configure_explains_itself_when_no_input_is_given(): void
    {
        $this->artisan('supabase:configure')
            ->expectsOutputToContain('Opsi `--uri` atau kombinasi')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): void
    {
        $contents = (string) file_get_contents($this->envPath);

        foreach ($values as $key => $value) {
            $contents .= $key.'='.$value."\r\n";
        }

        file_put_contents($this->envPath, $contents);
    }

    /**
     * Koneksi `supabase` diarahkan ke SQLite in-memory supaya pengujian
     * query information_schema bisa jalan tanpa server PostgreSQL.
     */
    private function useInMemorySupabaseConnection(): void
    {
        config([
            'database.connections.supabase' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('supabase');
    }

    private function createTable(string $name): void
    {
        DB::connection('supabase')->statement("create table {$name} (id integer primary key)");
    }

    /**
     * Daftar tabel yang harusnya terdeteksi perintah `supabase:check`.
     *
     * @return string[]
     */
    private function migrationTables(): array
    {
        $pattern = '/Schema::create\(\s*[\'"](?<table>[a-z0-9_]+)[\'"]/i';
        $tables = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            if (preg_match($pattern, (string) file_get_contents($file), $m) === 1) {
                $tables[$m['table']] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function makeJwt(array $claims): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(
            base64_encode((string) json_encode($data)),
            '+/',
            '-_'
        ), '=');

        return $encode(['alg' => 'HS256', 'typ' => 'JWT'])
            .'.'.$encode($claims)
            .'.signature';
    }
}