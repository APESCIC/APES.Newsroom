# Data inventory (issue #10)

This engineering inventory and its linked retention, processor, privacy,
consent, rights, and threat-model records were approved for the launch gate by
Bambie Murphy (APES CIC / repository owner) on 2026-08-06. See the
[recorded legal/compliance and accessibility sign-off](https://github.com/APESCIC/APES-Newsroom/issues/10#issuecomment-5208704103).

| Data category | Systems / tables | Lawful basis | Retention | Access |
|---------------|------------------|----------------------|-------------------|--------|
| Public account identity | `users` (name, email, password hash, verification) | Contract / legitimate interests for newsroom access | Until account deletion request + 30 days backup | User, admins |
| Account locks | `users.locked_at`, `users.locked_reason` (admin-entered, never emailed to the account holder), `users.locked_by` | Legitimate interests (account security / incident response) | Cleared on unlock; the lock and unlock remain in `audit_logs` (`user.locked`, `user.unlocked`) | Admins / super admins (shown on the admin user page) |
| Staff identity / roles | `users.role`, OIDC `external_id`, `ldap_group_snapshot` | Legitimate interests (staff access control) | While employed + audit retention | Admins / super admins |
| Editorial content | `posts`, `post_revisions`, `tags`, media on disk | Legitimate interests / public task of APES communications | Indefinite while published; soft-deleted retained 12 months | Staff / admins |
| Mailing contacts | `mailing_contacts`, `mailing_list_subscriptions`, `consent_events`, `suppressions` | Consent (PECR/GDPR) | Consent evidence retained 2 years after last campaign interaction or until erasure where required | Admins |
| Campaign delivery | `campaigns`, `campaign_recipients` | Consent / legitimate interests for send operations | 24 months operational metrics | Admins |
| Engagement | `profiles`, `comments`, `reactions`, `moderation_reports`, `moderation_audits` | Consent / legitimate interests for community features | Soft-deleted comments 12 months; moderation audits 24 months | Admins |
| Member CRM | `users`, `memberships`, `content_views` (member list with plan and recent signed-in reads) | Legitimate interests (membership administration) | As the underlying records | Admins only (`/admin/members`; staff access removed in v1.11.0) |
| Privileged audits | `audit_logs`, `import_runs`; admin actions on accounts (`user.locked`, `user.unlocked`, `user.signed_out`, `user.password_reset_forced`, `user.two_factor_reset`, `user.passkeys_removed`, `user.role_changed`) with the admin's reason; `settings.updated` with before/after values; `audit_log.exported` with filters and row count | Legitimate interests (security / accountability) | 24 months | Admins / super admins through the admin audit log viewer (`/admin/audit-log`, filterable, CSV export after re-authentication) and the per-user sign-in history on `/admin/users/{id}` |
| Authentication events | `audit_logs` (`auth.*`: sign-in, failure, lockout, sign-out, registration, verification, reset, magic link, staff SSO allow/deny with any reported `amr` methods, two-factor challenge/pass/fail, recovery code use, passkey sign-in) and `account.*` 2FA and passkey changes, with IP address and user agent. No passwords, tokens or links; failed attempts against unknown emails store no email. | Legitimate interests (account security / incident response) | 24 months; on self-service deletion the records are detached from the account (actor cleared) and age out on schedule | Admins / super admins |
| Integration changes | `audit_logs` (`api_token.*`, `webhook.*`, `content_api_key.*`, `integration_key.*`): token name/owner/abilities/expiry, key name/prefix, revocation reason, webhook name/host/events; integration key provider, key name, last four characters, check status and whether a failing check was overridden. Never token values, key values or webhook secrets. | Legitimate interests (security / accountability) | 24 months | Admins / super admins |
| Integration service keys | `integration_secrets`: provider, key name, value encrypted with the app key, last four characters, who set it and when, last rotation time. Values are write-only: never sent to the browser, logged or audited. | Legitimate interests (integrations) | Until a super admin removes or replaces the key | Super admins change keys; admins see status only (`/admin/settings/integrations`) |
| Integration check results | `integration_check_results`: provider, pass/warn/fail, plain message written by the check (no provider responses), duration, trigger, the admin who ran it | Legitimate interests (security / ops) | 30 days (`SECURITY_INTEGRATION_CHECK_RETENTION_DAYS`) | Admins |
| Two-factor authentication | `users.two_factor_secret` (encrypted with the app key), `users.two_factor_recovery_codes` (hashed, then encrypted), confirmed time, last used time step. Codes are shown once and never emailed or logged. | Necessary for service / legitimate interests (account security) | Until 2FA is turned off or the account is deleted | System; the account holder sees recovery codes once |
| Passkeys | `passkeys`: name chosen by the member, credential ID, public key and authenticator data (no biometrics or private keys; those never leave the device), created and last used times | Necessary for service / legitimate interests (account security) | Until the member removes the passkey or deletes the account | Account holder (names and dates); system |
| Sign-in tokens | `magic_link_tokens` (SHA-256 hash only), `password_reset_tokens` (hashed) | Necessary for service | Magic links purged 7 days after expiry/use; reset tokens cleared after expiry | System |
| Email change requests | `email_change_requests`: requested new address, SHA-256 hash of the confirmation token, expiry, confirmed/cancelled time. The current address is told and can cancel; the change applies only after the new address confirms. | Necessary for service / legitimate interests (account security) | Purged 7 days after expiry; deleted with the account | System |
| Security notifications | Transactional emails for password changes, email change requests, unfamiliar-device sign-ins, API token creation, account lock and unlock, and admin security actions (sign-out, password reset, two-factor reset, passkey removal). No codes, tokens, links that grant access, or internal reasons. | Legitimate interests (account security) | Not stored beyond mail logs | System |
| Sessions and devices | `user_sessions`: hashed session key, IP address, user agent with parsed browser/OS/device type, remembered flag, last active, ended time. Shown to the account holder with a masked IP; no location lookup. Essential `newsroom_device` cookie identifies a remembered browser. | Necessary for service / legitimate interests (account security) | 90 days after last use or sign-out; deleted with the account | Account holder (own sessions); system |
| API credentials | `api_tokens` (SHA-256 hash, name, abilities, expiry, last used, revoked time), `content_api_keys` (SHA-256 hash, 15-character prefix, name, creator, last used, revoked time), `webhook_endpoints` (signing secret encrypted with the app key). Plain values are shown once on creation or rotation. | Legitimate interests (integrations) | Until revoked / deleted; revoked tokens and keys stay listed for history | Admins (`/admin/api-tokens`, `/admin/content-api-keys`, `/admin/webhooks`), operators (tokens via CLI) |
| Webhook deliveries | `webhook_deliveries` (event payloads, status) | Legitimate interests (integrations) | Operational; no automated purge yet | Admins |
| Session / CSRF | cookies, session store (Redis/file) | Necessary for service | Session lifetime | System |
| Logs | application logs on Cloudron | Legitimate interests (security/ops) | 90 days (ops default) | Ops / admins |

## Rights workflows (product)

- Access / portability: `/account/export`
- Rectification: `/account` profile update
- Erasure: `/account` delete
- Mailing preferences / withdraw consent: `/account/mailing`, signed `/mailing/preferences`, `/mailing/unsubscribe`
- Objection / report harmful content: report controls on comments; admin moderation queue

## Sign-off

- [x] Legal / compliance approval recorded on issue #10 (2026-08-06)
- [x] Accessibility approval recorded on issue #10 (2026-08-06)
