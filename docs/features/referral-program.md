# Referral Program

## Overview

Members can earn 10% of subscription revenue from people they refer. No discounts for end users — everyone pays the same price. The affiliate earns for as long as the referred subscriber stays active.

## Business Rules

- **Members only** — only players with active membership can join the referral program
- **Self-service** — one-click join from `/referral-program`, no admin approval needed
- **Referral code = player code** — no separate code, if player changes their code the referral link changes too (info alert warns about this)
- **No discounts** — referral program works alongside existing voucher system, if user has a discount the payout is 10% of the discounted price
- **10% payout** — calculated per payment, stored per currency (CZK, EUR, USD shown separately)
- **Manual payouts** — admin marks payouts as paid manually (no automated transfers)
- **One referral per subscriber** — a subscriber can only credit one affiliate
- **Self-referral blocked** — players cannot refer themselves
- **Last-touch wins** — visiting a new `?ref=` link overwrites the previous referral cookie
- **Cookie lasts 30 days** — `referral_ref` cookie, httpOnly, SameSite=lax
- **Admin can suspend** — toggle on `/admin/affiliates` disables a player's referral without removing their data

## Attribution Flow

Two methods, code entry takes priority over cookie:

1. **Referral link** (`?ref=CODE`) — on any URL, sets cookie via redirect (strips `?ref=` param), shows flash message with affiliate name
2. **Referral code input** — on `/membership` page, Live Component validates code in real-time and stores in session

At checkout success:
1. `AttributeReferral` handler creates `Referral` record (session code > cookie code)
2. `CreateAffiliatePayout` handler creates payout for the first payment
3. Cookie is cleared

For renewals, `CreateAffiliatePayout` is dispatched from the Stripe webhook (`invoice.payment_succeeded`). Both dispatches are idempotent via unique `stripe_invoice_id` constraint.

## Data Model

No separate affiliate entity — referral program state lives on `Player`:

- `player.referral_program_joined_at` — null = not enrolled, non-null = active
- `player.referral_program_suspended` — admin toggle

Entities:
- **`Referral`** — links subscriber (`Player`) to affiliate (`Player`), with `ReferralSource` (link/code/manual) and timestamps
- **`AffiliatePayout`** — per-payment record with `stripe_invoice_id` (unique), amounts in cents, currency, `PayoutStatus` (pending/paid)

## Key Files

| Area | Files |
|------|-------|
| Entities | `Entity/Player.php` (referral fields), `Entity/Referral.php`, `Entity/AffiliatePayout.php` |
| Cookie & attribution | `EventSubscriber/ReferralCookieSubscriber.php`, `MessageHandler/AttributeReferralHandler.php` |
| Payout | `MessageHandler/CreateAffiliatePayoutHandler.php`, `Services/StripeWebhookHandler.php` |
| Join | `MessageHandler/JoinReferralProgramHandler.php` |
| Dashboard | `Controller/AffiliateDashboardController.php`, `templates/affiliate_dashboard.html.twig` |
| Code input | `Component/ReferralCodeInput.php`, `templates/components/ReferralCodeInput.html.twig` |
| Profile supporters | `Controller/PlayerProfileController.php`, `Query/GetAffiliateSupporters.php` |
| Admin | `Controller/Admin/AffiliatesController.php`, `templates/admin/affiliates.html.twig` |
| Stripe metadata | `MessageHandler/CreatePlayerStripeCustomerHandler.php` (`referral_player_id`) |

## UI Locations

- **User dropdown menu** — "Referral Program" link with NEW badge
- **Player profile dropdown** — same link (own profile only)
- **Player profile page** — referral link copy + supporters list (own profile only for the link, supporters visible to all)
- **Membership page** — referral code input card (always visible when not subscribed)
- **`/referral-program`** — join CTA or dashboard with stats, referral link, supporters
- **`/admin/affiliates`** — list members with suspend/unsuspend toggle
- **Flash message** — shown on `?ref=` visit after redirect

## Race Condition: First Payment

The Stripe webhook (`invoice.payment_succeeded`) fires before the user returns to checkout success. So `CreateAffiliatePayout` from the webhook finds no referral yet and skips. To fix this, `CreateAffiliatePayout` is also dispatched from `StripeCheckoutSuccessController` after `AttributeReferral`. The handler is idempotent — duplicate dispatches are safe.
