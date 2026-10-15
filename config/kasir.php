<?php

/**
 * Definisi pengaturan aplikasi.
 *
 * Ini satu-satunya sumber kebenaran untuk daftar setting: dipakai oleh
 * Setting::get() untuk nilai bawaan, seeder, dan halaman Pengaturan.
 *
 * PENTING: key memakai underscore, BUKAN titik. Laravel mem-parsing nama
 * atribut validasi dengan notasi titik, sehingga key `order.timeout` akan
 * dianggap sebagai path array `order[timeout]` dan selalu gagal divalidasi.
 *
 * Rules memakai `sometimes|required`: kalau key dikirim, nilainya wajib dan
 * valid; kalau tidak dikirim, tidak divalidasi sama sekali. Ini membuat
 * update sebagian tetap bisa jalan tanpa merusak field lain.
 */
return [

    'settings' => [

        'app_timezone' => [
            'label' => 'Zona waktu',
            'type' => 'string',
            'default' => 'Asia/Jakarta',
            'rules' => 'sometimes|nullable|string|max:64',
        ],

        'app_receipt_prefix' => [
            'label' => 'Awalan nomor struk',
            'type' => 'string',
            'default' => 'TXN',
            'rules' => 'sometimes|nullable|string|max:10',
        ],

        'order_rfid_timeout_seconds' => [
            'label' => 'Batas tunggu tap RFID (detik)',
            'type' => 'int',
            'default' => 60,
            'rules' => 'sometimes|required|integer|min:15|max:300',
        ],

        'order_auto_void_minutes' => [
            'label' => 'Auto-void order offline (menit)',
            'type' => 'int',
            'default' => 5,
            'rules' => 'sometimes|required|integer|min:1|max:60',
        ],

        'printer_show_price' => [
            'label' => 'Tampilkan harga di struk',
            'type' => 'bool',
            'default' => true,
            'rules' => 'sometimes|nullable|boolean',
        ],

    ],

];