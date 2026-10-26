<?php

namespace App\Http\Controllers\Staff;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Services\Mail\MailgunSenderCheck;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\SenderDomains;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
                'confirmed_count' => (int) $newsletter->confirmed_count,
                'pending_count' => (int) $newsletter->pending_count,
                'unsubscribed_count' => (int) $newsletter->unsubscribed_count,
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $newsletter = Newsletter::query()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] !== '' ? $validated['slug'] : Str::slug($validated['name']),
            ...$this->editableAttributes($validated),
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
            ],
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

        $newsletter->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'] !== '' ? $validated['slug'] : $newsletter->slug,
            ...$attributes,
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
     * @return array{name: string, slug: string, description: string, sender_name: string, sender_email: string, reply_to: string, subscribe_on_signup: bool}
     */
    private function validated(Request $request, ?Newsletter $newsletter = null): array
    {
        $slugRule = 'unique:newsletters,slug';
        if ($newsletter) {
            $slugRule .= ','.$newsletter->id;
        }

        /** @var array{name: string, slug: string|null, description: string|null, sender_name: string|null, sender_email: string|null, reply_to: string|null, subscribe_on_signup?: bool|string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', $slugRule],
            'description' => ['nullable', 'string', 'max:1000'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'string', 'email', 'max:255', $this->senderDomainRule()],
            'reply_to' => ['nullable', 'string', 'email', 'max:255'],
            'subscribe_on_signup' => ['sometimes', 'boolean'],
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

    private function senderDomainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $domains = SenderDomains::allowed();
            $domain = SenderDomains::domainOf((string) $value);

            if (! in_array($domain, $domains, true)) {
                $fail('The sender email must use an approved sending domain ('.implode(', ', $domains).').');
            }
        };
    }
}
