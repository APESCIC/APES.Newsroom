# Agent instructions for APES Newsroom

Cursor's user rules and skills set how work is done here (branches, issues, pull requests, merge, release, deploy, ship gate, decisions and safety). This file describes this repository and fills the named slots below. If anything here disagrees with them, Cursor's rules win and this file is corrected in the same milestone.

The owner is Bambie Murphy. Write UK English (organisation, behaviour, colour, licence) in the product, docs, issues and pull requests. Never print, paste or commit secrets.

## About the app

APES Newsroom is the news and mailing-list app of the Association of
Protecting Exotic Species CIC. The public read articles from APES staff,
departments and divisions and sign up to mailing lists; staff write and
publish; admins run users, moderation, settings and mail sending. Live at
<https://www.apesnews.org.uk/>. Setup, testing and operations are in the
[README](README.md).

- Repository: `APESCIC/APES.Newsroom`. Work only in this repository.

## Stack and architecture

- Laravel 13 on PHP 8.4, Inertia with React and TypeScript, Editor.js (or
  TinyMCE) content stored as Editor.js blocks, MySQL 8.4, Redis on Cloudron.
- Production is a Cloudron LAMP app (Apache, `public/.htaccess`);
  `CloudronEnvironmentServiceProvider` maps `CLOUDRON_*` values at runtime.
- Staff and admin roles come only from Cloudron directory groups
  (`config/rbac.php`); see [docs/user-management.md](docs/user-management.md).

## Local development and preview

Laravel Herd Pro on Windows is the only supported local setup (decided by
Bambie on 14 November 2026, #803). Never suggest Docker or any other local web
stack.

| Item | Value |
| --- | --- |
| Clone | `C:\Users\BambieMurphy\Documents\GitHub\APES.Newsroom` |
| Herd site | `apes-newsroom` (linked; PHP 8.4 isolated, nginx serving `public/`) |
| Preview URL | `http://apes-newsroom.test` |
| Preview command | `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1` (gate), `npm run dev` or `composer dev` |
| HMR port | 5181 (strict port, `127.0.0.1`) |
| Env file | `.env.example` (key names only, never values) |
| Database | MySQL, `apes_newsroom_local` (Herd MySQL 8.4 on 127.0.0.1:3306, `root`, no password) |
| Mail | Herd mail catcher (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=2525`) |
| Setup | `composer setup` ([scripts/herd-setup.ps1](scripts/herd-setup.ps1)) |

- Values: [docs/herd/profile.md](docs/herd/profile.md) and
  [docs/herd/profile.json](docs/herd/profile.json) (checked by
  `HerdProfileTest`); guide in [docs/local-dev.md](docs/local-dev.md). The
  repo rule is [`.cursor/rules/herd-local-dev.mdc`](.cursor/rules/herd-local-dev.mdc).
- Herd's global PHP is newer than 8.4. In agent shells run
  `. .\scripts\herd-env.ps1` (Herd's `php84`, Composer and Node 22 first on
  `PATH`), or use `herd php` and `herd composer`, before `composer`,
  `php artisan` or `npm`.
- Herd's nginx serves the site, so do not run `php artisan serve`. Vite can
  also run as `herd processes:start vite --site=apes-newsroom`.
- Demo data: `composer demo:seed` and `composer demo:reset`; contents and
  test logins in [docs/herd/demo-data.md](docs/herd/demo-data.md). Test
  changes against the demo data, sign in through the local role preview, and
  keep any new demo records clearly fake (`example.*` emails only).

### Local development with Laravel Herd

This project runs on Laravel Herd. Run `herd site:info` for the URL, PHP version and connected services.

- Use `herd php` and `herd composer` so commands use this site's PHP version.
- Use `herd db:connection` to find the database connection.
- After sending an email, use `herd mail:latest` or `herd mail:wait`, and `herd mail:links <id>` for verification links.
- Use `herd insights:list` for `dump()` output, queries and logs of recent requests, and `herd insights:analyze` for N+1 queries, slow queries and errors.
- Use `herd status` if something doesn't respond; `herd list` shows every command (`--json` for structured output).

## Tests and checks

| Check | Command |
| --- | --- |
| Tests | `composer test` (SQLite in memory, no database needed) |
| Lint / static analysis | `composer lint` (Pint), `npm run typecheck`, `npm run lint` |
| Frontend tests and build | `npm run test:frontend`, `npm run build` |
| CI workflows | `.github/workflows/ci.yml`, `milestone-version-check.yml`, `dependency-review.yml`, `codeql.yml` |

- After pulling a branch whose `composer.json` autoload changed, run
  `composer install` (or `composer dump-autoload`); otherwise tests fail with
  `Class "Scripts\MilestoneVersion\..." not found`.

## Workflow slots

Shipping follows Cursor's `github-milestone-completion` and `ship-gate` skills. This repo fills these slots:

| Slot | Value |
| --- | --- |
| Milestone branch pattern | `milestone/vX-Y-Z-<slug>`, printed by `composer milestone:next` |
| Milestone PR title | `[vX.Y.Z] <Epic title> (#<epic>)`, printed by `composer milestone:pr-title -- <issue>` |
| Version files | None: the tag is the version (`scripts/MilestoneVersion/fields.json`) |
| Release tooling | `composer milestone:bump -- vX.Y.Z` writes `changelog/releases/vX.Y.Z.json` (see [docs/change-log-hub.md](docs/change-log-hub.md)); pushing the tag runs `release.yml` |
| Area labels | None; type labels `chore`, `documentation` |
| Issue title prefix | `[Type] Short title` |

- `milestone:check-title`, `milestone:check-branch` and `milestone:check-release`
  explain what is wrong; CI runs the title and branch checks on every PR.
- Repo skills and rule: [`version-shipping.mdc`](.cursor/rules/version-shipping.mdc),
  [`complete-next-version`](.cursor/skills/complete-next-version/SKILL.md),
  [`release-version`](.cursor/skills/release-version/SKILL.md).
- Security checks use GitHub's free features only: Dependabot (into the
  `dependencies` branch, merged into each milestone branch when it starts),
  dependency review and CodeQL.
- Optional local check before each push: `git config core.hooksPath .githooks`.

## Deploy and health

| Item | Value |
| --- | --- |
| Target | Cloudron LAMP app |
| Cloudron app ID | `74a2a784-a161-4787-84ff-2b8efc957bc8` |
| Domain | <https://www.apesnews.org.uk> |
| Deploy command | `gh workflow run deploy.yml -f tag=vX.Y.Z` |
| Deploys | The release tag (never a branch); only a tag on `main` with a published Release |
| Auto-deploy couplings | None: merging and releasing do not deploy |
| Health URL | <https://www.apesnews.org.uk/health> |
| Version field | None: `/health` returns `status` and `checks`; the green deploy run for the tag is the evidence |
| Secrets live in | GitHub environment `beta` (`CLOUDRON_FQDN`, `CLOUDRON_TOKEN`, `CLOUDRON_APP_ID`, `CLOUDRON_APP_ORIGIN_HOST`) |

Release layout, backups and rollback: [docs/deployment.md](docs/deployment.md).

## Decisions and gotchas

- 2026-11-14: Laravel Herd Pro is the local stack for development and testing, decided by Bambie (#803).
- `public/.htaccess` is for Cloudron's Apache; nginx ignores it locally. Keep PHP settings inside `<IfModule php_module>` and keep the `HTTP_AUTHORIZATION` rule (`HerdProfileTest`).
- Staff-level accounts cannot use password or magic-link sign-in in any environment (#276); locally use the role preview.
- An issue is done when its acceptance criteria are met and committed on the milestone branch; it closes when the milestone PR merges. The milestone is done after the single merge, release and a verified deploy of the tag.

## Where to find things

- Local development: [docs/local-dev.md](docs/local-dev.md), [docs/herd/](docs/herd/)
- Deployment and rollback: [docs/deployment.md](docs/deployment.md)
- Change Log Hub and release notes: [docs/change-log-hub.md](docs/change-log-hub.md), `changelog/releases/`
- API: [docs/api.md](docs/api.md); mail: [docs/mail-sending.md](docs/mail-sending.md); users and roles: [docs/user-management.md](docs/user-management.md)
- Contributing, security and conduct: [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
