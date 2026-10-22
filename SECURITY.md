# Security policy

APES Newsroom is a public repository. **Never report a vulnerability in a
public issue, pull request, discussion, or comment.**

## Report a vulnerability

Use GitHub private vulnerability reporting:

1. Go to the repository's **Security** tab and choose
   **[Report a vulnerability](https://github.com/APESCIC/APES.Newsroom/security/advisories/new)**.
   This opens a private security advisory that only you and the repository
   maintainers can see.
2. Describe the affected surface (page, route, or API), the impact you
   observed, and safe reproduction steps.
3. Remove credentials, tokens, session values, personal data, private contact
   data, production exports, logs, and private infrastructure details before
   submitting. State that material was removed rather than redacting it inline.

If you cannot use GitHub, email the APES CIC directors and ask for a private
channel; do not include vulnerability details in the first message.

## Rules for testing

- Do not test against production (`www.apesnews.org.uk`) or any other live
  APES service. Use a local install (see [`README.md`](README.md)).
- Do not send campaigns or emails to real people, change DNS, or access,
  modify, or delete data that you are not authorized to use.
- Do not run denial-of-service, spam, or social-engineering tests.

## What happens next

Maintainers triage the advisory privately, agree a safe validation path with
you, and fix it on a private fork or branch where appropriate. The advisory is
published, with credit if you want it, only after the fix has been deployed
through the guarded deploy workflow.

## Account security features

Members can protect their own accounts from **Account → Security**: turn on
two-factor authentication with an authenticator app, add passkeys, review
and sign out signed-in sessions, change their password, and watch for the
security emails sent when a password, email address, device, API token,
two-factor setting or passkey changes. If you get one of those emails and it
wasn't you, reset your password and sign out other sessions. Staff sign in
through Cloudron single sign-on, where two-factor is enforced by Cloudron.

Administrators have a security console in the admin area. They can:

- find any account and review its sessions, sign-in history and security events
- lock a compromised account, sign it out everywhere, force a password reset,
  turn off two-factor or remove passkeys (each action needs a reason and a fresh
  identity check, is audit-logged, and is emailed to the account holder)
- browse and export the audit log
- set the security policy: minimum password length, breached-password check,
  idle session lifetime, emailed sign-in links, and roles that must use
  two-factor
- manage Admin API tokens (expiry, abilities, revocation), named Content API
  keys and encrypted webhook secrets, all shown only once

Controls are summarised in
[`docs/governance/threat-model.md`](docs/governance/threat-model.md).

## Maintainers

- Track remediation work in GitHub issues without exploit details; keep
  specifics in the private advisory and link to it ("details in
  GHSA-xxxx, maintainers only").
- Update the advisory with the fix PR, and publish it only after a deliberate
  deploy (see [`docs/deployment.md`](docs/deployment.md)).
- Never paste secrets, tokens, or personal data into issues, PRs, or
  advisories.

See GitHub's documentation on
[privately reporting a security vulnerability](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability).
