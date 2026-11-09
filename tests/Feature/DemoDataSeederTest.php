<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MailingContact;
use App\Models\Post;
use App\Models\User;
use App\Support\DemoAccounts;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'users', 'posts', 'pages', 'tags', 'profiles', 'comments', 'reactions', 'moderation_reports',
        'mailing_contacts', 'mailing_list_subscriptions', 'suppressions', 'campaigns', 'campaign_recipients',
        'memberships', 'offers', 'redirects', 'webhook_endpoints', 'audit_logs', 'media_assets', 'content_views',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_seeder_refuses_outside_local(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only loads when APP_ENV=local');

        $this->app->make(DemoDataSeeder::class)->setContainer($this->app)->__invoke();
    }

    public function test_command_refuses_outside_local(): void
    {
        $this->artisan('newsroom:demo-data')
            ->expectsOutputToContain('Refused: demo data only loads when APP_ENV=local')
            ->assertFailed();

        $this->assertSame(0, Post::query()->count());
    }

    public function test_reset_refuses_a_database_that_is_not_the_local_mysql_one(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('newsroom:demo-data', ['--reset' => true])
            ->expectsOutputToContain('Refused to reset')
            ->assertFailed();
    }

    public function test_seeding_twice_gives_the_same_records_without_duplicates(): void
    {
        $this->app['env'] = 'local';

        $this->seed(DemoDataSeeder::class);
        $first = $this->snapshot();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($first, $this->snapshot());
        $this->assertSame(48, $first['posts']['count']);
        $this->assertSame(45, $first['users']['count']);
        $this->assertGreaterThan(12, Post::query()->where('channel', 'apes_cic')->where('status', 'published')->count());
    }

    public function test_demo_logins_exist_with_the_right_roles(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(Role::Admin, User::query()->where('email', DemoAccounts::ADMIN)->value('role'));
        $this->assertSame(Role::Staff, User::query()->where('email', DemoAccounts::STAFF)->value('role'));
        $this->assertSame(Role::Public, User::query()->where('email', DemoAccounts::MEMBER)->value('role'));
        $this->assertSame(Role::Staff, User::query()->where('email', 'writer@example.test')->value('role'));

        $this->app['env'] = 'testing';
        $this->post('/login', ['email' => DemoAccounts::MEMBER, 'password' => DemoAccounts::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs(User::query()->where('email', DemoAccounts::MEMBER)->firstOrFail());
    }

    public function test_demo_data_is_clearly_fake(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DemoDataSeeder::class);

        $emails = collect()
            ->merge(User::query()->pluck('email'))
            ->merge(MailingContact::query()->pluck('email'))
            ->merge(DB::table('campaign_recipients')->pluck('email'))
            ->merge(DB::table('suppressions')->pluck('email'));

        foreach ($emails as $email) {
            $this->assertMatchesRegularExpression('/@example\.(com|org|test)$/', $email);
        }

        foreach (User::query()->whereNotNull('mobile_number')->get() as $user) {
            $this->assertMatchesRegularExpression('/^\+447700900\d{3}$/', $user->mobile_number);
            $this->assertStringStartsWith('ZZ', $user->address_postcode);
        }
    }

    public function test_no_real_email_domains_in_demo_seeders(): void
    {
        $files = [
            database_path('seeders/DemoDataSeeder.php'),
            database_path('seeders/DemoUsersSeeder.php'),
            database_path('seeders/VisualRegressionSeeder.php'),
            database_path('seeders/Demo/DemoContent.php'),
            app_path('Support/DemoAccounts.php'),
        ];

        foreach ($files as $file) {
            preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $domain) {
                $this->assertContains($domain, ['example.com', 'example.org', 'example.test', 'apes.local'], "{$file} uses {$domain}");
            }
        }
    }

    /**
     * @return array<string, array{count: int, hash: string}>
     */
    private function snapshot(): array
    {
        $random = ['password', 'remember_token', 'address_line1', 'address_line2', 'address_town', 'address_county',
            'address_postcode', 'mobile_number', 'whatsapp_value', 'secret', 'snapshot'];

        return collect(self::TABLES)->mapWithKeys(function (string $table) use ($random) {
            $rows = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => array_diff_key((array) $row, array_flip($random)))
                ->all();

            return [$table => ['count' => count($rows), 'hash' => hash('sha256', json_encode($rows))]];
        })->all();
    }
}
