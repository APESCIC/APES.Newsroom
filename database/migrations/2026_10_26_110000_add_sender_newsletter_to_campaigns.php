<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('campaigns', 'newsletter_id')) {
                $table->foreignId('newsletter_id')->nullable()->after('post_id')->constrained('newsletters')->nullOnDelete();
            }
            if (! Schema::hasColumn('campaigns', 'failure_reason')) {
                $table->string('failure_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('newsletter_id');
            $table->dropColumn('failure_reason');
        });
    }
};
