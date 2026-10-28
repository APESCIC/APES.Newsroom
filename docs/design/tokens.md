# APES Newsroom — design tokens

> **Status:** From v1.23.0 (#576) the public site and the staff and admin
> panels share one **Soft glass** palette; see
> [soft-glass-panels.md](soft-glass-panels.md) for the full token and component
> list. The dark public Direction C (#36/#55), Direction B Glass Studio
> (#145/#167) and Magazine Bold Grid (#53) are retired.

## Brand direction

Story-led conservation editorial: credible, calm and mission-led on public
pages, with focused glass operational workspaces for staff and administrators.
Use the supplied APES artwork as-is. Do not redraw, recolour, grayscale or
filter the marks.

## Colour

| Token | Value | Usage |
| --- | --- | --- |
| `--color-sg-ink` | `#0B2B2C` | Headings and body text |
| `--color-sg-muted` | `#40676A` | Secondary text |
| `--color-sg-teal` | `#006E70` | Buttons, links and active states |
| `--color-sg-teal-dark` | `#00585A` | Button hover |
| `--color-sg-accent` | `#00C4CC` | Decoration only (2.15:1 on white, so never text) |
| `--color-sg-base` | `#E8F6F6` | Page background under the gradient wash |
| `--color-sg-ok` / `-warn` / `-error` / `-info` | `#11613B` / `#8A4B0B` / `#A12828` / `#005B5D` | Badges and alerts, on their `-mist` tints |
| `--color-focus` | `#2563EB` | Visible focus rings |
| `--color-apes-primary` | `#2D6A4F` | APES CIC channel |
| `--color-shelter-accent` | `#BC6C25` | Shelter & Rescue borders and decorative icons |
| `--color-clinic-accent` | `#457B9D` | Pet Care Clinic borders and decorative icons |
| `--color-stream-accent` | `#8F6A08` | Stream 4 APES borders (darkened from `#B8860B` for 3:1 on the base) |
| `--color-shelter-text` | `#8A4B12` | Shelter & Rescue text and badges |
| `--color-clinic-text` | `#2F617D` | Pet Care Clinic text and badges |

The older names (`--color-brand-ink`, `--color-brand-teal`, `--color-teal-deep`,
`--color-body`, `--color-muted`, `--color-border` and the status colours) are
aliases of the Soft glass tokens, so existing utilities pick up the palette.
`resources/js/__tests__/soft-glass-contrast.test.ts` checks every text and UI
pair, including text on frosted cards over each gradient wash, and fails if the
accent is used as a text colour.

Never rely on channel or state colour without a text or icon
label. The text-specific Shelter token measures 5.76:1 on Shelter mist and
6.78:1 on white; the Clinic token measures 5.66:1 on Clinic mist and 6.72:1 on
white. These WCAG relative-luminance checks exceed the 4.5:1 AA requirement for
normal text, while the brighter accents remain available for non-text borders
and decorative icons.

## Typography

`Inter Variable` is self-hosted through Fontsource with a system sans-serif
fallback on every page (Instrument Sans was retired in v1.23.0); no remote
font request is required. Display titles
use the supported 700 weight and compact line height. Body and UI text use
400–600 weight, with 16px primary reading text and 14px minimum compact
metadata. Article measure is 65–72 characters.

## Public Soft glass classes (v1.23.0)

| Class | Purpose |
| --- | --- |
| `.public-shell` | Light page shell with the fixed gradient wash (shared with `.workspace-shell`) |
| `.public-header` | Frosted sticky header |
| `.public-nav-link` | Header and phone-menu links; the current page is a white pill with teal text |
| `.sg-card` | Frosted card for heroes, story cards and empty states |
| `.form-panel` | Frosted card with padding for forms and long-form reading |
| `.glass-channel` + `.glass-channel-*` | Channel card with its accent border |
| `.sg-btn` / `.button-primary` / `.button-secondary` | Pill buttons (teal primary, frosted secondary) |
| `.form-input` | Soft glass form field |
| `.sg-badge-*` / `.status-badge-*` | Status pills |
| `.text-link` | Teal link; underline it when it sits inside a sentence |
| `.eyebrow` / `.display-headline` | Teal uppercase label and ink hero headline |

## Page-shell mapping

- Public: `.public-shell` with a frosted header, frosted cards and a light footer.
- Staff/admin: `.workspace-shell` Soft glass panels (v1.20.0), same palette and wash.

## Spacing, radius and elevation

- 4px base grid; primary gaps are 16, 24, 32 and 48px.
- Public maximum width: 1200px; workspace maximum width: 1440px; article
  measure: 720px.
- Controls use 10px radius, normal cards 14px and feature surfaces 18px.
- Normal cards use a one-pixel border and no shadow. Elevated feature cards
  and menus may use `0 12px 32px rgba(6, 26, 26, 0.10)`.
- Friendly Guide emoji and chunky offset shadows are retired in Direction A.

## Focus, targets and motion

- Visible 2px `--color-focus` outline with at least 2px offset.
- Minimum interactive target: 44×44px.
- Motion is limited to subtle opacity or translate transitions.
- Disable non-essential motion for `prefers-reduced-motion`.
- Maintain WCAG 2.2 AA contrast and reflow at 200% zoom.

## Surface tokens

Plain custom properties on `:root`, swapped to near-solid white by the
`@supports not (backdrop-filter)` and `prefers-reduced-transparency` fallbacks.

| Token | Value | Usage |
| --- | --- | --- |
| `--sg-surface` | `rgba(255, 255, 255, 0.45)` | Cards and panels, blur 20px |
| `--sg-surface-strong` | `rgba(255, 255, 255, 0.72)` | Secondary buttons, article reading surface |
| `--sg-header-surface` | `rgba(255, 255, 255, 0.55)` | Public header |
| `--sg-rail-surface` | `rgba(255, 255, 255, 0.35)` | Panel sidebar, blur 24px |
| `--sg-edge` | `rgba(255, 255, 255, 0.85)` | 1px frosted edge |

## Editorial grid

- Channel cards remain three equal columns on desktop; single column on mobile.
- Recent stories use uniform frosted cards (no lead-column asymmetry).
- Grid reflows to single column below 768px for accessibility at 200% zoom.
