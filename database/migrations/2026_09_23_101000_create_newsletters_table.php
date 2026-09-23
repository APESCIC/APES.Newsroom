<?php

use App\Enums\MailingList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('legacy_list')->nullable()->unique();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach (MailingList::cases() as $list) {
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

        Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
            $table->foreignId('newsletter_id')->nullable()->after('mailing_contact_id')->constrained('newsletters')->nullOnDelete();
        });

        $ids = DB::table('newsletters')->pluck('id', 'legacy_list');

        foreach ($ids as $legacyList => $newsletterId) {
            DB::table('mailing_list_subscriptions')
                ->where('list', $legacyList)
                ->update(['newsletter_id' => $newsletterId]);
        }

        Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
            $table->unique(['mailing_contact_id', 'newsletter_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['mailing_contact_id', 'newsletter_id']);
            $table->dropConstrainedForeignId('newsletter_id');
        });

        Schema::dropIfExists('newsletters');
    }
};
