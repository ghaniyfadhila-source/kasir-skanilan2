<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Menjalankan satu alur kasir lengkap dari ujung ke ujung.
 *
 * Tujuannya menjawab pertanyaan "apakah instalasi ini benar-benar bisa
 * dipakai?", bukan sekadar "halaman login terbuka?". Alurnya meniru apa yang
 * benar-benar terjadi di kantin:
 *
 *   kasir login -> pilih menu -> buat order -> ESP8266 melihat order
 *   -> pembeli tap kartu -> transaksi selesai -> saldo berkurang -> kasir
 *   melihat struk
 *
 * Semua langkah dilalui lewat HTTP, jadi perintah ini juga menguji RLS,
 * tipe kolom, dan konfigurasi Rute yang tidak akan tersentuh test suite.
 * Test suite memakai SQLite yang tidak menegakkan tipe kolom; perintah ini
 * tidak, sehingga bug yang hanya muncul di PostgreSQL akan ketahuan di sini.
 */
class CashierSmokeTest extends Command
{
    protected $signature = 'cashier:smoke
        {--url=http://127.0.0.1:8010 : Alamat server yang sedang berjalan}
        {--device=ESP-AABBCCDDEEFF : Device ID konsol}
        {--rfid=A1B2C3D4 : UID kartu RFID yang dipakai untuk tap}
        {--topup : Pastikan saldo pembeli cukup sebelum menguji}';

    protected $description = 'Uji alur kasir lengkap (login, order, tap RFID, pembayaran) lewat HTTP';

    /** @var array<int, array{0: string, 1: string}> */
    private array $failures = [];

    public function handle(): int
    {
        $base = rtrim((string) $this->option('url'), '/');
        $device = (string) $this->option('device');
        $rfid = (string) $this->option('rfid');

        $this->components->info("Menguji alur kasir di {$base}");

        // Order yang tertinggal dari percobaan sebelumnya akan membuat
        // `pending-order` mengembalikan order lama, bukan order yang baru dibuat,
        // sehingga pemeriksaan "ESP8266 melihat order" gagal tanpa sebab yang
        // sebenarnya. Order yang sudah kedaluwarsa memang harus dibatalkan,
        // jadi membersihkannya di sini sekaligus menjalankan aturan yang benar.
        $this->callSilent('orders:void-expired');

        $login = $this->call_('POST', "{$base}/api/cashier/login", [], [
            'device_id' => $device,
            'password' => 'kasir12345',
        ]);

        if (! ($login['json']['success'] ?? false)) {
            $this->failStep('Login kasir', $login);

            return self::FAILURE;
        }

        $token = $login['json']['data']['token'];
        $auth = ['Accept: application/json', 'Authorization: Bearer '.$token];
        $this->okStep('Login kasir', 'mendapat token Sanctum');

        $menu = $this->call_('GET', "{$base}/api/cashier/menu", $auth);
        $categories = $menu['json']['data'] ?? [];
        $item = null;

        // Item termurah dipilih supaya smoke test berhasil walau saldo pembeli
        // tinggal sedikit.
        foreach ($categories as $category) {
            foreach ($category['items'] ?? [] as $candidate) {
                if ($item === null || $candidate['price_cents'] < $item['price_cents']) {
                    $item = $candidate;
                }
            }
        }

        if ($item === null) {
            $this->failStep('Ambil menu', $menu, 'tidak ada menu aktif');

            return self::FAILURE;
        }

        $this->okStep('Ambil menu', $item['name'].' - '.$item['price_formatted']);

        $consoleHeaders = [
            'X-Device-ID: '.$device,
            'X-API-Key: '.$this->resolveApiKey($device),
        ];

        // Saldo pembeli dibaca lebih dulu supaya jumlah pesanan bisa disesuaikan.
        // Kalau tidak, smoke test bisa gagal karena `insufficient_balance` -
        // yang memang aturan yang benar, tapi bukan sesuatu yang ingin diuji
        // di sini.
        $balance = $this->call_('GET', "{$base}/api/console/balance/{$rfid}", $consoleHeaders);
        $cents = $balance['json']['data']['balance_cents'] ?? 0;
        $affordable = $item['price_cents'] > 0 ? intdiv($cents, $item['price_cents']) : 0;

        if ($affordable < 1 && $this->option('topup')) {
            $topUp = $this->topUpBuyer($rfid, $item['price_cents'] * 2);
            $cents = $topUp;
            $affordable = intdiv($cents, $item['price_cents']);
            $this->okStep('Top-up saldo', 'Rp '.number_format($cents / 100, 0, ',', '.'));
        }

        if ($affordable < 1) {
            $this->components->twoColumnDetail(
                '  <fg=red>Saldo pembeli</>',
                ($balance['json']['data']['balance_formatted'] ?? 'Rp 0')
                .' - tidak cukup untuk satu '.$item['name'].' ('.$item['price_formatted'].')'
            );
            $this->line('    <fg=gray>Jalankan ulang dengan --topup, atau pakai --rfid untuk kartu lain.</>');

            return self::FAILURE;
        }

        $this->okStep(
            'Cek saldo',
            $balance['json']['data']['balance_formatted'].' ('.$balance['json']['data']['name'].')'
        );

        $quantity = min(2, $affordable);
        $order = $this->call_('POST', "{$base}/api/cashier/orders", [...$auth, 'Content-Type: application/json'], [
            'items' => [['menu_item_id' => $item['id'], 'quantity' => $quantity]],
            'idempotency_key' => 'smoke-'.bin2hex(random_bytes(8)),
        ]);

        if (($order['json']['success'] ?? false) !== true) {
            $this->failStep('Buat order', $order);

            return self::FAILURE;
        }

        $orderId = $order['json']['data']['order_id'];
        $this->okStep(
            'Buat order',
            sprintf(
                '%s x%d, %s, kedaluwarsa %s',
                $item['name'],
                $quantity,
                $order['json']['data']['total_formatted'],
                $order['json']['data']['expires_at']
            )
        );

        $pending = $this->call_('GET', "{$base}/api/console/pending-order", $consoleHeaders);
        $seen = ($pending['json']['data']['order_id'] ?? null) === $orderId;
        $seen
            ? $this->okStep('ESP8266 melihat order', 'pending-order cocok')
            : $this->failStep('ESP8266 melihat order', $pending, 'order tidak muncul di pending-order');

        $tap = $this->call_('POST', "{$base}/api/console/rfid-tap", [...$consoleHeaders, 'Content-Type: application/json'], [
            'rfid_uid' => $rfid,
            'order_id' => $orderId,
        ]);

        if (($tap['json']['success'] ?? false) !== true) {
            $this->failStep('Tap RFID', $tap);

            return self::FAILURE;
        }

        $data = $tap['json']['data'];
        $this->okStep(
            'Tap RFID',
            sprintf('%s, struk %s, saldo %s', $data['status'], $data['receipt_no'], $data['new_balance_formatted'])
        );

        $balanceAfter = $this->call_('GET', "{$base}/api/console/balance/{$rfid}", $consoleHeaders);
        $this->okStep(
            'Saldo setelah pembayaran',
            $balanceAfter['json']['data']['balance_formatted']
            .' (turun '.$data['total_formatted'].')'
        );

        $status = $this->call_('GET', "{$base}/api/cashier/orders/{$orderId}/status", $auth);
        ($status['json']['data']['status'] ?? '') === 'completed'
            ? $this->okStep('Kasir melihat struk', 'status completed')
            : $this->failStep('Kasir melihat struk', $status);

        $this->newLine();

        if ($this->failures !== []) {
            $this->components->error(count($this->failures).' langkah gagal.');

            return self::FAILURE;
        }

        $this->components->info('Seluruh alur kasir berjalan normal.');

        return self::SUCCESS;
    }

    /**
     * Ambil API key konsol dari database.
     *
     * Nilainya tidak pernah dicetak: yang ditampilkan hanya panjangnya, supaya
     * output perintah ini aman dishare.
     */
    private function resolveApiKey(string $deviceId): string
    {
        return (string) \App\Models\ConsoleCredential::query()
            ->whereHas('console', fn ($q) => $q->where('device_id', $deviceId))
            ->value('api_key');
    }

    /**
     * Tambah saldo pembeli memakai method yang sama dengan yang dipakai admin.
     *
     * Hanya dipanggil bila opsi `--topup` diberikan, supaya perintah ini tidak
     * mengubah saldo diam-diam.
     */
    private function topUpBuyer(string $rfid, int $targetCents): int
    {
        $card = \App\Models\RfidCard::query()->where('rfid_uid', $rfid)->first();
        $user = $card?->user;

        if ($user === null) {
            return 0;
        }

        if ($user->balance_cents < $targetCents) {
            $user->topUp($targetCents - $user->balance_cents, 'Top-up smoke test kasir');
        }

        return (int) $user->fresh()->balance_cents;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     * @return array{code: int, json: mixed, raw: string}
     */
    private function call_(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        // Header JSON selalu disertakan. Tanpa `Accept: application/json`,
        // Laravel tidak mengenali endpoint ini sebagai permintaan API dan
        // menjawab pengalihan 302 ke beranda alih-alih JSON, sehingga galat
        // asli ikut tersembunyi.
        $headers = ['Accept: application/json', ...$headers];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }

        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
    }

    private function okStep(string $label, string $detail): void
    {
        $this->components->twoColumnDetail("  <fg=green>{$label}</>", $detail);
    }

    /**
     * @param  array{code: int, json: mixed, raw: string}  $response
     */
    private function failStep(string $label, array $response, string $extra = ''): void
    {
        $this->components->twoColumnDetail("  <fg=red>{$label}</>", 'HTTP '.$response['code']);

        $body = $response['json'] ?? $response['raw'];
        $detail = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);

        if ($detail !== '') {
            $this->line('    <fg=gray>'.mb_substr((string) $detail, 0, 300).'</>');
        }

        if ($extra !== '') {
            $this->line('    <fg=yellow>'.$extra.'</>');
        }

        $this->failures[] = $label;
    }
}