<?php

namespace App\Support\Integrations;

use InvalidArgumentException;

/**
 * Single registry of integration providers, the keys admins may manage for
 * each, and the config path each key falls back to. Keep docs/integrations.md
 * in sync when adding a provider or key.
 */
final class IntegrationDefinitions
{
    public const MAILGUN = 'mailgun';

    public const STRIPE = 'stripe';

    public const UNSPLASH = 'unsplash';

    public const OPENAI = 'openai';

    public const SLACK = 'slack';

    public const CLOUDRON_OIDC = 'cloudron_oidc';

    public const CUSTOM_SMTP = 'custom_smtp';

    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     managed: bool,
     *     keys: array<string, array{label: string, help: string, config: string, sensitive: bool, rules: list<string>}>
     * }>
     */
    public static function providers(): array
    {
        return [
            self::MAILGUN => [
                'label' => 'Mailgun',
                'description' => 'Sending-domain checks and, when chosen under Mail sending, newsletter and system mail through the Mailgun API.',
                'managed' => true,
                'keys' => [
                    'api_key' => [
                        'label' => 'API key',
                        'help' => 'A Mailgun sending API key that can read domains and send through them.',
                        'config' => 'services.mailgun.secret',
                        'sensitive' => true,
                        'rules' => ['string', 'min:8', 'max:255'],
                    ],
                    'domain' => [
                        'label' => 'Sending domain',
                        'help' => 'The domain registered in Mailgun, for example mg.apesnews.org.uk.',
                        'config' => 'services.mailgun.domain',
                        'sensitive' => false,
                        'rules' => ['string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
                    ],
                    'endpoint' => [
                        'label' => 'API endpoint',
                        'help' => 'api.eu.mailgun.net for EU accounts, api.mailgun.net for US accounts.',
                        'config' => 'services.mailgun.endpoint',
                        'sensitive' => false,
                        'rules' => ['string', 'in:api.eu.mailgun.net,api.mailgun.net'],
                    ],
                    'webhook_signing_key' => [
                        'label' => 'Webhook signing key',
                        'help' => 'The account-wide HTTP webhook signing key from Mailgun → Sending → Webhooks. Used to check that bounce, complaint and tracking events really come from Mailgun.',
                        'config' => 'services.mailgun.webhook_signing_key',
                        'sensitive' => true,
                        'rules' => ['string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9-]+$/'],
                    ],
                    'management_key' => [
                        'label' => 'Webhook management key',
                        'help' => 'A Developer-role Mailgun API key, used only to register, repair and remove webhooks and change tracking settings. If your plan has no role keys, use the main account key and rotate it regularly.',
                        'config' => 'services.mailgun.management_key',
                        'sensitive' => true,
                        'rules' => ['string', 'min:8', 'max:255'],
                    ],
                ],
            ],
            self::STRIPE => [
                'label' => 'Stripe',
                'description' => 'Membership billing: checkout, billing portal, offers and webhooks.',
                'managed' => true,
                'keys' => [
                    'secret' => [
                        'label' => 'Secret key',
                        'help' => 'Starts with sk_test_ or sk_live_ (restricted keys: rk_). Live keys need operational sign-off.',
                        'config' => 'services.stripe.secret',
                        'sensitive' => true,
                        'rules' => ['string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
                    ],
                    'publishable' => [
                        'label' => 'Publishable key',
                        'help' => 'Starts with pk_test_ or pk_live_.',
                        'config' => 'services.stripe.publishable',
                        'sensitive' => false,
                        'rules' => ['string', 'max:255', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
                    ],
                    'webhook_secret' => [
                        'label' => 'Webhook signing secret',
                        'help' => 'Starts with whsec_. Shown in Stripe on the webhook endpoint for /stripe/webhook.',
                        'config' => 'services.stripe.webhook_secret',
                        'sensitive' => true,
                        'rules' => ['string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
                    ],
                ],
            ],
            self::UNSPLASH => [
                'label' => 'Unsplash',
                'description' => 'Stock photo search in the staff media picker.',
                'managed' => true,
                'keys' => [
                    'access_key' => [
                        'label' => 'Access key',
                        'help' => 'The Unsplash application access key (not the secret key).',
                        'config' => 'newsroom.unsplash.access_key',
                        'sensitive' => true,
                        'rules' => ['string', 'min:8', 'max:255'],
                    ],
                ],
            ],
            self::OPENAI => [
                'label' => 'OpenAI',
                'description' => 'Editorial AI assist suggestions. Also needs NEWSROOM_AI_ASSIST_ENABLED in the environment.',
                'managed' => true,
                'keys' => [
                    'api_key' => [
                        'label' => 'API key',
                        'help' => 'Starts with sk-.',
                        'config' => 'newsroom.ai_assist.api_key',
                        'sensitive' => true,
                        'rules' => ['string', 'max:255', 'regex:/^sk-[A-Za-z0-9_-]+$/'],
                    ],
                ],
            ],
            self::SLACK => [
                'label' => 'Slack',
                'description' => 'Editorial notifications when posts are published or submitted for review.',
                'managed' => true,
                'keys' => [
                    'bot_token' => [
                        'label' => 'Bot token',
                        'help' => 'Starts with xoxb-. Needs the chat:write scope.',
                        'config' => 'services.slack.notifications.bot_user_oauth_token',
                        'sensitive' => true,
                        'rules' => ['string', 'max:255', 'regex:/^xoxb-[A-Za-z0-9-]+$/'],
                    ],
                    'default_channel' => [
                        'label' => 'Channel',
                        'help' => 'Channel id or name, for example C0123456789 or #newsroom.',
                        'config' => 'services.slack.notifications.channel',
                        'sensitive' => false,
                        'rules' => ['string', 'max:80', 'regex:/^#?[A-Za-z0-9._-]+$/'],
                    ],
                ],
            ],
            self::CUSTOM_SMTP => [
                'label' => 'Custom SMTP',
                'description' => 'An SMTP server of your choice for system mail or newsletters, chosen under Mail sending.',
                'managed' => true,
                'keys' => [
                    'host' => [
                        'label' => 'Host',
                        'help' => 'For example smtp.eu.mailgun.org or smtp.zeptomail.eu.',
                        'config' => 'mail.mailers.custom-smtp.host',
                        'sensitive' => false,
                        'rules' => ['string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
                    ],
                    'port' => [
                        'label' => 'Port',
                        'help' => '587 for STARTTLS, 465 for SMTPS.',
                        'config' => 'mail.mailers.custom-smtp.port',
                        'sensitive' => false,
                        'rules' => ['string', 'regex:/^[1-9][0-9]{0,4}$/'],
                    ],
                    'scheme' => [
                        'label' => 'Encryption',
                        'help' => 'smtp (STARTTLS, usually port 587) or smtps (TLS from the start, usually port 465).',
                        'config' => 'mail.mailers.custom-smtp.scheme',
                        'sensitive' => false,
                        'rules' => ['string', 'in:smtp,smtps'],
                    ],
                    'username' => [
                        'label' => 'Username',
                        'help' => 'For ZeptoMail this is emailapikey.',
                        'config' => 'mail.mailers.custom-smtp.username',
                        'sensitive' => false,
                        'rules' => ['string', 'max:255'],
                    ],
                    'password' => [
                        'label' => 'Password',
                        'help' => 'The SMTP password or token (for ZeptoMail, the Send Mail Token). Never shown again after saving.',
                        'config' => 'mail.mailers.custom-smtp.password',
                        'sensitive' => true,
                        'rules' => ['string', 'min:8', 'max:1024'],
                    ],
                ],
            ],
            self::CLOUDRON_OIDC => [
                'label' => 'Cloudron SSO',
                'description' => 'Staff sign-in. Credentials are injected by Cloudron and stay in the server environment.',
                'managed' => false,
                'keys' => [],
            ],
        ];
    }

    /**
     * @return array{label: string, description: string, managed: bool, keys: array<string, array{label: string, help: string, config: string, sensitive: bool, rules: list<string>}>}
     */
    public static function provider(string $provider): array
    {
        return self::providers()[$provider]
            ?? throw new InvalidArgumentException("Unknown integration provider [{$provider}].");
    }

    /**
     * @return array{label: string, help: string, config: string, sensitive: bool, rules: list<string>}
     */
    public static function key(string $provider, string $key): array
    {
        return self::provider($provider)['keys'][$key]
            ?? throw new InvalidArgumentException("Unknown integration key [{$provider}.{$key}].");
    }

    /**
     * "Stripe secret key", "Mailgun API key": for use mid-sentence.
     */
    public static function phrase(string $provider, string $key): string
    {
        $label = self::key($provider, $key)['label'];

        return self::provider($provider)['label'].' '.(preg_match('/^[A-Z]{2}/', $label) ? $label : lcfirst($label));
    }

    public static function hasKey(string $provider, string $key): bool
    {
        return isset(self::providers()[$provider]['keys'][$key]);
    }

    /**
     * @return list<string>
     */
    public static function providerNames(): array
    {
        return array_keys(self::providers());
    }
}
