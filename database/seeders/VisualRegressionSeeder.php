<?php

namespace Database\Seeders;

use App\Enums\Channel;
use App\Enums\ModerationStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Offer;
use App\Models\Page;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Fixed content for the Playwright screenshot and accessibility suite.
 *
 * Every value and timestamp is constant so screenshots are stable between
 * runs. Refuses to run outside local and testing.
 */
class VisualRegressionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('VisualRegressionSeeder skipped: only seeded in local and testing.');

            return;
        }

        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', 'UTC'));

        try {
            $this->call(DemoUsersSeeder::class);

            $staff = User::query()->where('email', 'staff@apes.local')->firstOrFail();
            $admin = User::query()->where('email', 'admin@apes.local')->firstOrFail();

            $this->seedPosts($staff, $admin);
            $this->seedPages($staff);
            $this->seedModeration();
            $this->seedAdminRecords($admin);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function seedPosts(User $staff, User $admin): void
    {
        $posts = [
            ['Rescued tortoises settle into their new enclosure', PostStatus::Published, Channel::ApesShelterRescue, $staff, 1],
            ['Winter care guide for bearded dragons', PostStatus::InReview, Channel::ApesPetCareClinic, $staff, 2],
            ['Volunteer open day at the sanctuary', PostStatus::Draft, Channel::ApesCic, $staff, 3],
            ['Annual report on exotic species welfare', PostStatus::Published, Channel::ApesCic, $admin, 4],
            ['How we rehome surrendered parrots', PostStatus::Scheduled, Channel::ApesShelterRescue, $admin, 5],
            ['Clinic opening hours over the holidays', PostStatus::Draft, Channel::ApesPetCareClinic, $staff, 6],
        ];

        foreach ($posts as [$title, $status, $channel, $author, $daysAgo]) {
            $post = Post::query()->updateOrCreate(['slug' => Str::slug($title)], [
                'author_id' => $author->id,
                'title' => $title,
                'excerpt' => 'A short summary of '.Str::lower($title).'.',
                'content' => [
                    'time' => 1760950800000,
                    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Story text for '.$title.'.']]],
                    'version' => '2.29.0',
                ],
                'status' => $status,
                'channel' => $channel,
                'published_at' => $status === PostStatus::Published ? now()->subDays($daysAgo) : null,
                'scheduled_for' => $status === PostStatus::Scheduled ? now()->addDays(2) : null,
                'review_notes' => $status === PostStatus::InReview ? 'Please check the temperature figures.' : null,
            ]);

            $post->forceFill(['created_at' => now()->subDays($daysAgo + 1), 'updated_at' => now()->subDays($daysAgo)])->saveQuietly();
        }
    }

    private function seedPages(User $staff): void
    {
        foreach (['About APES CIC' => PostStatus::Published, 'Volunteer with us' => PostStatus::Draft] as $title => $status) {
            Page::query()->updateOrCreate(['slug' => Str::slug($title)], [
                'author_id' => $staff->id,
                'title' => $title,
                'excerpt' => 'Page about '.Str::lower($title).'.',
                'content' => [
                    'time' => 1760950800000,
                    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Page text for '.$title.'.']]],
                    'version' => '2.29.0',
                ],
                'status' => $status,
                'published_at' => $status === PostStatus::Published ? now()->subDays(10) : null,
            ]);
        }
    }

    private function seedModeration(): void
    {
        $reader = User::query()->updateOrCreate(['email' => 'reader@apes.local'], [
            'name' => 'Riley Reader',
            'password' => 'password',
            'email_verified_at' => now(),
            'auth_provider' => 'password',
        ]);
        $reader->forceFill(['role' => Role::Public])->save();

        Profile::query()->updateOrCreate(['user_id' => $reader->id], [
            'display_name' => 'Riley R.',
            'bio' => 'Keeper of two leopard geckos.',
            'moderation_status' => ModerationStatus::Pending,
            'public_opt_in' => true,
        ]);

        $post = Post::query()->where('status', PostStatus::Published)->orderBy('id')->firstOrFail();
        Comment::query()->updateOrCreate(['post_id' => $post->id, 'user_id' => $reader->id], [
            'body' => 'Lovely to see them doing so well.',
            'moderation_status' => ModerationStatus::Pending,
            'body_hash' => hash('sha256', 'Lovely to see them doing so well.'),
        ]);
    }

    private function seedAdminRecords(User $admin): void
    {
        WebhookEndpoint::query()->updateOrCreate(['name' => 'Slack newsroom feed'], [
            'url' => 'https://hooks.example.test/newsroom',
            'secret' => 'visual-regression-secret',
            'events' => ['post.published'],
            'is_active' => true,
        ]);

        Offer::query()->updateOrCreate(['code' => 'WINTER25'], [
            'name' => 'Winter supporter offer',
            'discount_type' => 'percent',
            'discount_value' => 25,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(30),
            'max_redemptions' => 100,
            'redemption_count' => 4,
            'is_active' => true,
        ]);

        foreach (['settings.updated', 'user.role_changed', 'api_token.created'] as $index => $action) {
            AuditLog::query()->firstOrCreate(['action' => $action, 'actor_id' => $admin->id], [
                'subject_type' => User::class,
                'subject_id' => $admin->id,
                'payload' => ['source' => 'visual-regression'],
                'ip_address' => '192.0.2.'.($index + 10),
                'user_agent' => 'Playwright',
            ]);
        }
    }
}
