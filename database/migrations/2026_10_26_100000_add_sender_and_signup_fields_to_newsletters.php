<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REPLY_TO = 'info@apes.org.uk';

    /**
     * Newsletters as configured in Ghost (ghost-legacy.apesnews.org.uk).
     * Existing rows are matched by legacy_list or slug; sender fields only
     * fill blanks so staff edits are never overwritten on re-run.
     *
     * @var list<array<string, mixed>>
     */
    private const NEWSLETTERS = [
        [
            'legacy_list' => 'apes_cic',
            'slug' => 'apes-cic',
            'name' => 'APES CIC',
            'ghost_slug' => 'default-newsletter',
            'sender_name' => 'APES Newsroom',
            'sender_email' => 'app@apesnews.org.uk',
            'archived' => false,
        ],
        [
            'legacy_list' => null,
            'slug' => 'apes-times',
            'name' => 'APES Times',
            'description' => 'News and features from APES Times.',
            'ghost_slug' => 'apes-times',
            'sender_name' => 'APES Times',
            'sender_email' => 'times@apesnews.org.uk',
            'archived' => false,
        ],
        [
            'legacy_list' => 'apes_shelter_rescue',
            'slug' => 'apes-shelter-rescue',
            'name' => 'APES Shelter & Rescue',
            'ghost_slug' => 'shelter-rescue',
            'sender_name' => 'APES Shelter & Rescue',
            'sender_email' => 'shelterandrescue@apesnews.org.uk',
            'archived' => false,
        ],
        [
            'legacy_list' => 'apes_pet_care_clinic',
            'slug' => 'apes-pet-care-clinic',
            'name' => 'APES Pet Care Clinic',
            'ghost_slug' => 'apes-pet-care-clinic',
            'sender_name' => 'APES Pet Care Clinic',
            'sender_email' => 'noreply@apesnews.org.uk',
            'archived' => false,
        ],
        [
            'legacy_list' => null,
            'slug' => 'myapes-portal',
            'name' => 'MyAPES Portal',
            'description' => 'Updates about the MyAPES member portal.',
            'ghost_slug' => 'myapes-portal-newsroom',
            'sender_name' => 'MyAPES Portal',
            'sender_email' => 'myapes@apesnews.org.uk',
            'archived' => false,
        ],
        [
            'legacy_list' => null,
            'slug' => 'stream-4-apes',
            'name' => 'Stream 4 APES',
            'description' => 'Stream 4 APES fundraising updates.',
            'ghost_slug' => 'stream-4-apes',
            'sender_name' => 'Stream 4 APES',
            'sender_email' => 'stream4apes@apesnews.org.uk',
            'archived' => false,
        ],
    ];

    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            if (! Schema::hasColumn('newsletters', 'sender_name')) {
                $table->string('sender_name')->nullable()->after('description');
            }
            if (! Schema::hasColumn('newsletters', 'sender_email')) {
                $table->string('sender_email')->nullable()->after('sender_name');
            }
            if (! Schema::hasColumn('newsletters', 'reply_to')) {
                $table->string('reply_to')->nullable()->after('sender_email');
            }
            if (! Schema::hasColumn('newsletters', 'subscribe_on_signup')) {
                $table->boolean('subscribe_on_signup')->default(false)->after('reply_to');
            }
            if (! Schema::hasColumn('newsletters', 'sender_verification')) {
                $table->json('sender_verification')->nullable()->after('subscribe_on_signup');
            }
            if (! Schema::hasColumn('newsletters', 'ghost_slug')) {
                $table->string('ghost_slug')->nullable()->unique()->after('legacy_list');
            }
        });

        $now = now();

        foreach (self::NEWSLETTERS as $definition) {
            $existing = DB::table('newsletters')
                ->when(
                    $definition['legacy_list'] !== null,
                    fn ($query) => $query->where('legacy_list', $definition['legacy_list']),
                    fn ($query) => $query->where('slug', $definition['slug']),
                )
                ->first();

            if ($existing) {
                $updates = [];
                foreach (['sender_name', 'sender_email', 'ghost_slug'] as $field) {
                    if ($existing->{$field} === null && $definition[$field] !== null) {
                        $updates[$field] = $definition[$field];
                    }
                }
                if ($existing->reply_to === null && $definition['sender_email'] !== null) {
                    $updates['reply_to'] = self::REPLY_TO;
                }
                if ($updates !== []) {
                    $updates['subscribe_on_signup'] = ! $definition['archived'];
                    $updates['updated_at'] = $now;
                    DB::table('newsletters')->where('id', $existing->id)->update($updates);
                }

                continue;
            }

            DB::table('newsletters')->insert([
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'description' => $definition['description'] ?? null,
                'legacy_list' => null,
                'ghost_slug' => $definition['ghost_slug'],
                'sender_name' => $definition['sender_name'],
                'sender_email' => $definition['sender_email'],
                'reply_to' => $definition['sender_email'] !== null ? self::REPLY_TO : null,
                'subscribe_on_signup' => ! $definition['archived'],
                'archived_at' => $definition['archived'] ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->dropUnique(['ghost_slug']);
            $table->dropColumn([
                'sender_name',
                'sender_email',
                'reply_to',
                'subscribe_on_signup',
                'sender_verification',
                'ghost_slug',
            ]);
        });
    }
};
