<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_check_results', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('status', 10);
            $table->string('message', 255);
            $table->unsignedInteger('duration_ms');
            $table->string('trigger', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');

            $table->index(['provider', 'checked_at'], 'integration_check_results_provider_checked_idx');
            $table->index('checked_at', 'integration_check_results_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_check_results');
    }
};
