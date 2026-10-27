# Agent workflow commands

The commands behind the loop in [`AGENTS.md`](../AGENTS.md): track work in
issues and milestones from the start, then commit, merge, release and deploy
at the end. Examples use bash syntax; in PowerShell, wrap `--jq` filters and
query-string URLs in single quotes the same way.

All commands assume `gh` is authenticated against `APESCIC/APES.Newsroom`
(`gh auth status`). Cloud agents get `gh` from
[`.cursor/Dockerfile`](../.cursor/Dockerfile).

```bash
REPO=APESCIC/APES.Newsroom
```

## 1. Find or create the issue

```bash
gh issue list --repo $REPO --search "keyword in:title" --state open
```

Create one only if nothing fits. Use a `[Type]` title prefix, acceptance
criteria as checkboxes, and the milestone it will ship in:

```bash
gh issue create --repo $REPO \
  --title "[Task] Short outcome" \
  --body-file issue.md \
  --milestone vX.Y.Z \
  --assignee @me
```

Set the GitHub issue type to match the prefix (`Feature`, `Task`, `Bug`):

```bash
gh api -X PATCH repos/$REPO/issues/<N> -f type=Task
```

## 2. Pick the version and milestone

```bash
gh release list --repo $REPO --limit 1
gh api "repos/$REPO/milestones?state=open" --jq '.[] | "\(.number) \(.title)"'
```

- Patch (`v1.19.0` → `v1.19.1`): fixes, docs, tooling, internal-only changes.
- Minor (`v1.19.0` → `v1.20.0`): new user-facing features.
- Reuse an open milestone the work already belongs to. Otherwise create one:

```bash
gh api repos/$REPO/milestones -f title=vX.Y.Z -f description="One-line theme"
gh issue edit <N> --repo $REPO --milestone vX.Y.Z
```

The milestone title, changelog file name and release tag must all be the
same `vX.Y.Z`.

## 3. Link sub-issues

The REST API needs the child's numeric **id**, not its number:

```bash
CHILD_ID=$(gh api repos/$REPO/issues/<child> --jq .id)
gh api repos/$REPO/issues/<parent>/sub_issues -F sub_issue_id=$CHILD_ID
gh api repos/$REPO/issues/<parent>/sub_issues --jq '.[] | "\(.number) \(.state) \(.title)"'
```

## 4. Branch and progress

```bash
git switch main && git pull --ff-only
git switch -c cursor/<feature|fix|chore>/<N>-<slug>
gh issue comment <N> --repo $REPO --body "Progress: …"
```

Edit the issue body to tick acceptance criteria as they are met
(`gh issue edit <N> --body-file issue.md`).

## 5. Commit, changelog, PR, CI

1. Copy [`changelog/releases/_template.json`](../changelog/releases/_template.json)
   to `changelog/releases/vX.Y.Z.json` and fill it in
   ([`docs/change-log-hub.md`](change-log-hub.md)). Use the `internal-only`
   topic tag for changes members never see.
2. Run the quick checks: `composer test`, `composer lint`,
   `npm run typecheck`, `npm run lint`.
3. Commit, push and open the PR:

```bash
git add <files for this change only>
git commit -m "chore(scope): summary (#N)"
git push -u origin HEAD
gh pr create --repo $REPO --fill --body-file pr.md   # body: Closes #N, Milestone: vX.Y.Z
gh pr edit --milestone vX.Y.Z
```

4. Set the changelog `pr` field to the PR number, commit, push.
5. Wait for CI and fix any failures on the branch:

```bash
gh pr checks --watch --fail-fast
```

## 6. Merge

```bash
gh pr merge <PR> --repo $REPO --merge --delete-branch
git switch main && git pull --ff-only
MERGE_SHA=$(git rev-parse HEAD)
```

## 7. Release

Tag the merge commit. Pushing the tag runs
[`release.yml`](../.github/workflows/release.yml), which publishes the
GitHub Release with generated notes. It never deploys.

```bash
git tag -a vX.Y.Z -m "vX.Y.Z" $MERGE_SHA
git push origin vX.Y.Z
gh run list --repo $REPO --workflow release.yml --limit 1
gh release view vX.Y.Z --repo $REPO
```

## 8. Deploy to Cloudron

Dispatch the guarded workflow against `main` and watch it to completion:

```bash
gh workflow run deploy.yml --repo $REPO --ref main -f confirm_branch=main
sleep 5
RUN_ID=$(gh run list --repo $REPO --workflow deploy.yml --limit 1 --json databaseId --jq '.[0].databaseId')
gh run watch $RUN_ID --repo $REPO --exit-status
curl -fsS https://www.apesnews.org.uk/health   # expect "status":"ok"  # pragma: allowlist secret
```

The workflow backs up the app first and rolls back on its own if
activation, restart or the health check fails. If it fails, leave the issue
open, comment the run URL, and fix forward with a new patch version.

Manual rollback, for a release that passed health but is broken, is in
[`docs/deployment.md`](deployment.md#manual-rollback).

## 9. Close out

```bash
gh issue comment <N> --repo $REPO --body "Shipped in vX.Y.Z: PR #<PR>, merge <short-sha>, release <url>, deploy run <url>, /health ok."
gh issue close <N> --repo $REPO --reason completed      # only when acceptance criteria are met
gh api "repos/$REPO/milestones/<milestone-number>" --jq '"open=\(.open_issues)"'
gh api -X PATCH "repos/$REPO/milestones/<milestone-number>" -f state=closed   # when open=0
```

Close a parent issue only when all its sub-issues are closed.

## 10. Report and offer next options

Finish with the PR, merge SHA, release, deploy run and health result, then
2–4 next options with the recommended one first and a one-line reason each.
