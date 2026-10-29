<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('source', 20)->default('mailgun');
            $table->string('type', 30)->index();
            $table->string('severity', 20)->nullable();
            $table->string('recipient')->index();
            $table->string('domain')->nullable();
            $table->text('reason')->nullable();
            $table->text('url')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_events');
    }
};
