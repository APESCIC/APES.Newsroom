<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sending_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('label')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('mailgun_domain')->nullable();
            $table->json('last_check')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sending_domains');
    }
};
