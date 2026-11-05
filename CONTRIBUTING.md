# Contributing to APES Newsroom

Thanks for your interest. APES Newsroom is built and run by the Association of Protecting Exotic Species CIC for its own use; the code is public.

## How you can help
- **Ideas and feedback:** start a post in [Discussions](https://github.com/APESCIC/APES.Newsroom/discussions) (Ideas or Feedback).
- **Questions:** use Discussions → Q&A.
- **Bugs:** open an issue using the bug report form.
- **Security problems:** never in public; see [SECURITY.md](SECURITY.md).

## Code contributions
We don't usually accept outside pull requests, because the app is planned around APES's own needs. If you'd like to suggest a change, please start a Discussion first so we can talk it through.

For the APES team: work is planned in milestones (`vX.Y.Z Beta: Name`), every pull request needs a changelog entry for the Change Log Hub (or the `skip-changelog` label), and CI must pass before merging. Setup, local development and testing are covered in the [README](README.md).

## How work ships

Work ships once per GitHub milestone, in four stages:

1. Complete **all** of the milestone's issues on its milestone branch.
2. Merge all of that work into `main` in **one merge** (a merge commit, never a
   squash).
3. Release the milestone once.
4. Deploy that release once.

You can commit and push to the milestone branch at any time, but nothing goes
to `main`, and nothing is tagged, released or deployed, until stage 2. A hotfix
may skip the stages only with Bambie's written approval recorded on the issue.

The full rules, including the definition of done, are in [AGENTS.md](AGENTS.md#milestone-shipping).

## Licence
By contributing, you agree your contributions are licensed under the AGPL-3.0 licence of this project.

## Code of Conduct
Everyone taking part follows our [Code of Conduct](CODE_OF_CONDUCT.md). Report concerns to legal@apes.org.uk.
