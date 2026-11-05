# Threat model (draft, issue #10)

## Assets

- Staff publishing credentials (Cloudron OIDC)
- Public account credentials / magic links
- Two-factor secrets, recovery codes and passkey public keys
- Mailing contact consent ledger and suppressions
- Unpublished drafts and revision history
- SMTP send capability (campaign abuse risk)

## Key threats and controls

| Threat | Control |
|--------|---------|
| Privilege escalation | Ranked roles; fail-closed LDAP reconciliation; route `role:` middleware plus policies for posts, pages, webhooks, members, users, tokens and keys (#315); `AuthzMatrixTest` calls every staff and admin route as each role and fails when a route is missing (#315); staff and admin roles come only from the directory, and in-app role changes are limited to demoting non-SSO accounts to Public by an admin, never the last unlocked admin (#307, #717) |
| Compromised account still in use | Admins can lock an account (refused on every sign-in path, API tokens and open sessions), sign it out everywhere, force a password reset, turn off two-factor or remove passkeys, including on other admins; each needs a reason and recent re-authentication, is audit-logged and emailed, and cannot target the admin's own account; the last unlocked admin cannot be locked, erased or demoted (#305, #306, #717) |
| Leaked integration credential | Admin API tokens with expiry, per-route abilities and revocation (#312); named, hashed, revocable Content API keys with rotation (#314); webhook secrets encrypted at rest (#313); every plain value shown once only |
| XSS via Editor.js or TinyMCE | Server `BlockValidator` allowlist; text fields limited to `strong`, `em`, `code`, `br` and http/https/mailto links by `InlineHtmlSanitizer` on save and again in `BlockRenderer` (#390); TinyMCE HTML converted to blocks server-side, read-only blocks re-validated, unknown or forged blocks rejected (#257, #258); TinyMCE self-hosted with no third-party script origin, CSP `img-src` adds `blob:` only (#255); XSS and hostile-HTML feature tests (#259) |
| CSRF | Laravel CSRF + Inertia; session cookies |
| Campaign spam / PECR breach | Double opt-in; suppressions; unsubscribe headers; import paths force `mail.default=array` for the run / never notify |
| Open redirects | Redirect table only; fixed status codes; no user-controlled open redirect helper |
| Session fixation / theft | HTTPS (Cloudron) with HSTS; session cookies forced `Secure` on Cloudron and in production (#280); session ID regenerated on login; 120-minute sessions, or up to 30 days with the remember-me option (#293); sessions tracked per device in `user_sessions` (hashed key only) so members can review and end them (#290, #291); all sessions revoked on password reset or change, email change, SSO link or role change (#277, #292); new-device sign-in emails (#294) |
| Account takeover after session theft | Recent re-authentication (password, SSO, or emailed code) before account deletion, email change, signing out other sessions, webhook and settings changes (#289); email changes applied only after confirmation from the new address, with a cancel link to the old one (#295); security notification emails for password, email, device and API token changes (#294) |
| Stolen or phished member password | Optional authenticator-app two-factor with single-use codes (replayed time steps refused), 8 single-use recovery codes stored hashed, and 5 wrong codes abandoning the pending sign-in (#296–#299); passkeys bound to the exact site origin, which phishing sites cannot use (#300–#302); a passkey counts as two-factor only when the device confirmed the user (PIN or biometric); turning 2FA off, getting new recovery codes, and adding or removing a passkey need recent re-authentication and send an email (#299, #301) |
| Staff account takeover | Staff sign in only through Cloudron SSO with Cloudron Mandatory 2FA; reported `amr` values audited on each staff sign-in; staff cannot use passkey or TOTP sign-in in the Newsroom (#303, see [`docs/deployment.md`](../deployment.md#staff-two-factor-authentication-cloudron)) |
| Weak or breached passwords | Minimum 12 characters, raisable by admins in Settings but never lowered below 12; Pwned Passwords k-anonymity range check, switchable in Settings and failing open (#287, #310); password change and re-auth endpoints rate limited (see [`docs/auth-rate-limits.md`](../auth-rate-limits.md)) |
| Undetected account abuse | Authentication, privileged change, token and webhook events recorded in the audit log (#284, #285); retention purge per [`retention-schedule.md`](retention-schedule.md) (#286); admin audit log viewer with filters and audited CSV export, and per-user sign-in history (#308, #309) |
| Dependency vulns | CI `composer audit` / `npm audit` |
| Content injection via Ghost import | HTML→blocks conversion; legacy blocks sanitized; needs_import_review flag |

## Review: wider admin rights (v1.28.0, #720)

v1.28.0 merged the super admin role into admin (#713). Every admin now holds
what only super admins could do before:

| Ability admins gained | Re-confirmed sign-in | Audit entry |
|-----------------------|----------------------|-------------|
| Add, replace or remove integration keys | Within 5 minutes (`auth.recent:300`) | `integration_key.set`, `.replaced`, `.removed` |
| Test an integration connection (#742) | Yes (`auth.recent`) | `integration_key.tested` |
| Change mail sending transports and bounce limits | Within 5 minutes | `mail_sending.updated` |
| Register, repair or remove Mailgun webhooks; change tracking | Within 5 minutes | `email_sending_domain.webhooks_*`, `email_sending_domain.tracking_changed` |
| Lock, unlock, sign out, reset credentials, edit or resend verification for **other admins** | Yes (`auth.recent`) | `user.locked`, `user.unlocked`, `user.signed_out`, `user.password_reset_forced`, `user.two_factor_reset`, `user.passkeys_removed`, `user.updated` |
| Delete or anonymise any other account, including admins | Yes | `user.erased` |
| Demote a non-SSO account (including an admin) to Public | Yes | `user.role_changed` |

Limits that still hold: nobody acts on their own account; the last unlocked
admin cannot be locked, erased or demoted (checked in `UserPolicy` and again
under a row lock in `LastAdminGuard`, so two admins acting on each other at the
same moment cannot both succeed); staff and admin roles still come only from
Cloudron groups; every action needs a reason and is rate limited.

**Recommendation.** Admin accounts are now the highest privilege in the app.
Keep Cloudron Mandatory 2FA on for every `newsroom.admin` member, keep that
group small, and review it whenever someone changes role.

Findings from the review, and what happened to each:

- Concurrent demotion or lock could leave no admin: fixed with `LastAdminGuard` (row lock in the service transaction).
- Integration connection tests did not need a recent sign-in: fixed in #742.
- Deploying before members are in `newsroom.admin` would demote SSO-only former super admins: covered by the pre-merge step of the runbook in [`docs/deployment.md`](../deployment.md#retiring-the-old-super-admin-group-v1280) (#723).
- New code reading an unmigrated `super_admin` row: not reachable, because `cloudron-activate.sh` migrates before switching the release.
- Rolling the migration back after live use restores the original roles, which could re-elevate accounts changed since: accepted; roll back only straight after a failed deploy.
- The audit rewrite changes exact `super_admin` values only and skips payloads that are not JSON: accepted; none are expected, and the snapshot keeps every changed row.

## Headers

`SecurityHeaders` middleware sets CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy` on web and API responses, plus `Strict-Transport-Security` over HTTPS (see [`docs/deployment.md`](../deployment.md#transport-security-secure-cookies-hsts)).

## Residual risks requiring human sign-off

- Live SMTP misconfiguration on beta/production
- Incomplete WCAG evidence until formal a11y review
- Production cutover authorization (#11)
- Cloudron Mandatory 2FA is on (confirmed 2026-10-12, #369) and must stay on; the app cannot verify it because Cloudron does not report `amr`. Cloudron impersonate and superadmin one-time passwords bypass 2FA (#303)
- Two-factor and passkeys are optional for members unless an admin requires them for the Public role in Settings (#311); an account with neither is protected only by its password or email inbox
- Webhook secrets are encrypted with `APP_KEY`; rotating `APP_KEY` makes them unreadable, so they must be re-entered after a key rotation (#313)
