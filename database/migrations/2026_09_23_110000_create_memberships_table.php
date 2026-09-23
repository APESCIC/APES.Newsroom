<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('memberships')) {
            Schema::create('memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('free')->index();
                $table->string('interval')->nullable();
                $table->string('stripe_customer_id')->nullable()->index();
                $table->string('stripe_subscription_id')->nullable()->index();
                $table->timestamp('current_period_end')->nullable();
                $table->timestamps();

                $table->unique('user_id', 'memberships_user_id_uq');
            });
        }

        $now = now();

        $existingUserIds = DB::table('memberships')->pluck('user_id')->all();

        DB::table('users')
            ->when($existingUserIds !== [], fn ($query) => $query->whereNotIn('id', $existingUserIds))
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($now): void {
                $rows = [];

                foreach ($users as $user) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'status' => 'free',
                        'interval' => null,
                        'stripe_customer_id' => null,
                        'stripe_subscription_id' => null,
                        'current_period_end' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('memberships')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
