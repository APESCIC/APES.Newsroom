# Local development with Laragon

**Laragon on Windows is the only supported local setup for APES Newsroom.**
The repo runs as its own Laragon project at <http://apes-newsroom.test>, with
its own MySQL database (`apes_newsroom_local`) and its own Vite port (5181),
so it can run beside other APESCIC projects. The project's Laragon settings
are in [docs/laragon/profile.md](laragon/profile.md).

| Part | What runs it | Address |
| --- | --- | --- |
| Web server | Laragon's Apache 2.4, serving `public/` | <http://apes-newsroom.test> |
| PHP | Laragon's PHP 8.4 (thread-safe build) | |
| Database | Laragon's MySQL 8.4, database `apes_newsroom_local` | `127.0.0.1:3306` |
| Live reload | Vite (`npm run dev`) | `http://127.0.0.1:5181`, written to `public/hot` |
| Mail | `MAIL_MAILER=log`, written to `storage/logs/laravel.log` (or Laragon's Mailpit, see below) | |

Tests use SQLite in memory (`phpunit.xml`), so `composer test` needs no
database.

## Prerequisites

- Windows 10 or 11 with [Laragon](https://laragon.org/download/) (Full),
  installed at the default `C:\laragon`, with Auto Virtual Hosts on (the
  default) and the `{name}.test` hostname pattern.
- Laragon's PHP 8.4 thread-safe build. If **Menu > PHP > Version** has no
  8.4, add it from the same menu.
- Laragon's Node.js 22 (`C:\laragon\bin\nodejs\node-v22`), the same major
  version as CI.
- Git.

## 1. Put the repo in its own Laragon project folder

Clone it straight into Laragon's document root:

```powershell
git clone https://github.com/APESCIC/APES.Newsroom.git C:\laragon\www\apes-newsroom
```

If you already have a clone elsewhere (for example from GitHub Desktop),
link it in instead of cloning twice:

```powershell
cmd /c mklink /J C:\laragon\www\apes-newsroom C:\Users\<you>\Documents\GitHub\APES.Newsroom
```

The folder name `apes-newsroom` gives the hostname `apes-newsroom.test`.

## 2. Apply the Laragon profile and start Laragon

1. Open Laragon and click **Start All** (Apache and MySQL).
2. Choose **Menu > Apache > Reload**. Auto Virtual Hosts creates the
   `apes-newsroom.test` site with `public/` as its document root and adds
   the hosts entry.
3. Check **Menu > PHP > Version** is 8.4. The setup script also installs a
   Laragon profile called `apes-newsroom` that pins PHP 8.4, MySQL 8.4 and
   Node.js 22. You can switch to it with **Menu > Profile > apes-newsroom**.
   A profile is global in Laragon, not per project; see
   [profile.md](laragon/profile.md#the-laragon-profile).

## 3. Run the setup script

From the repo folder, in Laragon's Terminal (**Menu > Laragon > Terminal**).
It has its own `PATH` with Laragon's PHP, Composer and Node.js, so it is
the safest place to run this and the other commands on this page:

```powershell
composer setup
# or: powershell -NoProfile -ExecutionPolicy Bypass -File scripts\laragon-setup.ps1
```

[`scripts/laragon-setup.ps1`](../scripts/laragon-setup.ps1) reads
[`docs/laragon/profile.json`](laragon/profile.json) and:

1. checks Laragon's PHP (and the PHP that Apache runs), PHP extensions,
   Composer, Node.js and MySQL, and warns clearly if anything is the wrong
   version or if the `php` or `node` on your `PATH` is not Laragon's;
2. checks the project folder and that `apes-newsroom.test` resolves;
3. installs the `apes-newsroom` Laragon profile if it is missing;
4. copies `.env.example` to `.env` if there is no `.env`, and generates
   `APP_KEY` if it is empty;
5. runs `composer install` and `npm ci` when they are needed;
6. creates the `apes_newsroom_local` database if it does not exist, links
   `public/storage` and runs the migrations;
7. offers to load the demo data.

It is safe to run again at any time, and it only touches this repo, the
`apes_newsroom_local` database and `C:\laragon\usr\profile\apes-newsroom.ini`.

| Switch | Does |
| --- | --- |
| `-Demo` / `-NoDemo` | Load or skip the demo data without asking |
| `-UpdateEnv` | If an existing `.env` has different `APP_URL` or `DB_*` values, back it up to `.env.backup-<time>` and set the profile values |
| `-LaragonRoot D:\laragon` | Laragon is not in `C:\laragon` |

Then open <http://apes-newsroom.test>.

## 4. Demo data

```powershell
composer demo:seed    # load the demo data (safe to run again, adds no duplicates)
composer demo:reset   # wipe apes_newsroom_local, migrate and load the demo data again
```

The data is fake, the same on every run (fixed seed), and only loads when
`APP_ENV=local`. A reset also refuses unless the database is MySQL on
`127.0.0.1` and its name ends in `_local`. What it contains and the test
logins are in [docs/laragon/demo-data.md](laragon/demo-data.md). Test new
work against it.

## 5. Live preview

```powershell
npm run dev     # Vite with hot reload on http://127.0.0.1:5181
composer dev    # Vite plus the queue worker (php artisan dev)
```

Apache serves the site, so there is no `php artisan serve`. While Vite runs,
`public/hot` points the pages at it: saving a React component or CSS file
updates the browser without a reload, and saving a Blade template, route or
PHP file reloads the page. When Vite stops, the site uses the last
`npm run build` output from `public/build`.

The app's Content Security Policy allows the Vite address only when
`APP_ENV=local` and `public/hot` exists, so production is unaffected.

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

Each APESCIC repo is its own Laragon project: its own folder in
`C:\laragon\www`, its own `<folder>.test` hostname, its own `_local`
database and its own Vite port. Laragon's Apache (ports 80 and 443) and MySQL
(3306) are shared. Laragon runs one PHP version for all sites at a time, and
every APESCIC Laravel repo accepts PHP 8.4, so keep Laragon on 8.4.

APES Newsroom's Vite port is 5181 with `strictPort`, so it fails loudly
instead of moving to another project's port. Ports are listed in
[profile.md](laragon/profile.md#ports-used-by-apescic-projects).

## Production-like testing (optional)

Use this to check Redis drivers, staff OIDC sign-in and LDAP role mapping
before a deploy.

**Redis.** Start Laragon's Redis (**Menu > Redis**). Laragon's PHP has no
`redis` extension, so use predis:

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Cloudron uses `phpredis`. With `QUEUE_CONNECTION=redis`, run `composer dev`
(or `php artisan queue:work`) so queued jobs are processed.

**Mail.** To read outgoing mail in a browser instead of the log, use
Laragon's Mailpit (inbox at <http://localhost:8025>):

```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

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

1. In Laragon choose **Menu > Apache > SSL > Enabled**, then
   **Menu > Apache > SSL > Add laragon.crt to Trust Store**.
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
using HTTPS for `apes-newsroom.test`. To go back to plain HTTP, set `APP_URL`
back and clear the name in `chrome://net-internals/#hsts` (Edge:
`edge://net-internals/#hsts`).

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

## Common fixes

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Setup warns that Apache runs another PHP version | Laragon's PHP is set to something other than 8.4 | **Menu > PHP > Version > php-8.4.x**, or **Menu > Profile > apes-newsroom**, then **Menu > Apache > Reload** |
| Setup warns that `php` or `node` on `PATH` is not Laragon's | Another PHP or Node.js install comes earlier on Windows `PATH`, so `composer`, `npm run dev` and `npm run build` use it instead of Laragon's PHP 8.4 and Node.js 22 | Run the commands from Laragon's Terminal (**Menu > Laragon > Terminal**). For other terminals and editors, choose **Menu > Tools > Path > Add Laragon to Path**; if `where.exe php` or `where.exe node` still lists another install first, remove that install from `PATH` |
| `apes-newsroom.test` does not open, or opens Laragon's start page | Virtual host or hosts entry not created yet | Check the folder is `C:\laragon\www\apes-newsroom`, then **Menu > Apache > Reload** (Laragon asks for admin rights to edit the hosts file) |
| Apache will not start: port 80 or 443 in use | Another web server or IIS is running | Stop the other server (or IIS: `iisreset /stop` in an elevated prompt), or change Laragon's port in **Menu > Preferences > Services & Ports** |
| MySQL will not start: port 3306 in use | Another MySQL or MariaDB is running | Stop it, then **Start All** in Laragon |
| `npm run dev` says port 5181 is in use | Another Vite for this repo is still running | Stop the old one (close its terminal); never change the port to dodge it, as other projects rely on theirs |
| Pages load without styles or scripts | `public/hot` points at a Vite that has stopped | Start `npm run dev`, or delete `public/hot` to use the built assets |
| `SQLSTATE[HY000] [1049] Unknown database` | The database was not created | Run `composer setup` (it creates `apes_newsroom_local`) |
| `SQLSTATE[HY000] [2002]` connection refused | MySQL is not running | **Start All** in Laragon |
| `cache: false` on `/health` | `CACHE_STORE=redis` but Laragon's Redis is stopped | **Menu > Redis > Start**, or set `CACHE_STORE=database` |
| Demo images missing | `public/storage` link missing or GD off | `php artisan storage:link`; **Menu > PHP > Extensions > gd** |
| "Permission denied" writing `storage/` or `bootstrap/cache` | The repo is in a protected folder or a file is read-only | Keep the repo under `C:\laragon\www` or your user folder, and clear read-only: `attrib -r /s storage\* bootstrap\cache\*` |
| OIDC redirect mismatch | Wrong redirect URI on the client | Register `http://apes-newsroom.test/auth/cloudron/callback` exactly in Cloudron |
| Staff sign-in denied (no group) | `memberof` values do not match `config/rbac.php` | Run ldapsearch (see `docs/deployment.md`) and update the keys |
| Queue jobs not processing | Worker not running | Run `composer dev`, or `php artisan queue:work` |
