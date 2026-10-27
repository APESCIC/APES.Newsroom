## Summary

<!-- What does this PR change and why? -->

## Issue

Closes #
Parent: #
Milestone: vX.Y.Z

## Test plan

- [ ] `composer test`
- [ ] `composer lint`
- [ ] `npm run typecheck`
- [ ] `npm run lint`
- [ ] Other: …

## Checklist

- [ ] Conventional commit / title references `#N`
- [ ] Changelog entry `changelog/releases/vX.Y.Z.json` matches the milestone (or PR labeled `skip-changelog`)
- [ ] Issue and PR assigned to the milestone
- [ ] No secrets or production credentials in the diff

## After merge

Merging does **not** auto-deploy. Finish the loop in
[`docs/agent-workflow.md`](../docs/agent-workflow.md) and record it here or on the issue:

- [ ] Tag `vX.Y.Z` pushed on the merge commit; GitHub Release published
- [ ] **Deploy to Cloudron (beta)** dispatched against `main` (run URL: …)
- [ ] `https://www.apesnews.org.uk/health` returns `"status":"ok"`  <!-- pragma: allowlist secret -->
- [ ] Issues closed against acceptance criteria; milestone closed when empty

Never commit `CLOUDRON_TOKEN`.
