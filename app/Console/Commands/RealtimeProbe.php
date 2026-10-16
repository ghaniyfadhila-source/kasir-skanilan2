<?php

namespace App\Console\Commands;

use App\Services\Realtime\RealtimePublisher;
use App\Services\Realtime\RealtimeTopic;
use Illuminate\Console\Command;

/**
 * Mengirim event uji ke channel Realtime.
 *
 * Pasangan dari `realtime:listen`: jalankan listener di satu terminal, probe di
 * terminal lain, lalu lihat apakah event benar-benar sampai. Ini menguji jalur
 * yang tidak bisa disentuh test suite - dari publish HTTP sampai bingkai
 * WebSocket di sisi klien.
 */
class RealtimeProbe extends Command
{
    protected $signature = 'realtime:probe
        {--topic= : Topik tanpa suffix rahasia; default ke dashboard admin}
        {--device=ESP-AABBCCDDEEFF : Device ID untuk topik kasir/konsol}
        {--event=probe.test : Nama event}
        {--count=1 : Berapa kali mengirim}';

    protected $description = 'Kirim event uji ke channel Realtime';

    public function handle(RealtimePublisher $publisher): int
    {
        if (! RealtimeTopic::isConfigured()) {
            $this->error('Realtime belum dikonfigurasi. Yang kurang:');
            foreach (RealtimeTopic::missingConfiguration() as $missing) {
                $this->line('  - '.$missing);
            }

            return self::FAILURE;
        }

        $topic = $this->resolveTopic();
        $event = (string) $this->option('event');
        $count = max(1, min(20, (int) $this->option('count')));

        $this->components->info("Mengirim ke {$topic}");
        $this->line('  <fg=gray>Nama topic di frontend sudah termasuk suffix rahasia, jadi tidak ditampilkan utuh di sini.</>');

        $sent = 0;

        for ($i = 1; $i <= $count; $i++) {
            $payload = [
                'probe' => true,
                'urutan' => $i,
                'waktu' => now()->toIso8601String(),
                'source' => 'realtime:probe',
            ];

            if ($publisher->broadcast($topic, $event, $payload)) {
                $sent++;
            }

            if ($i < $count) {
                sleep(1);
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Terkirim', "{$sent}/{$count}");

        if ($sent === 0) {
            $this->error('Tidak ada event yang terkirim. Periksa `php artisan supabase:check` dan storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->components->info('Event diterima server. Jalankan `php artisan realtime:listen` untuk memastikan sampai ke klien.');

        return self::SUCCESS;
    }

    /**
     * Topik tujuan.
     *
     * Tanpa `--topic`, perintah ini mengirim ke dashboard admin. Untuk menguji
     * channel kasir, sebutkan topiknya tanpa suffix rahasia, misalnya
     * `cashier:ESP-AABBCCDDEEFF`; suffiksnya ditambahkan di sini.
     */
    private function resolveTopic(): string
    {
        $given = $this->option('topic');

        if (is_string($given) && $given !== '') {
            return RealtimeTopic::attachSecret($given);
        }

        return RealtimeTopic::adminDashboard();
    }
}