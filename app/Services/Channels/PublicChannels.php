<?php

namespace App\Services\Channels;

use App\Enums\Channel;
use App\Models\Newsletter;
use Illuminate\Support\Collection;

/**
 * Public channels are the Channel cases whose linked newsletter is active.
 * Placement on the home page and in the nav menu, order and public wording
 * come from the newsletter's settings.
 */
class PublicChannels
{
    /** @var Collection<int, Newsletter>|null */
    private ?Collection $newsletters = null;

    /**
     * @return Collection<int, Newsletter>
     */
    public function all(): Collection
    {
        return $this->newsletters ??= Newsletter::query()->publicChannels()->get();
    }

    public function find(Channel $channel): ?Newsletter
    {
        return $this->all()->first(fn (Newsletter $newsletter) => $newsletter->channel === $channel);
    }

    public function findBySlug(string $slug): ?Newsletter
    {
        $channel = Channel::fromSlug($slug);

        return $channel ? $this->find($channel) : null;
    }

    public function isPublic(Channel $channel): bool
    {
        return $this->find($channel) !== null;
    }

    public function label(Channel $channel): string
    {
        return $this->find($channel)?->publicLabel() ?? $channel->label();
    }

    /**
     * @return list<array{value: string, slug: string, label: string, description: string|null, signup_slug: string}>
     */
    public function forHome(): array
    {
        return $this->payload($this->all()->filter(fn (Newsletter $newsletter) => $newsletter->show_on_home));
    }

    /**
     * @return list<array{value: string, slug: string, label: string, description: string|null, signup_slug: string}>
     */
    public function forNav(): array
    {
        return $this->payload($this->all()->filter(fn (Newsletter $newsletter) => $newsletter->show_in_nav));
    }

    /**
     * @return list<array{value: string, slug: string, label: string, description: string|null, signup_slug: string}>
     */
    public function forListing(): array
    {
        return $this->payload($this->all());
    }

    /**
     * @return array{value: string, slug: string, label: string, description: string|null, signup_slug: string}
     */
    public function present(Newsletter $newsletter): array
    {
        /** @var Channel $channel */
        $channel = $newsletter->channel;

        return [
            'value' => $channel->value,
            'slug' => $channel->slug(),
            'label' => $newsletter->publicLabel(),
            'description' => $newsletter->publicDescription(),
            'signup_slug' => $newsletter->slug,
        ];
    }

    /**
     * @param  Collection<int, Newsletter>  $newsletters
     * @return list<array{value: string, slug: string, label: string, description: string|null, signup_slug: string}>
     */
    private function payload(Collection $newsletters): array
    {
        return $newsletters->map(fn (Newsletter $newsletter) => $this->present($newsletter))->values()->all();
    }
}
