<?php

namespace App\Observers;

use App\Events\ConsoleStatusChanged;
use App\Models\Console;

/**
 * Mengirim Realtime saat status konsol berubah.
 *
 * Sumbernya heartbeat dari ESP8266 dan perubahan manual dari halaman admin.
 * Dipisah dari observer menu karena PHP tidak mengizinkan satu kelas observer
 * punya dua method `updated()` dengan tipe parameter berbeda.
 */
class RealtimeConsoleObserver
{
    /**
     * @var string[]
     */
    private const VISIBLE_FIELDS = ['status', 'last_heartbeat_at'];

    public function updated(Console $console): void
    {
        $changed = $console->getChanges();

        foreach (self::VISIBLE_FIELDS as $field) {
            if (array_key_exists($field, $changed)) {
                ConsoleStatusChanged::dispatch($console);

                return;
            }
        }
    }
}