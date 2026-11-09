# Laragon profile: APES Newsroom

Laragon is the only supported local setup for APES Newsroom. This page
records the project's own Laragon settings. The same values are in
[`profile.json`](profile.json), which
[`scripts/laragon-setup.ps1`](../../scripts/laragon-setup.ps1) reads and
`tests/Feature/LaragonProfileTest.php` checks against `.env.example` and
`vite.config.ts`. Change them together.

| Setting | Value |
| --- | --- |
| Laragon project folder | `C:\laragon\www\apes-newsroom` |
| Hostname | <http://apes-newsroom.test> (HTTPS: <https://apes-newsroom.test> with Laragon SSL) |
| Document root | `public/` (Laragon's Auto Virtual Hosts picks it) |
| Web server | Apache 2.4 (Laragon) |
| PHP | 8.4, thread-safe build (`php-8.4.*-Win32-vs17-x64`), loaded by Apache's mod_php |
| PHP extensions | bcmath, curl, exif, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, sodium, zip |
| Database | MySQL 8.4 at `127.0.0.1:3306`, user `root`, empty password |
| Database name | `apes_newsroom_local` |
| Node.js | 22 (Laragon's `node-v22`, matching CI) |
| Vite dev server | `http://127.0.0.1:5181` (strict port) |
| Redis (optional) | Laragon's Redis on `127.0.0.1:6379`, `REDIS_CLIENT=predis` |
| Mail (optional) | Laragon's Mailpit: SMTP `127.0.0.1:1025`, inbox <http://localhost:8025> |
| Laragon profile name | `apes-newsroom` ([`apes-newsroom.ini`](apes-newsroom.ini)) |

## Ports used by APESCIC projects

Laragon's Apache (80, 443) and MySQL (3306) are shared by every project on
the machine. Each project keeps its own hostname, database and Vite port, so
several can run at once:

| Project | Hostname | Database | Vite port |
| --- | --- | --- | --- |
| APES Newsroom | `apes-newsroom.test` | `apes_newsroom_local` | 5181 |

Other APESCIC repos record their own values in their own repo. Pick a port
that is not 5173 (Vite's default) or 5181 when adding one.

## The Laragon profile

A Laragon profile pins the versions of Laragon's services (Apache, PHP,
MySQL, Node.js, Redis, Mailpit). Profiles are stored in
`C:\laragon\usr\profile\<name>.ini` and switched from **Menu > Profile**
([Laragon docs: Multi-Stack (Profile)](https://laragon.org/docs/profile)).

The setup script installs [`apes-newsroom.ini`](apes-newsroom.ini) as
`C:\laragon\usr\profile\apes-newsroom.ini` if it is missing, filling in the
installed folder names under `C:\laragon\bin`. It never changes an existing
profile or another project's profile.

What a profile does not do (confirmed in the Laragon docs and the
maintainer's answers on the Laragon GitHub):

- **It is global, not per project.** Switching a profile changes the service
  versions for the whole of Laragon. It does not change projects or the
  document root.
- **Apache runs one PHP version at a time.** Running two sites on different
  PHP versions at once needs a manual FastCGI setup, which this project does
  not use.

The fallback is that all APESCIC projects run under one active PHP 8.4
profile. Every APESCIC Laravel repo accepts PHP 8.4, so they can run side by
side, each with its own hostname, database and Vite port.

## Virtual host

Laragon's Auto Virtual Hosts creates
`C:\laragon\etc\apache2\sites-enabled\auto.apes-newsroom.test.conf` with the
document root `C:/laragon/www/apes-newsroom/public`, plus the
`127.0.0.1 apes-newsroom.test` hosts entry, when you choose **Menu > Apache >
Reload** (or restart Laragon). Nothing needs adding by hand.

If Auto Virtual Hosts is turned off, copy
[`apes-newsroom.test.conf`](apes-newsroom.test.conf) to
`C:\laragon\etc\apache2\sites-enabled\apes-newsroom.test.conf` (no `auto.`
prefix, so Laragon keeps it as is) and add the hosts entry yourself.
