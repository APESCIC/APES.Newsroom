<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offers')) {
            Schema::create('offers', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique('offers_code_uq');
                $table->string('name');
                $table->string('discount_type'); // percent|amount
                $table->unsignedInteger('discount_value');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->unsignedInteger('max_redemptions')->nullable();
                $table->unsignedInteger('redemption_count')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('stripe_coupon_id')->nullable();
                $table->string('stripe_promotion_code_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('offer_redemptions')) {
            Schema::create('offer_redemptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('stripe_checkout_session_id')->nullable();
                $table->timestamps();

                $table->unique(['offer_id', 'user_id'], 'offer_redemptions_offer_user_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_redemptions');
        Schema::dropIfExists('offers');
    }
};
