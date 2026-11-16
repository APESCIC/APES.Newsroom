<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('seo_noindex')->default(false)->after('canonical_url');
            $table->string('og_title')->nullable()->after('seo_noindex');
            $table->string('og_description', 500)->nullable()->after('og_title');
            $table->string('og_image')->nullable()->after('og_description');
            $table->string('twitter_title')->nullable()->after('og_image');
            $table->string('twitter_description', 500)->nullable()->after('twitter_title');
            $table->string('twitter_image')->nullable()->after('twitter_description');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('seo_noindex')->default(false)->after('canonical_url');
            $table->string('og_title')->nullable()->after('seo_noindex');
            $table->string('og_description', 500)->nullable()->after('og_title');
            $table->string('og_image')->nullable()->after('og_description');
            $table->string('twitter_title')->nullable()->after('og_image');
            $table->string('twitter_description', 500)->nullable()->after('twitter_title');
            $table->string('twitter_image')->nullable()->after('twitter_description');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn([
                'seo_noindex',
                'og_title',
                'og_description',
                'og_image',
                'twitter_title',
                'twitter_description',
                'twitter_image',
            ]);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn([
                'seo_noindex',
                'og_title',
                'og_description',
                'og_image',
                'twitter_title',
                'twitter_description',
                'twitter_image',
            ]);
        });
    }
};
