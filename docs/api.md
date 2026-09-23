# REST APIs

Versioned JSON APIs for headless reads and editorial writes. Stack remains Laravel/PHP.

Base paths:

- Content API: `/api/content/v1/…`
- Admin API: `/api/admin/v1/…`

## Content API (v1)

### Authentication

Site-level Content API key required on every request. Configure via `NEWSROOM_CONTENT_API_KEY`.

Accepted headers (either):

- `X-Newsroom-Content-Key: <key>`
- `Authorization: Newsroom-Key <key>`

Missing or invalid key → `401`. Empty/unconfigured key → `503`.

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

Mint a bootstrap token:

```bash
php artisan newsroom:issue-admin-api-token staff@example.com --name=ci
```

Additional tokens: `POST /api/admin/v1/tokens` with an existing bearer token and JSON `{ "name": "label" }` (plain token returned once).

Token owner must be `Role::Staff` or higher. Missing/invalid token → `401`.

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
| POST | `/api/admin/v1/tokens` | Issue another token for the authenticated staff user. |
| GET | `/api/admin/v1/posts` | Paginated editorial list. |
| POST | `/api/admin/v1/posts` | Create draft post. |
| GET | `/api/admin/v1/posts/{id}` | Show post including Editor.js `content`. |
| PATCH | `/api/admin/v1/posts/{id}` | Update post (partial). |
| POST | `/api/admin/v1/posts/{id}/publish` | Publish (admin+). |
| POST | `/api/admin/v1/posts/{id}/unpublish` | Unpublish (admin+). |
