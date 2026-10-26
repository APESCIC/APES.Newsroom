<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('key', 60);
            $table->text('value');
            $table->string('last_four', 4)->default('');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('set_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'key'], 'integration_secrets_provider_key_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_secrets');
    }
};
