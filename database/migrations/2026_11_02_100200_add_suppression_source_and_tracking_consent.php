<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppressions', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('reason');
        });

        Schema::table('mailing_contacts', function (Blueprint $table) {
            $table->timestamp('open_tracking_consented_at')->nullable();
            $table->timestamp('click_tracking_opted_out_at')->nullable();
        });

        Schema::table('email_sending_domains', function (Blueprint $table) {
            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);
            $table->json('webhooks')->nullable();
            $table->timestamp('webhooks_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_sending_domains', function (Blueprint $table) {
            $table->dropColumn(['track_opens', 'track_clicks', 'webhooks', 'webhooks_checked_at']);
        });

        Schema::table('mailing_contacts', function (Blueprint $table) {
            $table->dropColumn(['open_tracking_consented_at', 'click_tracking_opted_out_at']);
        });

        Schema::table('suppressions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
