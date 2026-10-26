<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Ghost's archived newsletters are not imported (#499). Rows are only
     * deleted while nothing references them.
     */
    private const SLUGS = ['pet-shop-newsroom', 'mailing-list', 'animal-therapy-waiting-list'];

    public function up(): void
    {
        $rows = DB::table('newsletters')
            ->whereIn('slug', self::SLUGS)
            ->whereNotNull('archived_at')
            ->get(['id', 'slug']);

        foreach ($rows as $row) {
            $inUse = DB::table('mailing_list_subscriptions')->where('newsletter_id', $row->id)->exists()
                || DB::table('newsletter_segments')->where('newsletter_id', $row->id)->orWhere('also_newsletter_id', $row->id)->exists()
                || DB::table('campaigns')->where('newsletter_id', $row->id)->exists();

            if ($inUse) {
                Log::warning('Archived newsletter kept because it is still referenced.', ['slug' => $row->slug]);

                continue;
            }

            DB::table('newsletters')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        // Deleted rows held no data; nothing to restore.
    }
};
