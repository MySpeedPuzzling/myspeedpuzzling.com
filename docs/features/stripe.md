# Stripe Subscription & Membership System

## Membership Fields

The `membership` table has four date fields that together determine whether a player has an active membership:

| Field | Managed by | Purpose |
|-------|-----------|---------|
| `ends_at` | Stripe webhooks | Subscription lifecycle state. `null` = active subscription, `= now` = paused/failed, `= period end` = cancelled |
| `billing_period_ends_at` | Stripe webhooks | Next billing date. Used as fallback when `ends_at` is null |
| `granted_until` | Admin / vouchers | Manually granted membership end date. Never touched by Stripe webhooks |
| `renewed_billing_period_end` | Stripe webhooks | Deduplication: tracks which billing period's renewal event was already emitted |

### Active Membership Check

```sql
GREATEST(
    COALESCE(ends_at, billing_period_ends_at, '1970-01-01'),
    COALESCE(granted_until, '1970-01-01')
) > NOW()
```

The player has an active membership if **either** the subscription date or the grant date is in the future. This means a cancelled subscription with an active grant still has membership.

## Membership States

### 1. No membership
- No row in `membership` table

### 2. Free/granted membership (no Stripe)
- `stripe_subscription_id`: null
- `ends_at`: null
- `billing_period_ends_at`: null
- `granted_until`: future date
- Set by: `GrantMembershipHandler`, `ClaimVoucherHandler` (free months, no active subscription; lifetime → `2199-12-31`, see [Lifetime Voucher](#lifetime-voucher))

### 3. Active Stripe subscription
- `stripe_subscription_id`: `sub_...`
- `ends_at`: null
- `billing_period_ends_at`: next billing date
- `granted_until`: may or may not be set (independent)

### 4. Subscription cancelled (cancel_at_period_end)
- `stripe_subscription_id`: `sub_...`
- `ends_at`: billing period end date (access continues until then)
- `billing_period_ends_at`: null (cleared by `cancel()`)
- Player keeps access until `ends_at`; after that, `granted_until` takes over if set

### 5. Subscription paused / payment failed (past_due, incomplete, unpaid, paused)
- `ends_at`: now (membership paused immediately)
- `billing_period_ends_at`: still set
- If `granted_until` is in the future, membership stays active despite subscription failure

### 6. Subscription deleted (customer.subscription.deleted)
- `ends_at`: now
- `billing_period_ends_at`: null
- `granted_until` preserved — if still in the future, membership continues

## Stripe Webhook Flow

### Handled Events

| Stripe Event | Handler | `isPaymentConfirmed` |
|-------------|---------|---------------------|
| `customer.subscription.created` | `UpdateMembershipSubscription` | false |
| `customer.subscription.updated` | `UpdateMembershipSubscription` | false |
| `customer.subscription.deleted` | `CancelMembershipSubscription` | — |
| `invoice.payment_succeeded` | `UpdateMembershipSubscription` | **true** |
| `invoice.payment_failed` | `NotifyAboutFailedPayment` | — |

### Normal Renewal Flow

```
T+0s    invoice.created (draft)
        - Not handled by app (Stripe keeps invoice in draft ~1 hour)

T+0s    customer.subscription.updated (status: active, new period_end)
        - billingPeriodEndsAt updated to new period (prevents 1-hour gap)
        - endsAt cleared to null
        - No renewal event emitted (payment not confirmed yet)

T+~1h   invoice.finalized
        - Not handled by app

T+~1h   invoice.payment_succeeded
        - MembershipSubscriptionRenewed event emitted
        - renewedBillingPeriodEnd updated (dedup marker)
        - Renewal notification email sent
```

### Failed Payment Flow

```
T+0s    customer.subscription.updated (status: active)
        - billingPeriodEndsAt updated to new period

T+~1h   invoice.payment_failed
        - Notification email sent (first attempt only)

T+~1h   customer.subscription.updated (status: past_due)
        - endsAt = now (membership paused)
        - If granted_until is in the future, membership stays active

T+3d    Stripe retries payment...

T+3d    invoice.payment_succeeded (if retry works)
        - endsAt cleared to null (membership resumes)
        - MembershipSubscriptionRenewed event emitted

T+3d    customer.subscription.updated (status: active)
        - Confirms active state
```

### Cancellation Flow (cancel_at_period_end)

```
        customer.subscription.updated (cancel_at_period_end: true)
        - cancel(billingPeriodEnd) called
        - endsAt = billing period end date
        - billingPeriodEndsAt = null
        - Player keeps access until endsAt
        - MembershipSubscriptionCancelled event emitted

        ...period ends...

        customer.subscription.deleted
        - endsAt = now (already past period end, so no practical change)
        - Claimed discount voucher cleared on player
```

### Immediate Cancellation

```
        customer.subscription.deleted
        - endsAt = now (immediate loss of subscription access)
        - If granted_until > now, membership continues via grant
```

## Grant vs Subscription Interaction

### Scenario: Player with grant subscribes

1. Admin grants membership until June 1 -> `granted_until = June 1`
2. Player buys subscription -> `customer.subscription.created` fires
3. `ends_at = null`, `billing_period_ends_at = April 23`, `granted_until = June 1` (untouched)
4. Active check: `GREATEST(April 23, June 1) = June 1 > now` -> active

### Scenario: Subscription cancelled, grant still valid

1. Player cancels subscription -> `ends_at = April 23`, `billing_period_ends_at = null`
2. April 24: subscription expired, but `granted_until = June 1 > now`
3. Membership still active via grant

### Scenario: Payment fails, grant still valid

1. Payment fails -> `ends_at = now` (past_due)
2. `granted_until = June 1 > now` -> membership still active
3. Player doesn't lose access despite payment failure

### Scenario: Grant expires, subscription still active

1. `granted_until = Jan 1 2025` (expired)
2. `ends_at = null`, `billing_period_ends_at = April 23 2026`
3. Active check: `GREATEST(April 23, Jan 1) = April 23 > now` -> active via subscription

## Voucher Integration

### Free Months Voucher

**With active subscription:** Sets `subscription.trial_end` to extend billing pause. The subscription billing is deferred, not cancelled. `billing_period_ends_at` updated to match trial end.

**Without active subscription:** Sets `granted_until` on the membership (or creates new membership with `granted_until`). When the player later subscribes, `MembershipManagement` calculates trial days from `granted_until`.

### Percentage Discount Voucher

**With active subscription:** Creates Stripe coupon and applies to subscription immediately.

**Without active subscription:** Stored on `player.claimed_discount_voucher`. Applied during next Stripe checkout. Cleared on subscription cancellation.

### Lifetime Voucher

Grants a membership that never ends. There is no dedicated column: `granted_until` is set to `LifetimeMembership::GRANTED_UNTIL` (`2199-12-31 23:59:59`), so every existing active-membership check keeps working. Always ask `hasLifetimeGrant()` before showing that date to a player and say "Lifetime" instead.

Single-use like free months (`voucher.used_at`). The voucher code itself still has a normal claim deadline (`valid_until`).

**With a Stripe subscription:** the subscription is stopped so the player is never charged again.
- `active` / `trialing` → `cancel_at_period_end: true` (the already-paid period runs out; skipped if already set)
- `past_due` / `unpaid` / `incomplete` / `paused` → cancelled immediately, so Stripe stops retrying the charge
- `canceled` / `incomplete_expired` → nothing to do

The webhooks that follow only move `ends_at`; `granted_until` keeps the membership active. `NotifyWhenMembershipSubscriptionCancelled` skips the "membership cancelled" e-mail for lifetime members.

**Without a subscription:** sets `granted_until` on the existing membership, or creates a new one.

**Afterwards:** a lifetime member cannot claim any other voucher (`PlayerAlreadyHasLifetimeMembership`, the code stays unused), and `MembershipManagement` refuses checkout (`PlayerAlreadyHaveMembership`) — Stripe would reject a trial running until 2199 anyway.

Generate with: `php bin/console myspeedpuzzling:vouchers:generate --type=lifetime --valid-until=2026-12-31 [--count=5] [--note="..."]` (no `--months`/`--percentage`, `--max-uses` must stay 1).

## Switching plans (monthly <-> yearly)

Members on the **regular** monthly (`puzzlership_monthly`, $6/€6/150 Kč) or yearly (`puzzlership_yearly`) price can switch to the other one from the membership page. The early adopter prices (`puzzlership_*_promo`, another product) never can - Jan's call 2026-11-02: that price was offered without a yearly option.

**Stripe does the money, we only open the right page.** The "Switch to yearly / monthly" button (`templates/membership/_plan_switch.html.twig`, POST + CSRF `membership_plan_switch`, `data-turbo="false"`) goes to `SwitchMembershipPlanController` → `MembershipPlanSwitching::confirmationUrl()`, which creates a billing portal session with `flow_data[type]=subscription_update_confirm` (the subscription item + the other price, the member's locale) on a **second portal configuration**. Stripe's page shows the exact amount, takes the payment (3-D Secure included) and sends the member to `membership_plan_switched`, which reads the subscription once (`UpdateMembershipSubscription`, sync) so the page shows the new plan before the webhook arrives.

- **Up to yearly:** prorated and charged at once (`always_invoice`) - the unused part of the month is credited, the year starts that day. Measured in test mode: a fresh €6 month → €54 today, €60 from then on; a 20 % forever coupon stays on the subscription (€48/year, €43.20 today).
- **Down to monthly:** booked for the end of the paid year (`schedule_at_period_end` on `shortening_interval`) - Stripe attaches a subscription schedule (yearly phase, then monthly, `end_behavior: release`); nothing is charged or credited, nobody is owed a refund. Stripe's portal cannot undo it (it keeps "Continue" disabled on the current price, a confirm deep link to it is refused with "no changes to confirm"), so **we do**: the page shows "Keep the yearly plan" next to "Moves to the monthly plan on …" → `KeepMembershipPlanController` (POST + CSRF `membership_keep_plan`) → `MembershipPlanSwitching::keepCurrentPlan()` **releases** the schedule - the subscription carries on as it is, nothing is charged (verified in test mode: still active, yearly, same renewal date, no invoice). Only a schedule exactly like the one the portal books (`Value\BookedPlanSwitch`: this customer's subscription, `active`, `end_behavior: release`, two phases - the current regular plan until the period end, then the other one, back to back, one item of quantity 1) - anything else is left alone. **Never `cancel`**: cancelling a schedule cancels its subscription at once (the service test fails if `cancel` is ever called). Support doing it by hand in the Dashboard: "Release", never "Cancel".

**Two checks, one rule each.** The page offers the switch from our copy (`PlayerMembership::planSwitchTarget()`: regular plan, nothing booked, not cancelled, no running grant days - a trial or voucher's days run on the subscription). Before Stripe is asked, the **live** subscription decides (`Value\MembershipPlanSwitchEligibility::targetFor()`): this customer's, `active` (not trialing - prorating would end the trial and charge), not cancelling, no `pause_collection` (voucher free months), no schedule, no `pending_update`, exactly one item of quantity 1 on a regular price. A refused switch says so in a flash and asks nothing of Stripe.

**The second portal configuration.** The default configuration ("Manage payments") keeps switching off - with it on, every member, the early adopters included, would get "Update plan" there. `myspeedpuzzling:stripe:sync-plan-switch-portal [--dry-run]` creates (or, found again by metadata `msp_purpose=plan_switch`, updates) a configuration that copies everything the default allows (customer details, invoices, payment method, cancellation with its reasons) on every run, adds the switch between exactly the two regular prices and turns off its login page. Its id goes into `STRIPE_PLAN_SWITCH_PORTAL_CONFIGURATION` - empty = the button is gone and the controller refuses. Branding (logo, colours) is account-wide, so the page looks like the default one. Run the command again after changing the default configuration in the Dashboard.

**What we store.** `membership.stripe_price_lookup_key` (the price the subscription is on) and `scheduled_price_lookup_key` + `scheduled_price_change_at` (the next phase of a schedule), written by `UpdateMembershipSubscriptionHandler` on every subscription webhook (`Membership::rememberStripePrice()`). Display and the first check only - never a reason to change anything. The page shows "Monthly plan" / "Yearly plan" and "Moves to the monthly plan on …". After the release that added them, `myspeedpuzzling:stripe:refresh-subscriptions [--dry-run]` reads every running subscription once through the same handler (no payment confirmed = no renewal e-mail).

A switch also produces the usual webhooks: `invoice.payment_succeeded` for the proration invoice (renewal notice + referral payout like any paid invoice) and `customer.subscription.updated` (the new period end - `billingPeriodEndsAt` only ever moves forward, which both directions do).

## Concurrency & Idempotency

- All webhook handlers acquire a lock on `stripe-subscription-{id}` to prevent race conditions
- Stripe doesn't guarantee webhook delivery order — the code handles any ordering of `customer.subscription.updated` and `invoice.payment_succeeded`
- `renewedBillingPeriodEnd` prevents duplicate `MembershipSubscriptionRenewed` events when both webhooks arrive
- Old subscription webhooks are ignored if the player already has an active membership with a different subscription ID

## Stripe API Notes

- **API version 2025+**: `current_period_end` is on `subscription.items.data[0]`, not on the subscription root
- **API version 2025+**: Invoice subscription ID is at `invoice.parent.subscription_details.subscription`
- **Draft invoice period**: ~1 hour by default (Stripe setting, minimum 1 hour). Cannot be reduced via dashboard. Can be bypassed by calling `POST /v1/invoices/{id}/finalize` on `invoice.created`, but not needed with the current approach
- **Subscription statuses**: `active`, `trialing`, `past_due`, `canceled`, `incomplete`, `incomplete_expired`, `unpaid`, `paused` — all handled
