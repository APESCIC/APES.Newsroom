# APES Newsroom

[![CI](https://github.com/APESCIC/APES-Newsroom/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/APESCIC/APES-Newsroom/actions/workflows/ci.yml)

APES Newsroom is the mission-led wildlife publishing platform for the
[Association for the Protection of Exotic Species (APES CIC)](https://apes.org.uk).
It provides a public newsroom for APES CIC, APES Shelter & Rescue, and APES Pet
Care Clinic, together with authenticated editorial, campaign, moderation, and
governance workspaces.

The production Newsroom is available at
[www.apesnews.org.uk](https://www.apesnews.org.uk/). The guarded cutover from  <!-- pragma: allowlist secret -->
Ghost completed on 2026-08-06 under
[issue #11](https://github.com/APESCIC/APES-Newsroom/issues/11).

## Repository status

Last verified: **2026-09-24**.

- `main` is the remote default branch and its CI workflow is current.
- The public Newsroom is live at `www.apesnews.org.uk`; the apex  <!-- pragma: allowlist secret -->
  `apesnews.org.uk` DNS transition was not recorded as complete during cutover.
- The former Ghost app remains stopped and recoverable at
  `ghost-legacy.apesnews.org.uk`. Retirement or deletion is not authorized.
- Live campaign sends remain an explicit operational decision; imports and
  migration did not send mail.
- Product and engineering work is tracked in
  [GitHub Issues](https://github.com/APESCIC/APES-Newsroom/issues). The current
  frog-led theme exploration remains gated in
  [issue #36](https://github.com/APESCIC/APES-Newsroom/issues/36).

See [Epic #1](https://github.com/APESCIC/APES-Newsroom/issues/1) and the
[delivery record](docs/epic-1-build-plan.md) for the original dependency map
and completion evidence. Agents and contributors must follow
[`AGENTS.md`](AGENTS.md) and keep issue records current.

## Product capabilities

- Public home, channel, article, author, tag, archive, search, RSS, sitemap,
  and Change Log Hub surfaces for the three APES channels.
- Versioned Editor.js content with staff drafts, review, scheduling, publishing,
  preview, revisions, redirects, and SEO controls.
- Password, verification, reset, and magic-link journeys for public accounts;
  Cloudron OIDC and LDAP-governed roles for staff.
- Per-channel mailing lists with double opt-in, consent evidence, suppressions,
  preferences, unsubscribe routes, campaign snapshots, and queued delivery.
- Moderated public profiles, comments, reports, and reactions.
- Idempotent Ghost content/media and members CSV import workflows with review
  flags and fail-closed consent handling.
- Governance artifacts for privacy, retention, threat modelling, accessibility,
  deployment, backup, and rollback.

## Architecture

The application uses Laravel 13, PHP 8.4, Inertia, React, TypeScript, Editor.js,
MySQL, and Redis. Production runs in an existing Cloudron LAMP app. Cloudron
provides the database, Redis-backed cache/session/queue services, SMTP, OIDC,
LDAP, backups, and runtime process management.

[`CloudronEnvironmentServiceProvider`](app/Providers/CloudronEnvironmentServiceProvider.php)
maps the platform's `CLOUDRON_*` environment values without storing credentials
in this repository. The release layout, queue worker, scheduler, health checks,
and rollback path are documented in the [deployment runbook](docs/deployment.md).

## Local setup

Prerequisites are PHP 8.4 with Composer and a current Node.js/npm runtime.
SQLite and database-backed sessions/cache/queues are sufficient for the default
local workflow.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan serve
```

Use `npm run dev` instead of the production build while editing frontend code.
For Redis, OpenLDAP, and OIDC integration, follow
[`docs/local-dev.md`](docs/local-dev.md). For publishing release notes to the
public Change Log Hub, follow [`docs/change-log-hub.md`](docs/change-log-hub.md).
Never commit `.env`, API tokens, passwords, personal data, production exports,
or application logs.

## Testing

```powershell
composer test
composer lint
npm run typecheck
npm run lint
npm run test:frontend
npm run build
composer audit --locked
npm audit
```

`composer format` rewrites PHP formatting and should be used only when those
changes are intended. CI runs the backend/frontend checks against `main`.

## Health and operations

`GET /health` reports database and cache connectivity as
`{"status":"ok","checks":{...}}` without returning configuration or secrets.
It is used by the guarded deployment workflow and external uptime checks.

Deployments are manual, backup-first, versioned, and rollback-capable. The
GitHub Environment name is **`beta`** but targets the live LAMP app at
`https://www.apesnews.org.uk/` (app id `74a2a784-a161-4787-84ff-2b8efc957bc8`). Follow the  <!-- pragma: allowlist secret -->
[Cloudron deployment runbook](docs/deployment.md) and the
[beta acceptance record](docs/deployment-beta-acceptance.md). Repository work
does not by itself authorize apex DNS changes, live campaign sends, or Ghost
retirement. Merge does not auto-deploy.

## Documentation and support

- [Delivery plan and dependency record](docs/epic-1-build-plan.md)
- [Local Redis/OIDC/LDAP development](docs/local-dev.md)
- [Deployment and rollback](docs/deployment.md)
- [Beta acceptance evidence](docs/deployment-beta-acceptance.md)
- [Design records](docs/design/), including
  [accessibility evidence](docs/design/accessibility-notes.md)
- [Governance records](docs/governance/), including the
  [data inventory](docs/governance/data-inventory.md),
  [retention schedule](docs/governance/retention-schedule.md), and
  [threat model](docs/governance/threat-model.md)
- [Security reporting](SECURITY.md)
- [Report a bug](https://github.com/APESCIC/APES-Newsroom/issues/new?template=bug_report.yml)
  or [request a feature](https://github.com/APESCIC/APES-Newsroom/issues/new?template=feature_request.yml)
- [All issues](https://github.com/APESCIC/APES-Newsroom/issues),
  [Discussions](https://github.com/APESCIC/APES-Newsroom/discussions), and
  [Releases](https://github.com/APESCIC/APES-Newsroom/releases)

The repository is maintained by [APES CIC](https://github.com/APESCIC).
