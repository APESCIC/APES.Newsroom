# REST APIs

Versioned JSON APIs for headless reads and editorial writes. Stack remains Laravel/PHP.

Base paths:

- Content API: `/api/content/v1/…`
- Admin API: `/api/admin/v1/…`

## Content API (v1)

### Authentication

A Content API key is required on every request. Admins manage **named keys** at `/admin/content-api-keys`:

- Each consumer gets its own key (`nr_content_…`), shown once when created. Only a SHA-256 hash and the first 15 characters are stored.
- Any number of keys can be active at once, so a key can be rotated by creating the new one, switching the consumer over, then revoking the old one.
- Revoking a key (with a reason) refuses it immediately. Creation and revocation need recent re-authentication and are audit-logged (`content_api_key.created`, `content_api_key.revoked`).

The single `NEWSROOM_CONTENT_API_KEY` environment key is still accepted as a fallback while integrations move to named keys. Remove it from the environment once nothing uses it.

Accepted headers (either):

- `X-Newsroom-Content-Key: <key>`
- `Authorization: Newsroom-Key <key>`

Missing or invalid key → `401`. No active named key and no environment key → `503`.

### Rate limits

Default: **120 requests per minute** per client IP (`throttle:content-api`). Override with `NEWSROOM_CONTENT_API_RATE_PER_MINUTE`.

### Resources

Only **published** posts and pages are returned. Drafts, in-review, scheduled-unpublished, and soft-deleted records are omitted.

**Body HTML** is included on show endpoints **only when `visibility` is `public`**. Members-only and paid resources appear as metadata with `gated: true` and `html: null` (same gating shape as the public site presenters). Internal tags are never listed.

| Method | Path | Notes |
|--------|------|--------|
| GET | `/api/content/v1/posts` | Paginated list (`per_page` 1–100, default 20). List items omit `html`. |
| GET | `/api/content/v1/posts/{slug}` | Single published post. |
| GET | `/api/content/v1/pages` | Paginated published pages. |
| GET | `/api/content/v1/pages/{slug}` | Single published page. |
| GET | `/api/content/v1/tags` | Public tags only (`is_internal=false`). |
| GET | `/api/content/v1/tags/{slug}` | Tag plus paginated published posts for that tag. |

### Pagination meta

List responses include:

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 0
  }
}
```

### Versioning

The `v1` path segment is the Content API contract. Breaking changes require a new major segment (`v2`). Additive fields may appear within `v1`.

## Admin API (v1)

### Authentication

Staff-owned bearer tokens (`Authorization: Bearer nr_admin_…`). Tokens are hashed at rest in `api_tokens`.

Admins manage tokens at **`/admin/api-tokens`**: create a token for an active staff account with a name, abilities and an expiry of 30, 90, 180 or 365 days (the plain token is shown once), and revoke it with a reason. Creation and revocation need recent re-authentication, are audit-logged, and the owner is emailed when a token is created. The API itself cannot create tokens (removed in #282).

Tokens are refused with `401` once revoked or expired, and with `403` while the owner's account is locked.

| Ability | Grants |
|---------|--------|
| `posts:read` | `GET /posts`, `GET /posts/{id}` |
| `posts:write` | `POST /posts`, `PATCH /posts/{id}` |
| `posts:publish` | `POST /posts/{id}/publish`, `POST /posts/{id}/unpublish` |

A request without the needed ability gets `403`. Tokens issued before v1.11.0 have no ability list and keep full access until revoked.

Artisan remains available on the server. On Cloudron, run artisan through the www-data wrapper (see [`deployment.md`](deployment.md#artisan-must-preserve-cloudron_-www-data)).

```bash
# Issue a token (plain token shown once); omit --ability for all abilities, --expires-days for no expiry
php artisan newsroom:issue-admin-api-token staff@example.com --name=ci --ability=posts:read --ability=posts:write --expires-days=90

# List tokens: id, name, owner, role, status, abilities, expiry, last used, created (never the secret)
php artisan newsroom:list-admin-api-tokens
php artisan newsroom:list-admin-api-tokens --user=staff@example.com

# Revoke (delete) by id, by name, by owner, or all (asks for confirmation unless --force)
php artisan newsroom:revoke-admin-api-token 12
php artisan newsroom:revoke-admin-api-token --name=ci --user=staff@example.com
php artisan newsroom:revoke-admin-api-token --user=staff@example.com
php artisan newsroom:revoke-admin-api-token --all --force
```

Token owner must be `Role::Staff` or higher. Missing/invalid token → `401`; owner below Staff → `403`. When `staff:reconcile-roles` demotes a user below Staff, their tokens are deleted.

### Rate limits

Default: **60 requests per minute** per authenticated user (`throttle:admin-api`). Override with `NEWSROOM_ADMIN_API_RATE_PER_MINUTE`.

### Authorization (mirrors staff UI)

| Action | Minimum role |
|--------|----------------|
| List/create/update posts | Staff (staff authors see own posts only; admin+ see all) |
| Publish / unpublish | Admin |

Writes run through `BlockValidator` and the same field rules as the staff editors (`PostPayloadRules` shape). Invalid Editor.js blocks return `422`.

### Endpoints

| Method | Path | Notes |
|--------|------|--------|
| GET | `/api/admin/v1/posts` | Paginated editorial list. |
| POST | `/api/admin/v1/posts` | Create draft post. |
| GET | `/api/admin/v1/posts/{id}` | Show post including Editor.js `content`. |
| PATCH | `/api/admin/v1/posts/{id}` | Update post (partial). |
| POST | `/api/admin/v1/posts/{id}/publish` | Publish (admin+). |
| POST | `/api/admin/v1/posts/{id}/unpublish` | Unpublish (admin+). |

## Outbound webhooks

Admins configure endpoints at `/admin/webhooks` (admin-only since v1.11.0; `/staff/webhooks` redirects there). Distinct from inbound Stripe webhooks.

### Events

- `post.published`
- `member.created` (first free membership provision)
- `subscription.updated` (Stripe-driven membership status changes)

### Signing

Each endpoint has a `whsec_…` secret. Deliveries POST JSON with headers:

- `X-Newsroom-Event`
- `X-Newsroom-Delivery` (UUID)
- `X-Newsroom-Signature: sha256=<hmac-sha256-hex of raw body>`

### Delivery

Queued (`DeliverWebhookJob`) with retries/backoff (up to 5 attempts). Failures never block publish or membership flows.
