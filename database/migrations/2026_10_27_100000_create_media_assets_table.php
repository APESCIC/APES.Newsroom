<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('mime', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->string('original_name')->nullable();
            $table->string('alt')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('failure_reason', 500)->nullable();
            $table->json('variants')->nullable();
            $table->string('source', 32)->default('upload');
            $table->string('source_url', 2048)->nullable();
            $table->char('sha256', 64)->unique();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
