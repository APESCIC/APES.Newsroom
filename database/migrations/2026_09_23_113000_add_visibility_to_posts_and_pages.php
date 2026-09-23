<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('posts') && ! Schema::hasColumn('posts', 'visibility')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->string('visibility')->default('public')->after('status')->index();
            });
        }

        if (Schema::hasTable('pages') && ! Schema::hasColumn('pages', 'visibility')) {
            Schema::table('pages', function (Blueprint $table) {
                $table->string('visibility')->default('public')->after('status')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('posts', 'visibility')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('visibility');
            });
        }

        if (Schema::hasColumn('pages', 'visibility')) {
            Schema::table('pages', function (Blueprint $table) {
                $table->dropColumn('visibility');
            });
        }
    }
};
