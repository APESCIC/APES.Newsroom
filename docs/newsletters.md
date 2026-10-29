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
| `sender_name`, `sender_email`, `reply_to` | From and Reply-To for campaigns and confirmation emails. From must use an enabled, verified approved domain; reply-to an enabled approved domain (see [`docs/email-sending-domains.md`](email-sending-domains.md)) |
| `subscribe_on_signup` | Offer this newsletter as an opt-in checkbox on `/register` |
| `sender_verification` | Last result of `mailing:verify-senders` (status, checks, message) |
| `archived_at` | Set when staff archive an audience. Archived newsletters are hidden from signup and segments and return no recipients. Ghost's archived newsletters are not imported (#499) |
| `channel` | Optional unique link to a `Channel` value. A linked, active newsletter is a public channel at `/{channel-slug}` |
| `show_on_home`, `show_in_nav` | Where the public channel appears: a home page section and the Channels menu |
| `display_order` | Order of public channels on the home page and in the menu (lowest first) |
| `public_label`, `public_description` | Public wording for the channel. Blank falls back to `name` and `description` |

The migration seeds one active newsletter per channel list and copies `legacy_list` onto existing `mailing_list_subscriptions.newsletter_id`.

Channel signup still writes `mailing_list_subscriptions.list`. New signups also set `newsletter_id` when a seeded newsletter matches that list. Unique `(mailing_contact_id, list)` remains for channel rows. Unique `(mailing_contact_id, newsletter_id)` covers newsletter membership, stored as `mailing_subs_contact_newsletter_uq` so the name stays within MySQL's 64-character identifier limit. The migration skips steps that already exist, so a deploy that failed on that index can be retried.

Staff manage newsletters at `/staff/newsletters`. The index shows confirmed, pending and unsubscribed counts separately, plus **account-holder** vs **mailing-only** confirmed counts. Mailing-list-only contacts (no account) are listed at `/staff/subscribers` — see [`docs/staff-subscribers.md`](staff-subscribers.md).

## Senders and verification

A campaign takes its sender from the post's segment newsletter. Otherwise it uses the first selected channel list, in fixed order: APES CIC, then Shelter & Rescue, then Pet Care Clinic. That newsletter is stored on `campaigns.newsletter_id`, and the campaign email uses its From and Reply-To.

Newsletters are sent through the transport chosen under [Mail sending](mail-sending.md): by default the Mailgun HTTP API, using the Mailgun domain of the newsletter's From domain, whenever the Mailgun API key is configured (see [`docs/email-sending-domains.md`](email-sending-domains.md#how-newsletters-are-sent)). If that transport fails, the send pauses and an admin resumes it from the campaign page. The From address is chosen from the allowed From addresses an admin manages there. `php artisan mailing:verify-senders` (or **Re-check sender** on the edit page) calls the Mailgun API read-only, using `MAILGUN_API_KEY`, `MAILGUN_ENDPOINT` and optionally `MAILGUN_DOMAIN`. It confirms the sender domain is active with valid SPF and DKIM, and checks DMARC in DNS. A sender counts as verified only while the stored check matches its current sender email **and** its From and reply-to domains are still approved (re-checked at send time; see [`docs/email-sending-domains.md`](email-sending-domains.md)).

Unverified senders are blocked:

- **Staff and Admin API publish:** a validation error on `email_on_publish`.
- **Scheduled publishing:** the post publishes, but a `failed` campaign is recorded with `failure_reason = sender_unverified`.
- **Test sends:** rejected.
- **The send job:** skips the recipient.

Confirmation emails use the newsletter sender once it is verified, and the global From until then.

## Public channels

The six channels are APES CIC, APES Shelter & Rescue, APES Pet Care Clinic, APES Times, MyAPES Portal and Stream 4 APES. Channel slugs are fixed in `App\Enums\Channel`; adding a seventh channel needs a code change. Which channels show, where, in what order and with what wording comes from each newsletter's **Public channel** settings at `/staff/newsletters/{id}/edit`.

- A channel is public only while its linked newsletter is active. Archiving the newsletter removes its channel page, menu entry, home section, feed and sitemap entry.
- The newsletter slug is locked while a channel is linked, because the channel's subscribe button links to `/newsletters/{slug}/signup`. Unlink the channel to change it.
- The post editor's Channel field lists channels whose newsletter is active. A post keeps its channel if the newsletter is later archived.

Imported Ghost posts are filed by the Ghost newsletter they were sent with (see [`config/ghost_newsletters.php`](../config/ghost_newsletters.php)). Posts sent with the default newsletter keep the tag and title heuristic. To refile posts imported before this mapping existed:

```bash
php artisan ghost:backfill-post-channels path/to/ghost-content.json --dry-run
php artisan ghost:backfill-post-channels path/to/ghost-content.json --force
```

It reads the same export shapes as `ghost:import-content`. It only moves posts that belong to APES Times, MyAPES Portal or Stream 4 APES, lists every change with whether it matched by Ghost newsletter or by title or slug, and never sends email. Add `--newsletter-only` to skip title and slug matches. The cutover export on live is `/app/data/shared/imports/cutover/content.json`; run artisan there through `deploy/cloudron-www-data.sh` (see [`docs/deployment.md`](deployment.md)).

## Signup default

Newsletters with `subscribe_on_signup` appear as unticked checkboxes on `/register`. Ticked ones start as pending and are confirmed when the member verifies their account email. That is one email, and it is still double opt-in. Imports never apply this default. Opt-outs and suppressions on an existing mailing contact are never reset by registration ticks. Linking a pre-existing contact to the new account waits until the email is verified (see [`docs/staff-subscribers.md`](staff-subscribers.md)).
