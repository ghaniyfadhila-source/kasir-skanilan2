<?php

namespace App\Support;

/**
 * Format angka rupiah untuk ditampilkan ke pengguna.
 *
 * Ada tiga salinan logika ini di codebase - di controller kasir, di controller
 * konsol, dan sebagai accessor pada model Transaction - dan ketiganya bisa
 * berbeda hasil. Modelnya satu tempat supaya tidak ada lagi tempat keempat
 * yang lupa.
 */
final class Rupiah
{
    /**
     * Ubah sen menjadi teks rupiah.
     *
     * Semua nilai uang di sistem ini disimpan sebagai integer sen, jadi 15000
     * berarti Rp 150. Tidak ada pecahan sen yang ditampilkan; harga kantin
     * selalu bulat.
     */
    public static function format(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, 0, ',', '.');
    }

    /**
     * Sama seperti {@see self::format()}, tapi aman untuk nilai null.
     */
    public static function formatNullable(?int $cents): ?string
    {
        return $cents === null ? null : self::format($cents);
    }
}