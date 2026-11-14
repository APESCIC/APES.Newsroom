# Herd profile: APES Newsroom

Laravel Herd Pro on Windows is the only supported local setup for APES
Newsroom (decided by Bambie on 14 November 2026, #803). This page records the
project's own Herd settings. The same values are in
[`profile.json`](profile.json), which
[`scripts/herd-setup.ps1`](../../scripts/herd-setup.ps1),
[`scripts/herd-env.ps1`](../../scripts/herd-env.ps1) and
[`scripts/preview.ps1`](../../scripts/preview.ps1) read and
`tests/Feature/HerdProfileTest.php` checks against `.env.example`,
`vite.config.ts` and `composer.json`. Change them together.

| Setting | Value |
| --- | --- |
| Herd site | `apes-newsroom`, linked to this clone with `herd link apes-newsroom` |
| Hostname | <http://apes-newsroom.test> (HTTPS: <https://apes-newsroom.test> after `herd secure apes-newsroom`) |
| Document root | `public/` (Herd's Laravel driver picks it) |
| Web server | nginx (Herd) |
| PHP | 8.4, pinned with `herd isolate 8.4 --site=apes-newsroom`, run by nginx through FastCGI (`php-cgi`) |
| PHP extensions | bcmath, curl, exif, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, sodium, zip |
| Database | Herd's MySQL 8.4 service at `127.0.0.1:3306`, user `root`, empty password |
| Database name | `apes_newsroom_local` (utf8mb4, utf8mb4_unicode_ci) |
| Mail | Herd's mail catcher: SMTP `127.0.0.1` port 2525, read in Herd > Mail |
| Node.js | 22 (Herd's nvm, matching CI) |
| Vite dev server | `http://127.0.0.1:5181` (strict port) |
| Redis (optional) | Herd Pro Redis service on `127.0.0.1:6379`, `REDIS_CLIENT=phpredis` (Herd's PHP ships the extension) |

## Why PHP is pinned to 8.4

Herd's global PHP is newer than 8.4. Cloudron runs PHP 8.4 and
`composer.json` requires `^8.4`, so the site is isolated to 8.4 and
`scripts/herd-env.ps1` puts Herd's `php84` first on `PATH` for the command
line. Bare `php` in a new terminal is Herd's global version, so run
`. .\scripts\herd-env.ps1` (or use `herd php` and `herd composer`) first.

Herd runs each PHP version in its own FastCGI pool, so one project's `.env`
values never reach another, and `/health` reports `php_sapi` `cgi-fcgi`
locally.

## Ports used by APESCIC projects

Herd's nginx (80, 443), MySQL (3306) and mail catcher (2525) are shared by
every project on the machine. Each project keeps its own `.test` site,
database and Vite port, so several can run at once:

| Project | Hostname | Database | Vite port |
| --- | --- | --- | --- |
| APES Newsroom | `apes-newsroom.test` | `apes_newsroom_local` | 5181 |

Other APESCIC repos record their own values in their own repo. Pick a port
that is not 5173 (Vite's default) or 5181 when adding one.
