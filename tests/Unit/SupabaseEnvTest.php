<?php

namespace Tests\Unit;

use App\Services\SupabaseEnv;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test untuk pembacaan/penulisan `.env` dan penguraian connection string.
 *
 * Fokus utama ada di dua hal yang dulu menggigit waktu setup Supabase:
 * password berawalan `#` yang jadi kosong tanpa error, dan connection string
 * pooler yang keliru diurai jadi username yang tidak ada.
 */
class SupabaseEnvTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'supabase-env-').'.env';
        file_put_contents($this->path, "APP_NAME=Kasir\nDB_CONNECTION=sqlite\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function env(): SupabaseEnv
    {
        return new SupabaseEnv($this->path);
    }

    private function rawEnv(): string
    {
        return (string) file_get_contents($this->path);
    }

    public function test_it_reads_a_plain_value(): void
    {
        $this->env()->set(['SUPABASE_URL' => 'https://abcdefghijklmnopqrst.supabase.co']);

        $this->assertSame(
            'https://abcdefghijklmnopqrst.supabase.co',
            $this->env()->get('SUPABASE_URL')
        );
    }

    /**
     * Regresi: password berawalan `#` tanpa tanda kutip dibaca phpdotenv sebagai
     * awal komentar, sehingga nilainya kosong dan autentikasi gagal tanpa pesan error.
     */
    public function test_it_quotes_a_password_starting_with_hash(): void
    {
        $this->env()->set(['SUPABASE_DB_PASSWORD' => '#contoh-sandi']);

        $this->assertStringContainsString(
            'SUPABASE_DB_PASSWORD="#contoh-sandi"',
            $this->rawEnv()
        );

        $this->assertSame('#contoh-sandi', $this->env()->get('SUPABASE_DB_PASSWORD'));
    }

    public function test_it_quotes_values_with_whitespace(): void
    {
        $this->env()->set(['SUPABASE_DB_PASSWORD' => 'rahasia dengan spasi']);

        $this->assertStringContainsString('SUPABASE_DB_PASSWORD="rahasia dengan spasi"', $this->rawEnv());
        $this->assertSame('rahasia dengan spasi', $this->env()->get('SUPABASE_DB_PASSWORD'));
    }

    public function test_it_leaves_ordinary_values_unquoted(): void
    {
        $this->env()->set(['SUPABASE_DB_USERNAME' => 'postgres.abcdefghijklmnopqrst']);

        $this->assertStringContainsString(
            'SUPABASE_DB_USERNAME=postgres.abcdefghijklmnopqrst',
            $this->rawEnv()
        );
    }

    public function test_setting_the_same_key_replaces_instead_of_appending(): void
    {
        $env = $this->env();
        $env->set(['SUPABASE_DB_HOST' => 'lama.example.com']);
        $env->set(['SUPABASE_DB_HOST' => 'baru.example.com']);

        $this->assertSame('baru.example.com', $env->get('SUPABASE_DB_HOST'));
        $this->assertSame(1, substr_count($this->rawEnv(), 'SUPABASE_DB_HOST='));
    }

    public function test_it_preserves_other_keys(): void
    {
        $this->env()->set(['SUPABASE_DB_HOST' => 'baru.example.com']);

        $this->assertSame('Kasir', $this->env()->get('APP_NAME'));
        $this->assertSame('sqlite', $this->env()->get('DB_CONNECTION'));
    }

    public function test_it_parses_a_pooler_uri(): void
    {
        $parsed = $this->env()->parseUri(
            'postgresql://postgres.abcdefghijklmnopqrst:sandi@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres'
        );

        $this->assertSame('aws-0-ap-southeast-1.pooler.supabase.com', $parsed['host']);
        $this->assertSame('5432', $parsed['port']);
        $this->assertSame('postgres', $parsed['database']);
        $this->assertSame('postgres.abcdefghijklmnopqrst', $parsed['username']);
        $this->assertSame('sandi', $parsed['password']);
        $this->assertTrue($parsed['via_pooler']);
    }

    public function test_it_url_decodes_the_password_from_a_uri(): void
    {
        $parsed = $this->env()->parseUri(
            'postgresql://postgres.abcdefghijklmnopqrst:sandi%20%23berubah@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres'
        );

        $this->assertSame('sandi #berubah', $parsed['password']);
    }

    public function test_it_parses_a_direct_uri(): void
    {
        $parsed = $this->env()->parseUri(
            'postgresql://postgres:sandi@db.abcdefghijklmnopqrst.supabase.co:5432/postgres'
        );

        $this->assertSame('db.abcdefghijklmnopqrst.supabase.co', $parsed['host']);
        $this->assertSame('postgres', $parsed['username']);
        $this->assertFalse($parsed['via_pooler']);
    }

    public function test_it_rejects_a_uri_it_cannot_parse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->env()->parseUri('bukan-connection-string');
    }

    public function test_it_builds_pooler_settings_from_url_and_region(): void
    {
        $settings = $this->env()->buildPoolerSettings(
            'https://abcdefghijklmnopqrst.supabase.co',
            'ap-southeast-1',
            '#sandi'
        );

        $this->assertSame('aws-0-ap-southeast-1.pooler.supabase.com', $settings['SUPABASE_DB_HOST']);
        $this->assertSame('postgres.abcdefghijklmnopqrst', $settings['SUPABASE_DB_USERNAME']);
        $this->assertSame('postgres', $settings['SUPABASE_DB_DATABASE']);
        $this->assertSame('#sandi', $settings['SUPABASE_DB_PASSWORD']);
    }

    public function test_it_rejects_an_unknown_region(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->env()->buildPoolerSettings('https://abcdefghijklmnopqrst.supabase.co', 'atlantis-1', 'sandi');
    }

    public function test_it_rejects_a_project_url_it_cannot_parse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->env()->buildPoolerSettings('https://contoh.saya', 'ap-southeast-1', 'sandi');
    }

    public function test_it_reads_the_project_ref_from_the_url(): void
    {
        $this->env()->set(['SUPABASE_URL' => 'https://abcdefghijklmnopqrst.supabase.co']);

        $this->assertSame('abcdefghijklmnopqrst', $this->env()->projectRef());
    }

    public function test_project_ref_is_null_for_a_missing_url(): void
    {
        $this->assertNull($this->env()->projectRef());
    }

    /**
     * Pembacaan claim `ref` inilah yang membuat salah ketik service_role key
     * terdeteksi: stringnya panjang dan vulnerable, tapi claim di dalamnya
     * bisa dibandingkan dengan project ref.
     */
    public function test_it_reads_the_ref_claim_from_a_jwt(): void
    {
        $jwt = $this->makeJwt(['ref' => 'abcdefghijklmnopqrst', 'role' => 'service_role']);

        $this->assertSame('abcdefghijklmnopqrst', $this->env()->jwtRef($jwt));
        $this->assertSame('service_role', $this->env()->jwtRole($jwt));
    }

    public function test_it_returns_null_for_a_value_that_is_not_a_jwt(): void
    {
        $this->assertNull($this->env()->jwtRef('sb_secret_abc'));
        $this->assertNull($this->env()->jwtRef(null));
    }

    public function test_it_classifies_the_key_formats(): void
    {
        $this->assertSame('jwt', SupabaseEnv::keyKind($this->makeJwt(['role' => 'anon'])));
        $this->assertSame('publishable', SupabaseEnv::keyKind('sb_publishable_abc'));
        $this->assertSame('secret', SupabaseEnv::keyKind('sb_secret_abc'));
        $this->assertSame('kosong', SupabaseEnv::keyKind(null));
    }

    public function test_banner_written_twice_does_not_stack(): void
    {
        $env = $this->env();
        $banner = 'Supabase - ditulis oleh perintah';

        $env->setWithBanner($banner, ['SUPABASE_DB_HOST' => 'pertama.example.com']);
        $env->setWithBanner($banner, ['SUPABASE_DB_HOST' => 'kedua.example.com']);

        $contents = $this->rawEnv();

        $this->assertSame('kedua.example.com', $env->get('SUPABASE_DB_HOST'));
        $this->assertSame(1, substr_count($contents, 'SUPABASE_DB_HOST='));
        $this->assertSame(1, substr_count($contents, $banner));
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