# Demo data

Fake data for clicking through APES Newsroom locally and for repeatable bug
reports. It is made by
[`DemoDataSeeder`](../../database/seeders/DemoDataSeeder.php) with the
`newsroom:demo-data` command.

```powershell
composer demo:seed    # load it (safe to run again: no duplicates)
composer demo:reset   # wipe apes_newsroom_local, migrate, load it again
```

## Rules

- **Local only.** The command and the seeder refuse to run unless
  `APP_ENV=local`, and exit with an error. A reset also refuses unless the
  database is MySQL on `127.0.0.1` or `localhost` and its name ends in
  `_local`. Deploys, Cloudron scripts and `DatabaseSeeder` never call it.
- **Clearly fake.** All names, stories and animals are made up. Email
  addresses only use `example.com`, `example.org` and `example.test`. Phone
  numbers come from Ofcom's drama ranges (`07700 900000` to `07700 900999`).
  Addresses use invented street names and the non-existent `ZZ` postcode
  area. Images are plain generated placeholders. Nothing is copied from real
  APES records or production.
- **UK formats.** Faker uses the `en_GB` locale, the clock is
  Europe/London, prices are in pounds and the text is UK English.
- **Repeatable.** Faker uses a fixed seed (`20261109`) and the clock is
  pinned to 2 November 2026 09:00 while seeding, so every run gives the same
  records. Scheduled posts are set for 15 January 2027.

## Test logins

One shared, obviously fake password for every demo account:
**`demo-local-only`**. Never reuse it anywhere else.

| Role | Email | How to sign in |
| --- | --- | --- |
| Admin | `admin@example.test` | **Admin** on the local role preview bar, or the Admin button on `/login` |
| Staff | `staff@example.test` | **Staff** on the role preview bar, or the Staff button on `/login` |
| Staff (second author) | `writer@example.test` | Not on the role preview bar; used as a post author |
| Public member | `member@example.test` | `/login` with the password above, or **Public** on the role preview bar |
| Public reader | `reader@example.test` | `/login` with the password above |
| Public readers | `reader01@example.org` to `reader40@example.org` | `/login` with the password above |

Staff and admin accounts are refused password sign-in in every environment
(issue #276), so they sign in through the role preview, which only exists
when `APP_ENV=local`.

## What it contains

| Area | Records |
| --- | --- |
| Users | 45: the 3 role-preview accounts, `writer@example.test`, `reader@example.test` and 40 readers with UK addresses and drama-range mobiles |
| Posts | 48 across all six channels: 39 published, 4 draft, 2 in review, 2 scheduled (for 2027) and 1 unpublished; some are members-only or paid. APES CIC has 14 published posts, so its channel page and the archive paginate. Staff posts has 3 pages |
| Pages | 6 (5 published, 1 draft) |
| Tags | 10 public tags and 1 internal tag, 2 per post |
| Media library | 30 placeholder PNGs in `storage/app/public/demo/` (git-ignored, redrawn each run); 30 posts and the pages use them as hero images |
| Public profiles | 18: 10 approved, 5 pending, 1 rejected, 1 suspended, 1 private |
| Comments | 40: 24 approved, 12 pending, 4 rejected, so the moderation queue paginates |
| Moderation reports | 6: 4 open, 1 resolved, 1 dismissed |
| Reactions | 60 |
| Page views | About 150 native page views for the analytics screens |
| Mailing contacts | 76 (the 20 first readers, 55 subscribers without accounts and the member), on 151 subscriptions across the three mailing lists: mostly confirmed, some pending, some unsubscribed |
| Suppressions | 5 (bounce, complaint, manual) |
| Campaigns | 4: 2 completed with 20 recipients each (opens, clicks and one failure), 1 cancelled, 1 draft |
| Memberships | 12: 5 active, 5 free, 1 past due, 1 cancelled (no real Stripe ids) |
| Offers | `WINTER25` (25% off) and `FRIENDS10` (10% off) |
| Redirects | 3 |
| Webhooks | 2 to `example.test` and `example.org` addresses, with fake secrets |
| Audit log | 30 entries from the documentation IP range `192.0.2.0/24`, so it paginates |
| Change Log Hub | The release notes from `changelog/releases/` (`ReleaseSeeder`) |

## Main pages to try

| Page | Shows |
| --- | --- |
| `/` | Featured story, channel sections and recent stories |
| `/apes-cic`, `/archive/2026` | Paginated story lists |
| `/articles/<slug>` | Story with hero image, tags, comments and reactions |
| `/staff/posts`, `/staff/pages`, `/staff/media` | Editorial lists with drafts, reviews and schedules |
| `/staff/subscribers`, `/staff/people`, `/staff/newsletters` | Subscribers, contacts and newsletters; a post's **Campaign** tab shows its sends |
| `/admin/users`, `/admin/moderation`, `/admin/audit-log`, `/admin/members` | Admin lists, all with more than one page |
