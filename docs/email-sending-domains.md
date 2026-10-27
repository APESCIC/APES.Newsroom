# Email sending domains

Admins manage which domains newsletters may use for their From and reply-to
addresses at **Admin → Settings → Email sending domains**
(`/admin/settings/email-domains`, admin and super admin only).

## Rules

| Address | Needs |
|---------|-------|
| From (`sender_email`) | An approved domain that is enabled **and verified**: Mailgun reports it `active`, its SPF and DKIM records are valid, and the domain publishes a DMARC record |
| Reply-to (`reply_to`) | An approved domain that is enabled. It does not need to be in Mailgun, because replies do not go through Mailgun |

- Staff pick the domain from a dropdown on the newsletter edit page, so the From list only shows verified domains.
- The server checks the same rules, so a bypassed form is still rejected.
- The part before the @ cannot contain spaces. It also cannot be one of the reserved names: `postmaster`, `abuse`, `hostmaster`, `webmaster` or `mailer-daemon`.
- An address that has not changed is accepted when the newsletter is saved, so staff can still edit other fields. It is still blocked at send time.
- The From domain must also match the Mailgun sending domain set under Integrations, either the same domain or a subdomain of it such as `mg.apesnews.org.uk`. Sending through more than one Mailgun domain is not supported yet.

## Send-time enforcement

Live campaigns, test sends and each queued recipient re-check both the From and
reply-to domains just before sending. The newsroom never falls back to a
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

The Newsroom only **reads** from Mailgun (`GET /v4/domains/{domain}`). It never
creates, verifies or deletes Mailgun domains.

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

Mailgun API errors (unreachable, HTTP errors, no API key) are shown on the page
but never change the status, so a Mailgun outage does not block sending on its
own.

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

Add, disable, enable, remove and every status change are written to the audit log (`email_sending_domain.*`) with the actor (none for the daily check), the domain, and the old and new status. Disable, enable, add and remove ask admins to confirm their identity if they have not done so in the last few minutes.

## Seeded domains

The v1.18.0 migration seeds:

- `apesnews.org.uk`, for newsletter From addresses
- `apes.org.uk`, for the `info@apes.org.uk` reply-to

It also seeds any extra domains listed in `MAILING_SENDER_DOMAINS`. A domain starts as `verified` when an existing newsletter sender on it was already verified, so sends are not interrupted by the deploy. After that, the database list is the source of truth and `MAILING_SENDER_DOMAINS` is ignored.
