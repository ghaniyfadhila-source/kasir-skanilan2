<?php

namespace App\Services\Realtime;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim event ke channel Supabase Realtime.
 *
 * Realtime yang dipakai di sini adalah **broadcast**: backend mengirim event
 * lewat REST, browser berlangganan lewat WebSocket. Dipilih dibanding
 * `postgres_changes` karena:
 *
 *   - browser tidak perlu hak baca tabel, jadi Row Level Security tetap bisa
 *     tetap ketat tanpa membuka data transaksi ke klien
 *   - payload bebas bentuknya, jadi backend tidak perlu menambah kolom khusus
 *     di tabel hanya untuk mendorong perubahan ke browser
 *
 * Kegagalan publish TIDAK boleh menggagalkan request yang sedang berjalan.
 * Realtime adalah tambahan tampilan; kasir harus tetap bisa menjual
 * walaupun Supabase sedang lambat. Karena itu semua galat ditangkap, dicatat
 * ke log, lalu ditelan.
 */
class RealtimePublisher
{
    /**
     * Mode kumpulkan: broadcast ditahan di memori sampai {@see endBatch()}.
     *
     * Dipakai oleh `POST /api/console/sync`: satu batch 50 tap menghasilkan
     * 100 event. Tanpa penggabungan, itu berarti 100 panggilan HTTP berurutan
     * ke Supabase dari dalam satu request — cukup untuk membuat perangkat
     * timeout dan mengulang seluruh batch.
     */
    private bool $buffering = false;

    /** @var array<int, array{topic: string, event: string, payload: array<string, mixed>}> */
    private array $buffer = [];

    /**
     * Mulai menahan broadcast sampai {@see endBatch()} dipanggil.
     */
    public function beginBatch(): void
    {
        $this->buffering = true;
        $this->buffer = [];
    }

    /**
     * Kirim semua broadcast yang tertahan dalam satu panggilan HTTP.
     *
     * @return int jumlah pesan yang terkirim
     */
    public function endBatch(): int
    {
        $this->buffering = false;
        $messages = $this->buffer;
        $this->buffer = [];

        if ($messages === []) {
            return 0;
        }

        return $this->send($messages) ? count($messages) : 0;
    }

    /**
     * Kirim satu event ke satu topic.
     *
     * @param  array<string, mixed>  $payload
     * @return bool  true kalau server menerima (HTTP 202) atau pesan dikumpulkan
     *               untuk dikirim oleh {@see endBatch()}
     */
    public function broadcast(string $topic, string $event, array $payload): bool
    {
        if ($topic === '' || $event === '') {
            $this->warn('Broadcast dilewati: topic atau event kosong.');

            return false;
        }

        if (! RealtimeTopic::isConfigured()) {
            $this->warn('Broadcast dilewati: Realtime belum dikonfigurasi. Kosong: '
                .implode(', ', RealtimeTopic::missingConfiguration()));

            return false;
        }

        if ($this->buffering) {
            $this->buffer[] = compact('topic', 'event', 'payload');

            return true;
        }

        return $this->send([compact('topic', 'event', 'payload')]);
    }

    /**
     * @param  array<int, array{topic: string, event: string, payload: array<string, mixed>}>  $messages
     */
    private function send(array $messages): bool
    {
        try {
            $response = Http::withHeaders([
                'apikey' => (string) config('realtime.server_key'),
                'Authorization' => 'Bearer '.config('realtime.server_key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('realtime.timeout', 5))
                ->post($this->endpoint(), ['messages' => $messages]);
        } catch (ConnectionException $e) {
            $this->warn("Broadcast gagal: {$e->getMessage()}");

            return false;
        }

        if (! $response->successful()) {
            $this->warn(sprintf(
                'Broadcast ditolak (HTTP %d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 200)
            ));

            return false;
        }

        foreach ($messages as $message) {
            Log::channel('audit')->info('Realtime broadcast', [
                'event' => $message['event'],
                'topic' => self::maskTopic($message['topic']),
            ]);
        }

        return true;
    }

    /**
     * Kirim event ke banyak topic sekaligus.
     *
     * Berguna untuk perubahan yang relevan bagi semua kasir sekaligus, misalnya
     * admin mengubah harga menu.
     *
     * @param  string[]  $topics
     * @param  array<string, mixed>  $payload
     * @return int  berapa topic yang berhasil
     */
    public function broadcastToMany(array $topics, string $event, array $payload): int
    {
        $ok = 0;

        foreach (array_unique($topics) as $topic) {
            if ($topic !== '' && $this->broadcast($topic, $event, $payload)) {
                $ok++;
            }
        }

        return $ok;
    }

    /**
     * Endpoint broadcast Realtime.
     *
     * Path-nya berubah antar versi Realtime. Versi sekarang memakai
     * `/realtime/v1/api/broadcast`; yang lama `/realtime/v1/broadcast` sekarang
     * membalas 401. Path lama sengaja tidak dicoba karena menambah satu
     * percobaan jaringan tanpa ada gunanya.
     */
    private function endpoint(): string
    {
        return rtrim((string) config('realtime.url'), '/').'/realtime/v1/api/broadcast';
    }

    /**
     * Samarkan topic di log supaya rahasia channel tidak bocor ke berkas log.
     *
     * Nama topic memuat `channel_secret`. Log biasanya dibaca lebih banyak orang
     * daripada `.env`, dan bocornya membuat channel bisa dilacak.
     */
    private static function maskTopic(string $topic): string
    {
        $parts = explode(':', $topic);

        if (count($parts) < 2) {
            return $topic;
        }

        $secret = array_pop($parts);

        return implode(':', $parts).':'.substr($secret, 0, 4).'***';
    }

    private function warn(string $message): void
    {
        Log::channel('audit')->warning('Realtime: '.$message);
    }
}