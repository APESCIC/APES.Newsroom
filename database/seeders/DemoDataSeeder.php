<?php

namespace Database\Seeders;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use App\Enums\MediaAssetStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModerationStatus;
use App\Enums\PostStatus;
use App\Enums\ReactionType;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Comment;
use App\Models\ContentView;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\MediaAsset;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\ModerationReport;
use App\Models\Newsletter;
use App\Models\Offer;
use App\Models\Page;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Reaction;
use App\Models\Redirect;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Mailing\CampaignService;
use App\Support\DemoAccounts;
use Database\Seeders\Demo\DemoContent;
use Database\Seeders\Demo\PlaceholderImages;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Realistic, clearly fake demo data for clicking through the app locally
 * (docs/herd/demo-data.md). Refuses to run unless APP_ENV=local.
 *
 * A fixed Faker seed and a pinned clock make every run produce the same
 * records, and every write is keyed on a natural key, so running it twice
 * adds no duplicates.
 */
class DemoDataSeeder extends Seeder
{
    public const SEED = 20261109;

    public const CLOCK = '2026-11-02 09:00:00';

    public const READERS = 40;

    public const CONTACTS = 75;

    private Generator $faker;

    private ?string $passwordHash = null;

    /** @var Collection<int, User> */
    private Collection $readers;

    /** @var Collection<int, Post> */
    private Collection $published;

    /** @var list<MediaAsset> */
    private array $media = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo data refused: it only loads when APP_ENV=local (this app is "'.app()->environment().'").');
        }

        Carbon::setTestNow(Carbon::parse(self::CLOCK, 'Europe/London'));
        $this->faker = FakerFactory::create('en_GB');
        $this->faker->seed(self::SEED);

        try {
            $this->call([DemoUsersSeeder::class, ReleaseSeeder::class]);

            $staff = $this->user(DemoAccounts::STAFF);
            $admin = $this->user(DemoAccounts::ADMIN);
            $writer = $this->seedPerson('writer@example.test', 'Wren Writer', Role::Staff);

            $this->seedReaders();
            $this->seedMedia($staff);
            $tags = $this->seedTags();
            $this->seedPosts([$staff, $writer, $admin], $tags);
            $this->seedPages($staff);
            $this->seedProfilesAndModeration();
            $this->seedReactionsAndViews();
            $this->seedMailing($admin);
            $this->seedMemberships();
            $this->seedAdminRecords($admin, $staff);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seedPerson(string $email, string $name, Role $role, bool $withContactDetails = false): User
    {
        $attributes = [
            'name' => $name,
            'password' => $this->passwordHash ??= Hash::make(DemoAccounts::PASSWORD),
            'email_verified_at' => now(),
            'auth_provider' => 'password',
        ];

        if ($withContactDetails) {
            $mobile = $this->dramaMobile();
            $attributes += [
                'address_line1' => $this->faker->numberBetween(1, 120).' '.$this->faker->randomElement(DemoContent::streets()),
                'address_line2' => null,
                'address_town' => $this->faker->city(),
                'address_county' => $this->faker->county(),
                'address_postcode' => $this->fakePostcode(),
                'address_country' => 'GB',
                'mobile_number' => $mobile,
                'whatsapp_type' => 'number',
                'whatsapp_value' => $mobile,
                'whatsapp_same_as_mobile' => true,
            ];
        }

        $user = User::query()->updateOrCreate(['email' => $email], $attributes);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /**
     * Ofcom drama range 07700 900000 to 900999, in E.164.
     */
    private function dramaMobile(): string
    {
        return '+447700900'.str_pad((string) $this->faker->numberBetween(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * UK postcode format in the non-existent ZZ area, so it never matches a
     * real address.
     */
    private function fakePostcode(): string
    {
        return 'ZZ'.$this->faker->numberBetween(1, 99).' '.$this->faker->numberBetween(1, 9).strtoupper($this->faker->lexify('??'));
    }

    private function seedReaders(): void
    {
        $readers = [$this->seedPerson('reader@example.test', 'Riley Reader', Role::Public, true)];

        for ($i = 1; $i <= self::READERS; $i++) {
            $readers[] = $this->seedPerson(
                sprintf('reader%02d@example.org', $i),
                $this->faker->firstName().' '.$this->faker->lastName(),
                Role::Public,
                true,
            );
        }

        $this->readers = collect($readers);
    }

    private function seedMedia(User $uploader): void
    {
        if (! PlaceholderImages::available()) {
            $this->command?->warn('GD is not enabled, so demo images were skipped (enable extension=gd with "herd ini 8.4", then "herd restart php").');

            return;
        }

        PlaceholderImages::clear();
        $animals = DemoContent::animals();

        for ($i = 0; $i < 30; $i++) {
            [$name, $species] = $animals[$i % count($animals)];
            $label = $name.' the '.$species;
            $file = PlaceholderImages::make($i, $label);

            $this->media[] = MediaAsset::query()->updateOrCreate(['path' => $file['path']], [
                'uuid' => $this->faker->uuid(),
                'disk' => 'public',
                'mime' => 'image/png',
                'width' => PlaceholderImages::WIDTH,
                'height' => PlaceholderImages::HEIGHT,
                'bytes' => $file['bytes'],
                'original_name' => Str::slug($label).'.png',
                'alt' => 'Placeholder image for '.$label,
                'status' => MediaAssetStatus::Ready,
                'variants' => ['widths' => [], 'social' => []],
                'source' => MediaAsset::SOURCE_UPLOAD,
                'sha256' => $file['sha256'],
                'uploaded_by' => $uploader->id,
            ]);
        }
    }

    /**
     * @return Collection<int, Tag>
     */
    private function seedTags(): Collection
    {
        $tags = collect(DemoContent::tags())->map(
            fn (array $tag) => Tag::query()->updateOrCreate(['slug' => $tag[1]], ['name' => $tag[0], 'is_internal' => false]),
        );

        $tags->push(Tag::query()->updateOrCreate(['slug' => 'internal-review'], ['name' => 'Internal review', 'is_internal' => true]));

        return $tags;
    }

    /**
     * @param  list<User>  $authors
     * @param  Collection<int, Tag>  $tags
     */
    private function seedPosts(array $authors, Collection $tags): void
    {
        $paragraphs = DemoContent::paragraphs();
        $published = [];
        $index = 0;

        foreach (DemoContent::posts() as $channel => $posts) {
            foreach ($posts as [$title, $status]) {
                $author = $authors[$index % count($authors)];
                $daysAgo = 2 + $index * 3;
                $hero = $this->media[$index] ?? null;
                $body = $this->faker->randomElements($paragraphs, 3);

                $blocks = [
                    ['type' => 'paragraph', 'data' => ['text' => $body[0]]],
                    ['type' => 'header', 'data' => ['text' => 'What happens next', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => $body[1]]],
                    ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Check the enclosure temperature', 'Offer fresh water daily', 'Book a clinic health check']]],
                    ['type' => 'quote', 'data' => ['text' => $body[2], 'caption' => 'A member of the demo team']],
                ];

                if ($hero !== null && $index % 4 === 0) {
                    $blocks[] = ['type' => 'image', 'data' => ['file' => ['url' => $hero->url()], 'alt' => $hero->alt, 'caption' => 'Demo placeholder image', 'credit' => 'APES demo data']];
                }

                $visibility = match ($index % 12) {
                    5 => ContentVisibility::Members,
                    11 => ContentVisibility::Paid,
                    default => ContentVisibility::Public,
                };

                $post = Post::query()->updateOrCreate(['slug' => Str::slug($title)], [
                    'author_id' => $author->id,
                    'title' => $title,
                    'excerpt' => $body[0],
                    'content' => ['time' => 1762074000000, 'blocks' => $blocks, 'version' => '2.29.0'],
                    'status' => $status,
                    'channel' => Channel::from($channel),
                    'visibility' => $visibility,
                    'featured' => $index === 0,
                    'published_at' => in_array($status, [PostStatus::Published, PostStatus::Unpublished], true) ? now()->subDays($daysAgo) : null,
                    'scheduled_for' => $status === PostStatus::Scheduled ? Carbon::parse('2027-01-15 10:00:00', 'Europe/London') : null,
                    'review_notes' => $status === PostStatus::InReview ? 'Please check the figures in the second paragraph.' : null,
                    'hero_image' => $hero?->url(),
                    'hero_image_alt' => $hero?->alt,
                    'hero_media_id' => $hero?->id,
                    'email_on_publish' => false,
                ]);

                $post->forceFill(['created_at' => now()->subDays($daysAgo + 1), 'updated_at' => now()->subDays($daysAgo)])->saveQuietly();
                $post->tags()->sync([$tags[$index % 10]->id, $tags[($index + 3) % 10]->id]);
                $post->authors()->sync([$author->id]);

                if ($status === PostStatus::Published) {
                    $published[] = $post;
                }

                $index++;
            }
        }

        $this->published = collect($published);
    }

    private function seedPages(User $author): void
    {
        foreach (DemoContent::pages() as $i => [$title, $status]) {
            $hero = $this->media[29 - $i] ?? null;

            Page::query()->updateOrCreate(['slug' => Str::slug($title)], [
                'author_id' => $author->id,
                'title' => $title,
                'excerpt' => 'Demo page: '.Str::lower($title).'.',
                'content' => ['time' => 1762074000000, 'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => DemoContent::paragraphs()[$i]]],
                    ['type' => 'paragraph', 'data' => ['text' => DemoContent::paragraphs()[$i + 6]]],
                ], 'version' => '2.29.0'],
                'status' => $status,
                'visibility' => ContentVisibility::Public,
                'published_at' => $status === PostStatus::Published ? now()->subDays(30 + $i) : null,
                'hero_image' => $hero?->url(),
                'hero_image_alt' => $hero?->alt,
                'hero_media_id' => $hero?->id,
            ]);
        }
    }

    private function seedProfilesAndModeration(): void
    {
        $statuses = [
            ...array_fill(0, 10, ModerationStatus::Approved),
            ...array_fill(0, 5, ModerationStatus::Pending),
            ModerationStatus::Rejected,
            ModerationStatus::Suspended,
            ModerationStatus::Private,
        ];

        foreach ($statuses as $i => $status) {
            $reader = $this->readers[$i + 1];
            Profile::query()->updateOrCreate(['user_id' => $reader->id], [
                'display_name' => Str::before($reader->name, ' ').' '.Str::substr(Str::after($reader->name, ' '), 0, 1).'.',
                'bio' => 'Keeper of '.$this->faker->randomElement(['two leopard geckos', 'a royal python', 'three tortoises', 'a pair of cockatiels', 'a bearded dragon', 'a chinchilla']).'.',
                'moderation_status' => $status,
                'public_opt_in' => $status !== ModerationStatus::Private,
            ]);
        }

        $bodies = DemoContent::comments();
        $comments = [];

        for ($i = 0; $i < 40; $i++) {
            $post = $this->published[$i % $this->published->count()];
            $reader = $this->readers[1 + ($i % 20)];
            $body = $bodies[$i % count($bodies)];
            $status = $i < 24 ? ModerationStatus::Approved : ($i < 36 ? ModerationStatus::Pending : ModerationStatus::Rejected);

            $comment = Comment::query()->updateOrCreate(
                ['post_id' => $post->id, 'user_id' => $reader->id, 'body_hash' => hash('sha256', $body)],
                ['body' => $body, 'moderation_status' => $status],
            );
            $comment->forceFill(['created_at' => now()->subDays(1 + $i), 'updated_at' => now()->subDays(1 + $i)])->saveQuietly();
            $comments[] = $comment;
        }

        $reports = [
            ['Spam link in the comment', 'open'],
            ['Rude to another reader', 'open'],
            ['Off-topic advertising', 'open'],
            ['Possibly unsafe care advice', 'open'],
            ['Duplicate comment', 'resolved'],
            ['Reported by mistake', 'dismissed'],
        ];

        foreach ($reports as $i => [$reason, $status]) {
            $comment = $comments[24 + $i];
            ModerationReport::query()->updateOrCreate(
                ['reporter_id' => $this->readers[30 + $i]->id, 'reportable_type' => $comment->getMorphClass(), 'reportable_id' => $comment->id],
                ['reason' => $reason, 'status' => $status],
            );
        }
    }

    private function seedReactionsAndViews(): void
    {
        $types = ReactionType::cases();

        for ($i = 0; $i < 60; $i++) {
            Reaction::query()->updateOrCreate(
                ['post_id' => $this->published[$i % $this->published->count()]->id, 'user_id' => $this->readers[$i % 30]->id],
                ['type' => $types[$i % count($types)]],
            );
        }

        foreach ($this->published as $i => $post) {
            for ($v = 0; $v < 2 + ($i % 5); $v++) {
                ContentView::query()->firstOrCreate([
                    'path' => '/articles/'.$post->slug,
                    'post_id' => $post->id,
                    'viewed_at' => now()->subDays($v)->subHours($i % 24),
                ]);
            }
        }
    }

    private function seedMailing(User $admin): void
    {
        $newsletters = Newsletter::query()->whereNotNull('legacy_list')->get()->keyBy(fn (Newsletter $n) => $n->legacy_list->value);
        $lists = MailingList::cases();
        $domains = ['example.com', 'example.org', 'example.test'];
        $contacts = [];

        for ($i = 0; $i < self::CONTACTS; $i++) {
            $reader = $i < 20 ? $this->readers[$i + 1] : null;
            $name = $reader?->name ?? $this->faker->firstName().' '.$this->faker->lastName();
            $email = $reader?->email ?? Str::slug($name, '.').'.'.($i + 1).'@'.$domains[$i % 3];

            $contact = MailingContact::query()->updateOrCreate(['email' => $email], ['name' => $name, 'user_id' => $reader?->id]);
            $contacts[] = $contact;

            foreach ($lists as $l => $list) {
                if (($i + $l) % 3 === 2) {
                    continue;
                }

                $status = match (true) {
                    $i % 10 === 7 => SubscriptionStatus::Unsubscribed,
                    $i % 10 === 4 => SubscriptionStatus::Pending,
                    default => SubscriptionStatus::Confirmed,
                };

                MailingListSubscription::query()->updateOrCreate(
                    ['mailing_contact_id' => $contact->id, 'list' => $list->value],
                    [
                        'newsletter_id' => $newsletters[$list->value]->id ?? null,
                        'status' => $status,
                        'confirmed_at' => $status === SubscriptionStatus::Pending ? null : now()->subDays(20 + $i),
                        'unsubscribed_at' => $status === SubscriptionStatus::Unsubscribed ? now()->subDays(5 + $i % 7) : null,
                    ],
                );
            }
        }

        $member = MailingContact::query()->updateOrCreate(['email' => DemoAccounts::MEMBER], ['name' => 'Public Demo', 'user_id' => $this->user(DemoAccounts::MEMBER)->id]);
        MailingListSubscription::query()->updateOrCreate(
            ['mailing_contact_id' => $member->id, 'list' => MailingList::ApesCic->value],
            ['newsletter_id' => $newsletters[MailingList::ApesCic->value]->id ?? null, 'status' => SubscriptionStatus::Confirmed, 'confirmed_at' => now()->subDays(60)],
        );

        foreach ([7, 17, 27, 37, 47] as $n => $i) {
            Suppression::query()->updateOrCreate(['email' => $contacts[$i]->email], [
                'reason' => ['bounce', 'complaint', 'manual', 'bounce', 'manual'][$n],
                'source' => 'demo',
                'notes' => 'Demo suppression',
            ]);
        }

        $this->seedCampaigns($admin, $newsletters);
    }

    /**
     * @param  Collection<string, Newsletter>  $newsletters
     */
    private function seedCampaigns(User $admin, Collection $newsletters): void
    {
        $campaigns = [
            ['demo-campaign-1', Channel::ApesCic, CampaignStatus::Completed, 21],
            ['demo-campaign-2', Channel::ApesShelterRescue, CampaignStatus::Completed, 9],
            ['demo-campaign-3', Channel::ApesPetCareClinic, CampaignStatus::Cancelled, 4],
            ['demo-campaign-4', Channel::ApesCic, CampaignStatus::Draft, 1],
        ];
        $snapshots = app(CampaignService::class);

        foreach ($campaigns as [$key, $channel, $status, $daysAgo]) {
            $post = $this->published->first(fn (Post $p) => $p->channel === $channel) ?? $this->published->first();
            $list = MailingList::from($channel->value);
            $newsletter = $newsletters[$list->value] ?? null;
            $sent = $status === CampaignStatus::Completed;

            $campaign = Campaign::query()->updateOrCreate(['idempotency_key' => $key], [
                'post_id' => $post->id,
                'created_by' => $admin->id,
                'lists' => [$list->value],
                'snapshot' => $snapshots->buildSnapshot($post, $newsletter),
                'status' => $status,
                'is_test' => false,
                'newsletter_id' => $newsletter?->id,
                'queued_at' => $sent ? now()->subDays($daysAgo) : null,
                'completed_at' => $sent ? now()->subDays($daysAgo)->addMinutes(12) : null,
            ]);

            if (! $sent) {
                continue;
            }

            $recipients = MailingListSubscription::query()
                ->where('list', $list->value)
                ->where('status', SubscriptionStatus::Confirmed)
                ->with('contact')
                ->orderBy('id')
                ->limit(20)
                ->get();

            foreach ($recipients as $r => $subscription) {
                $failed = $r === 19;
                CampaignRecipient::query()->updateOrCreate(['idempotency_key' => $key.'-'.$subscription->id], [
                    'campaign_id' => $campaign->id,
                    'email' => $subscription->contact->email,
                    'status' => $failed ? CampaignRecipientStatus::Failed : CampaignRecipientStatus::Accepted,
                    'attempts' => 1,
                    'accepted_at' => $failed ? null : now()->subDays($daysAgo)->addMinutes(5),
                    'failed_at' => $failed ? now()->subDays($daysAgo)->addMinutes(5) : null,
                    'last_error' => $failed ? 'Demo failure: mailbox full' : null,
                    'opened_at' => ! $failed && $r % 2 === 0 ? now()->subDays($daysAgo)->addHours(3) : null,
                    'clicked_at' => ! $failed && $r % 5 === 0 ? now()->subDays($daysAgo)->addHours(4) : null,
                ]);
            }
        }
    }

    private function seedMemberships(): void
    {
        $plans = MembershipPlan::query()->orderBy('id')->get();
        $members = [$this->user(DemoAccounts::MEMBER), ...$this->readers->slice(1, 11)->all()];
        $statuses = [
            MembershipStatus::Active, MembershipStatus::Active, MembershipStatus::Active, MembershipStatus::Active,
            MembershipStatus::Active, MembershipStatus::Free, MembershipStatus::Free, MembershipStatus::Free,
            MembershipStatus::Free, MembershipStatus::Free, MembershipStatus::PastDue, MembershipStatus::Canceled,
        ];

        foreach ($members as $i => $user) {
            $status = $statuses[$i];
            $plan = in_array($status, [MembershipStatus::Free], true) ? null : $plans[$i % max(1, $plans->count())] ?? null;

            Membership::query()->updateOrCreate(['user_id' => $user->id], [
                'status' => $status,
                'membership_plan_id' => $plan?->id,
                'interval' => $plan?->interval,
                'current_period_end' => $plan !== null ? now()->addDays(10 + $i * 3) : null,
            ]);
        }
    }

    private function seedAdminRecords(User $admin, User $staff): void
    {
        Offer::query()->updateOrCreate(['code' => 'WINTER25'], [
            'name' => 'Winter supporter offer',
            'discount_type' => 'percent',
            'discount_value' => 25,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(60),
            'max_redemptions' => 100,
            'redemption_count' => 4,
            'is_active' => true,
        ]);

        Offer::query()->updateOrCreate(['code' => 'FRIENDS10'], [
            'name' => 'Friends of the sanctuary',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'starts_at' => now()->subDays(40),
            'ends_at' => null,
            'max_redemptions' => null,
            'redemption_count' => 12,
            'is_active' => true,
        ]);

        foreach ([
            ['/news/tortoise-update', '/articles/rescued-tortoises-settle-into-their-new-enclosure'],
            ['/volunteer', '/volunteer-with-us'],
            ['/old-clinic-hours', '/articles/clinic-opening-hours-over-the-holidays'],
        ] as [$from, $to]) {
            Redirect::query()->updateOrCreate(['from_path' => $from], ['to_path' => $to, 'status_code' => 301]);
        }

        WebhookEndpoint::query()->updateOrCreate(['name' => 'Demo Slack feed'], [
            'url' => 'https://hooks.example.test/newsroom',
            'secret' => 'demo-webhook-secret-not-real',
            'events' => ['post.published'],
            'is_active' => true,
        ]);

        WebhookEndpoint::query()->updateOrCreate(['name' => 'Demo partner site'], [
            'url' => 'https://partner.example.org/webhooks/apes',
            'secret' => 'another-fake-demo-secret',
            'events' => ['post.published', 'post.updated'],
            'is_active' => false,
        ]);

        $actions = ['settings.updated', 'user.role_changed', 'post.published', 'api_token.created', 'moderation.comment_approved', 'campaign.sent'];

        for ($i = 0; $i < 30; $i++) {
            $actor = $i % 3 === 0 ? $staff : $admin;
            $log = AuditLog::query()->firstOrCreate(['action' => $actions[$i % count($actions)], 'ip_address' => '192.0.2.'.($i + 1)], [
                'actor_id' => $actor->id,
                'subject_type' => User::class,
                'subject_id' => $actor->id,
                'payload' => ['source' => 'demo-data'],
                'user_agent' => 'Demo data',
            ]);
            $log->forceFill(['created_at' => now()->subHours(6 * $i), 'updated_at' => now()->subHours(6 * $i)])->saveQuietly();
        }
    }
}
