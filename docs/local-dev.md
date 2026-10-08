# Local development

Two modes: **default** (zero extra services) and **production-like**
(Redis, OIDC, LDAP). Everyday feature work and CI use the default.

## Default setup

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
composer dev    # or: php artisan serve + npm run dev
```

Uses SQLite with database-backed sessions, cache, and queue. No Docker
required. Seeding creates password demo users for local role preview
(see below). `DemoUsersSeeder` only runs when `APP_ENV` is `local` or
`testing`; deploys warn if any `@apes.local` account exists.

## Role preview (local only)

When `APP_ENV=local`, a floating **Local role preview** bar appears on
every page. Use it (or the one-click buttons on `/login`) to switch
sessions without Cloudron OIDC:

| Control | User | Default landing |
|---------|------|-----------------|
| Guest | logout | `/` |
| Public | `public@apes.local` | `/` |
| Staff | `staff@apes.local` | `/staff/posts` |
| Admin | `admin@apes.local` | `/admin/moderation` |
| Super admin | `superadmin@apes.local` | `/admin/moderation` |

Demo password for all seeded users: `password`. Only the public demo user
can sign in with it: staff-level accounts are refused password, magic-link
and password-reset sign-in (issue #276), so use the role preview for them.

Endpoints (`POST /_dev/login/{role}`, `POST /_dev/logout`) are registered
only when `APP_ENV=local`, and the controller also returns 404 outside
local. Do not enable this in staging or production.

```bash
php artisan migrate --seed
composer dev
# open http://localhost:8000 — use the floating switcher
```

## Production-like setup

Use this to verify Redis drivers, staff OIDC login, and LDAP role mapping
before deploying to beta.

### 1. Start optional services

```bash
docker compose up -d redis          # Redis only (most common)
docker compose up -d                # Redis + OpenLDAP
docker compose down
```

OpenLDAP is seeded from `docker/openldap/bootstrap.ldif` with test users
and groups matching `config/rbac.php`. The tracked Compose service uses the
image's `--copy-service` option so its startup scripts can safely process the
bind-mounted LDIF on Windows and Docker Desktop.

The fixture creates users before its `groupOfUniqueNames` groups. This order
and the groups' `uniqueMember` attributes match the image's enabled `memberOf`
overlay, which ignores membership references to users that do not yet exist.

### 2. Configure `.env`

Copy the commented block at the bottom of `.env.example` into your local
`.env` and uncomment the values you need.

**Redis:**

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Use `predis` on Windows if the PHP `redis` extension is not installed.
Cloudron uses `phpredis`.

**OIDC (Cloudron client for localhost):**

Create a **second** OpenID client in the Cloudron dashboard:

- Redirect URI: `http://localhost:8000/auth/cloudron/callback`

```env
APP_URL=http://localhost:8000
CLOUDRON_OIDC_DISCOVERY_URL=https://<cloudron-domain>/.well-known/openid-configuration
CLOUDRON_OIDC_ISSUER=https://<cloudron-domain>
CLOUDRON_OIDC_CLIENT_ID=<localhost-client-id>
CLOUDRON_OIDC_CLIENT_SECRET=<localhost-client-secret>
CLOUDRON_OIDC_PROVIDER_NAME=Cloudron
```

Sign-in uses PKCE (S256) and a nonce, and the callback verifies the ID
token against the discovery document's `jwks_uri`. `CLOUDRON_OIDC_ISSUER`
must match the token's `iss` claim (a trailing slash is ignored), and the
client ID must be in its `aud` claim.

**LDAP — option A: Cloudron directory**

Copy `CLOUDRON_LDAP_*` values from Cloudron `/app/data/credentials.txt`
into `.env`. Requires network access to the Cloudron LDAP host.

**LDAP — option B: Docker OpenLDAP**

```env
CLOUDRON_LDAP_URL=ldap://127.0.0.1:389
CLOUDRON_LDAP_USERS_BASE_DN=ou=users,dc=apes,dc=local
CLOUDRON_LDAP_GROUPS_BASE_DN=ou=groups,dc=apes,dc=local
CLOUDRON_LDAP_BIND_DN=cn=admin,dc=apes,dc=local
CLOUDRON_LDAP_BIND_PASSWORD=admin
```

Test users (password = username): `staffer@apes.local`, `admin@apes.local`,
`superadmin@apes.local`.

### 3. Run the app

```bash
# Terminal 1
composer dev

# Terminal 2 (required when QUEUE_CONNECTION=redis)
php artisan queue:work
```

### 4. Verify

```bash
php artisan deploy:preflight
curl http://localhost:8000/health
```

Expected health response when Redis is configured:

```json
{"status":"ok","checks":{"database":true,"cache":true}}
```

**Staff OIDC flow:**

1. Visit `http://localhost:8000/login`
2. Click the staff sign-in button
3. Authenticate at Cloudron OIDC
4. Callback reconciles LDAP groups and stores the session in Redis

## Automated tests

PHPUnit covers auth logic without live services:

```bash
composer test
```

`StaffOidcLoginTest` mocks LDAP lookup. `CloudronOidcProviderTest` signs
ID tokens with a throwaway RSA key and fakes the discovery, JWKS, token and
userinfo endpoints. `LdapGroupLookupTest` uses
LdapRecord's directory emulator. `LoginPageTest` verifies the staff
button appears only when OIDC is configured.

### Live OpenLDAP smoke test

The live integration test is intentionally outside the default PHPUnit suites,
so everyday development and CI do not require Docker. To rebuild only the
disposable OpenLDAP fixture from the tracked Compose configuration:

```powershell
docker compose -f docker-compose.yml config
docker compose -f docker-compose.yml stop openldap
docker compose -f docker-compose.yml rm -f openldap
docker compose -f docker-compose.yml up -d openldap
```

After OpenLDAP is ready, run the opt-in application-level test:

```powershell
$env:RUN_LIVE_LDAP_TESTS = '1'
php artisan test tests/Integration/LocalOpenLdapTest.php
Remove-Item Env:\RUN_LIVE_LDAP_TESTS
```

The test queries `LdapGroupLookup` for all three fixture users and requires the
staff, admin, and super-admin group DNs. To inspect only the staff result:

```powershell
$code = 'echo json_encode(app(\App\Services\Auth\LdapGroupLookup::class)->groupsForEmail("staffer@apes.local"));'
php artisan tinker --execute=$code
```

The expected result contains
`cn=newsroom-staff,ou=groups,dc=apes,dc=local`. Never use production LDAP
credentials for this fixture test.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `cache: false` on `/health` | Redis not running or wrong host | `docker compose up -d redis`; check `REDIS_HOST` |
| OIDC redirect mismatch | Wrong redirect URI on client | Register exact callback URL in Cloudron dashboard |
| Staff login denied (no group) | `memberof` values don't match `config/rbac.php` | Run ldapsearch (see `docs/deployment.md`) and update keys |
| OpenLDAP exits while loading the fixture on Windows | Container is not using the tracked copy-service behavior | Recreate only OpenLDAP with the commands in the live smoke-test section |
| LDAP connection timeout | Cloudron LDAP not reachable locally | Use Docker OpenLDAP (option B) |
| Queue jobs not processing | Worker not running | Start `php artisan queue:work` in a second terminal |
