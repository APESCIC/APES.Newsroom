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
- Live preview: `npm run dev` (or `composer dev` for Vite plus the queue
  worker). Apache serves the site, so do not run `php artisan serve`.
- Demo data: `composer demo:seed` and `composer demo:reset`; contents and
  test logins in [docs/laragon/demo-data.md](docs/laragon/demo-data.md). Test
  changes against the demo data, sign in through the local role preview, and
  keep any new demo records clearly fake (`example.*` emails only).

## Milestone shipping

Work ships per GitHub milestone, in four stages, in this order:

1. **Complete the milestone.** Work on the milestone and complete **all** of
   its issues before moving to stage 2.
2. **One merge.** All the milestone's work goes to `main` in **one merge**: a
   merge commit (`gh pr merge --merge`), never a squash.
3. **Release.** Tag and release the milestone once, after the merge
   (`release.yml` publishes the GitHub Release from the `vX.Y.Z` tag; release
   notes follow [docs/change-log-hub.md](docs/change-log-hub.md)).
4. **Deploy.** Deploy that release once, with the manual `deploy.yml` workflow.

Commits and pushes on the milestone branch can happen whenever needed, but
nothing goes to `main`, and nothing is tagged, released or deployed, until
stage 2. One milestone has one branch and one PR; there are no per-issue PRs
into `main`.

**Hotfix exception:** a hotfix may skip these stages only with Bambie's written
approval recorded on the issue.

The Cursor rule [`.cursor/rules/milestone-shipping.mdc`](.cursor/rules/milestone-shipping.mdc)
applies the same rules to every Cursor session.

### Definition of done

- An **issue** is done when its acceptance criteria are met and committed on
  its branch. It closes when the milestone PR merges.
- The **milestone** is done only after the single merge, release and deploy.
