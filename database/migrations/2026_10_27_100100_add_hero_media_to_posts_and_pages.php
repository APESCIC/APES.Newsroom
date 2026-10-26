<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['posts', 'pages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('hero_media_id')->nullable()->after('hero_image_credit')->constrained('media_assets')->nullOnDelete();
                $table->decimal('hero_focal_x', 5, 4)->nullable()->after('hero_media_id');
                $table->decimal('hero_focal_y', 5, 4)->nullable()->after('hero_focal_x');
            });
        }
    }

    public function down(): void
    {
        foreach (['posts', 'pages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('hero_media_id');
                $table->dropColumn(['hero_focal_x', 'hero_focal_y']);
            });
        }
    }
};
