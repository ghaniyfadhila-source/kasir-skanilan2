<?php

namespace App\Models\Concerns;

/**
 * Menandai model yang perubahannya harus masuk ke jejak audit.
 *
 * Dipasang via observer `AuditObserver`, bukan log manual di setiap controller,
 * supaya perubahan dari sumber mana pun (controller, seeder, artisan command,
 * API) tetap tercatat.
 */
trait Auditable
{
    /**
     * Prefix nama aksi untuk model ini, misal 'menu' -> menu.created.
     */
    abstract public function auditPrefix(): string;

    /**
     * Atribut yang boleh ikut dicatat nilai lama/barunya.
     *
     * Sengaja tidak memuat `password`, `password_hash`, `api_key`, atau
     * token apa pun. Daftar ini adalah satu-satunya tempat data sensitif
     * bisa bocor ke tabel audit.
     */
    public function auditWhitelistedAttributes(): array
    {
        return ['name', 'email', 'role', 'price_cents', 'is_active', 'status',
            'stock', 'balance_cents', 'device_id', 'location', 'sort_order',
            'rfid_uid', 'label'];
    }
}