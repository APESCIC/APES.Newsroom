<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('membership_plans')) {
            Schema::create('membership_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique('membership_plans_slug_uq');
                $table->string('interval'); // month|year
                $table->unsignedInteger('amount_pence');
                $table->string('currency', 3)->default('gbp');
                $table->string('stripe_price_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stripe_webhook_events')) {
            Schema::create('stripe_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique('stripe_webhook_events_id_uq');
                $table->string('type');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        $now = now();

        foreach ([
            [
                'name' => 'Monthly supporter',
                'slug' => 'monthly',
                'interval' => 'month',
                'amount_pence' => 500,
                'stripe_price_id' => env('STRIPE_PRICE_MONTHLY'),
            ],
            [
                'name' => 'Yearly supporter',
                'slug' => 'yearly',
                'interval' => 'year',
                'amount_pence' => 5000,
                'stripe_price_id' => env('STRIPE_PRICE_YEARLY'),
            ],
        ] as $plan) {
            $exists = DB::table('membership_plans')->where('slug', $plan['slug'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('membership_plans')->insert([
                'name' => $plan['name'],
                'slug' => $plan['slug'],
                'interval' => $plan['interval'],
                'amount_pence' => $plan['amount_pence'],
                'currency' => 'gbp',
                'stripe_price_id' => $plan['stripe_price_id'] ?: null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasColumn('memberships', 'membership_plan_id')) {
            Schema::table('memberships', function (Blueprint $table) {
                $table->foreignId('membership_plan_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('membership_plans')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('memberships', 'membership_plan_id')) {
            Schema::table('memberships', function (Blueprint $table) {
                $table->dropConstrainedForeignId('membership_plan_id');
            });
        }

        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('membership_plans');
    }
};
