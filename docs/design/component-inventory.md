# APES Newsroom — Component inventory

> **Status:** Direction C (Glassmorphism Modern) public components under #55;
> Direction B Glass Studio workspace shell under #145/#167 (v1.6.0).
> Supersedes Magazine Bold Grid (#53).

## Direction A layout and content

| Component | Description | States |
| --- | --- | --- |
| `PublicLayout` | Gradient shell with frosted glass sticky header, skip target, channels, Search, Account, page canvas and glass footer; mobile disclosure behaviour unchanged | guest, signed-in, mobile disclosure |
| `SiteFooter` | Glass panel footer with 64px brand mark and legal/mailing links | default |
| `DeskPanel` | Featured story in `.glass-hero` with white/teal typography | empty, populated |
| `ChannelTrailTile` | Channel entry card with `.glass-channel` tinted glass overlay | default, focus |
| `RecentStoryCard` | Glass story card with channel label, title, excerpt, hero image | default, missing metadata |
| `AccountLayout` | Public gradient wrapper with centred `.glass-form-panel` | default, status messages |
| `AuthCard` | Gradient shell with compact logo header and `.glass-form-panel` | login, register, password reset |
| `WorkspaceLayout` | Glass Studio shell: teal gradient, frosted rail and task chrome, near-opaque canvas, measured skip-link offset and scrollable modal mobile drawer with contained focus, inert and scroll-locked background, and automatic close to the active desktop destination at the desktop breakpoint | staff, admin, wrapped task header, short viewport, breakpoint change |
| `LineIcon` | First-party current-colour line icons without emoji dependencies | decorative |
| `ApesLogo` | Unfiltered horizontal, masthead, footer, square, or compact APES artwork; the masthead uses a deterministic tight crop, the footer uses a 64px derivative, and square placement uses 384/768 WebP sources with PNG fallback | per placement, responsive |

## Staff posts

| Component | Description | States |
| --- | --- | --- |
| `PostsFilter` | All, In review, and Published navigation | active filter |
| `PostsTable` | Title edit link, labelled status, channel and updated date | desktop, empty |
| Mobile post cards | The same source-backed post fields in narrow layouts | mobile, empty |
| `PostStatusBadge` | Text-labelled post state | draft, in review, published, fallback |

## Admin moderation

| Component | Description | States |
| --- | --- | --- |
| `ModerationSummaryCard` | Pressed-state button and count for one source-backed queue | default, active |
| `ModerationQueueTabs` | Profiles, comments, reports, and suspended selectors with persistent labelled panels and roving focus | click, Arrow keys, Home/End |
| `ModerationRecordCard` | Source-backed context and existing record actions | per queue, empty |

## Existing and later epic surfaces

| Area | Components |
| --- | --- |
| Publishing | `EditorWorkspace`, `ReviewPanel`, `SeoFields`, `SchedulePicker` |
| Articles | `ArticleBody`, `AuthorByline`, `TagPill`, `Pagination`, `ChannelHero` |
| Authentication | Labelled inputs, buttons, validation errors, `AuthCard` |
| Engagement | `ReactionBar`, `CommentForm`, `CommentList`, `ProfileCard` |
| Email | `CampaignEmail` preview and test-send states |
| Feedback | `EmptyState`, branded error pages, toast messages |

Tokens from [`tokens.md`](tokens.md) map to Tailwind theme variables and
shared component classes in `resources/css/app.css`.
