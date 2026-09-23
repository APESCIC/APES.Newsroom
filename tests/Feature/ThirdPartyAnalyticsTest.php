<?php

namespace Tests\Feature;

use App\Http\Controllers\AnalyticsConsentController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ThirdPartyAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_analytics_shared_as_disabled_by_default(): void
    {
        config(['newsroom.analytics.provider' => 'none']);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.provider', 'none')
                ->where('analytics.enabled', false)
                ->where('analytics.consent', false));
    }

    public function test_plausible_enabled_only_with_domain(): void
    {
        config([
            'newsroom.analytics.provider' => 'plausible',
            'newsroom.analytics.plausible_domain' => 'news.example.test',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.provider', 'plausible')
                ->where('analytics.enabled', true)
                ->where('analytics.plausibleDomain', 'news.example.test')
                ->where('analytics.consent', false));
    }

    public function test_plausible_without_domain_falls_back_to_none(): void
    {
        config([
            'newsroom.analytics.provider' => 'plausible',
            'newsroom.analytics.plausible_domain' => null,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.provider', 'none')
                ->where('analytics.enabled', false));
    }

    public function test_consent_cookie_is_recorded(): void
    {
        config([
            'newsroom.analytics.provider' => 'plausible',
            'newsroom.analytics.plausible_domain' => 'news.example.test',
        ]);

        $this->from('/')->post(route('analytics.consent'), ['consent' => true])
            ->assertRedirect('/')
            ->assertCookie(AnalyticsConsentController::COOKIE, '1');

        $this->withCookie(AnalyticsConsentController::COOKIE, '1')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('analytics.consent', true));
    }

    public function test_decline_sets_zero_cookie(): void
    {
        $this->from('/')->post(route('analytics.consent'), ['consent' => false])
            ->assertRedirect('/')
            ->assertCookie(AnalyticsConsentController::COOKIE, '0');
    }

    public function test_cookie_notice_mentions_third_party_consent(): void
    {
        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Legal/Cookies'));
    }

    public function test_native_metrics_route_unaffected(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get(route('staff.metrics.index'))
            ->assertOk();
    }
}
