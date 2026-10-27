<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Channel;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\EmailSendingDomain;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Services\Mail\MailgunSenderCheck;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\SenderDomains;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterController extends Controller
{
    public function __construct(private readonly CampaignSender $sender) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', Newsletter::STATUS_ACTIVE, Newsletter::STATUS_ARCHIVED], true)) {
            $status = 'all';
        }

        $newsletters = Newsletter::query()
            ->when($status === Newsletter::STATUS_ACTIVE, fn ($query) => $query->active())
            ->when($status === Newsletter::STATUS_ARCHIVED, fn ($query) => $query->archived())
            ->withCount([
                'subscriptions as confirmed_count' => fn ($query) => $query->where('status', SubscriptionStatus::Confirmed),
                'subscriptions as pending_count' => fn ($query) => $query->where('status', SubscriptionStatus::Pending),
                'subscriptions as unsubscribed_count' => fn ($query) => $query->where('status', SubscriptionStatus::Unsubscribed),
                'subscriptions as account_confirmed_count' => fn ($query) => $query
                    ->where('status', SubscriptionStatus::Confirmed)
                    ->whereHas('contact', fn ($contact) => $contact->whereNotNull('user_id')),
                'subscriptions as mailing_only_confirmed_count' => fn ($query) => $query
                    ->where('status', SubscriptionStatus::Confirmed)
                    ->whereHas('contact', fn ($contact) => $contact->whereNull('user_id')),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Newsletter $newsletter) => [
                'id' => $newsletter->id,
                'name' => $newsletter->name,
                'slug' => $newsletter->slug,
                'description' => $newsletter->description,
                'legacy_list' => $newsletter->legacy_list?->value,
                'status' => $newsletter->status(),
                'sender_name' => $newsletter->sender_name,
                'sender_email' => $newsletter->sender_email,
                'sender_verified' => $this->sender->isVerified($newsletter),
                'channel' => $newsletter->channel?->value,
                'show_on_home' => $newsletter->show_on_home,
                'show_in_nav' => $newsletter->show_in_nav,
                'display_order' => $newsletter->display_order,
                'confirmed_count' => (int) $newsletter->confirmed_count,
                'pending_count' => (int) $newsletter->pending_count,
                'unsubscribed_count' => (int) $newsletter->unsubscribed_count,
                'account_confirmed_count' => (int) $newsletter->account_confirmed_count,
                'mailing_only_confirmed_count' => (int) $newsletter->mailing_only_confirmed_count,
            ]);

        return Inertia::render('Staff/Newsletters/Index', [
            'newsletters' => $newsletters,
            'status' => $status,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Staff/Newsletters/Edit', [
            'newsletter' => null,
            'channelOptions' => $this->channelOptions(null),
            'domainOptions' => $this->domainOptions(),
            'reservedLocalParts' => SenderDomains::RESERVED_LOCAL_PARTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $newsletter = Newsletter::query()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] !== '' ? $validated['slug'] : Str::slug($validated['name']),
            ...$this->editableAttributes($validated),
            ...$this->channelAttributes($request),
        ]);

        return redirect()->route('staff.newsletters.edit', $newsletter);
    }

    public function edit(Newsletter $newsletter): Response
    {
        return Inertia::render('Staff/Newsletters/Edit', [
            'newsletter' => [
                'id' => $newsletter->id,
                'name' => $newsletter->name,
                'slug' => $newsletter->slug,
                'description' => $newsletter->description,
                'legacy_list' => $newsletter->legacy_list?->value,
                'archived_at' => $newsletter->archived_at?->toIso8601String(),
                'status' => $newsletter->status(),
                'sender_name' => $newsletter->sender_name,
                'sender_email' => $newsletter->sender_email,
                'reply_to' => $newsletter->reply_to,
                'subscribe_on_signup' => $newsletter->subscribe_on_signup,
                'sender_verification' => $this->verificationPayload($newsletter),
                'send_blocks' => $this->sendBlocks($newsletter),
                'channel' => $newsletter->channel?->value,
                'show_on_home' => $newsletter->show_on_home,
                'show_in_nav' => $newsletter->show_in_nav,
                'display_order' => $newsletter->display_order,
                'public_label' => $newsletter->public_label,
                'public_description' => $newsletter->public_description,
            ],
            'channelOptions' => $this->channelOptions($newsletter),
            'domainOptions' => $this->domainOptions(),
            'reservedLocalParts' => SenderDomains::RESERVED_LOCAL_PARTS,
            'segments' => $newsletter->segments()->with('alsoNewsletter')->get()->map(fn (NewsletterSegment $segment) => [
                'id' => $segment->id,
                'name' => $segment->name,
                'also' => $segment->alsoNewsletter->name,
            ]),
            'otherNewsletters' => Newsletter::query()->active()->where('id', '!=', $newsletter->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Newsletter $newsletter): RedirectResponse
    {
        $validated = $this->validated($request, $newsletter);

        $attributes = $this->editableAttributes($validated);
        if ($attributes['sender_email'] !== $newsletter->sender_email) {
            $attributes['sender_verification'] = null;
        }

        $slug = $validated['slug'] !== '' ? $validated['slug'] : $newsletter->slug;
        $channelAttributes = $this->channelAttributes($request);
        $staysLinked = array_key_exists('channel', $channelAttributes)
            ? $channelAttributes['channel'] !== null
            : $newsletter->channel !== null;

        if ($staysLinked && $newsletter->channel !== null && $slug !== $newsletter->slug) {
            return back()->withErrors([
                'slug' => 'This newsletter is a public channel, so its slug is used in signup links. Unlink the channel before changing the slug.',
            ]);
        }

        $newsletter->update([
            'name' => $validated['name'],
            'slug' => $slug,
            ...$attributes,
            ...$channelAttributes,
        ]);

        return back();
    }

    public function verifySender(Newsletter $newsletter, MailgunSenderCheck $check): RedirectResponse
    {
        $check->verify($newsletter);

        return back()->with('status', 'sender-checked');
    }

    public function storeSegment(Request $request, Newsletter $newsletter): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'also_newsletter_id' => ['required', 'integer', 'exists:newsletters,id'],
        ]);

        if ((int) $validated['also_newsletter_id'] === $newsletter->id) {
            return back()->withErrors(['also_newsletter_id' => 'Choose a different newsletter.']);
        }

        $newsletter->segments()->create([
            'name' => $validated['name'],
            'also_newsletter_id' => $validated['also_newsletter_id'],
        ]);

        return back();
    }

    public function archive(Newsletter $newsletter): RedirectResponse
    {
        $newsletter->update(['archived_at' => now()]);

        return back();
    }

    public function restore(Newsletter $newsletter): RedirectResponse
    {
        $newsletter->update(['archived_at' => null]);

        return back();
    }

    /**
     * @param  array{description: string, sender_name: string, sender_email: string, reply_to: string, subscribe_on_signup: bool}  $validated
     * @return array{description: string|null, sender_name: string|null, sender_email: string|null, reply_to: string|null, subscribe_on_signup: bool}
     */
    private function editableAttributes(array $validated): array
    {
        return [
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'sender_name' => $validated['sender_name'] !== '' ? $validated['sender_name'] : null,
            'sender_email' => $validated['sender_email'] !== '' ? Str::lower($validated['sender_email']) : null,
            'reply_to' => $validated['reply_to'] !== '' ? Str::lower($validated['reply_to']) : null,
            'subscribe_on_signup' => $validated['subscribe_on_signup'],
        ];
    }

    /**
     * Only fields present in the request are returned, so partial updates keep
     * the stored channel settings.
     *
     * @return array<string, mixed>
     */
    private function channelAttributes(Request $request): array
    {
        $attributes = [];

        if ($request->exists('channel')) {
            $attributes['channel'] = $request->filled('channel') ? Channel::from((string) $request->input('channel')) : null;
        }
        foreach (['show_on_home', 'show_in_nav'] as $flag) {
            if ($request->exists($flag)) {
                $attributes[$flag] = $request->boolean($flag);
            }
        }
        if ($request->exists('display_order')) {
            $attributes['display_order'] = (int) $request->input('display_order', 0);
        }
        foreach (['public_label', 'public_description'] as $text) {
            if ($request->exists($text)) {
                $value = trim((string) $request->input($text, ''));
                $attributes[$text] = $value !== '' ? $value : null;
            }
        }

        return $attributes;
    }

    /**
     * @return list<array{value: string, label: string, slug: string, taken_by: string|null}>
     */
    private function channelOptions(?Newsletter $newsletter): array
    {
        $linked = Newsletter::query()
            ->whereNotNull('channel')
            ->when($newsletter, fn ($query) => $query->where('id', '!=', $newsletter->id))
            ->get(['name', 'channel'])
            ->mapWithKeys(fn (Newsletter $other) => [$other->channel->value => $other->name]);

        return collect(Channel::cases())->map(fn (Channel $channel) => [
            'value' => $channel->value,
            'label' => $channel->label(),
            'slug' => $channel->slug(),
            'taken_by' => $linked->get($channel->value),
        ])->values()->all();
    }

    /**
     * @return array{name: string, slug: string, description: string, sender_name: string, sender_email: string, reply_to: string, subscribe_on_signup: bool}
     */
    private function validated(Request $request, ?Newsletter $newsletter = null): array
    {
        $slugRule = 'unique:newsletters,slug';
        if ($newsletter) {
            $slugRule .= ','.$newsletter->id;
        }

        $channelUnique = Rule::unique('newsletters', 'channel');
        if ($newsletter) {
            $channelUnique = $channelUnique->ignore($newsletter->id);
        }

        /** @var array{name: string, slug: string|null, description: string|null, sender_name: string|null, sender_email: string|null, reply_to: string|null, subscribe_on_signup?: bool|string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', $slugRule],
            'description' => ['nullable', 'string', 'max:1000'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'string', 'email', 'max:255', $this->addressRule($newsletter?->sender_email, SenderDomains::senderProblem(...))],
            'reply_to' => ['nullable', 'string', 'email', 'max:255', $this->addressRule($newsletter?->reply_to, SenderDomains::replyToProblem(...))],
            'subscribe_on_signup' => ['sometimes', 'boolean'],
            'channel' => ['nullable', Rule::enum(Channel::class), $channelUnique],
            'show_on_home' => ['sometimes', 'boolean'],
            'show_in_nav' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'public_label' => ['nullable', 'string', 'max:120'],
            'public_description' => ['nullable', 'string', 'max:500'],
        ], [
            'channel.unique' => 'Another newsletter is already linked to this channel.',
        ]);

        return [
            'name' => $validated['name'],
            'slug' => (string) ($validated['slug'] ?? ''),
            'description' => (string) ($validated['description'] ?? ''),
            'sender_name' => trim((string) ($validated['sender_name'] ?? '')),
            'sender_email' => trim((string) ($validated['sender_email'] ?? '')),
            'reply_to' => trim((string) ($validated['reply_to'] ?? '')),
            'subscribe_on_signup' => (bool) ($validated['subscribe_on_signup'] ?? false),
        ];
    }

    /**
     * @return array{verified: bool, status: string|null, message: string|null, checked_at: string|null}
     */
    private function verificationPayload(Newsletter $newsletter): array
    {
        $verification = $newsletter->sender_verification ?? [];

        return [
            'verified' => $this->sender->isVerified($newsletter),
            'status' => $verification['status'] ?? null,
            'message' => $verification['message'] ?? null,
            'checked_at' => $verification['checked_at'] ?? null,
        ];
    }

    /**
     * An unchanged stored address is accepted so other fields can still be
     * edited; send-time checks block it if its domain is no longer approved.
     *
     * @param  Closure(string): ?string  $domainProblem
     */
    private function addressRule(?string $current, Closure $domainProblem): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current, $domainProblem): void {
            $email = Str::lower(trim((string) $value));
            if ($current !== null && $email === Str::lower($current)) {
                return;
            }

            $problem = SenderDomains::localPartProblem($email) ?? $domainProblem($email);
            if ($problem !== null) {
                $fail($problem);
            }
        };
    }

    /**
     * Enabled domains: all may be used for reply-to, verified ones for From.
     *
     * @return list<array{domain: string, status: string, can_send: bool}>
     */
    private function domainOptions(): array
    {
        return EmailSendingDomain::query()
            ->enabled()
            ->orderBy('domain')
            ->get()
            ->map(fn (EmailSendingDomain $domain) => [
                'domain' => $domain->domain,
                'status' => $domain->status->value,
                'can_send' => $domain->isVerified(),
            ])
            ->all();
    }

    /**
     * @return array{reasons: list<string>, last_blocked_at: string|null}
     */
    private function sendBlocks(Newsletter $newsletter): array
    {
        $lastBlocked = Campaign::query()
            ->where('newsletter_id', $newsletter->id)
            ->where('failure_reason', CampaignService::FAILURE_SENDER_UNVERIFIED)
            ->latest('id')
            ->value('created_at');

        return [
            'reasons' => $this->sender->domainProblems($newsletter),
            'last_blocked_at' => $lastBlocked ? Carbon::parse($lastBlocked)->toIso8601String() : null,
        ];
    }
}
