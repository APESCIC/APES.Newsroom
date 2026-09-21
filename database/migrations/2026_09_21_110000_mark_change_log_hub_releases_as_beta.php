<?php

use Database\Seeders\ReleaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deploy runs migrate, not db:seed. Re-upsert baseline notes so live
        // Change Log Hub rows move from Stable to Beta without a manual edit.
        (new ReleaseSeeder)->run();

        DB::table('releases')
            ->where('channel', 'stable')
            ->update(['channel' => 'beta']);

        $rows = DB::table('releases')
            ->whereNotNull('version_type')
            ->where('version_type', 'like', '%stable%')
            ->get(['id', 'version_type']);

        foreach ($rows as $row) {
            DB::table('releases')
                ->where('id', $row->id)
                ->update([
                    'version_type' => str_ireplace('stable', 'beta', (string) $row->version_type),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally leave Beta channel rows; they reflect product history
        // during the pre-stable period.
    }
};
