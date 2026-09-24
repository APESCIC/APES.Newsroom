# Direction B UI — Glass Studio (workspaces)

Status: stakeholder-approved as Direction B for authenticated workspaces on
2026-09-21 under [issue #145](https://github.com/APESCIC/APES-Newsroom/issues/145)
(task [#167](https://github.com/APESCIC/APES-Newsroom/issues/167)). Milestone
**v1.6.0**.

> **Naming:** This Direction B is **Glass Studio** for staff/admin chrome.
> It is **not** Magazine Bold Grid (#53), which was superseded by public
> Direction C and remains retired.

## Selected character

Glass Studio extends the public Direction C glass tokens into authenticated
`WorkspaceLayout` (and pages that use it). Staff and admin shells sit on the
shared teal gradient with frosted glass rail, task header, and mobile chrome.
Public Direction C marketing surfaces stay unchanged.

The supplied APES logo files remain unchanged without filters or recolouring. No
frog mascot artwork is included.

## Page decisions

### Workspace shell

- `.workspace-gradient-shell` uses `--gradient-public` (same teal stack as public).
- `.workspace-glass-rail` frosted sidebar / mobile drawer (on-glass type).
- `.workspace-glass-chrome` higher-opacity frosted task header and mobile top bar
  so dark text and existing primary/secondary buttons stay readable.
- `.workspace-canvas` opaque / near-opaque work surface for page bodies (tables,
  forms, moderation queues) on the gradient.

### Reused public tokens

`--gradient-public`, `--glass-surface`, `--glass-border`, `--glass-blur`, and the
existing `@supports` / `prefers-reduced-motion` solid fallbacks (extended to the
workspace classes).

## Accessibility

- Solid fallbacks when `backdrop-filter` is unsupported or
  `prefers-reduced-motion` is set.
- WCAG 2.2 AA contrast on canvas and chrome; 44×44 targets; visible
  `--color-focus` rings.
- Skip link, inert drawer background, focus trap, and breakpoint close behaviour
  preserved.

## Out of scope

Production cutover; public homepage redesign; Ghost import; Magazine Bold layouts;
logo filters; frog artwork; reworking autosave concurrency (#43).
