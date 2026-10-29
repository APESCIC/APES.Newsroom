# Mail sending

**Admin → Settings → Mail sending** (`/admin/settings/mail-sending`) chooses how
the Newsroom sends **system and account mail** (sign-in links, password resets,
receipts, admin alerts) and **newsletters** (campaign emails and newsletter
confirmation emails).

| Role | View | Check connection and send test emails | Change |
|------|------|---------------------------------------|--------|
| Admin | Yes | Yes | No (403) |
| Super admin | Yes | Yes | Yes, after confirming their identity |

## Transports

| Transport | What it is | Credentials |
|-----------|------------|-------------|
| Cloudron SMTP | Cloudron's mail addon (`CLOUDRON_MAIL_*`). Locally, the environment's own mailer (`MAIL_MAILER`) | Injected by Cloudron; never stored by the Newsroom |
| Mailgun API | Mailgun's HTTP API, sending each From domain through its own Mailgun domain ([sending domains](email-sending-domains.md)) | Integrations → Mailgun → API key |
| Custom SMTP | Any SMTP server | Integrations → Custom SMTP: host, port, encryption, username and password (password encrypted, never shown again) |

The Cloudron sendmail addon stays required: Cloudron SMTP is always available
as a fallback for system mail.

**Starting setup.** Until a super admin saves this page, system mail uses
Cloudron SMTP, newsletters use the Mailgun API (or Cloudron SMTP while the
Mailgun API key is missing), and there is no fallback.

**Changes take effect straight away**, on the next request or queued job,
without a redeploy. A transport that is not set up (for example Mailgun without
an API key) cannot be chosen.

**Fallback for system mail.** Optional. When the system transport fails, the
Newsroom tries the fallback. Newsletters never use a fallback: if their
transport fails, the send pauses, admins are emailed once, and an admin resumes
it from the campaign page (`/staff/posts/{post}/campaign`).

**Cloudron SMTP and From addresses.** Cloudron only relays mail From its own
address, so newsletters sent through Cloudron SMTP use Cloudron's From address,
with the newsletter's address as Reply-To. Per-domain From addresses need the
Mailgun API (or a Custom SMTP provider that has verified those domains).

## Custom SMTP examples

Set these under **Admin → Settings → Integrations → Custom SMTP**, then choose
Custom SMTP on this page.

| Setting | Generic SMTP | Mailgun SMTP | ZeptoMail |
|---------|--------------|--------------|-----------|
| Host | your provider's host | `smtp.eu.mailgun.org` | `smtp.zeptomail.eu` |
| Port | `587` (STARTTLS) or `465` (SMTPS) | `587` | `587` or `465` |
| Encryption | `smtp` for STARTTLS, `smtps` for SMTPS | `smtp` | `smtp` or `smtps` |
| Username | your SMTP user | the domain's SMTP login, for example `postmaster@mail.apesnews.org.uk` | `emailapikey` |
| Password | your SMTP password | the SMTP password from Mailgun → Domain settings → SMTP credentials | the Send Mail Token |
| Use for | system mail or newsletters | system mail or newsletters (bounce handling works once the webhooks are registered) | **system mail only**: ZeptoMail is transactional-only, so the Newsroom refuses it for newsletters |

## Testing

Each purpose has two buttons:

- **Check connection** signs in without sending: SMTP transports connect, negotiate TLS and authenticate; the Mailgun API check lists one domain with the key.
- **Send test email** sends one real email straight away through the chosen transport to the signed-in admin, plus one optional address (Bcc).

The result shows success or the provider's or SMTP server's error text, is kept
on the page, and is audit-logged as `mail_sending.tested` (no secrets). Tests
share the limits of [sending-domain tests](email-sending-domains.md#send-test-email):
5 a minute per admin and 20 in 24 hours per purpose.

## What each transport supports

The page shows a table of what is unavailable without Mailgun: per-domain From
addresses, bounce and complaint handling, Mailgun tracking, bulk throughput and
domain status checks. Sending domain checks stay available whenever a Mailgun
API key is set, whichever transport newsletters use.

## Bounces, complaints and suppression

With newsletters on Mailgun and the [webhooks registered](email-sending-domains.md#webhooks-and-tracking):

| Event | Effect |
|-------|--------|
| Permanent failure (hard bounce) | Address suppressed, reason `bounced` |
| Spam complaint | Address suppressed, reason `complained` |
| Unsubscribe through the email app (Mailgun) | Address suppressed, reason `unsubscribed_mailgun` |
| Temporary failure | Suppressed as `repeated_soft_bounce` after **3** failures on separate sends within **30 days**. A delivery resets the count. Super admins change both numbers on this page |
| Delivered, opened, clicked | Stored for stats |

Suppressions reuse the existing `suppressions` table, record a `suppressed`
consent event for known contacts, and write `mailing.suppressed_by_delivery_event`
to the audit log. Queued sends skip suppressed addresses. Staff see the reason
and the last delivery problem on the person and subscriber pages, and can
remove the suppression with a note as before.

`php artisan mailing:sync-mailgun-suppressions` (daily at 05:40) also copies
each sending domain's Mailgun bounce, complaint and unsubscribe lists into the
Newsroom, read-only. Reruns change nothing.

Mail events are kept 24 months and pruned daily by
`newsroom:purge-security-data` (`SECURITY_MAIL_EVENT_RETENTION_MONTHS`).

## Open and click tracking

- **Open tracking is opt-in.** A tracking pixel is only included for readers who turned it on in their mailing preferences.
- **Click tracking is on unless the reader opts out** in their mailing preferences. Readers who opt out get plain links.
- **Per domain.** Super admins can turn either off for a sending domain (applied in Mailgun too).
- **One tracker per email.** Sends through the Mailgun API use Mailgun's tracking, set per message (`o:tracking-opens` and `o:tracking-clicks`); other transports use the Newsroom's own signed links. Never both.
- Changes to a reader's choices are recorded in their consent history.

Staff see sent, opens, clicks and top links per campaign on the campaign page,
and per newsletter on **Staff → Metrics**. Open rates are labelled indicative:
Apple Mail Privacy Protection opens some messages automatically, and readers
who did not opt in are never counted.

The [privacy notice](../resources/js/Pages/Legal/Privacy.tsx) and
[cookie notice](../resources/js/Pages/Legal/Cookies.tsx) describe both.
