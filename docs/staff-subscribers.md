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
- Opted-out / suppressed rows are labelled clearly. Each email opens the subscriber's person page.
- **Export CSV** audits as `subscribers.exported`. Page views are not audited.

## Editing a subscriber (v1.21.0, #569)

The person page (`/staff/people/contacts/{id}`) lets staff:

| Action | Rule |
|--------|------|
| Edit name and email | The email is re-keyed (`email_normalized` stays unique and cannot match an account). Active subscriptions go back to pending and a confirmation is sent to the new address. A suppression on the old address follows the contact. |
| Unsubscribe from one newsletter | Takes effect immediately. |
| Send a confirmation (resubscribe) | Only sends the double opt-in email; the subscription stays pending until the subscriber confirms it. Refused while the address is suppressed. |
| Suppress / remove suppression | Removing a suppression does not resubscribe anyone. |
| Delete | Admin+ only. Type the email to confirm; "also suppress" is on by default. Subscriptions are deleted; consent events are kept with the contact link cleared. |

Contacts linked to an account open the account's person page instead: their
name and email change through the account email-change flow, and their
newsletter toggles are admin-only.

Every change needs a reason (3-500 characters), recent re-authentication and
is rate limited (`people-actions`). It writes an audit entry (reason and
changed field names only) and a consent event with `source = staff` and
evidence `{actor_id, reason}`.

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
