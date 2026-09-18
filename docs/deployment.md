# Deploying to Cloudron (issue #3)

This documents the guarded deploy pipeline for the existing Cloudron LAMP app
(`74a2a784-a161-4787-84ff-2b8efc957bc8`). The watched beta deployment and
rollback drill completed on 2026-08-06 under
[#3](https://github.com/APESCIC/APES-Newsroom/issues/3#issuecomment-5208564154),
and the separately authorized production cutover completed under
[#11](https://github.com/APESCIC/APES-Newsroom/issues/11#issuecomment-5208865545).
The live app is `www.apesnews.org.uk`.

The workflow and scripts remain the operational runbook for future guarded
deployments. Treat credentials and production execution as separately
authorized operations even when following this document.

## How a release is structured on the server

```
/app/data/
  releases/
    <git-sha>-<run-id>/   one directory per deploy (run id avoids same-SHA collisions), immutable once activated
  shared/
    .env                  persists across every release (holds APP_KEY)
    storage/app/          user-uploaded files, persists across releases
    storage/logs/         persists across releases
  current -> releases/<git-sha>-<run-id>   atomic symlink; Apache serves current/public
  previous_release        text file: what `current` pointed to before the last activation
  run.sh                  starts the queue worker on every app boot/restart
  PHP_VERSION              "8.4"
  apache/app.conf          DocumentRoot -> /app/data/current/public
```

Activating a release is a symlink swap (`deploy/cloudron-activate.sh`), so
it is atomic from Apache's point of view. Rolling back
(`deploy/cloudron-rollback.sh`) swaps it back to whatever `previous_release`
recorded. Neither script deletes anything, so a bad release stays on disk
for inspection until manually cleaned up.

## One-time manual setup

These happen once, outside of CI, before the first deploy can work.

1. **Cloudron dashboard, this app's Location/SSO/etc.** are configured for the
   Newsroom at `www.apesnews.org.uk`. The former Ghost app remains stopped and
   recoverable at `ghost-legacy.apesnews.org.uk`. Do not retire it or change the
   unresolved apex DNS record without separate authorization.

2. **Pin PHP to 8.4** (issue #3 requires PHP 8.4; Cloudron LAMP defaults
   to 8.3):
   ```
   cloudron --server <fqdn> --token <token> push --app <app-id> deploy/PHP_VERSION /app/data/PHP_VERSION
   cloudron --server <fqdn> --token <token> restart --app <app-id>
   ```

3. **Point Apache at the releases symlink and disable phpMyAdmin.** Copy
   `deploy/cloudron-apache-app.conf` to `/app/data/apache/app.conf` (File
   Manager, or `cloudron push`), then restart the app. See that file's
   comments - phpMyAdmin's include is already commented out, satisfying
   the issue #3 acceptance criterion.

4. **Install the queue worker startup script.** Copy
   `deploy/cloudron-run.sh` to `/app/data/run.sh` (File Manager, or
   `cloudron push`). Runs on every app boot/restart.

5. **Add the scheduler cron entry.** In the Cloudron dashboard, this
   app's `Cron` section, add:
   - Schedule: `* * * * *`
   - Command: `bash /app/data/current/deploy/cloudron-www-data.sh /usr/bin/php /app/data/current/artisan schedule:run`

   Do **not** use bare `sudo -u www-data php …` — that strips `CLOUDRON_*`
   and artisan falls back to sqlite from the shared `.env` while the web
   app still uses MySQL. See `deploy/cloudron-www-data.sh`.

   (Cloudron's per-app Cron feature, not a crontab file - see
   docs.cloudron.io/apps#cron.)

6. **GitHub: protected `beta` environment.** Repo Settings -> Environments
   -> New environment `beta`. Add required reviewers (per issue #3's
   "protected GitHub deployment environment"). Add these environment
   secrets, scoped to `beta` only:
   - `CLOUDRON_FQDN` - the Cloudron instance's hostname
   - `CLOUDRON_TOKEN` - an API token from the Cloudron profile page, with
     the minimum scope needed for exec/push/restart/backup on this app
   - `CLOUDRON_APP_ID` - `74a2a784-a161-4787-84ff-2b8efc957bc8`
   - `CLOUDRON_APP_ORIGIN_HOST` - the beta hostname the health check
     should curl, e.g. `beta.apesnews.org.uk`

## What a deploy actually does

Trigger: Actions tab -> "Deploy to Cloudron (beta)" -> Run workflow, typing
`main` to confirm. Deliberately manual (see the workflow file's comment)
rather than automatic on every push, while the pipeline is unproven.

1. Verifies the input branch confirmation matches `main` and that the
   workflow is actually running against `main`.
2. Builds a production artifact in the runner: `composer install --no-dev
   --optimize-autoloader`, `npm ci && npm run build`. No dependency
   installation happens on the Cloudron box itself.
3. Assembles a clean `release/` directory (no `.git`, tests, `node_modules`,
   or `.env` - matches "do not build dependencies interactively in
   production" and "never commit credentials").
4. `cloudron backup create --app ...` - one blocking app-level backup
   before anything touches the server.
5. `cloudron sync push` uploads `release/` to
   `/app/data/releases/<short-sha>-<run-id>/` (the Actions run id keeps
   each deploy path unique so re-running against the same commit does not
   overwrite an already-activated tree whose `storage/app` is a symlink).
6. `cloudron exec ... deploy/cloudron-activate.sh <id>` - inside the
   container: links the persistent `.env` and `storage/app`/`storage/logs`
   into the new release, runs `artisan migrate --force`, caches
   config/routes/views, then atomically repoints `/app/data/current`.
7. `cloudron restart` - picks up the new code for both Apache and the
   queue worker (`run.sh` runs again on boot).
8. Polls `https://<beta-host>/health` for up to 60 seconds.
9. If activation, restart, or the health check fails: runs
   `deploy/cloudron-rollback.sh` (repoints `current` back to
   `previous_release`), restarts again, and fails the workflow loudly.
   The workflow does **not** assume rollback succeeded - it says so and
   expects a human to verify.

## Manual rollback

If a bad release is discovered after the fact (health looked fine but
something's actually broken):

```
cloudron --server <fqdn> --token <token> exec --app <app-id> -- \
  bash /app/data/current/deploy/cloudron-rollback.sh
cloudron --server <fqdn> --token <token> restart --app <app-id>
```

This only goes back one step (whatever `previous_release` recorded at the
last activation). Old release directories under `/app/data/releases/` are
never deleted automatically, so reactivating an older one is a manual
symlink edit inside `cloudron exec` if a deeper rollback is ever needed.

## Preflight command

`php artisan deploy:preflight --target=beta` checks git cleanliness (when
run from a checkout), required env vars, `APP_DEBUG` being off for
guarded targets, database/Redis connectivity, and pending migrations. It
never changes anything. It's a single `php artisan ...` invocation, so it
runs identically from PowerShell, cmd, or bash - no separate Windows
script to maintain. Run it locally against a non-production database
before trusting a new environment, and it's what
`DeployPreflightCommandTest` exercises in CI.

**Local run (2026-08-06):** preflight executed from dev checkout. Expected
failures without Cloudron credentials: uncommitted changes, `APP_DEBUG=true`,
database unreachable. See [`deployment-beta-acceptance.md`](deployment-beta-acceptance.md).

## Operational boundary

The same guarded release mechanics now support the live Newsroom, but this
document does not authorize running them. A future production deployment, DNS
or hostname change, live campaign send, or Ghost retirement requires its own
explicit approval and current preflight evidence. The apex `apesnews.org.uk`
record was not switched during the 2026-08-06 cutover.

## Redis addon

Enable Redis in the Cloudron dashboard for this LAMP app, then restart.
Cloudron injects `CLOUDRON_REDIS_*` at runtime; `CloudronEnvironmentServiceProvider`
switches cache, queue, and sessions to Redis automatically.

**Verify after restart:**

```bash
cloudron exec --app 74a2a784-a161-4787-84ff-2b8efc957bc8 -- printenv | grep CLOUDRON_REDIS
curl https://beta.apesnews.org.uk/health
```

Expected: `"cache": true` in the health response.

Ensure `/app/data/run.sh` exists (copy from `deploy/cloudron-run.sh`) so
the queue worker starts on boot against the Redis-backed queue.

## Artisan must preserve CLOUDRON_* (www-data)

Cloudron injects `CLOUDRON_MYSQL_*` / `CLOUDRON_REDIS_*` / SMTP vars into
the container environment. Apache/php-fpm sees them, so the web app uses
MySQL. Bare `sudo -u www-data …` **strips** those variables, so artisan
falls back to `DB_CONNECTION=sqlite` from the shared `.env` and can
silently migrate the wrong database.

Always run artisan as www-data via:

```bash
bash /app/data/current/deploy/cloudron-www-data.sh \
  /usr/bin/php /app/data/current/artisan <command>
```

`cloudron-activate.sh`, `cloudron-rollback.sh`, and `cloudron-run.sh` use
this helper. Scheduler cron must use it too (see one-time setup above).

**Symptom of the bug:** `/health` and `/login` OK; `/` and discovery routes
500 with `Table '….posts' doesn't exist` on MySQL while `migrate:status`
under `sudo -u www-data` looks clean against sqlite.

## OIDC client (staff sign-in)

LAMP apps do **not** auto-inject OIDC credentials. Create an OpenID
client manually in the Cloudron dashboard:

1. **Users & Groups → OpenID Clients → Add Client**
2. **Redirect URI (beta):** `https://beta.apesnews.org.uk/auth/cloudron/callback`
3. Scopes: `openid`, `email`, `profile`
4. Note the client ID, client secret, and Cloudron domain

Add to `/app/data/shared/.env` (never commit):

```env
CLOUDRON_OIDC_DISCOVERY_URL=https://<cloudron-domain>/.well-known/openid-configuration
CLOUDRON_OIDC_ISSUER=https://<cloudron-domain>
CLOUDRON_OIDC_CLIENT_ID=<client-id>
CLOUDRON_OIDC_CLIENT_SECRET=<client-secret>
CLOUDRON_OIDC_PROVIDER_NAME=Cloudron
```

Restart the app and run `php artisan config:cache` inside the container.
The login page shows a staff sign-in button once `CLOUDRON_OIDC_DISCOVERY_URL`
is set.

For local development, create a **second** client with redirect URI
`http://localhost:8000/auth/cloudron/callback` — see
[`docs/local-dev.md`](local-dev.md).

## LDAP group mapping

Staff roles are derived from each user's `memberof` attribute in Cloudron
LDAP. Live Cloudron groups are `newsroom.staff`, `newsroom.admin`, and
`newsroom.superadmin` (mapped in `config/rbac.php`). `memberof` returns
full DNs such as `cn=newsroom.staff,ou=groups,dc=cloudron`; the reconciler
matches the CN RDN as well as bare CNs used in local OpenLDAP.

**Discover group names** inside the Cloudron container:

```bash
cloudron exec --app 74a2a784-a161-4787-84ff-2b8efc957bc8 -- bash -c \
  'ldapsearch -x -H "$CLOUDRON_LDAP_URL" -D "$CLOUDRON_LDAP_BIND_DN" -w "$CLOUDRON_LDAP_BIND_PASSWORD" -b "$CLOUDRON_LDAP_GROUPS_BASE_DN" cn'
```

**Inspect a user's `memberof`:**

```bash
cloudron exec --app 74a2a784-a161-4787-84ff-2b8efc957bc8 -- bash -c \
  'ldapsearch -x -H "$CLOUDRON_LDAP_URL" -D "$CLOUDRON_LDAP_BIND_DN" -w "$CLOUDRON_LDAP_BIND_PASSWORD" -b "$CLOUDRON_LDAP_USERS_BASE_DN" "(mail=<user-email>)" memberof'
```

OIDC credentials for LAMP apps are not auto-injected. Prefer
`cloudron env set` for `CLOUDRON_OIDC_*` so `CloudronEnvironmentServiceProvider`
(which reads `getenv`) sees them after `config:cache`. Also keep the same
keys in `/app/data/shared/.env` if you maintain that file by hand.

## End-to-end verification (beta)

| Check | Expected |
|-------|----------|
| `GET /health` | `"cache": true`, `"database": true` |
| `deploy:preflight --target=beta` | Redis reachable |
| Visit `/login` | Staff sign-in button visible |
| Staff sign-in | Redirect to Cloudron OIDC |
| Login as group member | User created with correct role |
| Login as non-member | Denied on login page |
| Queue worker | Processing jobs via Redis |
