<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The newsletter template a post's campaign uses: null for the sending
 * newsletter's own version (then the default), "newsletter" for the default
 * or "newsletter:<id>" for one newsletter's version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('newsletter_template', 64)->nullable()->after('newsletter_segment_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('newsletter_template');
        });
    }
};
