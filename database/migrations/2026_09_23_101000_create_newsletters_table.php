<?php

use App\Enums\MailingList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * MySQL identifiers max out at 64 characters. The default name for this
     * unique index is 65, which failed the beta deploy after earlier DDL had
     * already committed.
     */
    private const SUBSCRIPTION_NEWSLETTER_UNIQUE = 'mailing_subs_contact_newsletter_uq';

    public function up(): void
    {
        if (! Schema::hasTable('newsletters')) {
            Schema::create('newsletters', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('legacy_list')->nullable()->unique();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        $now = now();

        foreach (MailingList::cases() as $list) {
            $seeded = DB::table('newsletters')->where('legacy_list', $list->value)->exists();

            if ($seeded) {
                continue;
            }

            DB::table('newsletters')->insert([
                'name' => $list->label(),
                'slug' => Str::slug($list->label()),
                'description' => $list->purpose(),
                'legacy_list' => $list->value,
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasColumn('mailing_list_subscriptions', 'newsletter_id')) {
            Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
                $table->foreignId('newsletter_id')->nullable()->after('mailing_contact_id')->constrained('newsletters')->nullOnDelete();
            });
        }

        $ids = DB::table('newsletters')->pluck('id', 'legacy_list');

        foreach ($ids as $legacyList => $newsletterId) {
            DB::table('mailing_list_subscriptions')
                ->where('list', $legacyList)
                ->whereNull('newsletter_id')
                ->update(['newsletter_id' => $newsletterId]);
        }

        if (! $this->hasSubscriptionNewsletterUnique()) {
            Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
                $table->unique(
                    ['mailing_contact_id', 'newsletter_id'],
                    self::SUBSCRIPTION_NEWSLETTER_UNIQUE,
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
            $table->dropUnique(self::SUBSCRIPTION_NEWSLETTER_UNIQUE);
            $table->dropConstrainedForeignId('newsletter_id');
        });

        Schema::dropIfExists('newsletters');
    }

    private function hasSubscriptionNewsletterUnique(): bool
    {
        foreach (Schema::getIndexes('mailing_list_subscriptions') as $index) {
            if (($index['name'] ?? '') === self::SUBSCRIPTION_NEWSLETTER_UNIQUE) {
                return true;
            }
        }

        return false;
    }
};
