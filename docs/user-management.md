# User management and contact details

Admins manage accounts at **Admin → Users** (`/admin/users`). The area is
Admin+ only; Staff have no access to user records or contact details.
**Admin → Members** (`/admin/members`) is a separate CRM view of public
readers' membership plans and reading activity; account changes happen in Users.

## Who can do what

| Action | Admin | Super admin |
|--------|-------|-------------|
| View the list and user pages | Yes | Yes |
| Edit name, email and contact details | Accounts ranked below them | Anyone except another super admin |
| Lock (suspend), unlock, sign out, credential resets | Accounts ranked below them | Anyone except another super admin |
| Resend the verification email | Accounts ranked below them | Anyone except another super admin |
| Change role (to Public only, non-SSO accounts) | No | Yes |
| Delete a user | No | Anyone except themselves or another super admin |

Nobody can act on their own account from these screens. Every change needs a
reason, recent re-authentication, and is rate limited (`admin-user-actions`:
10 per minute per admin and user, 30 per minute per admin).

Accounts that sign in through Cloudron SSO keep their name, email, role and
credentials in the directory. Their contact details can still be edited.

## Contact details

Users can add an optional postal address, mobile number and WhatsApp contact
on their account page. Admins see and edit them on the user page.

- Mobile and WhatsApp numbers are stored in E.164 form. UK numbers can be typed
  nationally (`07700 900123` is stored as `+447700900123`).
- Postcodes are checked against the UK format when the country is `GB`.
- WhatsApp is a number, a username, or "same as mobile".
- Values are encrypted at rest (`encrypted` casts) and listed in
  `User::$hidden`, so they never serialise by accident. They are not in shared
  Inertia props, public profiles, the Content API or the Admin API.
- The account data download includes them; deleting or anonymising the
  account clears them.

## Lock (suspend)

Lock is the suspension mechanism. It blocks every sign-in method and API
token, signs the user out everywhere, and tells them by email. Unlock reverses it.

## Deleting a user

Super admins delete from the user page by typing the user's email and a
reason. What happens depends on `AccountDeletionPolicy`:

- **Deleted**: Public, non-SSO accounts that own no editorial or audit records
  are removed, with their mailing contact and subscriptions (the same path as
  self-service deletion, `AccountEraser`).
- **Anonymised**: anything else is kept so authored content and audit history
  stay linked. The name becomes "Deleted user", the email becomes
  `erased-{id}@users.invalid`, contact details, password, two-factor, passkeys,
  API tokens, profile, SSO link and mailing contact are removed, the role drops
  to Public, and the account is locked.

## Audit log

| Action | Payload |
|--------|---------|
| `user.updated` | reason; `name` from/to; `email_change_requested`; `contact_details_changed` (group names only) |
| `user.verification_resent` | reason |
| `user.erased` | reason, `mode` (`deleted` or `anonymised`), `user_id` |
| `account.contact_details_updated` | `changed` group names (by the user) |

Contact values are never written to the audit log. The user page lists recent
entries and links to the audit log filtered by actor or by subject
(`?subject_type=User&subject_id={id}`).
