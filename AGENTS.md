# Agent guide — APES Newsroom

Instructions for AI agents and contributors working in this repository.

## Source of truth for work

Track all product and engineering work with **GitHub Issues** on
[APESCIC/APES.Newsroom](https://github.com/APESCIC/APES.Newsroom/issues).

- Launch epic (closed): [#1 Build and launch APES Newsroom](https://github.com/APESCIC/APES.Newsroom/issues/1),
  sequenced in [`docs/epic-1-build-plan.md`](docs/epic-1-build-plan.md)
- Post-launch work is grouped into **version milestones** (`vX.Y.Z`), one
  milestone per release, each holding a `[Feature]` issue with `[Task]` /
  `[Bug]` sub-issues

Do not treat chat history, local notes, or unlinked PRs as the record of
what is done. Update the relevant issue(s) and milestone.

Every agent runs the same loop. Exact `gh` commands for each step are in
[`docs/agent-workflow.md`](docs/agent-workflow.md).

```text
find/create issue → milestone → sub-issues → branch → commit → PR → CI green
  → merge → tag release → deploy → /health ok → close issues + milestone
  → next options (one recommended)
```

## Starting work (required)

1. **Issue.** Find an open issue that covers the work, or create one with a
   `[Feature]`, `[Task]`, `[Bug]` or `[Chore]` title prefix and the matching
   GitHub issue type. Include acceptance criteria.
2. **Parent.** If the work belongs to a larger feature, link it as a
   sub-issue of that parent (REST `sub_issues` API, not just “Relates to”).
   When scope splits, open new sub-issues instead of burying leftovers in a PR.
3. **Milestone.** Assign the issue (and its sub-issues) to the version
   milestone it will ship in. Create the milestone if it does not exist.
   Choose the version with semver from the latest release: patch for fixes
   and internal-only changes, minor for new user-facing features.
4. **Branch** off an up-to-date `main` (see Branch naming).

### While working

- Comment on the issue when something load-bearing lands: a new surface, a
  decision, a blocker, the PR opening, the merge, the deploy.
- Tick acceptance-criteria checkboxes in the issue body as they are met.
- Keep milestone membership accurate: move issues that slip to the next
  milestone rather than leaving them behind.

## Completing work (required)

Work is not finished at “code on a branch” or “PR merged”. Run the whole
sequence; stop and report only if a step fails.

1. **Commit** with a conventional commit (`feat`, `fix`, `chore`, `docs`,
   `refactor`, `test`) that references `#N`. Stage only the files for this
   change.
2. **Changelog.** Add exactly one `changelog/releases/vX.Y.Z.json` matching
   the milestone ([`docs/change-log-hub.md`](docs/change-log-hub.md)).
3. **PR.** Push with `-u`, open a PR whose body says `Closes #N` and names
   the milestone. Run the quick checks below first.
4. **CI green.** Wait for every required check; fix failures on the branch.
5. **Merge** into `main` with a merge commit, then delete the branch.
6. **Release.** Tag the merge commit `vX.Y.Z` and push the tag;
   [`release.yml`](.github/workflows/release.yml) publishes the GitHub Release.
7. **Deploy** by dispatching **Deploy to Cloudron (beta)** with
   `confirm_branch=main`, watch the run to completion, and confirm
   `https://www.apesnews.org.uk/health` returns `"status":"ok"`.  <!-- pragma: allowlist secret -->
8. **Close out.** Comment the PR, merge SHA, release and deploy run on the
   issue. Close issues whose acceptance criteria are met (never for “mostly
   done”); close the parent when all its sub-issues are closed; close the
   milestone when it has no open issues.

If the deploy fails, the workflow rolls back automatically. Leave the issue
open, comment the failed run, and fix forward with a new patch version.

## Options and recommendations

Whenever a decision is needed — and at the end of every task — give the
best next options (2–4), with the recommended option first, labelled
**(Recommended)**, and a one-line reason for each. In Cursor, ask with the
AskQuestion tool rather than a bare list. Do not stall waiting on a choice
that has a clear default; take the recommended option and say so.

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
- **Merge does not auto-deploy.** Agents dispatch the deploy themselves as
  step 7 of Completing work, after the release tag. Do not add push- or
  merge-triggered deploy workflows.
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

The repository is public. Vulnerabilities go through private advisories per
[`SECURITY.md`](SECURITY.md) — never public issues, PRs, or comments. Keep
remediation issues free of exploit detail.

## Branch naming

Use `cursor/<type>/<issue-number>-<short-slug>`, where `<type>` is
`feature`, `fix` or `chore` and the slug is short kebab-case (for example
`cursor/feature/335-user-management-contact-fields`). Reuse the branch for
the same issue; never commit directly to `main`; never force-push.

## Authorization boundary

The live Newsroom already runs at `www.apesnews.org.uk`. Completing or  <!-- pragma: allowlist secret -->
closing issues still does **not** authorize:

- apex DNS changes for `apesnews.org.uk` (apex was not switched at cutover)
- live campaign sends
- retiring or deleting Ghost (`ghost-legacy.apesnews.org.uk`)

Those boundaries require explicit sign-off (see issue #11 and the epic body).
Routine deploys of merged, released work through the guarded workflow are
part of Completing work and need no extra sign-off.

## Cursor Cloud environment

Committed repo-managed env:

- [`.cursor/environment.json`](.cursor/environment.json)
- [`.cursor/Dockerfile`](.cursor/Dockerfile)

The image includes the GitHub CLI (`gh`), which the workflow above needs.
If `gh auth status` fails in a Cloud agent, add a `GH_TOKEN` Cursor secret
scoped to this repository (issues, pull requests, contents, actions,
workflows). After merging changes to these
files, save/rebuild the Cursor environment in the dashboard so new agents
pick them up. Other `.cursor/` paths stay gitignored.

## Project pointers

| Topic | Where |
|-------|--------|
| Agent workflow commands (issues, milestones, release, deploy) | [`docs/agent-workflow.md`](docs/agent-workflow.md) |
| Local setup & testing | [`README.md`](README.md) |
| Redis/OIDC local stack | [`docs/local-dev.md`](docs/local-dev.md) |
| Deploy & rollback | [`docs/deployment.md`](docs/deployment.md) |
| Beta acceptance checklist | [`docs/deployment-beta-acceptance.md`](docs/deployment-beta-acceptance.md) |
| Design drafts | [`docs/design/`](docs/design/) |
| Change Log Hub authoring | [`docs/change-log-hub.md`](docs/change-log-hub.md) |
| Vulnerability reporting | [`SECURITY.md`](SECURITY.md) |
| Admin API tokens | [`docs/api.md`](docs/api.md) |
| Integration keys & health checks | [`docs/integrations.md`](docs/integrations.md) |
| Approved email sending domains | [`docs/email-sending-domains.md`](docs/email-sending-domains.md) |
| Admin user management & contact fields | [`docs/user-management.md`](docs/user-management.md) |
| Staff editors (Editor.js / TinyMCE) | [`docs/editors.md`](docs/editors.md) |

Quick checks before opening a PR: `composer test`, `composer lint`,
`npm run typecheck`, `npm run lint`.
