<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoAccounts;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccountSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_in_production_creates_no_demo_users(): void
    {
        $this->app['env'] = 'production';

        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        $this->app->make(DemoUsersSeeder::class)->setContainer($this->app)->__invoke();

        $this->assertSame(0, DemoAccounts::query()->count());
    }

    public function test_seeding_in_testing_creates_the_demo_users(): void
    {
        $this->seed();

        $this->assertSame(4, DemoAccounts::query()->count());
    }

    public function test_check_command_passes_quietly_when_no_demo_accounts_exist(): void
    {
        $this->artisan('newsroom:check-demo-accounts')
            ->expectsOutput('No demo accounts found.')
            ->assertSuccessful();
    }

    public function test_check_command_warns_but_succeeds_when_demo_accounts_exist(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $this->artisan('newsroom:check-demo-accounts')
            ->expectsOutputToContain('WARNING: 4 demo account(s)')
            ->assertSuccessful();
    }

    public function test_import_fallback_author_is_not_a_demo_account(): void
    {
        User::factory()->create(['email' => 'import-fallback@apes.local']);

        $this->artisan('newsroom:check-demo-accounts')
            ->expectsOutput('No demo accounts found.')
            ->assertSuccessful();
    }
}
