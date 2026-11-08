<?php

namespace Tests\Feature\Staff;

use App\Enums\ContentVisibility;
use App\Models\Post;
use App\Services\EmailTemplates\NewsletterTemplates;
use App\Services\Mailing\CampaignService;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTemplateGatedWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_wording_matches_the_text_used_before_templates(): void
    {
        $this->assertSame('This article is for members. Create a free account to read the full post.', NewsletterTemplateDefaults::settings()['members_cta']);
        $this->assertSame('This article is for paid members. Subscribe in your account to read the full post.', NewsletterTemplateDefaults::settings()['paid_cta']);
    }

    public function test_without_a_template_gated_posts_keep_the_original_wording(): void
    {
        $members = app(CampaignService::class)->buildSnapshot($this->gatedPost(ContentVisibility::Members));
        $paid = app(CampaignService::class)->buildSnapshot($this->gatedPost(ContentVisibility::Paid));

        $this->assertSame("Teaser only\n\n".NewsletterTemplateDefaults::MEMBERS_CTA, $members['excerpt']);
        $this->assertSame("Teaser only\n\n".NewsletterTemplateDefaults::PAID_CTA, $paid['excerpt']);
        $this->assertArrayNotHasKey('template', $members);
    }

    public function test_template_wording_replaces_the_members_and_paid_text(): void
    {
        $template = app(NewsletterTemplates::class)->draft(NewsletterTemplateDefaults::SCOPE_DEFAULT, '{{ post_title }}', '', [
            'members_cta' => 'Join {{ site_name }} for free to read "{{ post_title }}".',
            'paid_cta' => 'Supporters can read this one.',
        ]);

        $members = app(CampaignService::class)->buildSnapshot($this->gatedPost(ContentVisibility::Members), null, $template);
        $paid = app(CampaignService::class)->buildSnapshot($this->gatedPost(ContentVisibility::Paid), null, $template);

        $this->assertSame("Teaser only\n\nJoin ".config('app.name').' for free to read "Dragon care".', $members['excerpt']);
        $this->assertSame("Teaser only\n\nSupporters can read this one.", $paid['excerpt']);
        $this->assertNull($members['html']);
    }

    private function gatedPost(ContentVisibility $visibility): Post
    {
        return Post::factory()->create([
            'title' => 'Dragon care',
            'visibility' => $visibility,
            'excerpt' => 'Teaser only',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Secret']]]],
        ]);
    }
}
