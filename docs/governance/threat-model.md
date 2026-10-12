# Threat model (draft, issue #10)

## Assets

- Staff publishing credentials (Cloudron OIDC)
- Public account credentials / magic links
- Mailing contact consent ledger and suppressions
- Unpublished drafts and revision history
- SMTP send capability (campaign abuse risk)

## Key threats and controls

| Threat | Control |
|--------|---------|
| Privilege escalation | Ranked roles; fail-closed LDAP reconciliation; route `role:` middleware; AuthzMatrix tests |
| XSS via Editor.js | Server `BlockValidator` allowlist; escaped `BlockRenderer`; XSS feature tests |
| CSRF | Laravel CSRF + Inertia; session cookies |
| Campaign spam / PECR breach | Double opt-in; suppressions; unsubscribe headers; import paths force `mail.default=array` for the run / never notify |
| Open redirects | Redirect table only; fixed status codes; no user-controlled open redirect helper |
| Session fixation / theft | HTTPS (Cloudron) with HSTS; session cookies forced `Secure` on Cloudron and in production (#280); session ID regenerated on login; 120-minute sessions, or up to 30 days with the remember-me option (#293); sessions tracked per device in `user_sessions` (hashed key only) so members can review and end them (#290, #291); all sessions revoked on password reset or change, email change, SSO link or role change (#277, #292); new-device sign-in emails (#294) |
| Account takeover after session theft | Recent re-authentication (password, SSO, or emailed code) before account deletion, email change, signing out other sessions, webhook and settings changes (#289); email changes applied only after confirmation from the new address, with a cancel link to the old one (#295); security notification emails for password, email, device and API token changes (#294) |
| Weak or breached passwords | Minimum 12 characters; Pwned Passwords k-anonymity range check in production, failing open (#287); password change and re-auth endpoints rate limited (see [`docs/auth-rate-limits.md`](../auth-rate-limits.md)) |
| Undetected account abuse | Authentication, privileged change, token and webhook events recorded in the audit log (#284, #285); retention purge per [`retention-schedule.md`](retention-schedule.md) (#286) |
| Dependency vulns | CI `composer audit` / `npm audit` |
| Content injection via Ghost import | HTML→blocks conversion; legacy blocks sanitized; needs_import_review flag |

## Headers

`SecurityHeaders` middleware sets CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy` on web and API responses, plus `Strict-Transport-Security` over HTTPS (see [`docs/deployment.md`](../deployment.md#transport-security-secure-cookies-hsts)).

## Residual risks requiring human sign-off

- Live SMTP misconfiguration on beta/production
- Incomplete WCAG evidence until formal a11y review
- Production cutover authorization (#11)
