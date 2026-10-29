<?php

namespace App\Services\Mailing;

use App\Enums\MailingList;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Models\Post;
use Illuminate\Support\Str;

class CampaignSender
{
    public const VERIFIED = 'verified';

    /**
     * The newsletter whose sender identity a post's campaign uses: the
     * segment's newsletter, otherwise the first selected list in enum order.
     */
    public function newsletterForPost(Post $post): ?Newsletter
    {
        if ($post->newsletter_segment_id) {
            return NewsletterSegment::query()->with('newsletter')->find($post->newsletter_segment_id)?->newsletter;
        }

        return $this->newsletterForLists($post->mailing_lists ?? []);
    }

    /**
     * @param  list<string|MailingList>  $lists
     */
    public function newsletterForLists(array $lists): ?Newsletter
    {
        $values = array_map(fn ($list) => $list instanceof MailingList ? $list->value : (string) $list, $lists);

        foreach (MailingList::cases() as $list) {
            if (in_array($list->value, $values, true)) {
                return Newsletter::query()->where('legacy_list', $list->value)->first();
            }
        }

        return null;
    }

    /**
     * Verified only while the stored check matches the current sender email
     * and the From and reply-to domains are still approved (re-read on every
     * call, so disabling a domain blocks queued sends too).
     */
    public function isVerified(?Newsletter $newsletter): bool
    {
        if (! $newsletter || blank($newsletter->sender_email)) {
            return false;
        }

        $verification = $newsletter->sender_verification ?? [];

        return ($verification['status'] ?? null) === self::VERIFIED
            && Str::lower((string) ($verification['sender_email'] ?? '')) === Str::lower($newsletter->sender_email)
            && $this->domainProblems($newsletter) === [];
    }

    /**
     * Sending-domain reasons this newsletter cannot send right now.
     *
     * @return list<string>
     */
    public function domainProblems(Newsletter $newsletter): array
    {
        return array_values(array_filter([
            SenderDomains::senderProblem($newsletter->sender_email),
            SenderDomains::replyToProblem($newsletter->reply_to),
        ]));
    }

    public function blockedMessage(?Newsletter $newsletter): string
    {
        if (! $newsletter) {
            return 'No newsletter sender could be found for this email.';
        }

        if (blank($newsletter->sender_email)) {
            return 'Set a sender email on the '.$newsletter->name.' newsletter before emailing it.';
        }

        $problems = $this->domainProblems($newsletter);
        if ($problems !== []) {
            return 'The '.$newsletter->name.' newsletter cannot send: '.implode(' ', $problems).' An admin can review it under Settings → Email sending domains.';
        }

        return 'The '.$newsletter->name.' sender ('.$newsletter->sender_email.') is not verified in Mailgun yet. Re-check it on the newsletter page before emailing.';
    }

    /**
     * @return array{address: string, name: string|null, reply_to: string|null}|null
     */
    public function identity(?Newsletter $newsletter): ?array
    {
        if (! $newsletter || blank($newsletter->sender_email)) {
            return null;
        }

        return [
            'address' => $newsletter->sender_email,
            'name' => $newsletter->sender_name,
            'reply_to' => $newsletter->reply_to ?? DefaultReplyTo::usable(),
        ];
    }

    /**
     * Sender for transactional mail (confirmations); falls back to the
     * global From until the newsletter sender is verified.
     *
     * @return array{address: string, name: string|null, reply_to: string|null}|null
     */
    public function verifiedIdentity(?Newsletter $newsletter): ?array
    {
        return $this->isVerified($newsletter) ? $this->identity($newsletter) : null;
    }
}
