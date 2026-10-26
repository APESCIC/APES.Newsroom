<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Imported Ghost posts can carry canonical URLs such as
 * `__GHOST_URL__/p/<uuid>/` (Ghost preview links). They never resolve, break
 * the canonical tag and fail editor validation, so they are cleared (#556).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['posts', 'pages'] as $table) {
            $ids = DB::table($table)
                ->where('canonical_url', 'like', '%GHOST%URL%')
                ->pluck('canonical_url', 'id')
                ->filter(fn ($url) => str_contains((string) $url, '__GHOST_URL__'))
                ->keys();

            if ($ids->isNotEmpty()) {
                DB::table($table)->whereIn('id', $ids)->update(['canonical_url' => null]);
            }
        }
    }

    public function down(): void
    {
        // Cleared values were unusable placeholders; nothing to restore.
    }
};
