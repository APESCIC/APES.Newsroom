<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Settled in #630 (v1.25.1): MyAPES Portal becomes MyAPES at /myapes.
     * ghost_slug is left alone so Ghost imports still match, and wording
     * staff have already changed is kept.
     */
    public function up(): void
    {
        $this->rename(fromSlug: 'myapes-portal', toSlug: 'myapes', fromName: 'MyAPES Portal', toName: 'MyAPES');
    }

    public function down(): void
    {
        $this->rename(fromSlug: 'myapes', toSlug: 'myapes-portal', fromName: 'MyAPES', toName: 'MyAPES Portal');
    }

    private function rename(string $fromSlug, string $toSlug, string $fromName, string $toName): void
    {
        $newsletter = DB::table('newsletters')->where('slug', $fromSlug)->first(['id', 'name', 'sender_name', 'public_label']);
        if ($newsletter === null || DB::table('newsletters')->where('slug', $toSlug)->exists()) {
            return;
        }

        DB::table('newsletters')->where('id', $newsletter->id)->update(array_merge(
            ['slug' => $toSlug, 'updated_at' => now()],
            $newsletter->name === $fromName ? ['name' => $toName] : [],
            $newsletter->sender_name === $fromName ? ['sender_name' => $toName] : [],
            $newsletter->public_label === $fromName ? ['public_label' => $toName] : [],
        ));
    }
};
