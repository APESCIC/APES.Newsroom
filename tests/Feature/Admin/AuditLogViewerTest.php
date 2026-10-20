<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_only_admins_can_view_or_export_the_audit_log(): void
    {
        $this->get('/admin/audit-log')->assertRedirect('/login');
        $this->get('/admin/audit-log/export')->assertRedirect('/login');

        foreach ([User::factory()->create(), User::factory()->staff()->create()] as $user) {
            $this->actingAs($user)->withRecentAuthentication()->get('/admin/audit-log')->assertForbidden();
            $this->actingAs($user)->withRecentAuthentication()->get('/admin/audit-log/export')->assertForbidden();
        }

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)->get('/admin/audit-log')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/AuditLog/Index'));
        }
    }

    public function test_entries_are_paginated_newest_first(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (range(1, 30) as $i) {
            $this->log(['action' => "test.event_{$i}"]);
        }

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.total', 30)
                ->has('entries.data', 25)
                ->where('entries.data.0.action', 'test.event_30'));

        $this->actingAs($admin)->get('/admin/audit-log?page=2')
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 5));
    }

    public function test_filters_by_actor_action_subject_date_and_ip(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->staff()->create(['name' => 'Edith Editor', 'email' => 'edith@example.com']);
        $post = Post::factory()->create();

        $this->log(['actor_id' => $editor->id, 'action' => 'post.published', 'subject_type' => Post::class, 'subject_id' => $post->id, 'ip_address' => '203.0.113.9', 'created_at' => '2026-10-01 10:00:00']);
        $this->log(['actor_id' => $editor->id, 'action' => 'auth.login', 'ip_address' => '198.51.100.4', 'created_at' => '2026-10-05 10:00:00']);
        $this->log(['actor_id' => $admin->id, 'action' => 'settings.updated', 'created_at' => '2026-10-10 10:00:00']);

        $cases = [
            'actor=edith' => 2,
            'actor='.$admin->id => 1,
            'action=auth.' => 1,
            'action=post' => 1,
            'subject_type=Post' => 1,
            'subject_type=Unknown' => 0,
            'from=2026-10-04' => 2,
            'to=2026-10-04' => 1,
            'from=2026-10-02&to=2026-10-06' => 1,
            'ip=203.0.113.9' => 1,
        ];

        foreach ($cases as $query => $expected) {
            $this->actingAs($admin)->get("/admin/audit-log?{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('entries.total', $expected), "Filter {$query}");
        }

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertInertia(fn (Assert $page) => $page->where('subjectTypes', ['Post']));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/audit-log?ip=not-an-ip')->assertSessionHasErrors('ip');
        $this->actingAs($admin)->get('/admin/audit-log?action=%25%27')->assertSessionHasErrors('action');
        $this->actingAs($admin)->get('/admin/audit-log?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');
    }

    public function test_payload_is_flattened_to_display_strings(): void
    {
        $admin = User::factory()->admin()->create();
        $this->log(['action' => 'settings.updated', 'payload' => [
            'changes' => ['editor.driver' => ['from' => 'editorjs', 'to' => '<script>alert(1)</script>']],
            'events' => ['post.published', 'member.created'],
            'remember' => true,
        ]]);

        $this->actingAs($admin)->get('/admin/audit-log')
            ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.payload', [
                ['key' => 'changes.editor.driver.from', 'value' => 'editorjs'],
                ['key' => 'changes.editor.driver.to', 'value' => '<script>alert(1)</script>'],
                ['key' => 'events', 'value' => '["post.published","member.created"]'],
                ['key' => 'remember', 'value' => 'true'],
            ]));
    }

    public function test_export_needs_recent_authentication(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/audit-log/export')->assertRedirect(route('account.confirm-identity'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'audit_log.exported']);
    }

    public function test_export_streams_filtered_csv_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $this->log(['action' => 'auth.login', 'user_agent' => '=HYPERLINK("https://evil.example")']);
        $this->log(['action' => 'auth.logout']);
        $this->log(['action' => 'webhook.created']);

        $response = $this->actingAs($admin)->withRecentAuthentication()->get('/admin/audit-log/export?action=auth.');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('id,created_at,action', $lines[0]);
        $this->assertStringContainsString('auth.logout', $lines[1]);
        $this->assertStringContainsString('auth.login', $lines[2]);
        $this->assertStringContainsString("'=HYPERLINK", $lines[2]);
        $this->assertStringNotContainsString('webhook.created', $response->streamedContent());

        $export = AuditLog::query()->where('action', 'audit_log.exported')->sole();
        $this->assertSame($admin->id, $export->actor_id);
        $this->assertSame(['filters' => ['action' => 'auth.'], 'rows' => 2], $export->payload);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function log(array $attributes): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill([
            'action' => 'test.event',
            'payload' => [],
            ...$attributes,
        ])->save();

        return $log;
    }
}
