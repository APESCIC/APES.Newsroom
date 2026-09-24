# Direction C UI — Glassmorphism Modern

Status: stakeholder-approved on 2026-08-24 under
[issue #36](https://github.com/APESCIC/APES-Newsroom/issues/36).
Correction tracked under
[issue #55](https://github.com/APESCIC/APES-Newsroom/issues/55).
Supersedes Direction B (Magazine Bold Grid, #53).

## Selected character

Direction C is a logo-led Glassmorphism Modern treatment. Public pages use a
dark teal gradient shell with frosted-glass navigation, hero, three channel
entry cards, and story cards while remaining calm, credible, and
newsroom-first. Authenticated workspaces use **Direction B — Glass Studio**
(see `direction-b-glass-studio-ui.md`); public Direction C tokens are reused
there without changing marketing surfaces.

The supplied APES logo files remain unchanged without filters or recolouring. No
frog mascot artwork is included.

## Page decisions

### Homepage

- Full-width `.glass-hero` panel: featured story left, hero image or mission
  panel right.
- Three equal `.glass-channel` entry cards (APES CIC, Shelter & Rescue, Pet
  Care Clinic).
- Recent stories in uniform `.glass-story-card` grid (no lead-column asymmetry).

### All public surfaces

- `.public-gradient-shell` on PublicLayout, AuthCard, and account pages.
- `.glass-form-panel` for legal, mailing, auth, account, and article reading
  surfaces.

### Workspace

- Glass Studio Direction B (v1.6.0): frosted rail/chrome on teal gradient with
  opaque canvas. See `direction-b-glass-studio-ui.md`.

## Accessibility

- Glass panels fall back to solid dark surfaces when `backdrop-filter` is
  unsupported or `prefers-reduced-motion` is set.
- Channel and state colour always paired with text labels.
- 44×44px targets and visible focus rings preserved.

## Out of scope

Logo modification, frog mascot, new routes, production deployment. Workspace
glass is **in scope** for v1.6.0 via Direction B Glass Studio (#145), not via
this public Direction C document.
