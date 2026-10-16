<?php

namespace App\Events;

use App\Models\MenuItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Harga, ketersediaan, atau status aktif sebuah item menu berubah.
 *
 * Semua kasir yang sedang terbuka perlu tahu supaya tampilan harga di layar
 * tidak lagi sama dengan yang akan ditagih.
 */
class MenuItemChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  string  $action  apa yang terjadi: created|updated|deleted
     */
    public function __construct(
        public readonly MenuItem $menuItem,
        public readonly string $action = 'updated',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'menu_item_id' => $this->menuItem->id,
            'name' => $this->menuItem->name,
            'price_cents' => $this->menuItem->price_cents,
            'is_active' => (bool) $this->menuItem->is_active,
            'action' => $this->action,
        ];
    }
}