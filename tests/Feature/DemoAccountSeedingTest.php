<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoAccounts;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $this->assertSame(3, DemoAccounts::query()->count());
        $this->assertEqualsCanonicalizing(DemoAccounts::EMAILS, DemoAccounts::query()->pluck('email')->all());
        $this->assertTrue(Hash::check(DemoAccounts::PASSWORD, (string) User::query()->where('email', DemoAccounts::MEMBER)->value('password')));
    }

    public function test_demo_accounts_use_example_test_addresses(): void
    {
        foreach (DemoAccounts::EMAILS as $email) {
            $this->assertStringEndsWith('@example.test', $email);
        }
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
            ->expectsOutputToContain('WARNING: 3 demo account(s)')
            ->assertSuccessful();
    }

    public function test_check_command_still_flags_pre_v1_30_3_demo_addresses(): void
    {
        User::factory()->create(['email' => 'staff@apes.local']);

        $this->artisan('newsroom:check-demo-accounts')
            ->expectsOutputToContain('WARNING: 1 demo account(s)')
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
