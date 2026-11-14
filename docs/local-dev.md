# Local development with Laravel Herd

**Laravel Herd Pro on Windows is the only supported local setup for APES
Newsroom.** The repo runs as its own Herd site at <http://apes-newsroom.test>,
with its own MySQL database (`apes_newsroom_local`) and its own Vite port
(5181), so it can run beside other APESCIC projects. The project's Herd
settings are in [docs/herd/profile.md](herd/profile.md).

| Part | What runs it | Address |
| --- | --- | --- |
| Web server | Herd's nginx, serving `public/` | <http://apes-newsroom.test> |
| PHP | Herd's PHP 8.4, pinned to the site with `herd isolate`, run through FastCGI | |
| Database | Herd's MySQL 8.4 service, database `apes_newsroom_local` | `127.0.0.1:3306` |
| Live reload | Vite (`npm run dev`) | `http://127.0.0.1:5181`, written to `public/hot` |
| Mail | Herd's mail catcher (nothing is sent) | SMTP `127.0.0.1:2525`, read in Herd > Mail |

Tests use SQLite in memory (`phpunit.xml`), so `composer test` needs no
database.

## Prerequisites

- Windows 10 or 11 with [Laravel Herd](https://herd.laravel.com/windows) Pro,
  running, with the `.test` domain (the default).
- Herd's PHP 8.4 (`herd php:install 8.4` if Herd > PHP does not list it).
- Herd's MySQL 8.4 service on port 3306 (Herd > Services).
- Node.js 22 from Herd's nvm (Herd > Settings > Node.js, then
  `herd node:install 22`), the same major version as CI.
- Git.

## 1. Clone the repo

Clone it anywhere in your user folder, for example:

```powershell
git clone https://github.com/APESCIC/APES.Newsroom.git C:\Users\<you>\Documents\GitHub\APES.Newsroom
```

The setup script links the clone to Herd as `apes-newsroom`, which gives the
hostname `apes-newsroom.test`. Herd's helper service writes the hosts entry.

## 2. Put Herd's PHP 8.4 and Node 22 first on PATH

Herd's global PHP is newer than 8.4, so a new terminal's `php` is not the
version the site runs. In each PowerShell session, from the repo folder:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
. .\scripts\herd-env.ps1
```

[`scripts/herd-env.ps1`](../scripts/herd-env.ps1) puts Herd's `php84`,
Composer and Node 22 first on `PATH` for that session only and sets
`XDEBUG_MODE=off`. `herd php ...` and `herd composer ...` also use the site's
PHP version.

## 3. Run the setup script

```powershell
composer setup
# or: powershell -NoProfile -ExecutionPolicy Bypass -File scripts\herd-setup.ps1
```

[`scripts/herd-setup.ps1`](../scripts/herd-setup.ps1) reads
[`docs/herd/profile.json`](herd/profile.json) and:

1. checks Herd is running and has PHP 8.4 with the needed extensions,
   Composer, Node.js 22 and MySQL, and that `public/.htaccess` stays safe for
   Cloudron's Apache;
2. links the site (`herd link apes-newsroom`) and pins it to PHP 8.4
   (`herd isolate 8.4 --site=apes-newsroom`) if that is not done yet;
3. copies `.env.example` to `.env` if there is no `.env`, and warns if
   `APP_URL`, `DB_*` or `MAIL_*` differ from the profile;
4. runs `composer install` and `npm ci` when they are needed, and generates
   `APP_KEY` if it is empty;
5. creates the `apes_newsroom_local` database if it does not exist, links
   `public/storage` and runs the migrations;
6. offers to load the demo data.

It is safe to run again at any time, and it only touches this repo, its Herd
site link and the `apes_newsroom_local` database.

| Switch | Does |
| --- | --- |
| `-Demo` / `-NoDemo` | Load or skip the demo data without asking |
| `-UpdateEnv` | If an existing `.env` has different `APP_URL`, `DB_*` or `MAIL_*` values, back it up to `.env.backup-<time>` and set the profile values |
| `-HerdRoot <folder>` | Herd is not in `%USERPROFILE%\.config\herd` |

Then open <http://apes-newsroom.test>.

## 4. Demo data

```powershell
composer demo:seed    # load the demo data (safe to run again, adds no duplicates)
composer demo:reset   # wipe apes_newsroom_local, migrate and load the demo data again
```

The data is fake, the same on every run (fixed seed), and only loads when
`APP_ENV=local`. A reset also refuses unless the database is MySQL on
`127.0.0.1` and its name ends in `_local`. What it contains and the test
logins are in [docs/herd/demo-data.md](herd/demo-data.md). Test new work
against it.

## 5. Live preview

```powershell
npm run dev     # Vite with hot reload on http://127.0.0.1:5181
composer dev    # Vite plus the queue worker (php artisan dev)
```

Herd's nginx serves the site, so there is no `php artisan serve`. Vite can
also run in the background through Herd:
`herd processes:start vite --site=apes-newsroom`. While Vite runs,
`public/hot` points the pages at it: saving a React component or CSS file
updates the browser without a reload, and saving a Blade template, route or
PHP file reloads the page. When Vite stops, the site uses the last
`npm run build` output from `public/build`.

The app's Content Security Policy allows the Vite address only when
`APP_ENV=local` and `public/hot` exists, so production is unaffected.

Before any change, the live preview gate runs
[`scripts/preview.ps1`](../scripts/preview.ps1): it loads the latest code,
installs changed dependencies, reports pending migrations, checks
`/health` shows the checked-out revision on PHP 8.4 through FastCGI, and starts
Vite.

## Local role preview

When `APP_ENV=local`, a floating **Local role preview** bar appears on every
page. Use it, or the one-click buttons on `/login`, to switch between the
demo accounts without Cloudron OIDC:

| Control | User | Default landing |
|---------|------|-----------------|
| Guest | signed out | `/` |
| Public | `member@example.test` | `/` |
| Staff | `staff@example.test` | `/staff` |
| Admin | `admin@example.test` | `/admin` |

Staff-level accounts are refused password, magic-link and password-reset
sign-in in every environment (issue #276), so staff and admin always use the
role preview locally. The endpoints (`POST /_dev/login/{role}`,
`POST /_dev/logout`) only exist when `APP_ENV=local`, and the controller also
returns 404 outside local.

## Running several APESCIC projects side by side

Each APESCIC repo is its own Herd site: its own `<site>.test` hostname, its
own `_local` database and its own Vite port. Herd's nginx (ports 80 and 443),
MySQL (3306) and mail catcher (2525) are shared. Each site is pinned to its
own PHP version with `herd isolate`, and Herd runs each version in its own
FastCGI pool, so one project's `.env` values never reach another.

APES Newsroom's Vite port is 5181 with `strictPort`, so it fails loudly
instead of moving to another project's port. Ports are listed in
[profile.md](herd/profile.md#ports-used-by-apescic-projects).

## Production-like testing (optional)

Use this to check Redis drivers, staff OIDC sign-in and LDAP role mapping
before a deploy.

**Redis.** Herd Pro runs Redis as a service. Create it once in Herd >
Services (or `herd services:create redis`), then set:

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Herd's PHP ships the `redis` extension, the same `phpredis` client Cloudron
uses. With `QUEUE_CONNECTION=redis`, run `composer dev` (or
`php artisan queue:work`) so queued jobs are processed.

**Mail.** Mail always goes to Herd's mail catcher locally
(`MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=2525`). Read it in
Herd > Mail; nothing leaves the machine.

**OIDC (Cloudron client for local use).** Create a **second** OpenID client
in the Cloudron dashboard with the redirect URI
`http://apes-newsroom.test/auth/cloudron/callback`, then set:

```env
CLOUDRON_OIDC_DISCOVERY_URL=https://<cloudron-domain>/.well-known/openid-configuration
CLOUDRON_OIDC_ISSUER=https://<cloudron-domain>
CLOUDRON_OIDC_CLIENT_ID=<local-client-id>
CLOUDRON_OIDC_CLIENT_SECRET=<local-client-secret>
CLOUDRON_OIDC_PROVIDER_NAME=Cloudron
```

Sign-in uses PKCE (S256) and a nonce, and the callback verifies the ID token
against the discovery document's `jwks_uri`. `CLOUDRON_OIDC_ISSUER` must match
the token's `iss` claim (a trailing slash is ignored), and the client ID must
be in its `aud` claim.

**LDAP.** Copy the `CLOUDRON_LDAP_*` values for a test directory you can
reach into `.env`. Never use production credentials locally. Group names are
mapped to roles in `config/rbac.php`.

Then check:

```powershell
php artisan deploy:preflight
curl.exe http://apes-newsroom.test/health
```

With Redis configured, `/health` returns
`{"status":"ok","checks":{"database":true,"cache":true}}`.

## Passkeys locally

Browsers only allow WebAuthn on `https://` addresses and on `localhost`, so
passkeys do not work at `http://apes-newsroom.test`. To test them:

1. Run `herd secure apes-newsroom` (Herd adds a trusted local certificate).
2. Set `APP_URL=https://apes-newsroom.test` and run
   `php artisan config:clear`.
3. Open <https://apes-newsroom.test>.

The relying party ID and allowed origin come from `APP_URL` at boot. Override
them only for unusual setups:

```env
# PASSKEYS_RP_ID=apes-newsroom.test
# PASSKEYS_ALLOWED_ORIGINS=https://apes-newsroom.test
```

With an `https://` `APP_URL` the app sends HSTS, so your browser then keeps
using HTTPS for `apes-newsroom.test`. To go back to plain HTTP, run
`herd unsecure apes-newsroom`, set `APP_URL` back and clear the name in
`chrome://net-internals/#hsts` (Edge: `edge://net-internals/#hsts`).

Chrome DevTools > More tools > WebAuthn can add a virtual authenticator if
you have no platform passkey. Automated tests use
`tests/Support/FakeAuthenticator.php`, which signs real assertions with a
throwaway P-256 key.

## Automated tests

```powershell
composer test
composer lint
npm run typecheck
npm run lint
npm run test:frontend
npm run build
```

Auth tests need no live services. `StaffOidcLoginTest` mocks the LDAP lookup,
`CloudronOidcProviderTest` signs ID tokens with a throwaway RSA key and fakes
the discovery, JWKS, token and userinfo endpoints, `LdapGroupLookupTest` uses
LdapRecord's directory emulator, and `LoginPageTest` checks the staff button
appears only when OIDC is configured.

## Debugging with Herd

The Herd app and its MCP server show the last exception (`herd site:error`),
the Laravel log (`herd logs:read`), slow and N+1 queries (`herd insights:analyze`),
`dump()` output and captured mail for the `apes-newsroom` site.

## Common fixes

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `php -v` shows 8.5 or newer, or Composer complains about the PHP version | The terminal uses Herd's global PHP | `. .\scripts\herd-env.ps1`, or use `herd php` and `herd composer` |
| `/health` or the preview gate reports another PHP version | The site is not pinned to 8.4 | `herd isolate 8.4 --site=apes-newsroom` |
| `apes-newsroom.test` shows Herd's 404 page | The site is not linked to this clone | `composer setup`, or `herd link apes-newsroom` from the repo folder; check with `herd links` |
| `apes-newsroom.test` does not open at all | The hosts entry is missing or Herd is stopped | Open Herd; `herd status`; restart Herd so its helper service rewrites the hosts entry |
| Port 80 or 443 in use | Another web server or IIS is running | Stop it (IIS: `iisreset /stop` in an elevated prompt), then restart Herd |
| MySQL will not start: port 3306 in use | Another MySQL or MariaDB is running | Stop it, then start the service in Herd > Services |
| `npm run dev` says port 5181 is in use | Another Vite for this repo is still running | Stop the old one (`herd processes:stop vite --site=apes-newsroom` or close its terminal); never change the port to dodge it, as other projects rely on theirs |
| Pages load without styles or scripts | `public/hot` points at a Vite that has stopped | Start `npm run dev`, or delete `public/hot` to use the built assets |
| `SQLSTATE[HY000] [1049] Unknown database` | The database was not created | Run `composer setup` (it creates `apes_newsroom_local`) |
| `SQLSTATE[HY000] [2002]` connection refused | Herd's MySQL service is stopped | Start it in Herd > Services |
| `cache: false` on `/health` | `CACHE_STORE=redis` but Herd's Redis service is stopped or missing | Start it in Herd > Services, or set `CACHE_STORE=database` |
| Demo images missing | `public/storage` link missing or GD off | `php artisan storage:link`; enable `extension=gd` with `herd ini 8.4`, then `herd restart php` |
| 502 Bad Gateway | A stale PHP process or nginx config | `herd isolate 8.4 --site=apes-newsroom` rewrites the config; otherwise `herd restart` |
| OIDC redirect mismatch | Wrong redirect URI on the client | Register `http://apes-newsroom.test/auth/cloudron/callback` exactly in Cloudron |
| Staff sign-in denied (no group) | `memberof` values do not match `config/rbac.php` | Run ldapsearch (see `docs/deployment.md`) and update the keys |
| Queue jobs not processing | Worker not running | Run `composer dev`, or `php artisan queue:work` |
