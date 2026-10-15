<?php

namespace App\Http\Controllers;

use App\Models\Console;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Console yang sudah diautentikasi oleh middleware `cashier` atau
     * `console.auth`.
     *
     * Disimpan di request *attributes*, bukan input, supaya tidak ikut masuk
     * `$request->all()` lalu bocor ke validasi atau log. Perlu diakses lewat
     * method ini karena `Request::__get()` hanya membaca input + route param.
     */
    protected function console(Request $request): Console
    {
        $console = $request->attributes->get('console');

        abort_unless($console instanceof Console, 500, 'Console belum terautentikasi pada request ini.');

        return $console;
    }
}