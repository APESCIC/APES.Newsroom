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
| Session fixation / theft | HTTPS (Cloudron), secure cookies, short sessions |
| Dependency vulns | CI `composer audit` / `npm audit` |
| Content injection via Ghost import | HTML→blocks conversion; legacy blocks sanitized; needs_import_review flag |

## Headers

`SecurityHeaders` middleware sets CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy`.

## Residual risks requiring human sign-off

- Live SMTP misconfiguration on beta/production
- Incomplete WCAG evidence until formal a11y review
- Production cutover authorization (#11)
