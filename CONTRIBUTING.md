# Contributing to APES Newsroom

Thanks for your interest. APES Newsroom is built and run by the Association of Protecting Exotic Species CIC for its own use; the code is public.

## How you can help
- **Ideas and feedback:** start a post in [Discussions](https://github.com/APESCIC/APES.Newsroom/discussions) (Ideas or Feedback).
- **Questions:** use Discussions → Q&A.
- **Bugs:** open an issue using the bug report form.
- **Security problems:** never in public; see [SECURITY.md](SECURITY.md).

## Code contributions
We don't usually accept outside pull requests, because the app is planned around APES's own needs. If you'd like to suggest a change, please start a Discussion first so we can talk it through.

For the APES team: work is planned in milestones (`vX.Y.Z Beta: Name`), every pull request needs a changelog entry for the Change Log Hub (or the `skip-changelog` label), and CI must pass before merging. Setup, local development and testing are covered in the [README](README.md). Laragon on Windows is the only supported local setup; see [docs/local-dev.md](docs/local-dev.md).

## How work ships

Work ships once per milestone, by the milestone's version, never by GitHub's
milestone number: branch `milestone/v1-31-0-seo-social-previews`, issue PR
title `[v1.31.0] JSON-LD structured data (#671)`, milestone PR
`v1.31.0: SEO and social previews`, tag `v1.31.0`.

1. Before any change, run the Laragon live preview gate:
   `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1`.
2. Commit every issue on the milestone branch, or on a short issue branch
   merged into it. Commit messages follow Conventional Commits and name the
   issue: `fix: keep the tag filter on reload (Refs #123)`.
3. The milestone PR merges into `main` once, as a merge commit, after
   Bambie's ship-gate answer. The `vX.Y.Z` tag publishes the Release, and that
   tag is deployed with `gh workflow run deploy.yml -f tag=vX.Y.Z`.

A hotfix may skip the stages only with Bambie's written approval recorded on
the issue.

- `composer milestone:next` shows the next milestone's version, branch, PR
  title and Release title; `composer milestone:pr-title -- <issue>` gives the
  issue PR title.
- `composer milestone:check-title -- "<title>"` and
  `composer milestone:check-branch -- "<branch>"` tell you what is wrong. The
  `milestone-version-check` CI job runs them on every pull request.
- To check your branch name before each push, run
  `git config core.hooksPath .githooks` once.

The rule is
[`.cursor/rules/version-shipping.mdc`](.cursor/rules/version-shipping.mdc);
see also [AGENTS.md](AGENTS.md#shipping-and-versioning).
## Licence
By contributing, you agree your contributions are licensed under the AGPL-3.0 licence of this project.

## Code of Conduct
Everyone taking part follows our [Code of Conduct](CODE_OF_CONDUCT.md). Report concerns to legal@apes.org.uk.
