# APES Newsroom — Accessibility Notes

> **Status:** WCAG 2.2 AA launch evidence and named accessibility approval were
> accepted on 2026-08-06 in
> [issue #10](https://github.com/APESCIC/APES-Newsroom/issues/10#issuecomment-5208704103).
> The checklists below remain regression criteria for future changes rather
> than an indication that the launch gate is open.

## Global requirements

- [ ] Skip link to `#main-content` on every page
- [ ] Logical heading hierarchy (one `h1` per page)
- [ ] Landmarks: `header`, `nav`, `main`, `footer`
- [ ] Visible focus on all interactive elements
- [ ] 4.5:1 contrast for body text, 3:1 for large text
- [ ] No information conveyed by colour alone
- [ ] 200% zoom without horizontal scroll (reflow)
- [ ] 44×44px minimum touch targets
- [ ] Respect `prefers-reduced-motion`

## Public newsroom

| Surface | Checks |
|---------|--------|
| Homepage | Keyboard nav through featured cards; visible channel names provide link names; responsive artwork has alt text; recent publication dates use `time` |
| Channel pages | Channel accent does not reduce text contrast below AA |
| Article | Semantic article markup; image alt/caption/credit; readable line length |
| Search | Labelled input; results announced; empty state |
| RSS/sitemap | Machine-readable; no a11y impact on humans |

## Authentication

| Surface | Checks |
|---------|--------|
| Login/register | Labels associated with inputs; errors linked via `aria-describedby` |
| Magic link | Clear instructions; no account enumeration via UI |
| Staff OIDC | Accessible button; error messages readable |
| Email verification | Status messages announced |

## Editor.js workspace

| Surface | Checks |
|---------|--------|
| Block toolbar | Keyboard-operable; focus not trapped |
| Image blocks | Alt text required before save |
| Preview | Same renderer as production; `noindex` |

## TinyMCE workspace

| Surface | Checks |
|---------|--------|
| Editing area | Iframe titled "Article body editor. Press Alt+0 for keyboard help."; TinyMCE keyboard help on Alt+0 |
| Toolbar | Every button has an accessible name; menus and dialogs keyboard-operable (Alt+F10 to reach the toolbar) |
| Read-only blocks | `role="group"` with an `aria-label` such as "Callout block. Read-only here; edit it in Editor.js."; help text linked with `aria-describedby` |
| Images | Alternative description field offered; save fails without alt text |
| Editor change notice | `role="status"` when the site editor changes while a draft is open |

## Mailing preferences

| Surface | Checks |
|---------|--------|
| Signup | Unchecked list boxes; clear purpose labels |
| Preference centre | Per-list controls labelled; unsubscribe-all available |
| HTML email | Semantic structure; alt on hero; sufficient contrast |

## Moderation

| Surface | Checks |
|---------|--------|
| Queues | Persistent tab/panel relationships, roving tab focus, pressed queue summaries, keyboard-navigable tables/lists, and labelled actions |
| Rejection reasons | Visible to moderators only; not leaked publicly |

## Testing plan

1. Automated: axe-core in CI on key page templates
2. Manual keyboard: tab through all interactive flows
3. Screen reader: NVDA/VoiceOver smoke on login, article read, preference centre
4. Zoom: 200% on article and form pages

## Sign-off

- [x] Design review complete
- [x] Automated checks accepted for launch
- [x] Manual accessibility evidence accepted for launch
- [ ] A distinct screen-reader smoke run is not separately identified in the durable issue record
- [x] Named accessibility approver recorded: Bambie Murphy, APES CIC / repository owner, 2026-08-06

## Test evidence pointers (code-complete v1)

- Skip link present on homepage (`resources/js/Pages/home.tsx`)
- Branded error page `Errors/Show` for 404/410/429/500
- Engagement controls expose `aria-pressed` on reactions
- Feature tests cover public reading, engagement gates, and form validation
- Named legal/compliance and accessibility sign-off is recorded on issue #10.
  This repository record does not claim a separate third-party certification.
