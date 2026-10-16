<?php

/**
 * Konfigurasi Supabase Realtime.
 *
 * Realtime dipakai untuk pushes dari backend ke browser: halaman kasir berpindah
 * halaman otomatis setelah pembayaran, dashboard admin menampilkan transaksi
 * baru tanpa perlu refresh.
 *
 * CATATAN SOAL KEAMANAN
 * ---------------------
 * Channel broadcast Supabase secara bawaan bersifat publik: siapa pun yang
 * mengetahui nama topic bisa berlangganan, tanpa perlu login. Kunci anon memang
 * publik (terpasang di browser), jadi yang melindungi data di sini adalah
 * `channel_secret` yang ditambahkan ke nama topic dan hanya dikirim ke halaman yang
 * sudah terautentikasi.
 *
 * Ini keamanan berbasis penyembunyian, bukan kriptografi. Untuk instalasi yang
 * butuh jaminan lebih kuat, jalurnya adalah Supabase Auth + channel privat
 * (`private: true` di sisi klien) beserta policy di tabel `realtime.messages`.
 * Itu butuh JWT Secret project yang tidak dimiliki aplikasi ini.
 *
 * Pabrik `channel_secret` dibuat oleh `php artisan supabase:configure`.
 */
return [

    /*
     | Kunci server. Wajib punya izin publish. `sb_secret_` (format baru) dan
     | JWT `service_role` sama-sama bisa dipakai.
     */
    'server_key' => env('SUPABASE_SERVICE_ROLE_KEY') ?: env('SUPABASE_SECRET_KEY'),

    /*
     | Kunci untuk browser. Hanya boleh punya izin subscribe.
     */
    'client_key' => env('SUPABASE_ANON_KEY') ?: env('SUPABASE_PUBLISHABLE_KEY'),

    /*
     | URL project Supabase.
     */
    'url' => env('SUPABASE_URL'),

    /*
     | Rahasia yang ditambahkan ke nama setiap topic.
     |
     | Tanpa ini, nama topic seperti `admin:dashboard` bisa ditebak siapa saja,
     | dan karena kuncinya ikut terpasang di browser, klien bisa berlangganan
     | dan membaca data transaksi.
     |
     | Panjang minimal 32 karakter.
     */
    'channel_secret' => env('REALTIME_CHANNEL_SECRET'),

    /*
     | Berapa lama menunggu jawaban server sebelum menyerah.
     */
    'timeout' => (int) env('REALTIME_TIMEOUT', 5),

    /*
     | Berapa lama `realtime:listen` menunggu event sebelum keluar, dalam detik.
     | Nol berarti menunggu tanpa batas.
     */
    'listen_timeout' => (int) env('REALTIME_LISTEN_TIMEOUT', 20),

];