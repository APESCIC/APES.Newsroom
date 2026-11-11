<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Services\Geo\GeoLocation;
use App\Services\Geo\GeoLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SessionLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
    }

    public function test_sign_in_stores_the_resolved_country_and_city_but_no_coordinates(): void
    {
        $locator = $this->fakeLocator(new GeoLocation('GB', 'Leeds'));
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->withServerVariables(['REMOTE_ADDR' => '81.2.69.160'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $session = UserSession::query()->sole();
        $this->assertSame('GB', $session->country_code);
        $this->assertSame('Leeds', $session->city);
        $this->assertSame(['81.2.69.160'], $locator->lookups);
        $this->assertArrayNotHasKey('latitude', $session->getAttributes());
    }

    public function test_sign_in_without_a_resolvable_location_keeps_the_location_empty(): void
    {
        $this->fakeLocator(null);
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $session = UserSession::query()->sole();
        $this->assertNull($session->country_code);
        $this->assertNull($session->city);
    }

    public function test_security_page_shows_the_location_next_to_the_masked_ip(): void
    {
        $this->fakeLocator(null);
        $user = User::factory()->create();
        $located = UserSession::factory()->for($user)->create([
            'last_seen_at' => now()->subMinutes(10),
            'country_code' => 'GB',
            'city' => 'Leeds',
        ]);
        $unlocated = UserSession::factory()->for($user)->create([
            'last_seen_at' => now()->subMinutes(20),
            'country_code' => null,
            'city' => null,
        ]);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Security')
                ->has('sessions', 3)
                ->where('sessions.1.id', $located->id)
                ->where('sessions.1.location', 'Leeds, United Kingdom')
                ->where('sessions.1.ip', '203.0.113.x')
                ->where('sessions.2.id', $unlocated->id)
                ->where('sessions.2.location', null)
                ->where('sessions.2.ip', '203.0.113.x'));
    }

    public function test_a_new_ip_on_an_existing_session_is_located_again(): void
    {
        $locator = $this->fakeLocator(new GeoLocation('FR', null));
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'))->assertOk();
        $session = UserSession::query()->sole();
        $session->forceFill(['ip_address' => '203.0.113.9', 'last_seen_at' => now()->subMinutes(10)])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get(route('account.show'))
            ->assertOk();

        $session->refresh();
        $this->assertSame('198.51.100.20', $session->ip_address);
        $this->assertSame('FR', $session->country_code);
        $this->assertNull($session->city);
        $this->assertContains('198.51.100.20', $locator->lookups);
    }

    private function fakeLocator(?GeoLocation $result): GeoLocator
    {
        $locator = new class($result) implements GeoLocator
        {
            /** @var list<?string> */
            public array $lookups = [];

            public function __construct(private readonly ?GeoLocation $result) {}

            public function locate(?string $ip): ?GeoLocation
            {
                $this->lookups[] = $ip;

                return $this->result;
            }
        };

        $this->app->instance(GeoLocator::class, $locator);

        return $locator;
    }
}
