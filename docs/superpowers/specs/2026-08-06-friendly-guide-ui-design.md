# Friendly Guide UI — Design Spec

> **Historical:** This #27 specification is retained for provenance but was
> superseded by the stakeholder-approved [Direction A system](../../design/direction-a-ui.md)
> under #31 and #32.

> **Status:** Approved for implementation planning (stakeholder choice 2026-08-06)  
> **Issue:** [#27](https://github.com/APESCIC/APES-Newsroom/issues/27)  
> **Relates to:** #1, closed design baseline #2  
> **Branch:** `cursor/feature/27-friendly-guide-ui`

## Goal

Refresh the public newsroom chrome and homepage with a fun, graphical **Friendly Guide** personality, and expose **Admin** (and Staff desk) via the authenticated **Account menu** for eligible roles only.

## Decisions (locked)

| Decision | Choice |
|----------|--------|
| Visual personality | **Friendly Guide** — trail tiles, chunky offset shadows, approachable channel cues |
| Homepage layout | **Split desk** — channels left; “On the desk” story stack right |
| Admin entry | **Account menu** — not a persistent header Admin button |
| Brand colours | Keep existing APES / Shelter / Clinic accents from `docs/design/tokens.md` |

Rejected alternatives (for the record): Living Habitat gradient hero; Story Billboard poster blocks; trails-first or story-led homepage; always-visible Admin header button; dual Staff+Admin header buttons.

## Personality principles

1. **Playful but mission-led** — graphical tiles and shadows; no carnival clutter (no pill clusters, stat strips, or floating promo badges on heroes).
2. **Channel as trails** — each channel is a tappable “trail” tile with icon, label, short hint, accent border, and offset shadow matching that channel’s colour.
3. **Newsroom desk metaphor** — the homepage right column is labelled **On the desk**: featured story first, then recent items with channel badges.
4. **Calm reading elsewhere** — article long-form stays high-contrast and quiet; Friendly Guide energy lives mainly in chrome, homepage, and channel landings.
5. **Respect reduced motion** — shadow/hover motion is optional and disabled when `prefers-reduced-motion: reduce`.

## Colour & type (delta on existing tokens)

Retain current tokens in `docs/design/tokens.md`. Add Friendly Guide surface tokens:

| Token | Suggested value | Usage |
|-------|-----------------|-------|
| `--color-page-tint` | `#F7FBF9` | Page background (soft green tint, not flat grey) |
| `--shadow-chunky` | `4px 4px 0 var(--accent)` | Trail tiles and desk panel (accent = channel or neutral-900) |
| `--radius-tile` | `1rem` (16px) | Trail tiles / desk panel |
| `--font-display` | Instrument Sans (existing `--font-sans`) at heavier weights | UI titles; avoid decorative novelty fonts |

Do **not** introduce purple gradients, cream+terracotta editorial kitsch, or broadsheet hairline newspaper layouts.

Channel accents (unchanged):

- APES CIC: `#2D6A4F`
- Shelter & Rescue: `#BC6C25`
- Pet Care Clinic: `#457B9D`

## Layout — homepage (desktop)

```
┌─────────────────────────────────────────────────────────┐
│ [A] APES Newsroom              Search · You ▾           │
├──────────────┬──────────────────────────────────────────┤
│ 🌳 APES CIC  │  ON THE DESK                             │
│ Mission…     │  Featured title + excerpt + CTA          │
├──────────────┤  ─────────────────────────────────────── │
│ 🏠 Shelter   │  [Clinic] recent…                        │
├──────────────┤  [Shelter] recent…                       │
│ 💉 Clinic    │  [CIC] recent…                           │
└──────────────┴──────────────────────────────────────────┘
```

- **Grid:** ~2fr / 3fr (channels / desk), gap `--space-4`–`--space-6`, max width consistent with current `max-w-5xl` or slightly wider if needed for the two-pane desk.
- **Trail tiles:** full-height stack; each links to `/{channel_slug}`; keyboard-focusable; 44×44px minimum hit area (tile itself is large).
- **Desk panel:** white surface, 2px border `--color-neutral-900` (or primary), chunky shadow; featured story; dashed divider; recent list with coloured channel badges (non-colour cue: badge text + position).

### Mobile / tablet

- Stack: header → desk (featured + recent) → trail tiles as a horizontal scroll or 3-up wrap (prefer wrap for accessibility over hidden horizontal scroll).
- Account menu remains a disclosure button (not hover-only).

## Shared chrome

### Header

- Brand mark: square rounded “A” tile in `--color-apes-primary` + wordmark **APES Newsroom**.
- Public links: Search (and optional channel links if space; otherwise channels live on homepage trails).
- Auth:
  - Guest: **Login** / **Register**.
  - Signed-in: **You ▾** (or display name) disclosure menu.

### Account menu contents

| Item | Visible when |
|------|----------------|
| Profile / Account | Any authenticated user |
| **Admin panel** | `admin` → primary admin entry (e.g. `/admin/moderation` or agreed admin hub) |
| **Staff desk** | `staff` or `admin` → `/staff` or `/staff/posts` |

> **Historic note:** this table originally also named `super_admin`. That role was merged into `admin` in v1.28.0 (#713); do not treat it as a live Newsroom role.
| Sign out | Any authenticated user |

Rules:

- Never show Admin or Staff links to `public`-only users.
- Menu must work with keyboard (Escape closes; arrow/tab within).
- Prefer one Admin destination that lands on a sensible hub; deep links to campaigns/moderation live inside admin UI.

### Footer

Unchanged legal/mailing links; optional quiet Staff link is **not** required if Account menu covers it.

## Components to add / extend

| Component | Responsibility |
|-----------|----------------|
| `AppShell` / shared layout | Header, footer, page tint, skip link |
| `AccountMenu` | Role-gated Admin + Staff desk items |
| `ChannelTrailTile` | Accent border, icon, label, chunky shadow, link |
| `DeskPanel` | “On the desk” container |
| `StoryDeskItem` | Featured + list row with channel badge |

Reuse existing Inertia page props for featured/recent/channels where possible (`resources/js/Pages/home.tsx`).

## Surfaces in scope (v1 of this refresh)

1. Shared public header/footer (all public pages that currently inline chrome).
2. Homepage split-desk layout.
3. Account menu Admin + Staff links (role-gated).
4. Light token/CSS updates for page tint and chunky shadow utility.

### Explicitly deferred

- Full redesign of article typography, Editor.js workspace, moderation tables, email HTML.
- Custom illustrated SVG mascots (emoji/icon placeholders OK until brand assets land).
- Channel landing page redesign beyond accent consistency.

## Accessibility

- WCAG 2.2 AA contrast on tinted backgrounds and channel badges.
- Visible focus rings (`--color-focus`); never remove outlines on tiles/menu.
- Account menu: `button` + `aria-expanded` / `aria-controls`; focus trap optional if overlay; otherwise natural tab order.
- Touch targets ≥ 44×44px.
- Channel meaning not by colour alone (icon + label + badge text).
- Honour `prefers-reduced-motion`.

## Success criteria

Matches issue #27 acceptance criteria: Friendly Guide chrome, split-desk homepage, role-gated Account-menu Admin/Staff, tokens updated, this spec followed.

## Open implementation notes (resolve in plan, not redesign)

1. Exact Admin hub URL if multiple `/admin/*` routes exist (pick one default).
2. Whether channel icons are emoji, Lucide/SVG, or brand assets.
3. Extract shared layout from duplicated page headers vs homepage-only first pass.
