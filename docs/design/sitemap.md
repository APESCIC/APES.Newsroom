# APES Newsroom — Sitemap (draft)

> **Status:** First-pass draft for stakeholder review (#2). Not approved for implementation sign-off.

## Public surfaces

| Path | Page | Notes |
|------|------|-------|
| `/` | Homepage | Featured + recent stories across all channels |
| `/apes-cic` | APES CIC channel | Mission-led landing, channel stories |
| `/apes-shelter-rescue` | Shelter & Rescue channel | Distinct accent, same layout system |
| `/apes-pet-care-clinic` | Pet Care Clinic channel | Distinct accent, same layout system |
| `/apes-times` | APES Times channel | Distinct accent, same layout system |
| `/myapes` | MyAPES channel (`/myapes-portal` redirects here) | Distinct accent, same layout system |
| `/stream-4-apes` | Stream 4 APES channel | Distinct accent, same layout system |
| `/{channel}/rss.xml` | Channel RSS feed | One per public channel |
| `/articles/{slug}` | Article | Long-form reading, SEO, comments/reactions counts |
| `/authors/{slug}` | Author | Staff/public author profiles where approved |
| `/search` | Search | Full-text across published content |
| `/category/{slug}` | Category archive | Filtered article list |
| `/tag/{slug}` | Tag archive | Filtered article list |
| `/archive/{year}` | Date archive | Year listing |
| `/archive/{year}/{month}` | Month archive | Month listing |
| `/rss.xml` | RSS feed | All channels or per-channel variants |
| `/sitemap.xml` | XML sitemap | Published content + news entries |
| `/login` | Login | Password, magic link, staff OIDC entry |
| `/register` | Register | Public account creation |
| `/mailing/preferences` | Preference centre | Signed links for non-account contacts |
| `/mailing/unsubscribe` | Unsubscribe | Per-list and unsubscribe-all |

## Authenticated surfaces (public accounts)

| Path | Page | Auth |
|------|------|------|
| `/account` | Profile & settings | Verified public account |
| `/account/export` | Data export | Verified public account |
| `/verify-email` | Email verification | Authenticated, unverified |

## Staff workspace

| Path | Page | Role |
|------|------|------|
| `/staff` | Staff dashboard | staff+ |
| `/staff/posts` | Draft list | staff+ (own drafts) |
| `/staff/posts/new` | New draft | staff+ |
| `/staff/posts/{id}/edit` | Editor.js workspace | staff+ (own) / admin+ (all) |
| `/staff/posts/{id}/preview` | Preview (noindex) | staff+ with access |

## Admin workspace

| Path | Page | Role |
|------|------|------|
| `/admin/review` | Review queue | admin+ |
| `/admin/campaigns` | Campaign management | admin+ |
| `/admin/moderation/profiles` | Profile moderation | admin+ |
| `/admin/moderation/comments` | Comment moderation | admin+ |

## Super admin

| Path | Page | Role |
|------|------|------|
| `/super-admin/settings` | Newsroom configuration | super_admin |
| `/super-admin/audit` | Audit log access | super_admin |

## Error pages

| Status | Page |
|--------|------|
| 404 | Not found — branded, helpful navigation |
| 410 | Gone — retired content |
| 429 | Rate limited |
| 500 | Server error — calm, no technical detail |

## Navigation hierarchy

```
Home
├── Channels (disclosure menu on desktop, grouped list on mobile)
│   ├── APES CIC
│   ├── Shelter & Rescue
│   ├── Pet Care Clinic
│   ├── APES Times
│   ├── MyAPES
│   └── Stream 4 APES
├── Search
├── About APES (static, optional v1)
└── Account / Login
    ├── Profile
    ├── Mailing preferences
    └── Staff sign-in (when OIDC configured)
```

Staff users see an additional **Workspace** entry in the header after authentication.

Which channels appear in the Channels menu and on the home page, and in what order, is set per newsletter under **Public channel** at `/staff/newsletters` (see [`docs/newsletters.md`](../newsletters.md)).
