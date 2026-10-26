<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Newsletter slug => [channel value, display order]. Rows are linked only
     * while unlinked, so staff edits survive a re-run.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const CHANNELS = [
        'apes-cic' => ['apes_cic', 1],
        'apes-shelter-rescue' => ['apes_shelter_rescue', 2],
        'apes-pet-care-clinic' => ['apes_pet_care_clinic', 3],
        'apes-times' => ['apes_times', 4],
        'myapes-portal' => ['myapes_portal', 5],
        'stream-4-apes' => ['stream_4_apes', 6],
    ];

    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            if (! Schema::hasColumn('newsletters', 'channel')) {
                $table->string('channel')->nullable()->unique()->after('ghost_slug');
            }
            if (! Schema::hasColumn('newsletters', 'show_on_home')) {
                $table->boolean('show_on_home')->default(false)->after('channel');
            }
            if (! Schema::hasColumn('newsletters', 'show_in_nav')) {
                $table->boolean('show_in_nav')->default(false)->after('show_on_home');
            }
            if (! Schema::hasColumn('newsletters', 'display_order')) {
                $table->unsignedSmallInteger('display_order')->default(0)->after('show_in_nav');
            }
            if (! Schema::hasColumn('newsletters', 'public_label')) {
                $table->string('public_label')->nullable()->after('display_order');
            }
            if (! Schema::hasColumn('newsletters', 'public_description')) {
                $table->text('public_description')->nullable()->after('public_label');
            }
        });

        $now = now();

        foreach (self::CHANNELS as $slug => [$channel, $order]) {
            if (DB::table('newsletters')->where('channel', $channel)->exists()) {
                continue;
            }

            $id = DB::table('newsletters')
                ->whereNull('channel')
                ->where(fn ($query) => $query->where('legacy_list', $channel)->orWhere('slug', $slug))
                ->orderByRaw('case when legacy_list = ? then 0 else 1 end', [$channel])
                ->value('id');

            if ($id === null) {
                continue;
            }

            DB::table('newsletters')
                ->where('id', $id)
                ->update([
                    'channel' => $channel,
                    'show_on_home' => true,
                    'show_in_nav' => true,
                    'display_order' => $order,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->dropUnique(['channel']);
            $table->dropColumn([
                'channel',
                'show_on_home',
                'show_in_nav',
                'display_order',
                'public_label',
                'public_description',
            ]);
        });
    }
};
