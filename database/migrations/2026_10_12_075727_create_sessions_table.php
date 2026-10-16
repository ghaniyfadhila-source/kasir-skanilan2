<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            // UUID, bukan foreignId(). Semua model aplikasi memakai primary key
            // UUID, dan Laravel menulis id user ke kolom ini setiap kali login.
            // Kolom bigint akan menolak UUID itu di PostgreSQL dengan
            // "invalid input syntax for type bigint". SQLite tidak menegakkan
            // tipe kolom, jadi bug ini hanya muncul di PostgreSQL.
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
