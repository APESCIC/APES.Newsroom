<?php

namespace Tests\Feature\Staff;

use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsletterTemplateEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_staff_open_the_default_template_with_the_built_in_wording(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/staff/newsletter-templates/default')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Staff/NewsletterTemplates/Edit')
            ->where('target', 'default')
            ->where('newsletter', null)
            ->where('template.subject', '{{ post_title }}')
            ->where('template.is_overridden', false)
            ->where('template.settings.members_cta', NewsletterTemplateDefaults::MEMBERS_CTA)
            ->where('placeholders', NewsletterTemplateDefaults::PLACEHOLDERS)
            ->where('postPlaceholders', NewsletterTemplateDefaults::POST_PLACEHOLDERS)
            ->where('versions', [])
            ->where('testRecipient', $staff->email));
    }

    public function test_a_newsletter_without_its_own_version_starts_from_the_edited_default(): void
    {
        $staff = User::factory()->staff()->create();
        $newsletter = Newsletter::query()->where('slug', 'myapes')->firstOrFail();
        EmailTemplate::query()->create([
            'key' => NewsletterTemplateDefaults::KEY,
            'scope' => NewsletterTemplateDefaults::SCOPE_DEFAULT,
            'subject' => 'Default {{ post_title }}',
            'body' => 'Hello',
            'settings' => NewsletterTemplateDefaults::settings(),
        ]);

        $this->actingAs($staff)->get("/staff/newsletter-templates/{$newsletter->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('newsletter.name', 'MyAPES')
            ->where('template.subject', 'Default {{ post_title }}')
            ->where('template.intro', 'Hello')
            ->where('template.is_overridden', false));
    }
}
