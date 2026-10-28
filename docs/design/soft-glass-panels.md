# Soft glass staff and admin panels (v1.20.0)

> **Status:** Shipped for the staff and admin panels in v1.20.0 under
> [#513](https://github.com/APESCIC/APES.Newsroom/issues/513), and for the
> public site in v1.23.0 under [#576](https://github.com/APESCIC/APES.Newsroom/issues/576).
> Design 5 (Soft glass), chosen by Bambie on 26 Oct 2026. Replaces the
> Direction B Glass Studio workspace chrome and the dark public Direction C.

The tokens, surfaces, buttons, form fields, cards and badges below are global.
The shell, sidebar and panel components are specific to `.workspace-shell`; the
public site uses `.public-shell`, described in [tokens.md](tokens.md).

## Tokens

Defined in `resources/css/app.css` (`@theme`), usable as Tailwind utilities.

| Token | Value | Use |
| --- | --- | --- |
| `--color-sg-ink` | `#0B2B2C` | Body text and headings |
| `--color-sg-muted` | `#40676A` | Secondary text and nav group headings |
| `--color-sg-teal` | `#006E70` | Primary buttons, active nav text, links |
| `--color-sg-accent` | `#00C4CC` | Focus glow and highlights only (not text) |
| `--color-sg-base` | `#E8F6F6` | Page background under the gradients |
| `--color-sg-ok` / `-mist` | `#11613B` / `#DCF5E7` | Green badges |
| `--color-sg-warn` / `-mist` | `#8A4B0B` / `#FDEFD3` | Amber badges |
| `--color-sg-error` / `-mist` | `#A12828` / `#FDE4E4` | Red badges, destructive actions |
| `--color-sg-info` / `-mist` | `#005B5D` / `#D5F4F5` | Teal badges |
| `--radius-sg-card` / `-rail` / `-field` | 20px / 22px / 12px | Cards, sidebar, inputs |
| `--shadow-sg-card` | `0 8px 30px rgba(0,83,90,.08)` | Cards and tiles |
| `--shadow-sg-primary` | `0 6px 18px rgba(0,110,112,.27)` | Primary buttons |
| `--container-panel` | 70rem (1120px) | Content width on every page |
| `--font-panel` | Inter Variable (self-hosted) | Panel type, 14px body, 28px titles |

Surfaces are custom properties on `:root` so fallbacks can swap them in one
place: `--sg-surface` (cards, white 45%, blur 20px), `--sg-rail-surface`
(sidebar, white 35%, blur 24px), `--sg-surface-strong` (secondary buttons,
pressed cards), `--sg-header-surface` (public header) and `--sg-edge` (1px
white border).

The older `--color-body`, `--color-muted`, `--color-border`,
`--color-teal-deep`, `--color-brand-*` and status colours are aliases of the
Soft glass palette everywhere, so older `text-muted` / `border-border`
utilities match on both ends.

### Fallbacks

- No `backdrop-filter` support: `@supports not (backdrop-filter…)` makes the
  surfaces 90–100% opaque white.
- `prefers-reduced-transparency: reduce`: the same solid surfaces and no blur.
- `prefers-reduced-motion: reduce`: transitions are removed (global rule).

Both fallbacks are checked in `tests/e2e/panels.spec.ts` and
`tests/e2e/public.spec.ts`.

## Shell (`WorkspaceLayout`)

- Floating frosted sidebar 16px from the window edge, 236px wide, with the
  APES badge and "APES Newsroom" wordmark, a Staff/Admin switch (admins only),
  grouped navigation and the user card with Account and Sign out.
- Navigation is defined once in `resources/js/Components/Workspace/navigation.ts`
  (groups, Lucide icons, role checks). The command palette reads the same list.
- Top bar inside the content column: breadcrumb (Panel › Section › Page),
  frosted search pill (Ctrl K / ⌘K), View site, then the page title, subtitle
  and the page's main actions. It is not sticky, so it never overlaps content.
- The page background is a fixed layer, so it covers pages of any length.
- One `<main id="main-content">` per page, owned by the layout. Flash messages
  render once, as a toast.
- Below 1024px the sidebar becomes a slide-over drawer with focus trapped,
  Escape to close and the background inert.

The Dashboard nav item arrives with the dashboards in v1.21.0 (#351, #352).

## Components (`resources/js/Components/Workspace/ui`)

| Component | Notes |
| --- | --- |
| `GlassCard` | Frosted surface with optional title, description and actions |
| `StatTile`, `StatGrid` | Number tiles, two per row on phones, four on desktop |
| `PillButton`, `pillClass` | `primary`, `secondary`, `danger`, `ghost`; renders a link when given `href` |
| `Badge` | `ok`, `warning`, `error`, `info`, `neutral` |
| `Tabs` | Pill tabs for filter links (`aria-current`) or in-page toggles (`aria-pressed`) |
| `DataTable` | Table in a card; rows become labelled stacked cards below 768px |
| `Pagination` | Previous / Page x of y / Next for Laravel paginators |
| `EmptyState` | Icon, title, description and optional action |
| `ConfirmDialog` | Modal `alertdialog`, Cancel focused first |
| `ActionMenu` | Overflow menu; arrow keys, Escape and outside click close it |
| `FormField`, `FormSection` | Labelled control with hint and error wiring; titled form card |
| `Toast` | Dismissible status message for flash text |
| `CommandPalette` | Ctrl/⌘ K; pages and quick actions filtered by role |

Rules:

- Destructive actions (delete, remove, disable, revoke) sit in an `ActionMenu`
  or open a `ConfirmDialog`; they are never a bare button beside Edit or Save.
  Admin → Releases keeps its existing delete link because v1.22.0 removes the
  page.
- Use `sg-*` classes or these components, not one-off colours. Legacy
  `button-*`, `form-input`, `status-badge-*` and `workspace-glass-*` classes are
  restyled inside the shell for compatibility.
- Icons are Lucide, 16px in buttons and 18px in the sidebar, always
  `aria-hidden` next to visible text.

## Accessibility

- axe (WCAG 2.0/2.1 A and AA) reports no serious or critical issues on any
  staff or admin page; this runs in CI on every pull request.
- Contrast on white: ink 15.0:1, muted 6.2:1, teal 6.1:1 (white on teal also
  6.1:1). The spec's `#5A7F81` nav-heading colour measured about 3.6:1 on the
  frosted sidebar, so headings use the muted colour instead.
- Focus stays visible (2px blue outline) on every control.
- The command palette is a modal dialog with a combobox and listbox,
  `aria-activedescendant`, a focus trap and Escape to close.

## Visual regression

`tests/e2e/panels.spec.ts` takes a screenshot of every staff and admin page
(plus the phone layout, drawer and command palette), and
`tests/e2e/public.spec.ts` does the same for the public home, channel,
article, search, signup, login, account and 404 pages and the phone menu. Both
compare against baselines in `tests/e2e/__screenshots__/linux`, run axe, and
check there is no sideways scrolling at 375, 768 and 1440px. The public spec
also checks visible keyboard focus, the skip link, reduced transparency and
the channel accent borders. Data comes from
`Database\Seeders\VisualRegressionSeeder` on a fresh SQLite database.

Set `CAPTURE_DIR` (and optionally `CAPTURE_ONLY=public` or `panels`) and run
`npx playwright test capture` to save desktop and phone screenshots for
before-and-after evidence on a pull request.

Run locally against a seeded app on port 8012:

```bash
php artisan migrate:fresh --seeder='Database\Seeders\VisualRegressionSeeder'
php artisan serve --port=8012
npm run test:e2e
```

Screenshots only match on Linux; on other systems run the axe and layout
checks with `npx playwright test panels public --grep-invert screenshot`. To refresh
baselines after an intended change, download the `playwright-results`
artifact from CI and commit `tests/e2e/__screenshots__/linux`.
