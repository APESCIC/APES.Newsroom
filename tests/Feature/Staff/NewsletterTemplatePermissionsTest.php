<?php

namespace Tests\Feature\Staff;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\EmailTemplates\EmailTemplateService;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTemplatePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_members_and_subscribers_get_403(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/staff/newsletter-templates/default')->assertForbidden();
        $this->actingAs($member)->put('/staff/newsletter-templates/default', $this->payload())->assertForbidden();
        $this->actingAs($member)->post('/staff/newsletter-templates/default/revert')->assertForbidden();
        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/staff/newsletter-templates/default')->assertRedirect();
        $this->put('/staff/newsletter-templates/default', $this->payload())->assertRedirect();
        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_unknown_targets_are_not_found(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/staff/newsletter-templates/999999')->assertNotFound();
        $this->actingAs($staff)->get('/staff/newsletter-templates/system')->assertNotFound();
    }

    public function test_staff_and_admins_can_save_and_every_save_is_a_version(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($staff)->put('/staff/newsletter-templates/default', $this->payload(['subject' => 'First {{ post_title }}']))
            ->assertRedirect('/staff/newsletter-templates/default')
            ->assertSessionHas('status');
        $this->actingAs($admin)->put('/staff/newsletter-templates/default', $this->payload(['subject' => 'Second {{ post_title }}']))
            ->assertRedirect();

        $template = EmailTemplate::query()->where('key', NewsletterTemplateDefaults::KEY)->sole();
        $this->assertSame(NewsletterTemplateDefaults::SCOPE_DEFAULT, $template->scope);
        $this->assertSame('Second {{ post_title }}', $template->subject);
        $this->assertSame(2, $template->versions()->count());
        $this->assertSame('Join us', $template->settings['members_cta']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $staff->id, 'action' => 'newsletter_template.updated']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'newsletter_template.updated']);
    }

    public function test_unknown_placeholders_are_rejected_on_save(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->put('/staff/newsletter-templates/default', $this->payload(['intro' => 'Hi {{ password }}']))
            ->assertSessionHasErrors('template');
        $this->actingAs($staff)->put('/staff/newsletter-templates/default', $this->payload(['settings' => [...$this->settings(), 'sign_off' => 'Bye {{ unsubscribe_url }}']]))
            ->assertSessionHasErrors('template');
        $this->actingAs($staff)->put('/staff/newsletter-templates/default', $this->payload(['settings' => [...$this->settings(), 'members_cta' => 'Hi {{ recipient_first_name }}']]))
            ->assertSessionHasErrors('template');

        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_restore_is_a_new_version_and_audit_logged(): void
    {
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->put('/staff/newsletter-templates/default', $this->payload(['subject' => 'Old {{ post_title }}']));
        $this->actingAs($staff)->put('/staff/newsletter-templates/default', $this->payload(['subject' => 'New {{ post_title }}']));
        $old = EmailTemplateVersion::query()->where('subject', 'Old {{ post_title }}')->sole();

        $this->actingAs($staff)->post("/staff/newsletter-templates/default/versions/{$old->id}/restore")
            ->assertRedirect('/staff/newsletter-templates/default');

        $template = EmailTemplate::query()->where('key', NewsletterTemplateDefaults::KEY)->sole();
        $this->assertSame('Old {{ post_title }}', $template->subject);
        $this->assertSame(3, $template->versions()->count());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $staff->id, 'action' => 'newsletter_template.restored']);
    }

    public function test_a_version_from_another_template_cannot_be_restored(): void
    {
        $staff = User::factory()->staff()->create();
        $newsletter = Newsletter::query()->where('slug', 'apes-cic')->firstOrFail();
        $this->actingAs($staff)->put("/staff/newsletter-templates/{$newsletter->id}", $this->payload(['subject' => 'CIC only']));
        $version = EmailTemplateVersion::query()->sole();

        $this->actingAs($staff)->post("/staff/newsletter-templates/default/versions/{$version->id}/restore")->assertNotFound();
    }

    public function test_reverting_a_newsletter_version_returns_it_to_the_default_and_is_audit_logged(): void
    {
        $staff = User::factory()->staff()->create();
        $newsletter = Newsletter::query()->where('slug', 'apes-pet-care-clinic')->firstOrFail();
        $this->actingAs($staff)->put("/staff/newsletter-templates/{$newsletter->id}", $this->payload(['subject' => 'Pet care {{ post_title }}']));

        $this->actingAs($staff)->post("/staff/newsletter-templates/{$newsletter->id}/revert")
            ->assertRedirect("/staff/newsletter-templates/{$newsletter->id}")
            ->assertSessionHas('status');

        $this->assertDatabaseCount('email_templates', 0);
        $this->assertDatabaseCount('email_template_versions', 0);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $staff->id, 'action' => 'newsletter_template.reverted']);
    }

    public function test_newsletter_templates_never_appear_in_the_admin_system_list(): void
    {
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->put('/staff/newsletter-templates/default', $this->payload());

        $groups = app(EmailTemplateService::class)->listForAdmin();
        $keys = collect($groups)->flatten(1)->pluck('key');

        $this->assertNotContains(NewsletterTemplateDefaults::KEY, $keys);
        $this->assertTrue(collect($groups)->flatten(1)->every(fn (array $row) => $row['has_override'] === false));
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [...NewsletterTemplateDefaults::settings(), 'members_cta' => 'Join us'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'subject' => '{{ post_title }}',
            'intro' => 'Hi {{ recipient_first_name }},',
            'settings' => $this->settings(),
            ...$overrides,
        ];
    }
}
