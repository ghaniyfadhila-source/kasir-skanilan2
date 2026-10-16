<?php

namespace App\Console\Commands;

use App\Services\Realtime\RealtimeTopic;
use Illuminate\Console\Command;

/**
 * Mendengarkan channel Realtime dan mencetak event yang masuk.
 *
 * Perintah ini ada karena pengujian Realtime tidak bisa pakai test suite:
 * test suite tidak punya soket WebSocket ke Supabase, dan yang paling penting
 * adalah proving bahwa event benar-benar sampai ke klien - bukan sekadar
 * HTTP 202 dari server publish.
 *
 * Dipakai berpasangan dengan `realtime:probe`:
 *
 *   Terminal 1:  php artisan realtime:listen
 *   Terminal 2:  php artisan realtime:probe
 *
 * Klien Phoenix di bawah ditulis tanpa pustaka tambahan: handshake WebSocket,
 * bingkai teks, dan protokol join Phoenix cukup untuk kasus ini.
 */
class RealtimeListen extends Command
{
    protected $signature = 'realtime:listen
        {--topic= : Topik spesifik untuk didengarkan (sensor suffix, pakai RealtimeTopic)}
        {--device=ESP-AABBCCDDEEFF : Device ID untuk topik kasir/konsol}
        {--seconds=20 : Berapa lama menunggu event}
        {--json : Keluarkan JSON mentah}
        {--debug : Tampilkan bingkai mentah yang masuk dari soket}';

    protected $description = 'Dengarkan channel Realtime dan cetak event yang masuk';

    public function handle(): int
    {
        if (! RealtimeTopic::isConfigured()) {
            $this->error('Realtime belum dikonfigurasi. Yang kurang:');
            foreach (RealtimeTopic::missingConfiguration() as $missing) {
                $this->line('  - '.$missing);
            }

            return self::FAILURE;
        }

        $topic = $this->resolveTopic();
        $seconds = max(1, (int) $this->option('seconds'));

        $this->components->info("Mendengarkan {$topic}");
        $this->line('  <fg=gray>Buka terminal lain: php artisan realtime:probe --topic="'.\Illuminate\Support\Str::beforeLast($topic, ':').'"</>');
        $this->line('  <fg=gray>Atau tunggu event dari aplikasi (tap RFID, ubah menu, heartbeat).</>');
        $this->newLine();

        $socket = $this->connect();

        if ($socket === null) {
            return self::FAILURE;
        }

        $this->join($socket, $topic);
        $this->line('  <fg=green>Channel bergabung. Menunggu '.$seconds.' detik...</>');
        $this->newLine();

        $received = $this->readFrames($socket, $seconds);

        fclose($socket);

        $this->newLine();
        $this->components->twoColumnDetail('Event diterima', $received['events']);
        $this->components->twoColumnDetail('Heartbeat', $received['heartbeats']);

        return $received['events'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Topik yang akan didengarkan.
     *
     * `--topic` boleh diisi setengah jadi (`admin:dashboard`) maupun lengkap;
     * {@see RealtimeTopic::attachSecret()} yang mengurus suffiksnya.
     */
    private function resolveTopic(): string
    {
        $given = $this->option('topic');

        if (is_string($given) && $given !== '') {
            return RealtimeTopic::attachSecret($given);
        }

        return RealtimeTopic::adminDashboard();
    }

    /**
     * Handshake WebSocket ke server Realtime.
     *
     * @return resource|null
     */
    private function connect()
    {
        $host = (string) parse_url((string) config('realtime.url'), PHP_URL_HOST);
        $url = '/realtime/v1/websocket?apikey='.urlencode((string) config('realtime.client_key')).'&vsn=1.0.0';

        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'peer_name' => $host],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            $this->error("Tidak bisa menghubungi {$host}: {$errstr}");

            return null;
        }

        $request = "GET {$url} HTTP/1.1\r\n"
            ."Host: {$host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: ".base64_encode(random_bytes(16))."\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."Origin: ".rtrim((string) config('app.url'), '/')."\r\n\r\n";

        fwrite($socket, $request);
        stream_set_timeout($socket, 10);

        $response = (string) fread($socket, 1024);

        if (! str_contains($response, '101')) {
            $this->error('Handshake ditolak: '.trim(explode("\r\n", $response)[0] ?? '(respons kosong)'));
            fclose($socket);

            return null;
        }

        return $socket;
    }

    /**
     * Kirim frame `phx_join` sesuai protokol Phoenix.
     *
     * @param  resource  $socket
     */
    private function join($socket, string $topic): void
    {
        // Bentuk pesan Phoenix itu datar: topic, event, payload, ref. Kalau
        // payload dibungkus satu lapis lagi, server menjawab
        // "unmatched topic" - bukan karena topiknya salah, tapi karena
        // strukturnya.
        $message = [
            'topic' => 'realtime:'.$topic,
            'event' => 'phx_join',
            'payload' => [
                'config' => [
                    'broadcast' => ['ack' => false, 'self' => false],
                    'presence' => ['key' => ''],
                ],
                'postgres_changes' => [],
            ],
            'ref' => '1',
        ];

        fwrite($socket, $this->frame((string) json_encode($message)));
        stream_set_timeout($socket, 5);
        $reply = (string) fread($socket, 4096);

        $decoded = $this->decode($reply);

        if (str_contains($decoded, '"status":"ok"')) {
            return;
        }

        $this->warn('Balasan join tidak ok: '.mb_substr($decoded, 0, 160));
    }

    /**
     * Baca frame sampai batas waktu, cetak setiap event.
     *
     * @param  resource  $socket
     * @return array{events: int, heartbeats: int}
     */
    private function readFrames($socket, int $seconds): array
    {
        $deadline = time() + $seconds;
        $events = 0;
        $heartbeats = 0;
        $buffer = '';

        stream_set_blocking($socket, false);

        while (time() < $deadline) {
            $read = [$socket];
            $write = $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            if ($read === []) {
                continue;
            }

            $chunk = @fread($socket, 65536);

            if ($chunk === false || $chunk === '') {
                continue;
            }

            if ($this->option('debug')) {
                $this->line(sprintf(
                    '  <fg=gray>[+ %d byte]</> %s',
                    strlen($chunk),
                    substr(bin2hex($chunk), 0, 60)
                ));
            }

            $buffer .= $chunk;

            foreach ($this->splitFrames($buffer) as $frame) {
                $text = $this->decode($frame);

                if ($text === '') {
                    continue;
                }

                $decoded = json_decode($text, true);

                if (! is_array($decoded)) {
                    continue;
                }

                if ($decoded['event'] === 'system') {
                    $heartbeats++;

                    continue;
                }

                $events++;
                $this->render($decoded);
            }
        }

        return ['events' => $events, 'heartbeats' => $heartbeats];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function render(array $decoded): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($decoded, JSON_UNESCAPED_SLASHES));

            return;
        }

        // Bungkus Phoenix untuk broadcast punya bentuk:
        //   { event: "broadcast", payload: { event: "<nama>", payload: { ... } } }
        // Jadi `event` di luar adalah jenis pesan, bukan nama event yang kita
        // kirim. Nama sebenarnya ada satu level di dalam.
        $outer = $decoded['payload'] ?? [];
        $name = is_array($outer) ? ($outer['event'] ?? $decoded['event'] ?? '(?)') : ($decoded['event'] ?? '(?)');
        $body = is_array($outer) ? ($outer['payload'] ?? $outer) : $outer;

        $this->line('  <fg=green>EVENT</> <fg=cyan>'.$name.'</>');
        $this->line('  '.$this->wrap((string) json_encode($body, JSON_UNESCAPED_SLASHES), 8));
    }

    /**
     * Pecah bingkai WebSocket menjadi pesan, sisanya dikembalikan lewat
     * referensi.
     *
     * Sisa itu penting: satu bingkai bisa datang terpotong di tengah. Kalau
     * bagian yang belum utuh dibuang tiap kali baca, bingkai yang terpotong
     * akan hilang begitu saja dan gejalanya "tidak ada event yang sampai"
     * padahal ada.
     *
     * @return string[]
     */
    private function splitFrames(string &$buffer): array
    {
        $frames = [];

        while (strlen($buffer) >= 2) {
            $byte1 = ord($buffer[0]);
            $byte2 = ord($buffer[1]);
            $opcode = $byte1 & 0x0F;
            $length = $byte2 & 0x7F;
            $offset = 2;

            if ($length === 126) {
                if (strlen($buffer) < 4) {
                    break;
                }
                $length = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($buffer) < 10) {
                    break;
                }
                $length = unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
            }

            $total = $offset + $length;

            if (strlen($buffer) < $total) {
                break;
            }

            // Bingkai dikembalikan UTUH, termasuk headernya, supaya
            // decode() bisa mengekstrak payload. Kalau headernya dilepas di
            // sini, decode akan mengira karakter kedua isi bingkai sebagai
            // panjang - dan setiap pesan JSON akan gagal dibaca karena
            // karakter kedua JSON adalah `{` (0x7B).
            $frame = substr($buffer, 0, $total);
            $buffer = substr($buffer, $total);

            // Opcode 0 adalah sisa bingkai sebelumnya, opcode 9 adalah ping.
            if ($opcode === 0 || $opcode === 9) {
                continue;
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Bungkus bingkai teks WebSocket, disamarkan sesuai aturan klien.
     */
    private function frame(string $payload): string
    {
        $length = strlen($payload);
        $header = chr(0x81);

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $header.$mask.$masked;
    }

    /**
     * Buka bingkai server (tidak disamarkan, jadi payload langsung terbaca).
     */
    private function decode(string $frame): string
    {
        if (strlen($frame) < 2) {
            return '';
        }

        $byte2 = ord($frame[1]);
        $length = $byte2 & 0x7F;
        $offset = 2;

        if ($length === 126) {
            $length = unpack('n', substr($frame, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            $length = unpack('J', substr($frame, 2, 8))[1];
            $offset = 10;
        }

        if (strlen($frame) < $offset + $length) {
            return substr($frame, $offset);
        }

        return substr($frame, $offset, $length);
    }

    private function wrap(string $text, int $indent): string
    {
        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach (explode(',', $text) as $part) {
            $lines[] = $pad.trim($part);
        }

        return implode("\n", $lines);
    }
}