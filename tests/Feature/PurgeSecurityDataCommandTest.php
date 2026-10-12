<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MagicLinkToken;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class PurgeSecurityDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-12 12:00:00');
    }

    public function test_audit_logs_older_than_retention_are_purged_at_the_boundary(): void
    {
        $expired = $this->auditAt(now()->subMonths(24)->subSecond());
        $boundary = $this->auditAt(now()->subMonths(24));
        $recent = $this->auditAt(now()->subMonths(23));

        $this->artisan('newsroom:purge-security-data')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($boundary);
        $this->assertModelExists($recent);
    }

    public function test_retention_period_is_configurable(): void
    {
        config(['security.audit_retention_months' => 6]);
        $old = $this->auditAt(now()->subMonths(7));
        $kept = $this->auditAt(now()->subMonths(5));

        $this->artisan('newsroom:purge-security-data')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($kept);
    }

    public function test_spent_magic_link_tokens_are_purged_after_seven_days(): void
    {
        $user = User::factory()->create();

        $longExpired = $this->token($user, expiresAt: now()->subDays(8));
        $recentlyExpired = $this->token($user, expiresAt: now()->subDays(6));
        $longUsed = $this->token($user, expiresAt: now()->addMinutes(5), usedAt: now()->subDays(8));
        $live = $this->token($user, expiresAt: now()->addMinutes(10));

        $this->artisan('newsroom:purge-security-data')
            ->expectsOutput('Purged 0 audit log(s) and 2 magic link token(s).')
            ->assertSuccessful();

        $this->assertModelMissing($longExpired);
        $this->assertModelMissing($longUsed);
        $this->assertModelExists($recentlyExpired);
        $this->assertModelExists($live);
    }

    public function test_purge_and_reset_token_cleanup_are_scheduled_daily(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

        $this->assertStringContainsString('newsroom:purge-security-data', $commands);
        $this->assertStringContainsString('auth:clear-resets', $commands);
    }

    private function auditAt(DateTimeInterface $createdAt): AuditLog
    {
        $log = AuditLog::query()->create(['action' => 'auth.login']);
        $log->forceFill(['created_at' => $createdAt])->save();

        return $log;
    }

    private function token(User $user, DateTimeInterface $expiresAt, ?DateTimeInterface $usedAt = null): MagicLinkToken
    {
        $token = $user->magicLinkTokens()->create([
            'token_hash' => hash('sha256', str()->random(40)),
            'expires_at' => $expiresAt,
        ]);
        $token->forceFill(['used_at' => $usedAt])->save();

        return $token;
    }
}
