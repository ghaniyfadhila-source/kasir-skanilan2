<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Console;
use App\Models\RfidCard;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Penulis jejak audit ke tabel `audit_logs`.
 *
 * Membedakan dua pihak:
 * - subject : siapa yang KENA aksi (siswa yang top-up, pembeli yang tap)
 * - actor   : siapa yang MELAKUKAN aksi (admin yang menekan tombol)
 *
 * Kalau `actor` tidak diisi, diambil dari user yang sedang login. Aksi yang
 * dilakukan sistem (mis. auto-void scheduler) tidak punya actor.
 */
class AuditLogger
{
    public const ACT_USER_CREATED = 'user.created';
    public const ACT_USER_UPDATED = 'user.updated';
    public const ACT_USER_DELETED = 'user.deleted';
    public const ACT_RFID_ASSIGNED = 'rfid.assigned';
    public const ACT_RFID_REVOKED = 'rfid.revoked';
    public const ACT_BALANCE_TOPUP = 'balance.topup';
    public const ACT_CONSOLE_CREATED = 'console.created';
    public const ACT_CONSOLE_UPDATED = 'console.updated';
    public const ACT_CONSOLE_DELETED = 'console.deleted';
    public const ACT_CONSOLE_CREDENTIALS_ROTATED = 'console.credentials_rotated';
    public const ACT_MENU_CREATED = 'menu.created';
    public const ACT_MENU_UPDATED = 'menu.updated';
    public const ACT_MENU_DELETED = 'menu.deleted';
    public const ACT_MENU_REORDERED = 'menu.reordered';
    public const ACT_TRANSACTION_COMPLETED = 'transaction.completed';
    public const ACT_TRANSACTION_VOIDED_TIMEOUT = 'transaction.voided_timeout';
    public const ACT_TRANSACTION_VOIDED_BY_CASHIER = 'transaction.voided_by_cashier';
    public const ACT_ADMIN_LOGIN = 'admin.login';
    public const ACT_ADMIN_LOGOUT = 'admin.logout';
    public const ACT_ADMIN_PASSWORD_CHANGED = 'admin.password_changed';
    public const ACT_ADMIN_SETTINGS_UPDATED = 'admin.settings_updated';
    public const ACT_CASHIER_LOGIN = 'cashier.login';
    public const ACT_SETTINGS_UPDATED = 'settings.updated';
    public const ACT_PASSWORD_RESET_GENERATED = 'admin.password_reset_generated';
    public const ACT_PASSWORD_RESET_USED = 'user.password_reset';

    /**
     * @param  string       $action       Lihat konstanta ACT_* di atas
     * @param  array        $metadata     Detail tambahan (disimpan sebagai JSON)
     * @param  string|null  $consoleId    Konsol terkait
     * @param  string|null  $subjectUserId User yang KENA aksi
     * @param  User|null    $actor        Admin yang melakukan; default = user login
     */
    public function log(
        string $action,
        array $metadata = [],
        ?string $consoleId = null,
        ?string $subjectUserId = null,
        ?User $actor = null
    ): AuditLog {
        $actor ??= Auth::user();

        $record = AuditLog::create([
            'actor_id' => $actor?->id,
            'user_id' => $subjectUserId,
            'console_id' => $consoleId,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'ip_address' => $this->clientIp(),
        ]);

        // Saluran log file tetap dipakai supaya jejak ada walau DB bermasalah.
        Log::channel('audit')->info($action, array_merge($metadata, [
            'actor_id' => $actor?->id,
            'subject_user_id' => $subjectUserId,
            'console_id' => $consoleId,
        ]));

        return $record;
    }

    /**
     * Log aksi terhadap sebuah model, dengan subjek & konsol diambil otomatis
     * dari model tersebut.
     *
     * Penting: `audit_logs.user_id` adalah user yang KENA aksi, bukan id model
     * yang berubah. Untuk Console/MenuItem/Category tidak ada user subjek,
     * jadi kolomnya dibiarkan null.
     *
     * @param bool $attachConsole false saat model sudah dihapus, supaya
     *                           console_id tidak menunjuk baris yang hilang
     *                           (FK akan menolak). Id konsol tetap ada di metadata.
     */
    public function logFor(
        string $action,
        object $subject,
        array $metadata = [],
        ?User $actor = null,
        bool $attachConsole = true
    ): AuditLog {
        return $this->log(
            $action,
            $metadata,
            $attachConsole ? $this->consoleIdOf($subject) : null,
            $this->subjectUserIdOf($subject),
            $actor,
        );
    }

    private function consoleIdOf(object $subject): ?string
    {
        return match (true) {
            $subject instanceof Console => $subject->id,
            $subject instanceof Transaction => $subject->console_id,
            default => $subject->console_id ?? null,
        };
    }

    private function subjectUserIdOf(object $subject): ?string
    {
        return match (true) {
            $subject instanceof User => $subject->id,
            $subject instanceof RfidCard => $subject->user_id,
            $subject instanceof Transaction => $subject->user_id,
            default => null,
        };
    }

    private function clientIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            // Di luar konteks HTTP (mis. command scheduler) tidak ada request.
            return null;
        }
    }
}