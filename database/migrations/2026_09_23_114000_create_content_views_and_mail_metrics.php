<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_views')) {
            Schema::create('content_views', function (Blueprint $table) {
                $table->id();
                $table->string('path', 512)->index();
                $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
                $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('viewed_at')->useCurrent()->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('campaign_recipients')) {
            Schema::table('campaign_recipients', function (Blueprint $table) {
                if (! Schema::hasColumn('campaign_recipients', 'opened_at')) {
                    $table->timestamp('opened_at')->nullable()->after('accepted_at');
                }
                if (! Schema::hasColumn('campaign_recipients', 'clicked_at')) {
                    $table->timestamp('clicked_at')->nullable()->after('opened_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaign_recipients', 'clicked_at')) {
            Schema::table('campaign_recipients', function (Blueprint $table) {
                $table->dropColumn('clicked_at');
            });
        }
        if (Schema::hasColumn('campaign_recipients', 'opened_at')) {
            Schema::table('campaign_recipients', function (Blueprint $table) {
                $table->dropColumn('opened_at');
            });
        }
        Schema::dropIfExists('content_views');
    }
};
