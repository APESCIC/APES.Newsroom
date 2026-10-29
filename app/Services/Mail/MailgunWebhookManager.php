<?php

namespace App\Services\Mail;

use App\Models\EmailSendingDomain;
use App\Services\Integrations\IntegrationSecrets;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Registers, repairs and removes the Newsroom's webhook on each Mailgun
 * domain, and reads and sets the domain's open and click tracking, using the
 * separate webhook management key. Other webhook URLs on a domain are kept.
 */
class MailgunWebhookManager
{
    /** Mailgun webhook ids the Newsroom listens to. */
    public const EVENTS = ['delivered', 'permanent_fail', 'temporary_fail', 'complained', 'unsubscribed', 'opened', 'clicked'];

    /** Mailgun allows this many URLs per webhook. */
    private const MAX_URLS = 3;

    public function __construct(
        private readonly IntegrationSecrets $secrets,
        private readonly MailgunDomainService $mailgun,
    ) {}

    public function isConfigured(): bool
    {
        return $this->secrets->configured(IntegrationDefinitions::MAILGUN, 'management_key');
    }

    /**
     * Whether the management key is the same key used for sending, which
     * means it has more access than webhook management needs.
     */
    public function usesSendingKey(): bool
    {
        $management = $this->secrets->get(IntegrationDefinitions::MAILGUN, 'management_key');

        return filled($management) && $management === $this->secrets->get(IntegrationDefinitions::MAILGUN, 'api_key');
    }

    public function callbackUrl(): string
    {
        return route('webhooks.mailgun');
    }

    /**
     * Read the domain's webhooks and tracking from Mailgun and store them.
     *
     * @return array{events: array<string, array{registered: bool, urls: list<string>}>, tracking: array{open: bool|null, click: bool|null}, error: string|null, checked_at: string}
     */
    public function refresh(EmailSendingDomain $domain): array
    {
        $mailgunDomain = $this->mailgunDomain($domain);

        try {
            $webhooks = $this->webhooks($mailgunDomain);
            $tracking = $this->json(fn () => $this->request()->get($this->url("/v3/domains/{$mailgunDomain}/tracking")), 'read tracking');
            $status = [
                'events' => collect(self::EVENTS)->mapWithKeys(fn (string $event) => [$event => [
                    'registered' => in_array($this->callbackUrl(), $webhooks[$event] ?? [], true),
                    'urls' => array_values($webhooks[$event] ?? []),
                ]])->all(),
                'tracking' => [
                    'open' => self::active(data_get($tracking, 'tracking.open.active')),
                    'click' => self::active(data_get($tracking, 'tracking.click.active')),
                ],
                'error' => null,
            ];
        } catch (RuntimeException $exception) {
            $status = [
                'events' => (array) ($domain->webhooks['events'] ?? []),
                'tracking' => (array) ($domain->webhooks['tracking'] ?? ['open' => null, 'click' => null]),
                'error' => $exception->getMessage(),
            ];
        }

        $status['checked_at'] = now()->toIso8601String();
        $domain->forceFill(['webhooks' => $status, 'webhooks_checked_at' => now()])->save();

        return $status;
    }

    /**
     * Add the Newsroom URL to every event that lacks it. Running it again
     * changes nothing.
     *
     * @return list<string> Events that were changed.
     */
    public function register(EmailSendingDomain $domain): array
    {
        $mailgunDomain = $this->mailgunDomain($domain);
        $webhooks = $this->webhooks($mailgunDomain);
        $url = $this->callbackUrl();
        $changed = [];

        foreach (self::EVENTS as $event) {
            $urls = $webhooks[$event] ?? null;
            if ($urls !== null && in_array($url, $urls, true)) {
                continue;
            }

            if ($urls === null || $urls === []) {
                $this->json(fn () => $this->request()->asForm()->post($this->url("/v3/domains/{$mailgunDomain}/webhooks"), ['id' => $event, 'url' => $url]), "register {$event}");
            } else {
                $this->put($mailgunDomain, $event, [...array_slice($urls, 0, self::MAX_URLS - 1), $url]);
            }
            $changed[] = $event;
        }

        $this->refresh($domain);

        return $changed;
    }

    /**
     * Drop Newsroom webhook URLs that point somewhere else (an old host or
     * path), then register the current one.
     *
     * @return list<string>
     */
    public function repair(EmailSendingDomain $domain): array
    {
        $mailgunDomain = $this->mailgunDomain($domain);
        $url = $this->callbackUrl();
        $changed = [];

        foreach ($this->webhooks($mailgunDomain) as $event => $urls) {
            if (! in_array($event, self::EVENTS, true)) {
                continue;
            }
            $kept = array_values(array_filter($urls, fn (string $existing) => $existing === $url || ! Str::endsWith(parse_url($existing, PHP_URL_PATH) ?? '', '/webhooks/mailgun')));
            if ($kept !== $urls) {
                $kept === [] ? $this->delete($mailgunDomain, $event) : $this->put($mailgunDomain, $event, $kept);
                $changed[] = $event;
            }
        }

        return array_values(array_unique([...$changed, ...$this->register($domain)]));
    }

    /**
     * Remove only the Newsroom URL from each event.
     *
     * @return list<string>
     */
    public function remove(EmailSendingDomain $domain): array
    {
        $mailgunDomain = $this->mailgunDomain($domain);
        $url = $this->callbackUrl();
        $changed = [];

        foreach ($this->webhooks($mailgunDomain) as $event => $urls) {
            if (! in_array($event, self::EVENTS, true) || ! in_array($url, $urls, true)) {
                continue;
            }
            $kept = array_values(array_filter($urls, fn (string $existing) => $existing !== $url));
            $kept === [] ? $this->delete($mailgunDomain, $event) : $this->put($mailgunDomain, $event, $kept);
            $changed[] = $event;
        }

        $this->refresh($domain);

        return $changed;
    }

    /**
     * Turn the domain's open or click tracking on or off in Mailgun.
     */
    public function setTracking(EmailSendingDomain $domain, string $kind, bool $active): void
    {
        if (! in_array($kind, ['open', 'click'], true)) {
            throw new RuntimeException("Unknown tracking kind [{$kind}].");
        }

        $mailgunDomain = $this->mailgunDomain($domain);
        $this->json(fn () => $this->request()->asForm()->put($this->url("/v3/domains/{$mailgunDomain}/tracking/{$kind}"), ['active' => $active ? 'yes' : 'no']),
            "change {$kind} tracking",
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function webhooks(string $mailgunDomain): array
    {
        $payload = $this->json(fn () => $this->request()->get($this->url("/v3/domains/{$mailgunDomain}/webhooks")), 'read webhooks');

        $webhooks = [];
        foreach ((array) ($payload['webhooks'] ?? []) as $event => $definition) {
            $urls = (array) ($definition['urls'] ?? (isset($definition['url']) ? [$definition['url']] : []));
            $webhooks[(string) $event] = array_values(array_filter(array_map('strval', $urls)));
        }

        return $webhooks;
    }

    /**
     * @param  list<string>  $urls
     */
    private function put(string $mailgunDomain, string $event, array $urls): void
    {
        $body = implode('&', array_map(fn (string $url) => 'url='.rawurlencode($url), $urls));

        $this->json(fn () => $this->request()
            ->withBody($body, 'application/x-www-form-urlencoded')
            ->put($this->url("/v3/domains/{$mailgunDomain}/webhooks/{$event}")), "update {$event}");
    }

    private function delete(string $mailgunDomain, string $event): void
    {
        $this->json(fn () => $this->request()->delete($this->url("/v3/domains/{$mailgunDomain}/webhooks/{$event}")), "remove {$event}");
    }

    private function request(): PendingRequest
    {
        $key = $this->secrets->get(IntegrationDefinitions::MAILGUN, 'management_key');
        if (blank($key)) {
            throw new RuntimeException('Set the Mailgun webhook management key under Integrations first.');
        }

        return Http::withBasicAuth('api', (string) $key)->acceptJson()->timeout(15);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(callable $send, string $action): array
    {
        try {
            $response = $send();
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach the Mailgun API.');
        }

        if (! $response->successful()) {
            $message = trim((string) ($response->json('message') ?? ''));

            throw new RuntimeException("Mailgun could not {$action} (HTTP {$response->status()})".($message !== '' ? ": {$message}" : '.'));
        }

        return (array) $response->json();
    }

    private function url(string $path): string
    {
        return 'https://'.trim($this->secrets->get(IntegrationDefinitions::MAILGUN, 'endpoint') ?? 'api.eu.mailgun.net', '/').$path;
    }

    private function mailgunDomain(EmailSendingDomain $domain): string
    {
        return rawurlencode($this->mailgun->mailgunDomainFor($domain->domain, $domain->mailgun_domain));
    }

    private static function active(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 'yes', $value === 'true', $value === 'htmlonly' => true,
            $value === false, $value === 'no', $value === 'false' => false,
            default => null,
        };
    }
}
