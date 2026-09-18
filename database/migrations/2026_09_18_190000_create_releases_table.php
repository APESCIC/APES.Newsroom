<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->string('previous_version')->nullable();
            $table->date('released_at');
            $table->string('channel');
            $table->string('version_type')->nullable();
            $table->string('theme')->nullable();
            $table->boolean('is_current')->default(false);
            $table->boolean('is_published')->default(false);
            $table->string('slug')->unique();
            $table->json('change_types')->nullable();
            $table->json('topic_tags')->nullable();
            $table->text('summary');
            $table->json('detailed_changes');
            $table->json('affected_areas');
            $table->json('version_decision');
            $table->json('validation');
            $table->timestamps();

            $table->index(['is_published', 'released_at']);
            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
