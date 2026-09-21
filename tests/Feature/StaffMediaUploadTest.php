<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_upload_an_image(): void
    {
        Storage::fake('public');
        $staff = User::factory()->staff()->create();
        $file = UploadedFile::fake()->image('hero.jpg', 40, 30);

        $response = $this->actingAs($staff)->postJson('/staff/media/upload', [
            'kind' => 'image',
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', 1);

        $url = $response->json('file.url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/storage/editor/', $url);
        $this->assertTrue(filter_var($url, FILTER_VALIDATE_URL) !== false);

        $relative = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $relative = preg_replace('#^storage/#', '', $relative) ?? '';
        Storage::disk('public')->assertExists($relative);
    }

    public function test_staff_can_upload_a_pdf_file(): void
    {
        Storage::fake('public');
        $staff = User::factory()->staff()->create();
        $file = UploadedFile::fake()->create('guide.pdf', 120, 'application/pdf');

        $response = $this->actingAs($staff)->postJson('/staff/media/upload', [
            'kind' => 'file',
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', 1)
            ->assertJsonPath('file.title', 'guide');

        $this->assertStringContainsString('KB', (string) $response->json('file.size'));
    }

    public function test_oversize_image_is_rejected(): void
    {
        Storage::fake('public');
        $staff = User::factory()->staff()->create();
        $file = UploadedFile::fake()->image('big.jpg')->size(6000);

        $this->actingAs($staff)->postJson('/staff/media/upload', [
            'kind' => 'image',
            'file' => $file,
        ])->assertStatus(422);
    }

    public function test_bad_mime_is_rejected(): void
    {
        Storage::fake('public');
        $staff = User::factory()->staff()->create();
        $file = UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload');

        $this->actingAs($staff)->postJson('/staff/media/upload', [
            'kind' => 'file',
            'file' => $file,
        ])->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guest_cannot_upload(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('x.jpg');

        $this->postJson('/staff/media/upload', [
            'kind' => 'image',
            'file' => $file,
        ])->assertUnauthorized();
    }

    public function test_public_user_cannot_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('x.jpg');

        $this->actingAs($user)->postJson('/staff/media/upload', [
            'kind' => 'image',
            'file' => $file,
        ])->assertForbidden();
    }

    public function test_staff_can_save_post_with_uploaded_media_urls(): void
    {
        Storage::fake('public');
        $staff = User::factory()->staff()->create();
        $image = UploadedFile::fake()->image('hero.png');
        $pdf = UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf');

        $imageUrl = $this->actingAs($staff)->postJson('/staff/media/upload', [
            'kind' => 'image',
            'file' => $image,
        ])->json('file.url');

        $fileUrl = $this->actingAs($staff)->postJson('/staff/media/upload', [
            'kind' => 'file',
            'file' => $pdf,
        ])->json('file.url');

        $response = $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Uploaded Media',
            'slug' => 'uploaded-media',
            'excerpt' => 'Has uploads',
            'channel' => 'apes_cic',
            'content' => [
                'blocks' => [
                    [
                        'type' => 'image',
                        'data' => [
                            'file' => ['url' => $imageUrl],
                            'alt' => 'Hero',
                        ],
                    ],
                    [
                        'type' => 'file',
                        'data' => [
                            'url' => $fileUrl,
                            'title' => 'Notes',
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $post = Post::query()->where('slug', 'uploaded-media')->first();
        $this->assertNotNull($post);
        $this->assertSame($imageUrl, $post->content['blocks'][0]['data']['file']['url']);
        $this->assertSame($fileUrl, $post->content['blocks'][1]['data']['url']);
    }
}
