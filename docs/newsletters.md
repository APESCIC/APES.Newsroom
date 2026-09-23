# Newsletters

Newsletters are audiences stored in `newsletters`, separate from the three fixed `MailingList` channel enums.

## Columns

| Column | Purpose |
|--------|---------|
| `name` | Staff-facing label |
| `slug` | Unique public identifier |
| `description` | Purpose shown to staff |
| `legacy_list` | Optional unique link to a `MailingList` value (`apes_cic`, `apes_shelter_rescue`, `apes_pet_care_clinic`) |
| `archived_at` | Set when staff archive an audience |

The migration seeds one active newsletter per channel list and copies `legacy_list` onto existing `mailing_list_subscriptions.newsletter_id`.

Channel signup still writes `mailing_list_subscriptions.list`. New signups also set `newsletter_id` when a seeded newsletter matches that list. Unique `(mailing_contact_id, list)` remains for channel rows. Unique `(mailing_contact_id, newsletter_id)` covers newsletter membership.

Staff manage newsletters at `/staff/newsletters`.
