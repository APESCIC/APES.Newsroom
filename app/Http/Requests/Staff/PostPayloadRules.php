<?php

namespace App\Http\Requests\Staff;

use App\Enums\Channel;
use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use Illuminate\Validation\Rule;

trait PostPayloadRules
{
    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'content' => ['required', 'array'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'visibility' => ['sometimes', Rule::enum(ContentVisibility::class)],
            'hero_image' => ['nullable', 'string', 'max:2048'],
            'hero_image_alt' => ['nullable', 'string', 'max:255'],
            'hero_image_caption' => ['nullable', 'string', 'max:500'],
            'hero_image_credit' => ['nullable', 'string', 'max:255'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'email_on_publish' => ['sometimes', 'boolean'],
            'mailing_lists' => ['nullable', 'array'],
            'mailing_lists.*' => [Rule::enum(MailingList::class)],
            'newsletter_segment_id' => ['nullable', 'integer', 'exists:newsletter_segments,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'featured' => ['sometimes', 'boolean'],
            'co_author_ids' => ['nullable', 'array'],
            'co_author_ids.*' => ['integer', 'exists:users,id'],
            'expected_updated_at' => ['nullable', 'string'],
        ];
    }
}
