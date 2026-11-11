---
name: release-version
description: Ship a completed version - set the version once with composer milestone:bump, publish the release note, mark the milestone PR ready, get CI green, ask the ship-gate question, then merge once (merge commit), tag and release vX.Y.Z, deploy that tag to Cloudron with evidence and close out. Use when every issue in the milestone is committed, or when the user asks to merge, release, tag or deploy the milestone.
---

# Release a version

Follows `.cursor/rules/version-shipping.mdc` and the `ship-gate` and `cloudron-deploy` skills.

## Steps

1. **Sync.** Run `scripts\preview.ps1` (it merges `origin/main` into the milestone branch).
2. **Set the version once.** `composer milestone:bump -- vX.Y.Z`. It refuses a version that is not the next milestone's. Finish the release note by `docs/change-log-hub.md`.
3. **Last commit.** `chore: release vX.Y.Z` with the finished `changelog/releases/vX.Y.Z.json`. Push.
4. **Ready.** `gh pr ready <pr>`, then `gh pr checks <pr> --watch --fail-fast`. Fix failures on the same branch.
5. **Check the names.** `composer milestone:check-release -- vX.Y.Z "<release title>"`, using the "Release title" from `composer milestone:next`.
6. **Ship gate.** Summarise the milestone, PR, checks, merge method, tag and deploy target, then ask with exactly: a. Merge, b. Release, c. Deploy, d. Merge, release and deploy, e. Stop here. Post the answer on the milestone PR. Run only what was chosen; after each single step ask again with what remains.
7. **Merge.** `gh pr merge <pr> --merge --delete-branch`.
8. **Release.** Tag the merge commit; `release.yml` checks it and publishes the Release:

   ```powershell
   git fetch origin; git tag -a vX.Y.Z -m "vX.Y.Z" <merge-sha>; git push origin vX.Y.Z
   gh run watch (gh run list --workflow release.yml --limit 1 --json databaseId --jq '.[0].databaseId') --exit-status
   ```

9. **Deploy.** `gh workflow run deploy.yml -f tag=vX.Y.Z`, then `gh run watch <id> --exit-status` (backup, upload, migrate, activate, restart, `/health`, roll back on failure). Evidence: the green run on that tag and `https://www.apesnews.org.uk/health` returning `"status":"ok"`.
10. **Close out.** `Close-IssueTree.ps1` per issue and `Close-Milestone.ps1`, then merge `origin/main` into `dependencies`. Post the completion message with the PR, merge commit, issues, release and deploy evidence.

## Never

- Squash or rebase the milestone PR, tag anything but the merge commit, or deploy a branch.
- Rewrite a past tag, Release or published release note.
- Report a step without its command output.
