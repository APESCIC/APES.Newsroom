# APES Newsroom

[![Licence: AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-blue)](LICENSE)
[![Latest release](https://img.shields.io/github/v/release/APESCIC/APES.Newsroom)](https://github.com/APESCIC/APES.Newsroom/releases/latest)
[![CI](https://github.com/APESCIC/APES.Newsroom/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/APESCIC/APES.Newsroom/actions/workflows/ci.yml)

APES Newsroom is the news and mailing-list app of the Association of Protecting
Exotic Species CIC. Built and run by APES for its own use; the code is public.
It lets the public read articles from APES staff, departments and divisions at
https://www.apesnews.org.uk/, and sign up to mailing lists and more.

The production Newsroom runs at [www.apesnews.org.uk](https://www.apesnews.org.uk/). <!-- pragma: allowlist secret -->
The guarded cutover from Ghost completed on 2026-08-06 under
[issue #11](https://github.com/APESCIC/APES.Newsroom/issues/11); the former
Ghost app stays stopped and recoverable at `ghost-legacy.apesnews.org.uk`.

## Latest release

**v1.30.2**: see [Releases](https://github.com/APESCIC/APES.Newsroom/releases)
and the [Change Log Hub](https://www.apesnews.org.uk/change-log-hub).

## Roadmap

| Version | Milestone |
|---------|-----------|
| v1.29.0 | [System email templates](https://github.com/APESCIC/APES.Newsroom/milestone/35) |
| v1.30.0 | [Newsletter email templates](https://github.com/APESCIC/APES.Newsroom/milestone/36) |
| v1.31.0 | [SEO and social previews](https://github.com/APESCIC/APES.Newsroom/milestone/37) |
| v1.32.0 | [Publish flow wizard](https://github.com/APESCIC/APES.Newsroom/milestone/38) |
| v1.33.0 | [Social cross-posting via Postiz](https://github.com/APESCIC/APES.Newsroom/milestone/39) |
| v1.34.0 | [Tiptap editor](https://github.com/APESCIC/APES.Newsroom/milestone/40) |
| v1.35.0 | [Ghost admin parity](https://github.com/APESCIC/APES.Newsroom/milestone/20) |
| Later | [ActivityPub (Fediverse)](https://github.com/APESCIC/APES.Newsroom/milestone/10) |

v1.28.0 [merged super admin into admin](https://github.com/APESCIC/APES.Newsroom/milestone/41).

## Roles

| Role | Who | Can |
|------|-----|-----|
| Public | Readers with an account | Manage their account, mailing lists, profile and comments |
| Staff | Members of the `newsroom.staff` Cloudron group | Write and edit posts and pages, manage media, people and campaigns |
| Admin | Members of the `newsroom.admin` Cloudron group | Everything staff can, plus users, moderation, settings, integrations and mail sending; sensitive changes need a recent sign-in |

Staff and admin roles come only from Cloudron directory groups. See
[user management](docs/user-management.md) for the full list of actions.

## Product capabilities

- Public home, channel, article, author, tag, archive, search, RSS, sitemap,
  and Change Log Hub surfaces for the APES channels.
- Versioned Editor.js content with staff drafts, review, scheduling, publishing,
  preview, revisions, redirects, and SEO controls, edited in Editor.js or
  TinyMCE (see [Editors](#editors)).
- Password, verification, reset, and magic-link journeys for public accounts;
  Cloudron OIDC and LDAP-governed roles for staff.
- Per-channel mailing lists with double opt-in, consent evidence, suppressions,
  preferences, unsubscribe routes, campaign snapshots, and queued delivery.
- Moderated public profiles, comments, reports, and reactions.
- Idempotent Ghost content/media and members CSV import workflows with review
  flags and fail-closed consent handling.
- Governance artifacts for privacy, retention, threat modelling, accessibility,
  deployment, backup, and rollback.

## Stack

The application uses Laravel 13, PHP 8.4, Inertia, React, TypeScript, Editor.js,
MySQL, and Redis. Production runs in an existing Cloudron LAMP app. Cloudron
provides the database, Redis-backed cache/session/queue services, SMTP, OIDC,
LDAP, backups, and runtime process management.

[`CloudronEnvironmentServiceProvider`](app/Providers/CloudronEnvironmentServiceProvider.php)
maps the platform's `CLOUDRON_*` environment values without storing credentials
in this repository. The release layout, queue worker, scheduler, health checks,
and rollback path are documented in the [deployment runbook](docs/deployment.md).

## Editors

Staff write posts and pages in either Editor.js (the default) or TinyMCE. An
admin chooses one for the whole site with the `editor.driver` setting on
**Admin panel → Settings**. Content is always stored as Editor.js blocks, so
switching needs no migration and public pages, search, email and the Admin API
are unaffected. In TinyMCE, blocks it cannot edit (callouts, galleries, embeds
and similar) appear as read-only boxes that keep their data.

TinyMCE is bundled from npm and self-hosted under GPLv2+ (`license_key: 'gpl'`);
it is never loaded from Tiny Cloud. See [`docs/editors.md`](docs/editors.md) for
admin and staff guidance.

## Local development

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
The day-to-day preview is WampServer's Apache at `http://apes-newsroom.test`;
see [WampServer preview](docs/local-dev.md#wampserver-preview).
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
Third-party integrations (Mailgun, Stripe, Unsplash, OpenAI, Slack, Cloudron
SSO) are checked hourly and shown under Admin → Settings → Integrations; see
[integration keys and health checks](docs/integrations.md).

Deployments are manual, backup-first, versioned, and rollback-capable. The
GitHub Environment name is **`beta`** but targets the live LAMP app at
`https://www.apesnews.org.uk/` (app id `74a2a784-a161-4787-84ff-2b8efc957bc8`). Follow the  <!-- pragma: allowlist secret -->
[Cloudron deployment runbook](docs/deployment.md) and the
[beta acceptance record](docs/deployment-beta-acceptance.md). Merge does not
auto-deploy, and repository work does not by itself authorize live campaign
sends or Ghost retirement.

## Links

- [Wiki](https://github.com/APESCIC/APES.Newsroom/wiki)
- [Discussions](https://github.com/APESCIC/APES.Newsroom/discussions)
- [Getting help](SUPPORT.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md): report vulnerabilities privately, never in a public issue
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Deployment and rollback](docs/deployment.md),
  [governance records](docs/governance/) and [design records](docs/design/)
- [All issues](https://github.com/APESCIC/APES.Newsroom/issues) and
  [Releases](https://github.com/APESCIC/APES.Newsroom/releases)

## Licence

Copyright (C) 2026 Association of Protecting Exotic Species CIC. Licensed under
the GNU Affero General Public License v3.0; see [LICENSE](LICENSE).
