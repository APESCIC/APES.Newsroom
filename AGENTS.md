# Agent guide — APES Newsroom

Instructions for AI agents and contributors working in this repository.

## Source of truth for work

Track all product and engineering work with **GitHub Issues** on
[APESCIC/APES-Newsroom](https://github.com/APESCIC/APES-Newsroom/issues).

- Epic: [#1 Build and launch APES Newsroom](https://github.com/APESCIC/APES-Newsroom/issues/1)
- Sequencing and dependency map: [`docs/epic-1-build-plan.md`](docs/epic-1-build-plan.md)
- Sub-issues #2–#11 cover design, foundation, auth, publishing, newsroom,
  mailing, engagement, Ghost migration, governance, and cutover

Do not treat chat history, local notes, or unlinked PRs as the record of
what is done. Update the relevant issue(s).

## Issue workflow (required)

### Before coding

1. Find an existing open issue that covers the work, or **create** one.
2. For epic-scoped work, link the parent (`Parent: #1` or “Relates to #1”).
3. Work on a branch; reference `#N` in commit messages and PR titles/bodies
   (conventional commits: `feat`, `fix`, `chore`, `docs`, `refactor`, `test`).

### During work

- Comment on the issue when a milestone lands (merge, new surface, blocker).
- If scope splits into a discrete leftover that does not fit an existing
  sub-issue, open a new issue rather than burying it only in a PR.

### When finishing a chunk

1. Comment what shipped (PR/commit links) and what remains vs the issue’s
   acceptance criteria.
2. **Close the issue only when acceptance criteria are met** (or explicitly
   waived in the issue). Never close for “mostly done” or “code on main.”
3. When a delivery phase completes, add a short progress comment on epic #1.

```text
find/create issue → implement on branch → PR references #N
  → comment progress → AC met? → close : leave open with gaps
```

## Stack matrix

| Environment | Runtime | Data / services |
|-------------|---------|-----------------|
| Local (default) | PHP 8.4, Composer 2, Node 22 | SQLite; optional Redis/OIDC/LDAP via [`docs/local-dev.md`](docs/local-dev.md) |
| Cloudron LAMP (live) | PHP 8.4 in existing LAMP app | MySQL, Redis, SMTP, OIDC, LDAP via `CLOUDRON_*`; mapped by [`CloudronEnvironmentServiceProvider`](app/Providers/CloudronEnvironmentServiceProvider.php) |

## Live Cloudron target

- Origin: `https://www.apesnews.org.uk/`  <!-- pragma: allowlist secret -->
- Cloudron app id: `74a2a784-a161-4787-84ff-2b8efc957bc8` (identifier, not a secret)  <!-- pragma: allowlist secret -->
- Runbook: [`docs/deployment.md`](docs/deployment.md)
- CLI wrapper: [`scripts/cloudron.sh`](scripts/cloudron.sh)

The GitHub Actions Environment name remains **`beta`** (existing secrets and
reviewers). That environment targets the **live** Newsroom LAMP app above.
Ops must set `CLOUDRON_APP_ORIGIN_HOST=www.apesnews.org.uk` for health checks.  <!-- pragma: allowlist secret -->

## Deploy rules

- Workflow: **Deploy to Cloudron (beta)** (`.github/workflows/deploy.yml`).
- Trigger: deliberate `workflow_dispatch` against `main` only (type `main`).
- **Merge ≠ deploy.** Agents must not invent merge-auto-deploy or new
  auto-deploy workflows.
- Backup-first, versioned releases under `/app/data/releases/`, atomic
  symlink activation, rollback on failed health.

## Artisan on the server (www-data trap)

Always run artisan as www-data via:

```bash
bash /app/data/current/deploy/cloudron-www-data.sh \
  /usr/bin/php /app/data/current/artisan <command>
```

Never use bare `sudo -u www-data …` — that strips `CLOUDRON_*` and artisan
can silently use SQLite from the shared `.env` while the web app uses MySQL.
See [`docs/deployment.md`](docs/deployment.md).

## Health

`GET /health` must return JSON with `"status":"ok"` (plus database/cache
checks). Used by the deploy workflow and uptime monitors. Do not expose
secrets or config in the health payload.

## Secrets (names only — never commit values)

Same names for GitHub Environment `beta` and Cursor Project / Cloud Agent
secrets:

| Name | Purpose |
|------|---------|
| `CLOUDRON_FQDN` | Cloudron instance hostname |
| `CLOUDRON_TOKEN` | API token (minimum scope for this app) |
| `CLOUDRON_APP_ID` | Live LAMP app id above |
| `CLOUDRON_APP_ORIGIN_HOST` | Health host: `www.apesnews.org.uk` |  <!-- pragma: allowlist secret -->

Never commit `CLOUDRON_TOKEN` or any other credential. Do not put secrets in
`.cursor/environment.json`.

## Branch naming (Cloud Agents)

Use `bmurphy/<short-slug>` (kebab-case). Conventional commits with `#N`.
Do not commit directly to `main`.

## Authorization boundary

The live Newsroom already runs at `www.apesnews.org.uk`. Completing or  <!-- pragma: allowlist secret -->
closing issues still does **not** authorize:

- apex DNS changes for `apesnews.org.uk` (apex was not switched at cutover)
- live campaign sends
- retiring or deleting Ghost (`ghost-legacy.apesnews.org.uk`)

Deploy remains a guarded, separately triggered operation (see Deploy rules).
Those boundaries require explicit sign-off (see issue #11 and the epic body).

## Cursor Cloud environment

Committed repo-managed env:

- [`.cursor/environment.json`](.cursor/environment.json)
- [`.cursor/Dockerfile`](.cursor/Dockerfile)

After merging changes to these files, save/rebuild the Cursor environment in
the dashboard so new agents pick them up. Other `.cursor/` paths stay
gitignored.

## Project pointers

| Topic | Where |
|-------|--------|
| Local setup & testing | [`README.md`](README.md) |
| Redis/OIDC local stack | [`docs/local-dev.md`](docs/local-dev.md) |
| Deploy & rollback | [`docs/deployment.md`](docs/deployment.md) |
| Beta acceptance checklist | [`docs/deployment-beta-acceptance.md`](docs/deployment-beta-acceptance.md) |
| Design drafts | [`docs/design/`](docs/design/) |
| Change Log Hub authoring | [`docs/change-log-hub.md`](docs/change-log-hub.md) |

Quick checks before opening a PR: `composer test`, `composer lint`,
`npm run typecheck`, `npm run lint`.
