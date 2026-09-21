# Updating the Change Log Hub

The public [Change Log Hub](/change-log-hub) reads published rows from the
`releases` table. Those rows are **synced from the repository** on every
deploy.

## Authoring a release (required for user-facing PRs)

1. Copy [`changelog/releases/_template.json`](../changelog/releases/_template.json)
   to `changelog/releases/vX.Y.Z.json` (filename must match `version`).
2. Fill the required fields:
   - `version` (e.g. `v1.2.3`)
   - `summary`
   - `detailed_changes` (array of strings)
   - `change_types` — one or more of: `added`, `changed`, `fixed`, `removed`, `security`
   - `topic_tags` — one or more of: `compliance`, `accessibility`, `public-facing`, `internal-only`
3. Optionally set `theme`, `affected_areas`, `released_at`, `previous_version`,
   `pr`, or full `version_decision` / `validation` lists.
4. Open the PR. CI runs `php artisan newsroom:check-pr-changelog` and fails if
   the entry is missing or invalid.
5. Merge, then deploy beta as usual. Activate runs
   `php artisan newsroom:sync-releases` after migrate so the hub updates.

Bump patch for routine shipping PRs; use minor/major when the change warrants
it. Routine PRs should change **exactly one** version file; CI warns if more
than one entry changes, and fails if none do (unless skipped).

### Skipping the gate

Label the PR `skip-changelog` for non-user-facing work (docs-only, CI, chores
that do not affect public or staff product surfaces).

## How sync works

- Source of truth: `changelog/releases/*.json` (files starting with `_` are
  ignored).
- Command: `php artisan newsroom:sync-releases`
- Deploy: [`deploy/cloudron-activate.sh`](../deploy/cloudron-activate.sh) runs
  sync after migrate.
- Channel / version type: set from `NEWSROOM_RELEASES_IN_BETA` (see
  `config/newsroom.php`). Authors do not pick Stable while in beta.
- Highest semver in the directory becomes the current footer version.
- Admin → Releases remains for emergencies; matching file-backed versions are
  overwritten on the next sync.

## Local seed

`php artisan db:seed` (and the baseline release migrations) call the same sync
loader via `Database\Seeders\ReleaseSeeder`.

## Beta period

While `NEWSROOM_RELEASES_IN_BETA=true`, synced releases use the **Beta**
channel. Set it to `false` when leaving beta; historical Beta rows stay Beta
unless you rewrite their JSON and re-sync.
