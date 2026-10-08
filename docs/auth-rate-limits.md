# Auth and signup rate limits

Unauthenticated auth and signup endpoints are rate limited (issue #279).
Limits are defined in `AppServiceProvider::configureAuthRateLimits()` and
applied with `throttle:<name>` in `routes/auth.php` and `routes/web.php`.

| Limiter | Endpoint(s) | Limit |
|---------|-------------|-------|
| `magic-link` | `POST /login/magic-link` | 3 per minute per email, and 10 per minute per IP |
| `register` | `POST /register` | 5 per hour per IP |
| `forgot-password` | `POST /forgot-password` | 5 per minute per IP, on top of the password broker's per-email throttle (`auth.passwords.users.throttle`) |
| `mailing-signup` | `POST /mailing/signup`, `POST /newsletters/{slug}/signup` (including `?embed=1`) | 10 per minute per IP, shared across both routes |
| `oidc-callback` | `GET /auth/cloudron/callback` | 20 per minute per IP |

Limits that already existed before #279:

| Endpoint | Limit |
|----------|-------|
| `POST /login` | 5 failed attempts per email and IP, then locked out (`LoginRequest`) |
| Email verification link and resend | 6 per minute (`throttle:6,1`) |
| Content API | `NEWSROOM_CONTENT_API_RATE_PER_MINUTE` per IP (default 120) |
| Admin API | `NEWSROOM_ADMIN_API_RATE_PER_MINUTE` per token owner (default 60) |

## What users see

- **Form posts** (magic link, registration, forgot password, signups) are
  sent back to the form with an inline error on the email field:
  "Too many attempts. Please try again in N seconds."
- **Page loads** (the OIDC callback) show the standard 429 error page.
- **API and JSON requests** get a JSON 429 with a `Retry-After` header.

## Client IP depends on proxy trust

Per-IP limits use `$request->ip()`. On Cloudron that is only the visitor's
address when the proxy is trusted through `CLOUDRON_PROXY_IP`
(`bootstrap/app.php`). If that variable is missing, every request appears to
come from the proxy and the per-IP limits apply to the whole site at once.
After a deploy, check that two different visitors are not sharing a limit.

## Changing a limit

Edit the limit in `AppServiceProvider::configureAuthRateLimits()`, update
the table above, and adjust `tests/Feature/Auth/AuthRateLimitTest.php`.
