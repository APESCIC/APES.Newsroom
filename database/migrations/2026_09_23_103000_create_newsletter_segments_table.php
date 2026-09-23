<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('also_newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('newsletter_segment_id')->nullable()->after('mailing_lists')->constrained('newsletter_segments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('newsletter_segment_id');
        });

        Schema::dropIfExists('newsletter_segments');
    }
};
