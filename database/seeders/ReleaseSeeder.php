<?php

namespace Database\Seeders;

use App\Services\Releases\ReleaseChangelogSync;
use Illuminate\Database\Seeder;

class ReleaseSeeder extends Seeder
{
    public function run(): void
    {
        app(ReleaseChangelogSync::class)->sync();
    }
}
