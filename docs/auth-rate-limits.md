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

Signed-in account security endpoints (v1.9.0, epic #263):

| Limiter | Endpoint(s) | Limit |
|---------|-------------|-------|
| `password-change` | `PUT /account/password` | 5 per minute per user |
| `reauth` | `POST /account/confirm-identity/password`, `POST /account/confirm-identity/code/send`, `POST /account/confirm-identity/code` | 5 per minute per user, and 20 per minute per IP |
| `email-change` | `POST /account/email/resend` | 3 per minute per user, and 10 per minute per IP |
| `email-change-link` | `GET` and `POST /account/email-change/{id}/confirm` and `/cancel` (signed links) | 10 per minute per IP |

Multi-factor endpoints (v1.10.0):

| Limiter | Endpoint(s) | Limit |
|---------|-------------|-------|
| `two-factor` | `POST /two-factor-challenge` | 5 per minute per pending user, and 20 per minute per IP. Separately, 5 wrong codes abandon the pending sign-in (`PendingTwoFactorLogin::MAX_ATTEMPTS`) |
| `two-factor-setup` | `POST /account/two-factor/confirm` | 5 per minute per user |
| `passkey-manage` | `POST /account/passkeys/options`, `POST /account/passkeys` | 10 per minute per user. Each account can hold at most 20 passkeys (`PasskeyController::MAX_PASSKEYS`) |

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
