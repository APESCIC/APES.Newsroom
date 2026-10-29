<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sender_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_sending_domain_id')->constrained('email_sending_domains')->cascadeOnDelete();
            $table->string('address')->unique();
            $table->string('purpose', 20)->default('news');
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('last_tested_at')->nullable();
            $table->json('last_test_result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('email_sending_domains', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('verified_at');
            $table->json('last_test_result')->nullable()->after('last_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_sending_domains', function (Blueprint $table) {
            $table->dropColumn(['last_tested_at', 'last_test_result']);
        });

        Schema::dropIfExists('email_sender_addresses');
    }
};
