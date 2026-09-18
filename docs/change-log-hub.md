# Updating the Change Log Hub

The public [Change Log Hub](/change-log-hub) reads published rows from the
`releases` table. Keep it in sync when you cut a Newsroom release.

## When to update

Create or edit a release note whenever you ship a user-visible or operationally
notable change that should appear in public release history (features, fixes,
compliance/accessibility work, and similar).

## How to update

1. Sign in as an **admin** (or super admin).
2. Open **Admin → Releases** (`/admin/releases`).
3. Create a new release (or edit a draft).
4. Fill the structured sections used on the public cards:
   - Summary
   - Detailed changes (one item per line)
   - Affected areas
   - Version decision
   - Validation
5. Set change-type and topic tags so filter chips work.
6. Mark **Published**.
7. Mark **Mark as current release** for the live version (clears the previous
   current flag).
8. Confirm the footer shows `Website version: … · Change Log Hub` and the hub
   lists the new card.

## Seeded baseline

Local/`php artisan db:seed` loads Newsroom releases from **v1.0.0** forward via
`Database\Seeders\ReleaseSeeder`. Do not seed apes.org.uk CIC website history
into this hub.

## Optional metadata mirrors

If you also maintain a root `CHANGELOG` or GitHub Release for the same version,
keep the hub summary consistent with those notes. Automation is not required for
v1.1.1; admin authoring is the source of truth for the public hub.
