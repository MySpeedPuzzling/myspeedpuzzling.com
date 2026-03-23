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
- Set by: `GrantMembershipHandler`, `ClaimVoucherHandler` (free months, no active subscription)

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
