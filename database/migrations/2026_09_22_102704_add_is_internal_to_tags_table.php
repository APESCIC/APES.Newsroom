<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('slug');
        });

        // Backfill: names that still start with # (legacy imports that preserved the hash).
        DB::table('tags')->where('name', 'like', '#%')->update(['is_internal' => true]);
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
