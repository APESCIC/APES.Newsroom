# Integration keys and health checks

Service keys for third-party integrations can be managed from
**Admin → Settings → Integrations** (`/admin/settings/integrations`) instead
of editing the server environment. Values are encrypted at rest, never sent
back to the browser, and every change is audited.

## Key inventory

Every key or secret the Newsroom reads today. **Admin-managed** keys are read
from the encrypted `integration_secrets` table first and fall back to the
environment value when nothing is stored. **Environment-only** keys stay in
the server environment (Cloudron-injected or needed before the app boots).

| Provider | Key | Env var | Config key | Consumer | Management |
|----------|-----|---------|------------|----------|------------|
| Mailgun | API key | `MAILGUN_API_KEY` | `services.mailgun.secret` | `MailgunSenderCheck` (read-only sender domain checks) | Admin-managed |
| Mailgun | Sending domain | `MAILGUN_DOMAIN` | `services.mailgun.domain` | `MailgunSenderCheck` | Admin-managed |
| Mailgun | API endpoint | `MAILGUN_ENDPOINT` | `services.mailgun.endpoint` | `MailgunSenderCheck` | Admin-managed |
| Stripe | Secret key | `STRIPE_SECRET` | `services.stripe.secret` | `StripeBillingClient` binding, `AccountController` | Admin-managed |
| Stripe | Publishable key | `STRIPE_PUBLISHABLE` | `services.stripe.publishable` | Not read by code yet | Admin-managed |
| Stripe | Webhook signing secret | `STRIPE_WEBHOOK_SECRET` | `services.stripe.webhook_secret` | `StripeApiBillingClient::constructWebhookEvent` | Admin-managed |
| Unsplash | Access key | `UNSPLASH_ACCESS_KEY` | `newsroom.unsplash.access_key` | `UnsplashService` | Admin-managed |
| OpenAI | API key | `OPENAI_API_KEY` | `newsroom.ai_assist.api_key` | `AiAssistService` | Admin-managed |
| Slack | Bot token | `SLACK_BOT_USER_OAUTH_TOKEN` | `services.slack.notifications.bot_user_oauth_token` | `SlackEditorialNotifier` | Admin-managed |
| Slack | Default channel | `SLACK_BOT_USER_DEFAULT_CHANNEL` | `services.slack.notifications.channel` | `SlackEditorialNotifier` | Admin-managed |
| Cloudron OIDC | Discovery URL, issuer, client id, client secret | `CLOUDRON_OIDC_*` | `services.cloudron_oidc.*` | `CloudronOidcProvider`, `RecentAuthentication` | Environment-only |
| Cloudron LDAP | URL, base DNs, bind DN and password | `CLOUDRON_LDAP_*` | `services.cloudron_ldap.*`, `ldap.connections.default.*` | `LdapGroupLookup` | Environment-only |
| SMTP | Host, port, user, password, from | `CLOUDRON_MAIL_*` / `MAIL_*` | `mail.*` | Laravel mailer | Environment-only |
| Database | MySQL credentials | `CLOUDRON_MYSQL_*` / `DB_*` | `database.*` | Framework | Environment-only |
| Redis | Host, port, password | `CLOUDRON_REDIS_*` / `REDIS_*` | `database.redis.*` | Cache, queue, session | Environment-only |
| Application | Encryption key | `APP_KEY` | `app.key` | Encrypted casts (including `integration_secrets`) | Environment-only |
| Content API | Legacy shared key | `NEWSROOM_CONTENT_API_KEY` | `newsroom.content_api.key` | `AuthenticateContentApiKey` fallback | Environment-only (use named keys under Admin → Content API keys) |
| Analytics | Plausible domain, GTM container | `NEWSROOM_PLAUSIBLE_DOMAIN`, `NEWSROOM_GTM_CONTAINER_ID` | `newsroom.analytics.*` | `AnalyticsConfig` | Environment-only (identifiers, not secrets) |

Notes:

- `STRIPE_PRICE_MONTHLY` / `STRIPE_PRICE_YEARLY` are not read by code;
  price ids live on each membership plan.
- `POSTMARK_API_KEY`, `RESEND_API_KEY` and `AWS_*` are Laravel defaults
  that nothing uses.
- There is no Mailgun webhook signing key: the Newsroom does not receive
  Mailgun webhooks, and mail is sent through Cloudron SMTP.
- `APP_KEY` encrypts the stored integration keys. Rotating it needs the old
  key in `APP_PREVIOUS_KEYS`, or every stored key must be re-entered.
