<?php

namespace App\Observers;

use App\Models\Concerns\Auditable;
use App\Services\AuditLogger;

/**
 * Mencatat create/update/delete model yang memakai trait `Auditable`.
 *
 * Nilai yang dicatat hanya atribut yang ada di whitelist trait, jadi password
 * dan api_key tidak pernah bocor ke `audit_logs`.
 */
class AuditObserver
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function created(object $model): void
    {
        $this->record($model, 'created', $this->currentSnapshot($model));
    }

    public function updated(object $model): void
    {
        $changes = $this->diffFor($model);

        // Tidak ada yang berubah(nilainya sama persis) -> jangan noisy.
        if ($changes === []) {
            return;
        }

        $this->record($model, 'updated', ['changes' => $changes]);
    }

    public function deleted(object $model): void
    {
        // Baris model sudah hilang saat observer ini jalan, jadi console_id
        // tidak boleh diisi lagi (FK akan menolak). Id konsol tetap tersimpan
        // di metadata.
        $this->record($model, 'deleted', $this->currentSnapshot($model), attachConsole: false);
    }

    private function record(object $model, string $suffix, array $metadata, bool $attachConsole = true): void
    {
        $this->audit->logFor($model->auditPrefix().'.'.$suffix, $model, $metadata, null, $attachConsole);
    }

    /** Nilai_atribut yang boleh dicatat untuk model saat ini. */
    private function currentSnapshot(object $model): array
    {
        $snapshot = ['id' => $model->getKey()];

        foreach ($model->auditWhitelistedAttributes() as $attribute) {
            if ($model->offsetExists($attribute)) {
                $snapshot[$attribute] = $model->getAttribute($attribute);
            }
        }

        return $snapshot;
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function diffFor(object $model): array
    {
        if (! method_exists($model, 'getChanges')) {
            return [];
        }

        $changes = [];

        foreach ($model->getChanges() as $attribute => $new) {
            if (! in_array($attribute, $model->auditWhitelistedAttributes(), true)) {
                continue;
            }

            $changes[$attribute] = [
                'from' => $model->getOriginal($attribute),
                'to' => $new,
            ];
        }

        return $changes;
    }
}