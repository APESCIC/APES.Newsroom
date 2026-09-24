## Summary

<!-- What does this PR change and why? -->

## Issue

Closes #

## Test plan

- [ ] `composer test`
- [ ] `composer lint`
- [ ] `npm run typecheck`
- [ ] `npm run lint`
- [ ] Other: …

## Deploy note

Merging this PR does **not** auto-deploy. Cloudron updates require a deliberate
run of **Deploy to Cloudron (beta)** against `main` (GitHub Environment `beta`
→ live LAMP at `www.apesnews.org.uk`). Never commit `CLOUDRON_TOKEN`.  <!-- pragma: allowlist secret -->

## Checklist

- [ ] Conventional commit / title references `#N`
- [ ] Changelog entry under `changelog/releases/` (or PR labeled `skip-changelog`)
- [ ] No secrets or production credentials in the diff
