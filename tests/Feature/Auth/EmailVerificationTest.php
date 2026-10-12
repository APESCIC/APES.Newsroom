<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_redirects_to_verification_notice(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();
        $this->assertNull(auth()->user()->email_verified_at);
    }

    public function test_unverified_user_cannot_access_account(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_user_can_access_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk();
    }

    public function test_email_can_be_verified(): void
    {
        Event::fake([Verified::class]);

        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect(route('account.show'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_staff_oidc_users_are_considered_verified(): void
    {
        $user = User::factory()->staff()->create(['email_verified_at' => null]);

        $this->assertTrue($user->hasVerifiedEmail());
    }
}
