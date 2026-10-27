# Staff guide: mailing-list-only subscribers

Staff (and above) can review contacts who are on newsletters but do **not** have a Newsroom account.

## Where to look

| Surface | Path |
|---------|------|
| Mailing-only list | [`/staff/subscribers`](/staff/subscribers) |
| Per-newsletter account vs mailing-only counts | [`/staff/newsletters`](/staff/newsletters) |

Nav: **Staff → Subscribers** (next to Newsletters).

## List behaviour

- Shows contacts with `user_id` null: email, name if known (e.g. Ghost import), newsletters and statuses, signup date, latest consent source/action, and whether the address is suppressed.
- Filters: search (email/name), newsletter, consent source. Paginated.
- **Read-only** for subscription state — do not re-subscribe anyone from this page. Opted-out / suppressed rows are labelled clearly.
- **Export CSV** audits as `subscribers.exported`. Page views are not audited.

## Signup matching

When someone registers with an email that already has a mailing contact:

1. Before email verification (or OIDC), the app does **not** attach `user_id` to a pre-existing contact (avoids revealing subscriptions).
2. After verification (password) or Cloudron OIDC login (email treated as verified), `LinkMailingContactService` links the contact.
3. Registration newsletter ticks never undo `Unsubscribed` rows or suppressions; confirmed rows stay confirmed.
4. If a pre-existing contact with subscriptions is linked, the member sees a one-shot dismissible notice on Account / Mailing preferences.

Backfill after Ghost import:

```bash
php artisan newsroom:link-mailing-contacts --dry-run
php artisan newsroom:link-mailing-contacts
```

Unverified users are skipped. Safe to re-run.

## Account deletion and email change

- **Email change:** after the new address is confirmed, mailing consent transfers; unique `user_id` / `email_normalized` stay consistent.
- **Delete account:** checkbox **Keep my newsletter subscriptions** (default on) leaves the contact as mailing-only via `nullOnDelete`. Unchecked deletes the contact and subscriptions and records consent/audit. Documented on the rights and privacy pages.
