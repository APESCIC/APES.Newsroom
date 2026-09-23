# REST APIs

Versioned JSON APIs for headless reads and (later) editorial writes. Stack remains Laravel/PHP.

Base paths:

- Content API: `/api/content/v1/…`
- Admin API: `/api/admin/v1/…` (documented when shipped)

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
