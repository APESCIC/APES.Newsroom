# Public search ranking

Site search (`GET /search?q=`) covers **published** posts only (public `Post::published()` scope). Drafts, scheduled-future, and staff-only content never appear.

## Indexed fields

| Field | Source |
|-------|--------|
| `title` | Post title |
| `excerpt` | Post excerpt |
| `body_text` | Denormalized plain text extracted from Editor.js `content` blocks on save |

`body_text` is refreshed whenever a post is created or updated (including Ghost import). A migration backfills existing rows.

## Relevance order

Matches use case-insensitive `LIKE` (SQLite and MySQL). Results are ordered by:

1. **Title** match (highest)
2. **Excerpt** match
3. **Body** (`body_text`) match (lowest among field tiers)
4. Then **`published_at` descending** within the same tier

Limit: 20 results per query.
