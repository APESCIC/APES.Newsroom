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
| Privilege escalation | Ranked roles; fail-closed LDAP reconciliation; route `role:` middleware plus policies for posts, pages, releases, webhooks, members, users, tokens and keys (#315); `AuthzMatrixTest` calls every staff and admin route as each role and fails when a route is missing (#315); staff and admin roles come only from the directory, and in-app role changes are limited to demoting non-SSO accounts to Public by a super admin, never the last super admin (#307) |
| Compromised account still in use | Admins can lock an account (refused on every sign-in path, API tokens and open sessions), sign it out everywhere, force a password reset, turn off two-factor or remove passkeys; each needs a reason and recent re-authentication, is audit-logged and emailed, and cannot target the admin's own account or an equal or higher role (#305, #306) |
| Leaked integration credential | Admin API tokens with expiry, per-route abilities and revocation (#312); named, hashed, revocable Content API keys with rotation (#314); webhook secrets encrypted at rest (#313); every plain value shown once only |
| XSS via Editor.js | Server `BlockValidator` allowlist; escaped `BlockRenderer`; XSS feature tests |
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

## Headers

`SecurityHeaders` middleware sets CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy` on web and API responses, plus `Strict-Transport-Security` over HTTPS (see [`docs/deployment.md`](../deployment.md#transport-security-secure-cookies-hsts)).

## Residual risks requiring human sign-off

- Live SMTP misconfiguration on beta/production
- Incomplete WCAG evidence until formal a11y review
- Production cutover authorization (#11)
- Cloudron Mandatory 2FA is on (confirmed 2026-10-12, #369) and must stay on; the app cannot verify it because Cloudron does not report `amr`. Cloudron impersonate and superadmin one-time passwords bypass 2FA (#303)
- Two-factor and passkeys are optional for members unless an admin requires them for the Public role in Settings (#311); an account with neither is protected only by its password or email inbox
- Webhook secrets are encrypted with `APP_KEY`; rotating `APP_KEY` makes them unreadable, so they must be re-entered after a key rotation (#313)
