# Data inventory (issue #10)

This engineering inventory and its linked retention, processor, privacy,
consent, rights, and threat-model records were approved for the launch gate by
Bambie Murphy (APES CIC / repository owner) on 2026-08-06. See the
[recorded legal/compliance and accessibility sign-off](https://github.com/APESCIC/APES-Newsroom/issues/10#issuecomment-5208704103).

| Data category | Systems / tables | Lawful basis | Retention | Access |
|---------------|------------------|----------------------|-------------------|--------|
| Public account identity | `users` (name, email, password hash, verification) | Contract / legitimate interests for newsroom access | Until account deletion request + 30 days backup | User, admins |
| Staff identity / roles | `users.role`, OIDC `external_id`, `ldap_group_snapshot` | Legitimate interests (staff access control) | While employed + audit retention | Admins / super admins |
| Editorial content | `posts`, `post_revisions`, `tags`, media on disk | Legitimate interests / public task of APES communications | Indefinite while published; soft-deleted retained 12 months | Staff / admins |
| Mailing contacts | `mailing_contacts`, `mailing_list_subscriptions`, `consent_events`, `suppressions` | Consent (PECR/GDPR) | Consent evidence retained 2 years after last campaign interaction or until erasure where required | Admins |
| Campaign delivery | `campaigns`, `campaign_recipients` | Consent / legitimate interests for send operations | 24 months operational metrics | Admins |
| Engagement | `profiles`, `comments`, `reactions`, `moderation_reports`, `moderation_audits` | Consent / legitimate interests for community features | Soft-deleted comments 12 months; moderation audits 24 months | Admins |
| Privileged audits | `audit_logs`, `import_runs` | Legitimate interests (security / accountability) | 24 months | Admins / super admins |
| Authentication events | `audit_logs` (`auth.*`: sign-in, failure, lockout, sign-out, registration, verification, reset, magic link, staff SSO allow/deny with any reported `amr` methods, two-factor challenge/pass/fail, recovery code use, passkey sign-in) and `account.*` 2FA and passkey changes, with IP address and user agent. No passwords, tokens or links; failed attempts against unknown emails store no email. | Legitimate interests (account security / incident response) | 24 months; on self-service deletion the records are detached from the account (actor cleared) and age out on schedule | Admins / super admins |
| Integration changes | `audit_logs` (`api_token.*`, `webhook.*`): token name/owner, webhook name/host/events. Never token values or webhook secrets. | Legitimate interests (security / accountability) | 24 months | Admins / super admins |
| Two-factor authentication | `users.two_factor_secret` (encrypted with the app key), `users.two_factor_recovery_codes` (hashed, then encrypted), confirmed time, last used time step. Codes are shown once and never emailed or logged. | Necessary for service / legitimate interests (account security) | Until 2FA is turned off or the account is deleted | System; the account holder sees recovery codes once |
| Passkeys | `passkeys`: name chosen by the member, credential ID, public key and authenticator data (no biometrics or private keys; those never leave the device), created and last used times | Necessary for service / legitimate interests (account security) | Until the member removes the passkey or deletes the account | Account holder (names and dates); system |
| Sign-in tokens | `magic_link_tokens` (SHA-256 hash only), `password_reset_tokens` (hashed) | Necessary for service | Magic links purged 7 days after expiry/use; reset tokens cleared after expiry | System |
| Email change requests | `email_change_requests`: requested new address, SHA-256 hash of the confirmation token, expiry, confirmed/cancelled time. The current address is told and can cancel; the change applies only after the new address confirms. | Necessary for service / legitimate interests (account security) | Purged 7 days after expiry; deleted with the account | System |
| Security notifications | Transactional emails for password changes, email change requests, unfamiliar-device sign-ins and API token creation. No codes, tokens or links that grant access. | Legitimate interests (account security) | Not stored beyond mail logs | System |
| Sessions and devices | `user_sessions`: hashed session key, IP address, user agent with parsed browser/OS/device type, remembered flag, last active, ended time. Shown to the account holder with a masked IP; no location lookup. Essential `newsroom_device` cookie identifies a remembered browser. | Necessary for service / legitimate interests (account security) | 90 days after last use or sign-out; deleted with the account | Account holder (own sessions); system |
| API credentials | `api_tokens` (SHA-256 hash, name, last used), `webhook_endpoints` (signing secret) | Legitimate interests (integrations) | Until revoked / deleted | Staff (webhooks), operators (tokens via CLI) |
| Webhook deliveries | `webhook_deliveries` (event payloads, status) | Legitimate interests (integrations) | Operational; no automated purge yet | Staff |
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
