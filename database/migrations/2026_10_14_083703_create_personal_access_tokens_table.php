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
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            // Kolom id dan tokenable_id tetap auto-increment integer: ini kolom
            // milik Sanctum sendiri, bukan kolom yang memegang id model aplikasi.
            $table->id();

            // uuidMorphs, bukan morphs(). Semua model aplikasi memakai primary
            // key UUID; morphs() membuat tokenable_id bertipe bigint sehingga
            // pembuatan token Sanctum akan gagal di PostgreSQL.
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
