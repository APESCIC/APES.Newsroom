# Ghost newsletters → Newsroom

How each Ghost newsletter maps to a Newsroom newsletter, and the runbook for
reconciling member subscriptions from Ghost without emailing anyone.
Milestone v1.13.0 (#463); mapping #464, importer #468, repair #467,
rehearsal #471.

## Mapping

Stored in [`config/ghost_newsletters.php`](../../config/ghost_newsletters.php).
The reconciler reads it; nothing is hard-coded. Each Ghost newsletter maps to
its own Newsroom newsletter, and none are merged. Ghost ids and slugs for the
active newsletters come from the Ghost Content API
(`ghost-legacy.apesnews.org.uk/ghost/api/content/newsletters/`).

| Export file | Ghost newsletter | Ghost slug | Newsroom slug | Status | Ghost count |
|-------------|------------------|------------|---------------|--------|-------------|
| `newsroom.csv` | Association of Protecting Exotic Species CIC Newsroom | `default-newsletter` | `apes-cic` | active | 101 |
| `apes-times.csv` | APES Times | `apes-times` | `apes-times` | active | 9 |
| `shelter-rescue.csv` | APES Shelter & Rescue | `shelter-rescue` | `apes-shelter-rescue` | active | 15 |
| `pet-care-clinic.csv` | APES Pet Care Clinic | `apes-pet-care-clinic` | `apes-pet-care-clinic` | active | 15 |
| `myapes-portal.csv` | MyAPES Portal | `myapes-portal-newsroom` | `myapes` (renamed from `myapes-portal` in v1.25.1) | active | 8 |
| `stream-4-apes.csv` | Stream 4 APES | `stream-4-apes` | `stream-4-apes` | active | 6 |

### Decision on archived newsletters

Ghost's three archived newsletters (Pet Shop Newsroom, 1 member; Mailing
List, 107; Animal Therapy Waiting List, 2) are **not imported**, and the
Newsroom newsletters that were created for them have been deleted (26 Oct
2026, #499). Members who are only on those lists get no Newsroom
subscription. If an archived export is placed in the folder anyway, the
reconciler reports it as an unmapped file.

### Senders

Sender name and email come from Ghost. The reply-to address is
`info@apes.org.uk` for all six active newsletters. Sending is blocked until
`mailing:verify-senders` confirms the sender domain in Mailgun (see
[`docs/newsletters.md`](../newsletters.md)).

## What the reconciler does

`php artisan ghost:reconcile-newsletters <dir>` reads `members.csv` (Ghost
"Export all members") plus one `<key>.csv` export per newsletter. For each
Ghost member and each mapped newsletter it does the following:

- **Member is in that newsletter's export:** the subscription becomes
  `confirmed`, with a consent event (source `ghost_members_csv`, wording
  `ghost-import-v2`, evidence holding the Ghost newsletter and import run).
- **Opt-outs always win and are reported as blocked:** a deleted Ghost
  member, `subscribed_to_emails=false` (also recorded as a suppression), an
  existing suppression, or an unsubscribe made in the Newsroom.
- **Member is not in that export:** a held row created by the old
  v1 import is removed. A row is only removed when its whole consent history
  is `ghost_members_csv`; choices members made in the Newsroom are kept.
- **Contacts who are not Ghost members** are never touched.
- **"Subscribe on signup" is never applied.** Imported members keep exactly
  their Ghost choices.
- **No email is sent.** Nothing is queued or notified, and mail is switched
  to the `array` driver for the run.

The dry run writes only an `ImportRun` record (type `ghost_newsletters_csv`).
Its report shows, per newsletter:

- expected vs exported count;
- rows to confirm, rows already confirmed, and rows removed;
- blocked rows by reason, and Newsroom choices kept;
- the projected confirmed count.

It also lists missing or unmapped files and problems. Emails appear masked
(`a***@example.com`). The report is visible on `/admin/imports/ghost-members`.

Apply needs `--from-run=<dry run id>`. It refuses to run unless:

- that dry run completed with no problems;
- the export files are byte-identical to the ones dry-run (checksum);
- the counts matched the expected Ghost counts, unless `--accept-mismatch`
  is passed after written sign-off.

The old single-file upload on `/admin/imports/ghost-members` is now dry-run
only, and the members importer no longer creates any subscription rows. That
importer is what created three rows per member, which caused the "597 on every
newsletter" count (#467).

## Exporting from Ghost

In Ghost admin (`ghost-legacy.apesnews.org.uk/ghost/#/members`):

1. Clear all filters, then use **Export all members**. Save it as `members.csv`.
2. For each of the six active newsletters, filter **Newsletters → is
   subscribed to → \<name\>**. Export, then rename the file using the "Export
   file" column above. Do not export the archived newsletters.
3. Keep the files outside the repository. They contain personal data and
   must never be committed.

## Runbook (live)

The GitHub Environment `beta` deploys to the live app `www.apesnews.org.uk`.
Run artisan **only** through the www-data wrapper:

```bash
W="bash /app/data/current/deploy/cloudron-www-data.sh /usr/bin/php /app/data/current/artisan"
```

1. **Deploy** `main` with the guarded **Deploy to Cloudron (beta)** workflow,
   and confirm `/health` returns `"status":"ok"`.
2. **Worker queues.** Push the updated `deploy/cloudron-run.sh` to
   `/app/data/run.sh` and restart, so the worker also processes `mail-test`.
3. **Senders.**
   - Add `MAILGUN_API_KEY`, `MAILGUN_ENDPOINT` (`api.eu.mailgun.net` or
     `api.mailgun.net`) and, if Mailgun sends from a subdomain,
     `MAILGUN_DOMAIN` to `/app/data/shared/.env`.
   - Run `$W config:cache`, then `$W mailing:verify-senders`.
   - Fix any SPF, DKIM or DMARC gaps in DNS, then re-run. Until every sender
     passes, campaigns stay blocked.
4. **Upload exports.** Push the files to
   `/app/data/imports/ghost-newsletters/<date>/` with `scripts/cloudron.sh push`.
5. **Pre-checks.**
   - Take a backup: `cloudron backup create --app <id>`.
   - Confirm no campaign jobs are queued. The live queue runs on Redis, so
     check `Queue::size('default')` and `Queue::size('mail-test')` are 0.
   - Note the per-status counts on `/staff/newsletters`.
6. **Dry run.** Run
   `$W ghost:reconcile-newsletters /app/data/imports/ghost-newsletters/<date>`.
   Post the counts table (counts only, no emails) to #471 and get sign-off.
7. **Apply.** Run
   `$W ghost:reconcile-newsletters <dir> --apply --from-run=<id> --actor=<admin email>`.
   Then check three things:
   - `/staff/newsletters` shows 101/9/15/15/8/6 confirmed for the active
     newsletters, less any opt-outs reported as blocked;
   - the `campaign_recipients` and `jobs` tables are unchanged;
   - Mailgun logs for the window show no sends.
8. **Clean up.** Delete the export directory from the server.

**Rollback:** restore the backup from step 5. The reconciler only changes
`mailing_contacts`, `mailing_list_subscriptions`, `suppressions` and
`consent_events`, and records an `ImportRun`.
