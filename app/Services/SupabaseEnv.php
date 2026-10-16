<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Membaca dan menulis konfigurasi Supabase di file `.env`.
 *
 * Ada dua alasan kelas ini ada, dan keduanya berasal dari kesalahan nyata
 * saat menyiapkan Supabase secara manual:
 *
 * 1. Penulisan nilai ke `.env` tidak boleh asal. Nilai yang diawali `#`
 *    dibaca phpdotenv sebagai awal komentar, sehingga password seperti
 *    `#rahasia123` berakhir jadi string kosong tanpa error yang mencolok.
 *    Method formatValue() mencegah hal itu.
 *
 * 2. Connection string dari Dashboard perlu diurai dengan benar. Password bisa
 *    mengandung karakter khusus dan biasanya di-URL-encode, sementara bagian
 *    username berbeda antara direct connection (`postgres`) dan connection
 *    pooler (`postgres.<project-ref>`). Salah parse menghasilkan
 *    `tenant/user not found` yang sulit ditebak penyebabnya.
 */
class SupabaseEnv
{
    /**
     * Region Supabase yang relevan untuk Indonesia, beserta nama pooler host-nya.
     *
     * Host `db.<ref>.supabase.co` hanya punya AAAA record (IPv6). Untuk jaringan
     * tanpa IPv6, connection pooler (`aws-0-<region>.pooler.supabase.com`) adalah
     * satu-satunya jalan, dan nama host-nya bergantung pada region project.
     *
     * @var array<string, string>
     */
    public const REGIONS = [
        'ap-southeast-1' => 'Singapore',
        'ap-southeast-2' => 'Sydney',
        'ap-northeast-1' => 'Tokyo',
        'ap-east-1' => 'Hong Kong',
        'ap-south-1' => 'Mumbai',
        'us-east-1' => 'US East (Virginia)',
    ];

    /**
     * Nama bucket Storage untuk firmware ESP8266 (Ticket 14).
     */
    public const FIRMWARE_BUCKET = 'firmware';

    public function __construct(private readonly string $path) {}

    /**
     * Instance yang memakai `.env` di root project.
     */
    public static function default(): self
    {
        return new self(base_path('.env'));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Ambil satu nilai dari `.env`, tanpa tanda kutip.
     */
    public function get(string $key): ?string
    {
        $contents = $this->contents();

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $m) !== 1) {
            return null;
        }

        return $this->unquote(trim($m[1]));
    }

    /**
     * Tulis nilai ke `.env`: ganti kalau key sudah ada, tambahkan kalau belum.
     *
     * Nilai `null` berarti hapus key tersebut.
     *
     * @param  array<string, string|null>  $values
     */
    public function set(array $values): void
    {
        $contents = $this->contents();

        foreach ($values as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $line = $value === null ? null : $key.'='.$this->formatValue($value);

            if (preg_match($pattern, $contents) === 1) {
                if ($line === null) {
                    $contents = preg_replace($pattern, '', $contents);
                } else {
                    $contents = preg_replace($pattern, str_replace('$', '\$', $line), $contents);
                }

                continue;
            }

            if ($line !== null) {
                $contents = rtrim($contents, "\r\n")."\r\n".$line."\r\n";
            }
        }

        $this->put($contents);
    }

    /**
     * Tulis blok nilai preceded by banner komentar, dan buang banner lama dulu
     * supaya tidak menumpuk setiap kali perintah dijalankan.
     *
     * @param  array<string, string|null>  $values
     */
    public function setWithBanner(string $banner, array $values): void
    {
        $this->stripBanner($banner);
        $this->set($values);

        $contents = $this->contents();
        $lines = explode("\r\n", $contents);
        $block = $this->bannerLines($banner);

        $firstKey = array_key_first($values);
        $index = null;

        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), $firstKey.'=')) {
                $index = $i;
                break;
            }
        }

        $index ??= count($lines);
        array_splice($lines, $index, 0, $block);

        $this->put(implode("\r\n", $lines));
    }

    /**
     * Project ref dari SUPABASE_URL, misal `abcdefghijklmnopqrst`.
     */
    public function projectRef(): ?string
    {
        $url = $this->get('SUPABASE_URL');

        return is_string($url) && $url !== ''
            ? $this->extractRefFromUrlOrNull($url)
            : null;
    }

    /**
     * Claim `ref` di payload JWT Supabase, tanpa verifikasi signature.
     *
     * Dipakai untuk mendeteksi key yang salah ketik: kalau claim ini tidak sama
     * dengan project ref dari URL, key itu bukan milik project tersebut.
     */
    public function jwtRef(?string $jwt): ?string
    {
        $payload = $this->jwtPayload($jwt);
        $ref = $payload['ref'] ?? null;

        return is_string($ref) ? $ref : null;
    }

    /**
     * Claim `role` di payload JWT Supabase.
     */
    public function jwtRole(?string $jwt): ?string
    {
        $payload = $this->jwtPayload($jwt);
        $role = $payload['role'] ?? null;

        return is_string($role) ? $role : null;
    }

    /**
     * Bedakan key lama (JWT) dari key baru Supabase (sb_publishable_ / sb_secret_).
     */
    public static function keyKind(?string $key): string
    {
        if (! is_string($key) || $key === '') {
            return 'kosong';
        }

        return match (true) {
            str_starts_with($key, 'sb_publishable_') => 'publishable',
            str_starts_with($key, 'sb_secret_') => 'secret',
            substr_count($key, '.') === 2 => 'jwt',
            default => 'tidak dikenal',
        };
    }

    /**
     * Urai connection string PostgreSQL dari Supabase Dashboard.
     *
     * Menangani kedua bentuk yang dipakai Dashboard:
     *   direct   postgresql://postgres:PASSWORD@db.<ref>.supabase.co:5432/postgres
     *   pooler   postgresql://postgres.<ref>.PASSWORD@aws-0-<region>.pooler.supabase.com:5432/postgres
     *
     * @return array{host: string, port: string, database: string, username: string, password: string, via_pooler: bool}
     */
    public function parseUri(string $uri): array
    {
        // Delimiter `~`, bukan `#`: pola ini punya karakter `#` di dalam kelas
// karakter (untuk pembatasan userinfo), yang akan mengakhiri delimiter `#`.
if (preg_match(
            '~^[a-z][a-z0-9+.-]*://(?:(?<userinfo>[^/?#@]*)@)?(?<host>[^/?#:]+)(?::(?<port>\d+))?/(?<database>[^?#]*)~i',
            trim($uri),
            $m
        ) !== 1) {
            throw new InvalidArgumentException(
                'Format connection string tidak dikenali. Contoh yang benar: '.
                'postgresql://postgres.<ref>:<password>@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres'
            );
        }

        $userinfo = $m['userinfo'] ?? '';
        $username = 'postgres';
        $password = '';

        if ($userinfo !== '') {
            // Password bisa mengandung ':', jadi ambil setelah ':' pertama.
            $sep = strpos($userinfo, ':');

            if ($sep === false) {
                $username = rawurldecode($userinfo);
            } else {
                $username = rawurldecode(substr($userinfo, 0, $sep));
                $password = rawurldecode(substr($userinfo, $sep + 1));
            }
        }

        $host = strtolower($m['host']);

        return [
            'host' => $host,
            'port' => ($m['port'] ?? '') !== '' ? $m['port'] : '5432',
            'database' => ($m['database'] ?? '') !== '' ? rawurldecode($m['database']) : 'postgres',
            'username' => $username,
            'password' => $password,
            'via_pooler' => str_contains($host, '.pooler.'),
        ];
    }

    /**
     * Susun nilai SUPABASE_DB_* dari project URL + region, tanpa perlu URI.
     *
     * @return array<string, string>
     */
    public function buildPoolerSettings(string $projectUrl, string $region, string $password): array
    {
        $ref = $this->extractRefFromUrl($projectUrl);

        if (! isset(self::REGIONS[$region])) {
            throw new InvalidArgumentException(
                "Region `$region` tidak dikenal. Pilihan: ".implode(', ', array_keys(self::REGIONS)).'.'
            );
        }

        if ($password === '') {
            throw new InvalidArgumentException('Password database tidak boleh kosong.');
        }

        return [
            'SUPABASE_DB_HOST' => self::poolerHost($region),
            'SUPABASE_DB_PORT' => '5432',
            'SUPABASE_DB_DATABASE' => 'postgres',
            'SUPABASE_DB_USERNAME' => "postgres.{$ref}",
            'SUPABASE_DB_PASSWORD' => $password,
        ];
    }

    /**
     * Nama host pooler untuk sebuah region.
     */
    public static function poolerHost(string $region): string
    {
        return "aws-0-{$region}.pooler.supabase.com";
    }

    /**
     * Daftar region yang dikenal, untuk ditampilkan ke user.
     *
     * @return array<string, string>
     */
    public static function regions(): array
    {
        return self::REGIONS;
    }

    /**
     * Apit nilai dengan tanda kutip bila memang diperlukan.
     */
    public function formatValue(string $value): string
    {
        $needsQuotes = $value === ''
            || str_starts_with($value, '#')
            || preg_match('/\s/', $value) === 1
            || str_contains($value, '"');

        if (! $needsQuotes) {
            return $value;
        }

        return '"'.str_replace('"', '\\"', $value).'"';
    }

    /**
     * Buang tanda kutip dari nilai yang dibaca dari `.env`.
     */
    private function unquote(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                return str_replace('\\"', '"', substr($value, 1, -1));
            }

            if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                return substr($value, 1, -1);
            }
        }

        // Nilai tanpa kutip: buang trailing comment (`nilai # catatan`).
        $hash = strpos($value, ' #');

        return $hash === false ? $value : rtrim(substr($value, 0, $hash));
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtPayload(?string $jwt): array
    {
        if (! is_string($jwt) || substr_count($jwt, '.') !== 2) {
            return [];
        }

        $parts = explode('.', $jwt);
        $padded = strtr($parts[1], '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = json_decode((string) base64_decode($padded, true), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extractRefFromUrl(string $url): string
    {
        $ref = $this->extractRefFromUrlOrNull($url);

        if ($ref === null) {
            throw new InvalidArgumentException(
                "Project URL tidak dikenali: `$url`. Harus berbentuk https://<ref>.supabase.co"
            );
        }

        return $ref;
    }

    private function extractRefFromUrlOrNull(string $url): ?string
    {
        if (preg_match('#^https?://([a-z0-9]+)\.supabase\.(?:co|in)/?#i', trim($url), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * @return string[]
     */
    private function bannerLines(string $banner): array
    {
        return array_map(
            static fn (string $line): string => '# '.str_repeat('-', 70)."\r\n# $line",
            explode('|', $banner)
        );
    }

    private function stripBanner(string $banner): void
    {
        $lines = [];
        $inBlock = false;

        $fence = '# '.str_repeat('-', 70);

        foreach (explode("\r\n", $this->contents()) as $line) {
            if (str_starts_with(trim($line), $fence)) {
                $inBlock = ! $inBlock;
                $lines[] = '';

                continue;
            }

            if (! $inBlock) {
                $lines[] = $line;
            }
        }

        $this->put(implode("\r\n", $lines));
    }

    private function contents(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    private function put(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }
}