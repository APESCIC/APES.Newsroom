# Newsletters

Newsletters are audiences stored in `newsletters`, separate from the three fixed `MailingList` channel enums.

## Columns

| Column | Purpose |
|--------|---------|
| `name` | Staff-facing label |
| `slug` | Unique public identifier |
| `description` | Purpose shown to staff |
| `legacy_list` | Optional unique link to a `MailingList` value (`apes_cic`, `apes_shelter_rescue`, `apes_pet_care_clinic`) |
| `ghost_slug` | Slug of the matching Ghost newsletter (see [`docs/migration/ghost-newsletters.md`](migration/ghost-newsletters.md)) |
| `sender_name`, `sender_email`, `reply_to` | From and Reply-To for campaigns and confirmation emails. Sender email must use an approved domain (`MAILING_SENDER_DOMAINS`) |
| `subscribe_on_signup` | Offer this newsletter as an opt-in checkbox on `/register` |
| `sender_verification` | Last result of `mailing:verify-senders` (status, checks, message) |
| `archived_at` | Set when staff archive an audience. Archived newsletters are hidden from signup and segments and return no recipients. Ghost's archived newsletters are not imported (#499) |

The migration seeds one active newsletter per channel list and copies `legacy_list` onto existing `mailing_list_subscriptions.newsletter_id`.

Channel signup still writes `mailing_list_subscriptions.list`. New signups also set `newsletter_id` when a seeded newsletter matches that list. Unique `(mailing_contact_id, list)` remains for channel rows. Unique `(mailing_contact_id, newsletter_id)` covers newsletter membership, stored as `mailing_subs_contact_newsletter_uq` so the name stays within MySQL's 64-character identifier limit. The migration skips steps that already exist, so a deploy that failed on that index can be retried.

Staff manage newsletters at `/staff/newsletters`. The index shows confirmed, pending and unsubscribed counts separately.

## Senders and verification

A campaign takes its sender from the post's segment newsletter. Otherwise it uses the first selected channel list, in fixed order: APES CIC, then Shelter & Rescue, then Pet Care Clinic. That newsletter is stored on `campaigns.newsletter_id`, and the campaign email uses its From and Reply-To.

Mail is sent through Cloudron SMTP. `php artisan mailing:verify-senders` (or **Re-check sender** on the edit page) calls the Mailgun API read-only, using `MAILGUN_API_KEY`, `MAILGUN_ENDPOINT` and optionally `MAILGUN_DOMAIN`. It confirms the sender domain is active with valid SPF and DKIM, and checks DMARC in DNS. A sender counts as verified only while the stored check matches its current sender email.

Unverified senders are blocked:

- **Staff and Admin API publish:** a validation error on `email_on_publish`.
- **Scheduled publishing:** the post publishes, but a `failed` campaign is recorded with `failure_reason = sender_unverified`.
- **Test sends:** rejected.
- **The send job:** skips the recipient.

Confirmation emails use the newsletter sender once it is verified, and the global From until then.

## Signup default

Newsletters with `subscribe_on_signup` appear as unticked checkboxes on `/register`. Ticked ones start as pending and are confirmed when the member verifies their account email. That is one email, and it is still double opt-in. Imports never apply this default.
