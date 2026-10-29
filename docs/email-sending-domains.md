# Email sending domains

Admins manage which domains newsletters may use for their From and reply-to
addresses at **Admin → Settings → Email sending domains**
(`/admin/settings/email-domains`, admin and super admin only).

## Rules

| Address | Needs |
|---------|-------|
| From (`sender_email`) | An enabled address on a domain's **From addresses** list, on an approved domain that is enabled **and verified**: Mailgun reports it `active`, its SPF and DKIM records are valid, and the domain publishes a DMARC record |
| Reply-to (`reply_to`) | An approved domain that is enabled. It does not need to be in Mailgun, because replies do not go through Mailgun |

- Staff pick the From address from a list on the newsletter edit page. Only addresses on verified domains can be chosen.
- The server checks the same rules, so a bypassed form is still rejected.
- The part before the @ cannot contain spaces. It also cannot be one of the reserved names: `postmaster`, `abuse`, `hostmaster`, `webmaster` or `mailer-daemon`.
- An address that has not changed is accepted when the newsletter is saved, so staff can still edit other fields. It is still blocked at send time if its domain is no longer approved, enabled and verified.
- A From address may be on the domain itself (`news@apespetcare.org.uk`) or on the domain's Mailgun domain (`news@mail.apespetcare.org.uk` under `apespetcare.org.uk`). When the domain's DMARC record asks for strict alignment (`adkim=s` or `aspf=s`), only addresses on the Mailgun domain itself can verify. That is why `myapes.me.uk`, which has strict DMARC, uses `myapes.me.uk` itself as its Mailgun domain.
- Only addresses with the **Newsletters** purpose can be chosen as a newsletter From; No-reply addresses are for test emails and system mail.
- Newsletter confirmation emails use the newsletter's From address once it is verified, and go through the same transport as its newsletters.
- System and account emails (password resets, sign-in links, receipts) send from apesnews.org.uk through the system transport chosen under [Mail sending](mail-sending.md) (Cloudron SMTP by default).

## How newsletters are sent

Each newsletter sends through **its From domain's own Mailgun domain** using the
Mailgun HTTP API (`POST /v3/{mailgun-domain}/messages.mime`), for example:

| From | Mailgun domain |
|------|----------------|
| `app@apesnews.org.uk` | the Mailgun domain under Integrations when it is `apesnews.org.uk` or a subdomain of it, otherwise `apesnews.org.uk` |
| `news@apespetcare.org.uk` | `mail.apespetcare.org.uk` |
| `news@apesshelter.org.uk` | `mail.apesshelter.org.uk` |
| `news@myapes.me.uk` | `myapes.me.uk` |

The Mailgun domain is the domain's **Mailgun domain** override when one is set,
else the Integrations Mailgun domain when it matches, else the domain itself.
The transport refuses any From address whose domain is not approved and enabled.

This applies while newsletters use the Mailgun API under
[Mail sending](mail-sending.md), which is the starting setup once the Mailgun API
key is configured. Until a super admin chooses a newsletter transport, a missing
Mailgun API key keeps newsletters on Cloudron SMTP. A super admin can switch
newsletters to Cloudron SMTP or Custom SMTP there without a redeploy.

If the transport fails while a send is under way, the send **pauses** instead of
failing: recipients still waiting stay queued, admins are emailed once, and an
admin resumes it from the campaign page after fixing the problem. A refusal of
one message (for example an invalid address) fails only that recipient. A
temporary problem (Mailgun HTTP 429 or 5xx, or no connection) is retried after
30 seconds and 2 minutes before the send pauses.

## From addresses and default reply-to

Each domain card lists its **From addresses**. Admins can add, edit, disable and
enable them. Each address has a purpose (Newsletters or No-reply) and an
optional label. Disabling an address stops it being chosen for a newsletter; a
newsletter already using it keeps sending until its sender is changed.

**Default reply-to** is used when a newsletter has no reply-to of its own, and on
test emails. Use a monitored mailbox. Its domain must be on the list and enabled.

## Send test email

Every domain and every From address has a **Send test email** button, and the
button opens straight away after an address is added or edited. A test:

- sends synchronously through the domain's Mailgun domain to the signed-in admin, plus one optional extra address (sent as Bcc, so the two recipients do not see each other);
- uses the address's From, or for a domain-level test its first enabled No-reply address, then any enabled address, then `noreply@<domain>`;
- re-checks the domain live first and shows Active, SPF, DKIM and DMARC alongside the result;
- shows the Mailgun message ID on success, or Mailgun's error text on failure;
- warns when the domain is not verified, but still tries;
- stores the time and outcome on the domain and address, and writes `email_sending_domain.test_sent` to the audit log with the recipients and outcome.

Tests are limited to 5 a minute per admin and 20 in any 24 hours per domain
(shared by its addresses). Only tests an admin is allowed to send count:
refused, unauthenticated or invalid requests do not use up the limit. Further
attempts get a 429 with a message saying how long to wait. Disabled domains and
addresses cannot be tested. Change the limits with `MAILING_TEST_SEND_PER_MINUTE`
and `MAILING_TEST_SEND_PER_DOMAIN_PER_DAY`.

To confirm DMARC alignment, open the received message's headers and look for
`dmarc=pass` in `Authentication-Results`.

## Send-time enforcement

Live campaigns, test sends and each queued recipient re-check both the From and
reply-to domains just before sending, using the From and reply-to the email will
actually carry (the campaign snapshot, including a default reply-to filled in
when the campaign was created). The newsroom never falls back to a
different From address. When either domain is disabled, unapproved or (for From)
not verified:

- Staff publishing with email get a validation error explaining which domain is the problem.
- Scheduled publishing records a `failed` campaign (`failure_reason = sender_unverified`).
- Test sends are rejected.
- Queued recipients are skipped.

Blocked live and test sends are written to the audit log as
`newsletter.send_blocked`. The newsletter edit page shows the current
blocking reasons and the time of the last blocked send.

## Adding a domain

The Newsroom reads domains from Mailgun (`GET /v4/domains/{domain}`) and sends
mail through them. It never creates, verifies or deletes Mailgun domains.

1. In the Mailgun dashboard, add the domain (EU region) and copy the DNS records it lists.
2. Publish those records with the domain's DNS provider:
   - SPF (TXT)
   - DKIM (TXT at `…._domainkey…`)
   - the tracking CNAME
   - the MX records, if Mailgun should receive mail for the domain
3. Add a DMARC record at `_dmarc.<domain>`. A reasonable starting point is `v=DMARC1; p=quarantine; adkim=r; aspf=r`.
4. In the Newsroom, open **Email sending domains**, enter the domain (and optionally a Mailgun domain such as `mg.example.org.uk` if Mailgun sends from a subdomain), then choose **Add and check**.
5. Expand **DNS records** to see each record with a pass or fail and a copy button. After fixing DNS, choose **Check DNS**.

If the domain does not exist in Mailgun, it stays `pending` and can only be used
for reply-to addresses.

## Statuses

| Status | Meaning |
|--------|---------|
| `pending` | Added but not yet verified, or not found in Mailgun. Usable for reply-to only |
| `verified` | Mailgun active, SPF, DKIM and DMARC pass. Usable for From and reply-to |
| `failed` | Found in Mailgun but a check fails, or a previously verified domain disappeared from Mailgun. Usable for reply-to only |
| `disabled` | Turned off by an admin. Not usable at all; newsletters using it cannot send |

Mailgun API errors during a check (unreachable, HTTP errors, no API key) are
shown on the page but never change the status. A Mailgun outage while
newsletters are sending pauses the send (see [How newsletters are sent](#how-newsletters-are-sent)).

## Webhooks and tracking

Each domain card has a **Webhooks and tracking** section. Mailgun posts
delivered, bounced, temporary failure, complaint, unsubscribe, open and click
events to `/webhooks/mailgun`; the Newsroom checks each request's signature with
the **webhook signing key** (Integrations → Mailgun), refuses requests older than
5 minutes and ignores replayed tokens. Events are stored once each and kept for
24 months. Bounces, complaints, provider unsubscribes and repeated temporary
failures suppress the address (see [mail sending](mail-sending.md#bounces-complaints-and-suppression)).

- **Check webhooks** (admins) reads the domain's webhooks and tracking from Mailgun.
- **Register** (super admins) adds the Newsroom URL to every event that lacks it. Running it again changes nothing. Other URLs on the domain are kept.
- **Repair** drops Newsroom URLs that point at an old host, then registers.
- **Remove** takes only the Newsroom URL off each event.
- **Tracking** (super admins) turns open and click tracking on or off for the domain in Mailgun. Readers still choose for themselves in their mailing preferences.

These use the separate **webhook management key** (Integrations → Mailgun): a
Developer-role key, or the main account key if your plan has no role keys. The
page warns when it is the same as the sending key and when it is over 90 days
old. Every change is audit-logged as `email_sending_domain.webhooks_register`,
`.webhooks_repair`, `.webhooks_remove` and `.tracking_changed`.

## Daily check and alerts

`php artisan mailing:check-sending-domains` re-checks every enabled domain. It runs daily at 06:10 from the scheduler and is read-only. It exits with a failure when a domain used as a From domain is `failed`.

- Running **Re-check sender** on a newsletter (or `mailing:verify-senders`) also refreshes that newsletter's domain.
- When an active newsletter's From domain is `failed`, every admin page shows a banner that links here.
- Domains used only for reply-to do not raise the banner.
- Admins are not emailed about failing domains.

On the live server, run artisan through the www-data wrapper (see
[`docs/deployment.md`](deployment.md)):

```bash
bash /app/data/current/deploy/cloudron-www-data.sh \
  /usr/bin/php /app/data/current/artisan mailing:check-sending-domains
```

## Disabling and removing

- **Disable** keeps the domain on the list, blocks sending from it immediately, and asks for confirmation when newsletters use it.
- **Enable** puts it back to `pending` and re-checks it.
- **Remove** is blocked while any newsletter (active or archived) uses the domain for From or reply-to. Change those newsletters first, or disable the domain instead.

Add, disable, enable, remove and every status change are written to the audit log (`email_sending_domain.*`) with the actor (none for the daily check), the domain, and the old and new status. From address changes are logged as `email_sender_address.added`, `.updated` (with the changed fields), `.disabled` and `.enabled`, and default reply-to changes as `email_sending_domain.default_reply_to_changed`. Every change on this page except **Check DNS**, and every test email, asks admins to confirm their identity if they have not done so in the last few minutes. A From address that newsletters use cannot be renamed; add the new address alongside it instead. Staff get 403.

## Seeded domains

The v1.18.0 migration seeds:

- `apesnews.org.uk`, for newsletter From addresses
- `apes.org.uk`, for the `info@apes.org.uk` reply-to

It also seeds any extra domains listed in `MAILING_SENDER_DOMAINS`. A domain starts as `verified` when an existing newsletter sender on it was already verified, so sends are not interrupted by the deploy. After that, the database list is the source of truth and `MAILING_SENDER_DOMAINS` is ignored.

The v1.24.0 migration adds, as `pending` until the ops work below is done:

| Domain | Mailgun domain | From addresses |
|--------|----------------|----------------|
| `apespetcare.org.uk` | `mail.apespetcare.org.uk` | `news@apespetcare.org.uk`, `noreply@apespetcare.org.uk` |
| `apesshelter.org.uk` | `mail.apesshelter.org.uk` | `news@apesshelter.org.uk`, `noreply@apesshelter.org.uk` |
| `myapes.me.uk` | `myapes.me.uk` | `news@myapes.me.uk` only |

It also adds every existing newsletter From address to its domain's list, so current newsletters keep a selectable sender.

`myapes.me.uk` was first seeded with the Mailgun domain `news.myapes.me.uk`. The v1.25.1 migration moves it to the root domain (`myapes.me.uk`, From `news@myapes.me.uk`) for the MyAPES newsletter, and puts it back to `pending`. A domain an admin created themselves is left alone.

## Ops runbook: Mailgun and DNS for a new domain

Issues [#596](https://github.com/APESCIC/APES.Newsroom/issues/596), [#597](https://github.com/APESCIC/APES.Newsroom/issues/597) and [#598](https://github.com/APESCIC/APES.Newsroom/issues/598). The Newsroom cannot do these steps; they are done in the Mailgun dashboard and at each domain's DNS host.

1. In Mailgun (**EU region**), add the sending domain: `mail.apespetcare.org.uk`, `mail.apesshelter.org.uk` or `myapes.me.uk` (root, see below).
2. Publish the records Mailgun lists at the DNS host: SPF (TXT on the subdomain), DKIM (TXT at `…._domainkey.<subdomain>`), the tracking CNAME and Mailgun's MX records **on the subdomain only**. All four domains use Cloudflare DNS; set every record to **DNS only** (not proxied).
3. Keep the root domain's SPF within 10 DNS lookups. The sending subdomain has its own SPF, so the root SPF usually needs no change.
4. DMARC: keep `p=quarantine` with `rua` reporting on the root domain (`_dmarc.<domain>`). Move to `p=reject` after two clean weeks of reports.
5. In Mailgun, choose **Verify DNS settings** until the domain shows **Verified**.
6. In the Newsroom, open **Email sending domains** and choose **Check DNS** on the domain. It should show `verified` with SPF, DKIM and DMARC passing.
7. Choose **Send test email** on each From address. Check the message headers show `spf=pass`, `dkim=pass` and `dmarc=pass`.
8. Then pick the new From addresses on the relevant newsletters.

**myapes.me.uk (root Mailgun domain).** DMARC on myapes.me.uk is strict (`adkim=s`, `aspf=s`), and the MyAPES newsletter sends from `news@myapes.me.uk`, so Mailgun sends as `myapes.me.uk` itself. myapes.me.uk hosts MyAPES Account (`myaccount.myapes.me.uk`) and receives mail through Cloudron, so:

- Publish only Mailgun's **DKIM** TXT (`<selector>._domainkey.myapes.me.uk`) and the tracking CNAME (`email.myapes.me.uk`).
- **Do not** add Mailgun's MX records. The root MX stays on Cloudron (`mail.cloudron.apes.org.uk`), and Mailgun shows its receiving records as unverified, which is expected: sending only needs SPF and DKIM.
- The root SPF already includes `include:mailgun.org`. Keep it within 10 DNS lookups; if it goes over, replace an `a:` host with its IP (`ip4:`).
- Leave the MyAPES Account mail records alone, and after the change check that MyAPES Account mail still arrives.
- Use it for the MyAPES newsletter only.
