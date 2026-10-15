<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            // SPEC: audit_logs.id = UUID PK (konsisten dengan tabel lain,
            // model AuditLog memakai trait HasUuids).
            $table->uuid('id')->primary();
            $table->foreignUuid('console_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['console_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};