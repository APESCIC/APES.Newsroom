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

## Admin guide

### Who can do what

| Role | View status and history | Test connection | Add, replace or remove keys |
|------|-------------------------|-----------------|-----------------------------|
| Admin | Yes | Yes | No |
| Super admin | Yes | Yes | Yes |

Changing a key asks you to confirm your identity (Cloudron SSO for staff)
if you have not done so in the last five minutes. Key changes and tests are
limited to 10 per minute per person.

### Add or replace a key

1. Open **Admin → Settings → Integrations**.
2. Find the provider card and paste the new value into the key's field.
   Sensitive keys use a password field; the page never shows a saved value
   again, only `••••` and its last four characters.
3. Choose **Save** (or **Replace**). Before anything is stored, the
   Newsroom runs that provider's connection test with the new value.
   - If the test passes or only warns, the key is saved and takes effect
     on the next request or queued job. No redeploy is needed.
   - If the test fails, nothing is saved and the reason is shown. Fix the
     value, or tick **Save anyway** and save again (for example when the
     provider is having an outage). Forced saves are flagged in the audit
     log.

### Remove a key

Choose **Remove** next to a key that was set in the admin panel. The
Newsroom then uses the server environment value again, if there is one.
Keys that come from the environment cannot be removed here; change them on
the server instead (see [`deployment.md`](deployment.md)).

### Test a connection

**Test connection** runs a read-only check with the saved keys:

| Provider | What the check does |
|----------|---------------------|
| Mailgun | Looks up the sending domain (or lists domains when none is set) and reports whether it is active |
| Stripe | Retrieves the account, shows test or live mode, and confirms an enabled webhook endpoint points at `/stripe/webhook` and a signing secret is set |
| Unsplash | Fetches one photo listing with the access key |
| OpenAI | Lists models with the API key; warns if AI assist is turned off |
| Slack | Calls `auth.test` with the bot token; warns if no channel is set |
| Cloudron SSO | Fetches the OIDC discovery document (no key is sent) |

Checks time out after 5 seconds and never send email, create charges or
change anything at the provider. Results show pass, warning or fail with a
plain message, the time and how long the check took.

### Scheduled checks and alerts

`integrations:check` runs every hour for each configured provider. The
**Status overview** shows the latest result for each provider, and each
card's **History** lists recent checks and key changes.

When a provider's check moves from passing or warning to failing, every
admin and super admin (except locked accounts) gets one email. No more
emails are sent while it keeps failing; the next alert comes only after it
has recovered and failed again. Checks run before saving a new key never
count towards status or alerts.

Check results are kept for 30 days
(`SECURITY_INTEGRATION_CHECK_RETENTION_DAYS`).

### What is recorded

Every set, replace, remove and test is written to the audit log
(`integration_key.*`) with the actor, provider, key name and last four
characters only. Values never appear in the browser, logs, audit entries,
error reports or Git.
