<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('key_hash', 64)->unique('content_api_keys_hash_uq');
            $table->string('prefix', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_api_keys');
    }
};
