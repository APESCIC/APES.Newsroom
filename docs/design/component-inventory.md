# APES Newsroom — Component inventory

> **Status:** Public components use the Soft glass palette from v1.23.0
> (#576); the staff and admin panels have used it since v1.20.0 (#513). Both
> are listed in [soft-glass-panels.md](soft-glass-panels.md). The Direction B
> workspace rows below are historical. Supersedes Magazine Bold Grid (#53).

## Direction A layout and content

| Component | Description | States |
| --- | --- | --- |
| `PublicLayout` | `.public-shell` with a frosted sticky `.public-header`, skip target, pill nav links, Channels menu, Search, Account and the light footer; mobile disclosure behaviour unchanged | guest, signed-in, mobile disclosure |
| `SiteFooter` | Light frosted panels with teal pills, 64px brand mark and legal/mailing links (#583) | default |
| `DeskPanel` | Featured story in an `.sg-card` hero with ink headline and teal eyebrow | empty, populated |
| `ChannelSection` | Channel card with `.glass-channel` frosted surface and accent border | default, focus |
| `RecentStoryCard` | `.sg-card` story card with channel badge, title, excerpt, hero image | default, missing metadata |
| `AccountLayout` | Public shell with a centred `.form-panel` | default, status messages |
| `AuthCard` | Public shell with compact logo header and `.form-panel` | login, register, password reset |
| `Errors/Show` | Public error page: frosted header, centred card, home and search buttons (#584) | 403, 404, 419, 500, 503 |
| `WorkspaceLayout` | Glass Studio shell: teal gradient, frosted rail and task chrome, near-opaque canvas, measured skip-link offset and scrollable modal mobile drawer with contained focus, inert and scroll-locked background, and automatic close to the active desktop destination at the desktop breakpoint | staff, admin, wrapped task header, short viewport, breakpoint change |
| `LineIcon` | First-party current-colour line icons without emoji dependencies | decorative |
| `ApesLogo` | Unfiltered horizontal, masthead, footer, square, or compact APES artwork; the masthead is a transparent-background tight crop of the square artwork at 3x header height, the footer uses a 64px derivative, and square placement uses 384/768 WebP sources with PNG fallback | per placement, responsive |

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
