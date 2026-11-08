<?php

namespace Tests\Feature\Staff;

use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\EmailTemplates\NewsletterTemplates;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsletterTemplateResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_nothing_saved_means_the_built_in_email(): void
    {
        $this->assertNull(app(NewsletterTemplates::class)->resolve($this->newsletter('apes-cic')));
    }

    public function test_a_newsletter_uses_its_own_version_then_the_default(): void
    {
        $templates = app(NewsletterTemplates::class);
        $shelter = $this->newsletter('apes-shelter-rescue');
        $petCare = $this->newsletter('apes-pet-care-clinic');
        $default = $this->template(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Default {{ post_title }}');
        $own = $this->template(NewsletterTemplateDefaults::scopeFor($shelter->id), 'Shelter {{ post_title }}');

        $this->assertTrue($own->is($templates->resolve($shelter)));
        $this->assertTrue($default->is($templates->resolve($petCare)));
        $this->assertTrue($default->is($templates->resolve(null)));
    }

    public function test_removing_a_newsletter_version_returns_to_the_default(): void
    {
        $templates = app(NewsletterTemplates::class);
        $shelter = $this->newsletter('apes-shelter-rescue');
        $default = $this->template(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Default');
        $this->template(NewsletterTemplateDefaults::scopeFor($shelter->id), 'Shelter')->delete();

        $this->assertTrue($default->is($templates->resolve($shelter)));
    }

    public function test_an_explicit_choice_wins_over_the_newsletter_version(): void
    {
        $templates = app(NewsletterTemplates::class);
        $shelter = $this->newsletter('apes-shelter-rescue');
        $myapes = $this->newsletter('myapes');
        $default = $this->template(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Default');
        $this->template(NewsletterTemplateDefaults::scopeFor($shelter->id), 'Shelter');
        $other = $this->template(NewsletterTemplateDefaults::scopeFor($myapes->id), 'MyAPES');

        $this->assertTrue($default->is($templates->resolve($shelter, NewsletterTemplateDefaults::SCOPE_DEFAULT)));
        $this->assertTrue($other->is($templates->resolve($shelter, NewsletterTemplateDefaults::scopeFor($myapes->id))));
        $this->assertTrue($default->is($templates->resolve($shelter, 'newsletter:999999')));
    }

    public function test_system_and_newsletter_rows_share_the_table_without_clashing(): void
    {
        EmailTemplate::query()->create(['key' => NewsletterTemplateDefaults::KEY, 'scope' => EmailTemplate::SCOPE_SYSTEM, 'subject' => 's', 'body' => 'b']);
        $this->template(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Default');

        $this->assertSame(2, EmailTemplate::query()->where('key', NewsletterTemplateDefaults::KEY)->count());
        $this->assertSame('Default', app(NewsletterTemplates::class)->resolve(null)?->subject);
    }

    public function test_newsletter_pages_show_which_template_is_in_use(): void
    {
        $staff = User::factory()->staff()->create();
        $shelter = $this->newsletter('apes-shelter-rescue');
        $petCare = $this->newsletter('apes-pet-care-clinic');
        $this->template(NewsletterTemplateDefaults::scopeFor($shelter->id), 'Shelter');

        $this->actingAs($staff)->get('/staff/newsletters')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Staff/Newsletters/Index')
            ->where('newsletters.data', fn ($rows) => collect($rows)->firstWhere('id', $shelter->id)['template'] === 'own'
                && collect($rows)->firstWhere('id', $petCare->id)['template'] === 'built_in'));

        $this->template(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Default');

        $this->actingAs($staff)->get("/staff/newsletters/{$petCare->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('newsletter.template', 'default_edited'));
    }

    private function newsletter(string $slug): Newsletter
    {
        return Newsletter::query()->where('slug', $slug)->firstOrFail();
    }

    private function template(string $scope, string $subject): EmailTemplate
    {
        return EmailTemplate::query()->create([
            'key' => NewsletterTemplateDefaults::KEY,
            'scope' => $scope,
            'subject' => $subject,
            'body' => '',
            'settings' => NewsletterTemplateDefaults::settings(),
        ]);
    }
}
