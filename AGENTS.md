# AGENTS.md

Guidance for AI coding agents working in this repository. Setup, testing and
operations are in the [README](README.md); deployment and rollback are in the
[deployment runbook](docs/deployment.md).

## Local development (Laragon)

Laragon on Windows is the only supported local setup. Use this project's own
Laragon project and profile, and never suggest Docker or any other local web
stack.

- Site: `http://apes-newsroom.test` (Laragon's Apache, PHP 8.4, serving
  `public/`), database `apes_newsroom_local` on Laragon's MySQL 8.4, Vite on
  `http://127.0.0.1:5181`. Values: [docs/laragon/profile.md](docs/laragon/profile.md)
  and [docs/laragon/profile.json](docs/laragon/profile.json).
- Setup: `composer setup` ([scripts/laragon-setup.ps1](scripts/laragon-setup.ps1)),
  guide in [docs/local-dev.md](docs/local-dev.md).
- Agent shells are not Laragon's Terminal, so `php` and `node` may resolve to
  another install. If setup warns about that, put Laragon's PHP 8.4 and
  `C:\laragon\bin\nodejs\node-v22` first on `$env:Path` for that shell
  before running `composer`, `php artisan` or `npm`.
- Live preview: `npm run dev` (or `composer dev` for Vite plus the queue
  worker). Apache serves the site, so do not run `php artisan serve`.
- Demo data: `composer demo:seed` and `composer demo:reset`; contents and
  test logins in [docs/laragon/demo-data.md](docs/laragon/demo-data.md). Test
  changes against the demo data, sign in through the local role preview, and
  keep any new demo records clearly fake (`example.*` emails only).

## Shipping and versioning

Work ships once per milestone, by the milestone's version (`v1.31.0`), never by
GitHub's milestone number: one `milestone/vX-Y-Z-<slug>` branch, one PR into
`main` merged as a merge commit, one `vX.Y.Z` tag and Release, and one deploy
of that tag. Issue PRs may go into the milestone branch; nothing else goes
into `main`. The rule is
[`.cursor/rules/version-shipping.mdc`](.cursor/rules/version-shipping.mdc);
the skills are
[`complete-next-version`](.cursor/skills/complete-next-version/SKILL.md) and
[`release-version`](.cursor/skills/release-version/SKILL.md); the command is
`/complete-next-version`. Before any change, run the live preview gate in
[`.cursor/rules/laragon-local-dev.mdc`](.cursor/rules/laragon-local-dev.mdc).

- `composer milestone:next` prints the next milestone, its branch, PR title
  and Release title. `composer milestone:bump -- vX.Y.Z` creates the release
  note from its template if no PR has yet. `milestone:check-title`,
  `milestone:check-branch` and `milestone:check-release` fail with the reason;
  CI runs the title and branch checks on every pull request.
- Release: pushing the tag runs `release.yml`, which checks the tag is on
  `main` and publishes the Release titled `v<version> <milestone name>`.
- Deploy: `gh workflow run deploy.yml -f tag=vX.Y.Z`. It deploys only a tag on
  `main` with a published Release (see [docs/deployment.md](docs/deployment.md)).
- Security checks use GitHub's free features only: Dependabot (into the
  `dependencies` branch, merged into each milestone branch when it starts),
  dependency review and CodeQL.
- **Hotfix exception:** only with Bambie's approval, recorded on the issue.
- Optional local check before each push: `git config core.hooksPath .githooks`.

### Definition of done

- An **issue** is done when its acceptance criteria are met and committed on
  its branch. It closes when the milestone PR merges.
- The **milestone** is done only after the single merge, release and a
  verified deploy of the tag.