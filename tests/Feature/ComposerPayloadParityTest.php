<?php

namespace Tests\Feature;

use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Glass Studio composer (#165): create/update payloads from Edit.tsx stay
 * validated and persisted — including SEO, hero, campaign, co-authors, and
 * expected_updated_at concurrency (relate #43; no autosave rework).
 */
class ComposerPayloadParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_accepts_full_glass_composer_payload(): void
    {
        $staff = User::factory()->staff()->create();
        $coAuthor = User::factory()->staff()->create();
        $segment = $this->makeSegment();

        $response = $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Glass Studio Draft',
            'slug' => 'glass-studio-draft',
            'excerpt' => 'Side panel fields included',
            'channel' => 'apes_cic',
            'visibility' => ContentVisibility::Members->value,
            'content' => [
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
            ],
            'hero_image' => 'https://cdn.example.test/hero.jpg',
            'hero_image_alt' => 'Hero alt',
            'hero_image_caption' => 'Hero caption',
            'hero_image_credit' => 'Photo credit',
            'meta_title' => 'SEO title',
            'meta_description' => 'SEO description',
            'canonical_url' => 'https://canonical.example/glass-studio-draft',
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
            'newsletter_segment_id' => (string) $segment->id,
            'tags' => ['Glass', 'Composer'],
            'featured' => true,
            'co_author_ids' => [$coAuthor->id],
        ]);

        $response->assertRedirect();
        $post = Post::query()->where('slug', 'glass-studio-draft')->firstOrFail();

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame(ContentVisibility::Members, $post->visibility);
        $this->assertSame('https://cdn.example.test/hero.jpg', $post->hero_image);
        $this->assertSame('Hero alt', $post->hero_image_alt);
        $this->assertSame('Hero caption', $post->hero_image_caption);
        $this->assertSame('Photo credit', $post->hero_image_credit);
        $this->assertSame('SEO title', $post->meta_title);
        $this->assertSame('SEO description', $post->meta_description);
        $this->assertSame('https://canonical.example/glass-studio-draft', $post->canonical_url);
        $this->assertTrue($post->email_on_publish);
        $this->assertSame([MailingList::ApesCic->value], $post->mailing_lists);
        $this->assertSame($segment->id, $post->newsletter_segment_id);
        $this->assertTrue($post->featured);
        $this->assertEqualsCanonicalizing(['Glass', 'Composer'], $post->tags()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            [$staff->id, $coAuthor->id],
            $post->authors()->pluck('users.id')->all(),
        );
    }

    public function test_create_accepts_empty_optional_glass_fields_as_null(): void
    {
        $staff = User::factory()->staff()->create();

        // Mirrors Edit.tsx empty selects / cleared SEO inputs ("" → null).
        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Sparse Draft',
            'slug' => 'sparse-draft',
            'excerpt' => '',
            'channel' => 'apes_cic',
            'content' => [
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
            ],
            'hero_image' => '',
            'hero_image_alt' => '',
            'hero_image_caption' => '',
            'hero_image_credit' => '',
            'meta_title' => '',
            'meta_description' => '',
            'canonical_url' => '',
            'email_on_publish' => false,
            'mailing_lists' => [],
            'newsletter_segment_id' => '',
            'tags' => [],
            'featured' => false,
            'co_author_ids' => [],
        ])->assertRedirect();

        $post = Post::query()->where('slug', 'sparse-draft')->firstOrFail();
        $this->assertNull($post->hero_image);
        $this->assertNull($post->meta_title);
        $this->assertNull($post->canonical_url);
        $this->assertNull($post->newsletter_segment_id);
        $this->assertFalse($post->email_on_publish);
        $this->assertFalse($post->featured);
        $this->assertSame([], $post->mailing_lists ?? []);
        $this->assertCount(0, $post->tags);
    }

    public function test_update_round_trips_glass_payload_with_expected_updated_at(): void
    {
        $staff = User::factory()->staff()->create();
        $coAuthor = User::factory()->staff()->create();
        $segment = $this->makeSegment();
        $post = Post::factory()->create(['author_id' => $staff->id]);

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", [
            'title' => 'Updated glass title',
            'slug' => $post->slug,
            'excerpt' => 'Updated excerpt',
            'channel' => $post->channel->value,
            'visibility' => ContentVisibility::Paid->value,
            'content' => $post->content,
            'hero_image' => 'https://cdn.example.test/updated.jpg',
            'hero_image_alt' => 'Updated alt',
            'hero_image_caption' => 'Updated caption',
            'hero_image_credit' => 'Updated credit',
            'meta_title' => 'Updated SEO',
            'meta_description' => 'Updated meta',
            'canonical_url' => 'https://canonical.example/updated',
            'email_on_publish' => true,
            'mailing_lists' => [
                MailingList::ApesCic->value,
                MailingList::ApesShelterRescue->value,
            ],
            'newsletter_segment_id' => $segment->id,
            'tags' => ['Updated'],
            'featured' => true,
            'co_author_ids' => [$coAuthor->id],
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame('Updated glass title', $post->title);
        $this->assertSame(ContentVisibility::Paid, $post->visibility);
        $this->assertSame('https://cdn.example.test/updated.jpg', $post->hero_image);
        $this->assertSame('Updated SEO', $post->meta_title);
        $this->assertSame($segment->id, $post->newsletter_segment_id);
        $this->assertTrue($post->featured);
        $this->assertEqualsCanonicalizing(
            [MailingList::ApesCic->value, MailingList::ApesShelterRescue->value],
            $post->mailing_lists,
        );
        $this->assertEqualsCanonicalizing(
            [$staff->id, $coAuthor->id],
            $post->authors()->pluck('users.id')->all(),
        );

        $this->actingAs($staff)
            ->get("/staff/posts/{$post->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Staff/Posts/Edit')
                ->where('post.meta_title', 'Updated SEO')
                ->where('post.newsletter_segment_id', $segment->id)
                ->where('post.featured', true)
                ->where('post.co_author_ids', [$coAuthor->id])
                ->where('post.visibility', ContentVisibility::Paid->value));
    }

    public function test_update_rejects_stale_expected_updated_at_without_mutating(): void
    {
        $staff = User::factory()->staff()->create();
        $post = Post::factory()->create([
            'author_id' => $staff->id,
            'title' => 'Original title',
        ]);
        $originalUpdatedAt = $post->updated_at?->toIso8601String();

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", [
            'title' => 'Should not stick',
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'content' => $post->content,
            'expected_updated_at' => '2000-01-01T00:00:00+00:00',
        ])->assertSessionHasErrors('conflict');

        $post->refresh();
        $this->assertSame('Original title', $post->title);
        $this->assertSame($originalUpdatedAt, $post->updated_at?->toIso8601String());
    }

    public function test_invalid_glass_panel_fields_are_rejected(): void
    {
        $staff = User::factory()->staff()->create();
        $post = Post::factory()->create(['author_id' => $staff->id]);

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'content' => $post->content,
            'canonical_url' => 'not-a-url',
            'visibility' => 'not-a-visibility',
            'mailing_lists' => ['not-a-list'],
            'newsletter_segment_id' => 999999,
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ])->assertSessionHasErrors([
            'canonical_url',
            'visibility',
            'mailing_lists.0',
            'newsletter_segment_id',
        ]);
    }

    private function makeSegment(): NewsletterSegment
    {
        $primary = Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->firstOrFail();
        $also = Newsletter::query()->create(['name' => 'Also', 'slug' => 'also-parity']);

        return NewsletterSegment::query()->create([
            'newsletter_id' => $primary->id,
            'name' => 'Parity segment',
            'also_newsletter_id' => $also->id,
        ]);
    }
}
