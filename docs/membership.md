# Membership billing (test mode)

Free members are public accounts with a `memberships` row. Paid tiers use Stripe Checkout in **test mode only**.

## Environment

Set these in `/app/data/shared/.env` on Cloudron beta (or local `.env`) when exercising checkout against Stripe:

```env
STRIPE_SECRET=sk_test_...
STRIPE_PUBLISHABLE=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_MONTHLY=price_...
STRIPE_PRICE_YEARLY=price_...
```

Leave `STRIPE_SECRET` empty in local/CI to use the fake billing client. Seeded plans still appear; checkout URLs come from the fake.

Webhook endpoint: `POST /stripe/webhook` (CSRF exempt). Point a Stripe CLI or Dashboard webhook at that path for events:

- `checkout.session.completed`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.payment_failed`

## Staff

`/staff/membership-plans` edits plan names, amounts, Stripe price ids, and active flags.

## Authorization boundary

Live Stripe keys, real charges, production cutover, Ghost retirement, and new campaign live-sends are **not** authorized by shipping this feature. Ops sign-off is required before live mode.
