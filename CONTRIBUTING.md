# Contributing to APES Newsroom

Setup, local development and testing are covered in the [README](README.md).
Report security issues privately as described in [SECURITY.md](SECURITY.md).

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
