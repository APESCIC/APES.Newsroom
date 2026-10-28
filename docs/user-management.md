# User management and contact details

Admins manage account security at **Admin → Users** (`/admin/users`).
**Admin → Members** (`/admin/members`) is a CRM view of public readers'
membership plans and reading activity.

Since v1.21.0 (#567) every person also has a **person page** in the staff
panel: **Staff → People** (`/staff/people/users/{id}`) for accounts and
`/staff/people/contacts/{id}` for mailing-list subscribers without one. It
joins the account, contact details, membership, newsletters and public
profile. Users, Members and Subscribers all link to it.

## Who can do what

| Action | Staff | Admin | Super admin |
|--------|-------|-------|-------------|
| View People and person pages | Public accounts | Yes | Yes |
| Edit name, email and contact details | Public accounts | Accounts ranked below them | Anyone except another super admin |
| View and edit mailing-only subscribers | Yes | Yes | Yes |
| Delete a mailing-only subscriber | No | Yes | Yes |
| Edit or reset public profile fields | Public accounts | Accounts ranked below them | Anyone except another super admin |
| Complimentary access, member note, member newsletter toggles | No | Accounts ranked below them | Anyone except another super admin |
| View the Users list and security pages | No | Yes | Yes |
| Lock (suspend), unlock, sign out, credential resets | No | Accounts ranked below them | Anyone except another super admin |
| Resend the verification email | No | Accounts ranked below them | Anyone except another super admin |
| Change role (to Public only, non-SSO accounts) | No | No | Yes |
| Delete a user | No | No | Anyone except themselves or another super admin |

Nobody can act on their own account from these screens. Every change needs a
reason, recent re-authentication, and is rate limited (`admin-user-actions` on
`/admin/users`, `people-actions` on `/staff/people`: 10 per minute per actor
and person, 30 per minute per actor).

An email edit by staff or an admin only starts the confirmation flow: the new
address gets a link, the current address is told, and the change applies when
the member confirms it. Active newsletter subscriptions then move to the new
address and ask for confirmation again.

Accounts that sign in through Cloudron SSO keep their name, email, role and
credentials in the directory. Their contact details can still be edited.

## Contact details

Users can add an optional postal address, mobile number and WhatsApp contact
on their account page. Admins see and edit them on the user page; staff see
and edit them for public accounts on the person page.

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
| `membership.comp_granted`, `membership.comp_removed` | reason |
| `membership.note_updated` | reason, `cleared` (the note text is never logged) |
| `profile.fields_updated` | reason, `fields_changed` |
| `profile.fields_reset` | reason, `fields_reset` |
| `mailing_contact.updated` | reason, `fields_changed`, `reconfirmation_requested` |
| `mailing_contact.suppressed`, `mailing_contact.unsuppressed` | reason |
| `mailing_contact.deleted` | reason, `suppressed` |
| `mailing_subscription.unsubscribed` | reason, `subscription_id`, `newsletter_id` |
| `mailing_subscription.resubscribe_requested` | reason, `newsletter_id` |

Contact values, email addresses, notes and profile text are never written to
the audit log. The user page lists recent
entries and links to the audit log filtered by actor or by subject
(`?subject_type=User&subject_id={id}`).
