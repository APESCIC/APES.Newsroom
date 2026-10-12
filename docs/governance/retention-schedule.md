# Retention schedule (issue #10)

Draft retention periods for operators. Confirm with legal before cutover.

| Record | Retention | Disposal |
|--------|-----------|----------|
| Published posts | While published + historical archive | Soft delete → hard delete after 12 months unless legal hold |
| Post revisions | 24 months after post soft-delete | Cascade with post hard delete |
| Account data | Until user deletion | Export available; delete within 30 days of request |
| Consent events | 2 years after last relevant mailing activity | Anonymise email if erasure required and suppression must remain |
| Suppressions | Indefinite while needed to honour objection | Keep hashed/minimal email if full erasure conflicts with PECR honouring |
| Campaign recipients | 24 months | Delete or aggregate |
| Moderation audits / audit logs (including authentication events) | 24 months (`SECURITY_AUDIT_RETENTION_MONTHS`) | Delete — `audit_logs` enforced daily by `newsroom:purge-security-data` |
| Magic link tokens | 7 days after expiry or use (`SECURITY_MAGIC_LINK_RETENTION_DAYS`) | Delete — `newsroom:purge-security-data` |
| Password reset tokens | Until expiry (60 minutes) | Delete — scheduled `auth:clear-resets` |
| Session and device records | 90 days after last use or sign-out (`SECURITY_SESSION_RECORD_RETENTION_DAYS`) | Delete — `newsroom:purge-security-data`; removed with the account on deletion |
| Import runs / reports | 24 months | Delete files + DB rows |
| Application logs | 90 days | Rotate / purge |
| Backups (Cloudron) | Per Cloudron backup policy (document at deploy time) | Restore drills recorded on #3/#11 |

Operators must not use content or mailing imports to send email. Final production imports remain gated by #11 authorization.
