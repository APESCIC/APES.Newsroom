<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * v1.17.0 (#503): unique nullable user_id, email_normalized (unique, backfilled),
 * optional name. Duplicate user_id rows are cleared before the unique index
 * (keep the lowest id; null the rest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailing_contacts', function (Blueprint $table) {
            $table->string('email_normalized')->nullable()->after('email');
            $table->string('name')->nullable()->after('email_normalized');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::table('mailing_contacts')->orderBy('id')->each(function (object $row): void {
                DB::table('mailing_contacts')->where('id', $row->id)->update([
                    'email_normalized' => Str::lower(trim((string) $row->email)),
                ]);
            });
        } else {
            DB::table('mailing_contacts')->update([
                'email_normalized' => DB::raw('LOWER(TRIM(email))'),
            ]);
        }

        // Duplicate user_id: keep the earliest contact, unlink the rest.
        $duplicateUserIds = DB::table('mailing_contacts')
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $ids = DB::table('mailing_contacts')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->pluck('id');
            $keep = $ids->shift();
            if ($ids->isNotEmpty()) {
                DB::table('mailing_contacts')
                    ->whereIn('id', $ids->all())
                    ->update(['user_id' => null]);
            }
            unset($keep);
        }

        Schema::table('mailing_contacts', function (Blueprint $table) {
            $table->string('email_normalized')->nullable(false)->change();
            $table->unique('email_normalized');
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('mailing_contacts', function (Blueprint $table) {
            $table->dropUnique(['email_normalized']);
            $table->dropUnique(['user_id']);
            $table->dropColumn(['email_normalized', 'name']);
        });
    }
};
