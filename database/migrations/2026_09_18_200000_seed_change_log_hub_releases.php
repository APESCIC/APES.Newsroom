<?php

use Database\Seeders\ReleaseSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Deploy runs migrate, not db:seed. Upsert the baseline Newsroom
        // release notes so the public Change Log Hub is not empty after cutover.
        (new ReleaseSeeder)->run();
    }

    public function down(): void
    {
        // Intentionally leave release rows in place; they are product content.
    }
};
