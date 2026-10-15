<?php

namespace App\Console\Commands;

use App\Models\PasswordReset;
use Illuminate\Console\Command;

/**
 * Membersihkan kode reset yang sudah dipakai atau kedaluwarsa lebih dari
 * N jam, supaya tabel password_resets tidak menumpuk selamanya.
 */
class PrunePasswordResets extends Command
{
    protected $signature = 'password-resets:prune
                            {--hours=24 : Hapus kode yang selesai lebih lama dari N jam}';

    protected $description = 'Hapus kode reset password yang sudah dipakai atau kedaluwarsa';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        if ($hours < 1) {
            $this->error('Nilai --hours minimal 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        $deleted = PasswordReset::query()
            ->where(function ($q) use ($cutoff) {
                $q->where('used_at', '<=', $cutoff)
                    ->orWhere('expires_at', '<=', $cutoff);
            })
            ->delete();

        $this->info("$deleted kode reset dihapus (ambang {$hours} jam).");

        return self::SUCCESS;
    }
}