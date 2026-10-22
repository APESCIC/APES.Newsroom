<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Hostile TinyMCE `content_html` on the staff save path (#259).
 */
class StaffContentHtmlSanitisationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payloads that are cleaned and saved.
     *
     * @return array<string, array{0: string, 1: list<array<string, mixed>>}>
     */
    public static function sanitisedPayloads(): array
    {
        return [
            'script element' => [
                '<p>Hi<script>document.cookie</script> there</p><script>steal()</script>',
                [['type' => 'paragraph', 'data' => ['text' => 'Hi there']]],
            ],
            'event handlers and inline styles' => [
                '<p onclick="steal()" style="position:fixed"><strong onmouseover="steal()" style="color:red">Bold</strong></p>',
                [['type' => 'paragraph', 'data' => ['text' => '<strong>Bold</strong>']]],
            ],
            'javascript entity-encoded link' => [
                '<p><a href="jav&#x61;script&#x3a;steal()">click</a></p>',
                [['type' => 'paragraph', 'data' => ['text' => 'click']]],
            ],
            'data and relative links' => [
                '<p><a href="data:text/html,<b>x</b>">data</a> <a href="/admin">admin</a></p>',
                [['type' => 'paragraph', 'data' => ['text' => 'data admin']]],
            ],
            'iframes objects svg and forms' => [
                '<iframe src="https://evil.example"></iframe><object data="x.swf"></object><svg onload="steal()"><circle/></svg><form action="https://evil.example"><input name="x"></form><p>Kept</p>',
                [['type' => 'paragraph', 'data' => ['text' => 'Kept']]],
            ],
            'style element and unknown wrappers' => [
                '<style>body{display:none}</style><section><article><p><span class="x"><font color="red">Text</font></span></p></article></section>',
                [['type' => 'paragraph', 'data' => ['text' => 'Text']]],
            ],
            'image attributes' => [
                '<figure class="image" onclick="steal()"><img src="https://cdn.example.com/a.jpg" alt="Dog" onerror="steal()" style="width:9999px"><figcaption><script>x</script>Cap</figcaption></figure>',
                [['type' => 'image', 'data' => ['file' => ['url' => 'https://cdn.example.com/a.jpg'], 'alt' => 'Dog', 'caption' => 'Cap', 'credit' => '']]],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $expected
     */
    #[DataProvider('sanitisedPayloads')]
    public function test_hostile_html_is_sanitised_on_posts_and_pages(string $html, array $expected): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Hostile post',
            'channel' => 'apes_cic',
            'editor' => 'tinymce',
            'content_html' => $html,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($staff)->post('/staff/pages', [
            'title' => 'Hostile page',
            'slug' => 'hostile-page',
            'editor' => 'tinymce',
            'content_html' => $html,
        ])->assertRedirect()->assertSessionHasNoErrors();

        foreach ([Post::query()->where('title', 'Hostile post')->firstOrFail(), Page::query()->where('slug', 'hostile-page')->firstOrFail()] as $model) {
            $this->assertSame($expected, $model->content['blocks']);
            $json = (string) json_encode($model->content);
            $this->assertStringNotContainsStringIgnoringCase('steal', $json);
            $this->assertStringNotContainsStringIgnoringCase('<script', $json);
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $json);
        }
    }

    /**
     * Payloads that are rejected instead of stored.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedPayloads(): array
    {
        return [
            'placeholder with unknown type' => ['<div data-block-type="script" data-block="{}">x</div>', 'content'],
            'placeholder with broken json' => ['<div data-block-type="callout" data-block="{oops">x</div>', 'content'],
            'forged legacy html with a handler' => [
                '<div data-block-type="legacy" data-block="{&quot;html&quot;:&quot;&lt;img src=x onerror=steal()&gt;&quot;}">x</div>',
                'content',
            ],
            'forged embed with an unapproved service' => [
                '<div data-block-type="embed" data-block="{&quot;service&quot;:&quot;evil&quot;,&quot;source&quot;:&quot;https://evil.example&quot;}">x</div>',
                'content',
            ],
            'forged bookmark with a javascript url' => [
                '<div data-block-type="bookmark" data-block="{&quot;url&quot;:&quot;javascript:steal()&quot;}">x</div>',
                'content',
            ],
            'image with a data uri' => ['<figure class="image"><img src="data:image/png;base64,AAAA" alt="x"></figure>', 'content'],
            'image without alt text' => ['<figure class="image"><img src="https://cdn.example.com/a.jpg"></figure>', 'content'],
        ];
    }

    #[DataProvider('rejectedPayloads')]
    public function test_forged_or_invalid_html_is_rejected(string $html, string $errorKey): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Rejected',
            'channel' => 'apes_cic',
            'editor' => 'tinymce',
            'content_html' => $html,
        ])->assertSessionHasErrors($errorKey);

        $this->assertDatabaseMissing('posts', ['title' => 'Rejected']);
    }

    public function test_oversized_html_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Too big',
            'channel' => 'apes_cic',
            'editor' => 'tinymce',
            'content_html' => '<p>'.str_repeat('a', 1_000_001).'</p>',
        ])->assertSessionHasErrors('content_html');

        $this->assertDatabaseMissing('posts', ['title' => 'Too big']);
    }

    public function test_revisions_restore_regardless_of_the_editor_that_produced_them(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/staff/posts', [
            'title' => 'Mixed editors',
            'channel' => 'apes_cic',
            'editor' => 'tinymce',
            'content_html' => '<p>Written in <em>TinyMCE</em></p>',
        ])->assertRedirect();
        $post = Post::query()->where('title', 'Mixed editors')->firstOrFail();
        $tinymceRevision = $post->revisions()->latest('id')->firstOrFail();

        $this->actingAs($admin)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'editor' => 'editorjs',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Rewritten in Editor.js']]]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $editorJsRevision = $post->revisions()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->post("/staff/posts/{$post->id}/revisions/{$tinymceRevision->id}/restore")
            ->assertRedirect();
        $this->assertSame('Written in <em>TinyMCE</em>', $post->fresh()->content['blocks'][0]['data']['text']);

        $this->actingAs($admin)
            ->post("/staff/posts/{$post->id}/revisions/{$editorJsRevision->id}/restore")
            ->assertRedirect();
        $this->assertSame('Rewritten in Editor.js', $post->fresh()->content['blocks'][0]['data']['text']);
        $this->assertSame('Rewritten in Editor.js', $post->fresh()->body_text);
    }
}
