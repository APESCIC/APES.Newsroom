<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffEditorContentHtmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_tinymce_save_creates_post_blocks_and_body_text(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'From TinyMCE',
            'channel' => 'apes_cic',
            'editor' => 'tinymce',
            'content_html' => '<h2>Intro</h2><p>Hello <strong>readers</strong></p>',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $post = Post::query()->where('title', 'From TinyMCE')->firstOrFail();

        $this->assertSame([
            ['type' => 'header', 'data' => ['text' => 'Intro', 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => 'Hello <strong>readers</strong>']],
        ], $post->content['blocks']);
        $this->assertSame('Intro Hello readers', $post->body_text);
        $this->assertSame($post->content, $post->revisions()->latest('id')->firstOrFail()->content);
    }

    public function test_tinymce_update_keeps_custom_blocks(): void
    {
        $admin = User::factory()->admin()->create();
        $toggle = ['type' => 'toggle', 'data' => ['title' => 'More', 'content' => 'Hidden detail']];
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'content' => ['time' => 1, 'version' => '2.29.0', 'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Old']],
                $toggle,
            ]],
        ]);
        app(SettingsRepository::class)->set(SettingDefinitions::EDITOR_DRIVER, 'tinymce');

        $html = '';
        $this->actingAs($admin)->get("/staff/posts/{$post->id}/edit")
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$html) {
                $page->where('post.content_html', function ($value) use (&$html) {
                    $html = (string) $value;

                    return str_contains($html, 'data-block-type="toggle"');
                });
            });

        $this->actingAs($admin)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'editor' => 'tinymce',
            'content_html' => str_replace('<p>Old</p>', '<p>New</p>', $html),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([
            ['type' => 'paragraph', 'data' => ['text' => 'New']],
            $toggle,
        ], $post->fresh()->content['blocks']);
    }

    public function test_save_follows_the_request_editor_not_the_current_setting(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create(['author_id' => $admin->id]);

        app(SettingsRepository::class)->set(SettingDefinitions::EDITOR_DRIVER, 'tinymce');

        $this->actingAs($admin)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'editor' => 'editorjs',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Opened in Editor.js']]]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Opened in Editor.js', $post->fresh()->content['blocks'][0]['data']['text']);

        app(SettingsRepository::class)->set(SettingDefinitions::EDITOR_DRIVER, 'editorjs');

        $this->actingAs($admin)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'editor' => 'tinymce',
            'content_html' => '<p>Opened in TinyMCE</p>',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Opened in TinyMCE', $post->fresh()->content['blocks'][0]['data']['text']);
    }

    public function test_tinymce_save_on_pages(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/pages', [
            'title' => 'About',
            'slug' => 'about',
            'editor' => 'tinymce',
            'content_html' => '<p>We <em>rescue</em> animals.</p>',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $page = Page::query()->where('slug', 'about')->firstOrFail();
        $this->assertSame('We <em>rescue</em> animals.', $page->content['blocks'][0]['data']['text']);
    }

    public function test_editor_html_is_only_sent_when_the_site_uses_tinymce(): void
    {
        $admin = User::factory()->admin()->create();
        $page = Page::factory()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)->get("/staff/pages/{$page->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $assert) => $assert->where('page.content_html', null));

        app(SettingsRepository::class)->set(SettingDefinitions::EDITOR_DRIVER, 'tinymce');

        $this->actingAs($admin)->get("/staff/pages/{$page->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $assert) => $assert->where('page.content_html', fn ($html) => is_string($html)));
    }

    public function test_tinymce_payload_requires_content_html_and_editorjs_payload_requires_content(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Missing html',
            'channel' => 'apes_cic',
            'editor' => 'tinymce',
        ])->assertSessionHasErrors('content_html');

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Missing blocks',
            'channel' => 'apes_cic',
            'content_html' => '<p>Ignored without editor=tinymce</p>',
        ])->assertSessionHasErrors('content');

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Bad editor',
            'channel' => 'apes_cic',
            'editor' => 'word',
            'content' => ['blocks' => []],
        ])->assertSessionHasErrors('editor');
    }

    public function test_hostile_content_html_is_sanitised(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Hostile',
            'channel' => 'apes_cic',
            'editor' => 'tinymce',
            'content_html' => '<p onmouseover="steal()" style="color:red">Hi <a href="data:text/html,x">there</a></p>'
                .'<figure class="image"><img src="https://cdn.example.com/a.jpg" alt="Dog" onerror="steal()"></figure>'
                .'<svg onload="steal()"></svg><object data="x"></object>',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $post = Post::query()->where('title', 'Hostile')->firstOrFail();

        $this->assertSame([
            ['type' => 'paragraph', 'data' => ['text' => 'Hi there']],
            ['type' => 'image', 'data' => ['file' => ['url' => 'https://cdn.example.com/a.jpg'], 'alt' => 'Dog', 'caption' => '', 'credit' => '']],
        ], $post->content['blocks']);
        $this->assertStringNotContainsString('steal', json_encode($post->content));
    }
}
