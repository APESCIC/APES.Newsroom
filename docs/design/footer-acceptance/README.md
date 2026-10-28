# SiteFooter acceptance — milestone 9 / #176

## Keyboard / focus pass (2026-09-24)

Recorded against the rebuilt `SiteFooter` landmark during batch E:

1. Tab order enters the footer landmark after main content.
2. Brand logo home link, phone, and `ProtectedEmail` are reachable with visible `:focus-visible` rings.
3. Pill links in Visit / Stay informed / Policies columns are keyboard-operable; external links open with `rel="noopener noreferrer"`.
4. Partner pills and social links expose accessible names (`aria-label` on socials; image `alt` + text on partners) and meet 44×44 min targets (`min-h-11` / `min-w-11`).
5. Fineprint version / Change Log Hub links remain in tab order after socials.

Automated coverage: `resources/js/__tests__/site-footer.test.tsx` and footer assertions in `home.test.tsx`.

## Screenshots vs Shelter

| Viewport | Shelter reference | Newsroom |
|----------|-------------------|----------|
| Desktop | [shelter-footer-desktop.png](./shelter-footer-desktop.png) | [newsroom-footer-desktop.png](./newsroom-footer-desktop.png) |
| Mobile | [shelter-footer-mobile.png](./shelter-footer-mobile.png) | [newsroom-footer-mobile.png](./newsroom-footer-mobile.png) |

Shelter source: https://www.apesshelter.org.uk/ (footer markup also at `/includes/footer.html`).
Newsroom shots rendered from the production CSS build of the SiteFooter structure for visual parity (deep-green glass panels, pill links, lower partner/social row, CIC fineprint).

## v1.23.0: light glass footer (#583)

Approved on 27 Oct 2026 under [#576](https://github.com/APESCIC/APES.Newsroom/issues/576):
the footer deliberately departs from the Shelter-matched dark green panels
above and uses the Soft glass palette. The structure, landmarks, link lists,
44×44 targets and fineprint are unchanged, so the keyboard pass above still
applies. Shelter parity now covers the layout only, not the colours.

| Viewport | Newsroom (light glass) |
|----------|------------------------|
| Desktop | [newsroom-footer-light-desktop.png](./newsroom-footer-light-desktop.png) |
| Mobile | [newsroom-footer-light-mobile.png](./newsroom-footer-light-mobile.png) |

Panels are frosted white over the gradient wash, kickers and pill links are
teal (`#006E70`) and turn solid teal with white text on hover and focus, and
the fineprint uses the muted colour. The hard-coded `#f8f4df`, `#d9ece2` and
white-alpha colours are replaced with tokens. Automated coverage: the footer
landmark in `site-footer.test.tsx`, the footer in every `public.spec.ts`
screenshot, and its frosted panels in the reduced-transparency check.
