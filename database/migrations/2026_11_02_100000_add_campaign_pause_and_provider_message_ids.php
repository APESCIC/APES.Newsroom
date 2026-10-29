<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('completed_at');
            $table->text('pause_reason')->nullable()->after('paused_at');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->string('provider_message_id')->nullable()->after('idempotency_key');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn('provider_message_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'pause_reason']);
        });
    }
};
