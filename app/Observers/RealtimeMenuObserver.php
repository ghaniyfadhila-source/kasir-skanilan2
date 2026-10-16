<?php

namespace App\Observers;

use App\Events\MenuItemChanged;
use App\Models\MenuItem;

/**
 * Mengirim Realtime saat item menu berubah.
 *
 * Dipakai sebagai observer, bukan pemanggilan manual di controller, supaya
 * perubahan dari mana pun - form edit, drag-sort, seeder - ikut terkirim.
 * Kalau tiap pemanggil harus ingat memanggil broadcast, pasti ada yang
 * terlewat dan layar kasir menampilkan harga lama.
 */
class RealtimeMenuObserver
{
    /**
     * Atribut yang kalau berubah harus sampai ke layar kasir.
     *
     * @var string[]
     */
    private const VISIBLE_FIELDS = ['price_cents', 'name', 'is_active'];

    public function created(MenuItem $menuItem): void
    {
        MenuItemChanged::dispatch($menuItem, 'created');
    }

    public function updated(MenuItem $menuItem): void
    {
        if (! $this->touchesVisibleField($menuItem)) {
            return;
        }

        MenuItemChanged::dispatch($menuItem, 'updated');
    }

    public function deleted(MenuItem $menuItem): void
    {
        MenuItemChanged::dispatch($menuItem, 'deleted');
    }

    /**
     * Hanya atribut yang benar-benar tampil di layar kasir yang memicu
     * broadcast. Tanpa ini, satu `updated` pada kolom yang tidak terlihat
     * tetap menembus lewat jaringan.
     */
    private function touchesVisibleField(MenuItem $menuItem): bool
    {
        $changed = $menuItem->getChanges();

        foreach (self::VISIBLE_FIELDS as $field) {
            if (array_key_exists($field, $changed)) {
                return true;
            }
        }

        return false;
    }
}