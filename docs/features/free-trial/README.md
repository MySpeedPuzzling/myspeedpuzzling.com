# Free trial

10 days of full membership, once per lifetime, for players who never had a membership. No Stripe,
no card, ends by itself. A marketing lever: let people find out whether membership is useful to
them. The bar is *good UX, never disturbing*.

Status: plan + brainstorm. DeciStatus: **built and rolled out to everyone 2026-09-21** (no feature flag - it shipped dark behind
`FREE_TRIAL_ENABLED` for a few hours, then the flag was removed). This file keeps the reasoning
(D1-D12) as it was planned; where the build differs, "As built" below wins. Build order and rollout:
[`implementation-plan.md`](implementation-plan.md). The modal system: [`../announcement-modals.md`](../announcement-modals.md).

## As built

| Topic | Outcome |
|-------|---------|
| Eligibility | no `membership` row - never subscribed, never claimed a voucher, never granted one, never had a trial (`PlayerProfile::$freeTrialAvailable`) |
| Unlock conditions | the trial can be **started** only when the account is **7 days old** (`FreeTrial::MINIMUM_ACCOUNT_AGE_DAYS`) **and 5 puzzles are logged** (`MINIMUM_LOGGED_PUZZLES`, rows the player tracked, relax entries included) - `PlayerProfile::canStartFreeTrial()` for the UI, `StartFreeTrialHandler` authoritative (`FreeTrialNotUnlockedYet`). Keeps accounts made only to collect trials out. Production 2026-09-21: of 10,128 players without a membership 4,057 have ≥ 5 logged, 2,418 have 1-4, 3,653 none |
| Telling the player | **only what is still missing, never what is met**: one sentence out of three (`free_trial.unlock.age_and_puzzles` / `.age` / `.puzzles`, partial `membership/_free_trial_unlock_sentence.html.twig`) on the membership card and in the members-only modal, with the date the account turns 7 days and "N so far"; the card adds a two-row checklist (met = ticked) and an "Add a solved puzzle" button only when puzzles are what is missing |
| Storage (D1) | `membership.trial_started_at`, `trial_ends_at`, `trial_source`, `trial_ending_reminder_sent_at`, `trial_converted_at` (first subscription of a trial player - funnel only) |
| Verified e-mail (D9) | **not required.** Production, 2026-09-20: of 10,116 players without a membership only 6,378 have a verified e-mail - the condition would have locked ~3,700 players out of a marketing feature to prevent a EUR 6 abuse |
| Surfaces (D4) | membership page card; button in the members-only modal; one-time offer modal, never twice. Button and modal appear only for a player who can start the trial right now - a player who is not there yet keeps the modal unused for the day they are. All three web only (D7) |
| Start | `POST start_free_trial` (stateless CSRF id - the form sits in the layout), always a redirect: back to `return` from a modal, to the welcome page `free_trial_started` otherwise |
| During | membership page: days left + the plan buttons stay ("keep the rest of your trial", hidden in the last 24 h when checkout has no whole day to carry over); topbar badge |
| E-mails | `free_trial_started` (instead of `membership_granted`), `free_trial_ending` 3 days before the end - cron `myspeedpuzzling:send-free-trial-ending-reminders`, daily; nothing at the end |
| After | neutral "Your free trial has ended" instead of the red "expired" |
| Analytics | `activity_daily_summary.active_trials`; trial players are **not** in `active_members` |
| Numbers | Admin → "Free trial & modals" (`/admin/free-trial`): modal impressions, trials by source, conversions |
| Locales | all six, e-mails included |
| Side fix | the "membership granted" e-mail printed `endsAt` (always empty for a grant) as its expiry - now `grantedUntil` |
| After the trial lapses | same as any lapsed member, checked 2026-09-21: existing custom collections stay visible to their owner (members badge), creating / adding to them is gated, nothing is deleted, everything is back on subscribe |
| Cost | the puzzle count rides on the viewer's own profile query, only while the trial is still open to them, and is capped at 5 (`LIMIT`) - no extra query, no slow count for a player with thousands of times |
| Not done | `GrantMembership` still refuses a player who has a membership row (incl. an ended trial) - use a voucher for them, or extend the handler when it is first needed |

### Reading the numbers by SQL

```sql
-- modals: players shown / browsers that confirmed it opened
SELECT modal, count(*) AS displayed, count(seen_at) AS seen FROM player_modal_impression GROUP BY 1;

-- trials by where they were started, and how many became subscriptions
SELECT trial_source, count(*) AS started, count(trial_converted_at) AS subscribed,
       count(*) FILTER (WHERE trial_converted_at <= trial_ends_at) AS subscribed_during_trial
FROM membership WHERE trial_started_at IS NOT NULL GROUP BY 1;
```

y gives us

Findings from reading the membership code — they shape everything below.

| Fact | Where | Consequence |
|------|-------|-------------|
| A free, Stripe-less membership already exists: `membership.granted_until` | `Membership`, `PlayerMembership::isActive()`, `GetPlayerProfile` (`GREATEST(..., granted_until)`), `SnapshotActivityDailySummaryHandler` | **A trial is just a grant.** Set `granted_until = now + 10 days` and all ~100 membership gates (Twig `activeMembership`, API `has_active_membership`, collections, insights, picker filters…) work with zero changes. No new "is member" logic anywhere. |
| A `membership` row is created only by: an `active`/`trialing` Stripe subscription, a claimed voucher, `GrantMembership` | `UpdateMembershipSubscriptionHandler`, `ClaimVoucherHandler`, `GrantMembershipHandler` | **"Never had a membership" ≡ "has no `membership` row"** — exactly, with no false positives from abandoned checkouts (those never create a row). |
| `membership.player` is a `OneToOne` → unique index on `player_id` | `Membership` | Once-per-lifetime is enforced **by the database**: a double-click or two tabs cannot start two trials. |
| Subscribing while a grant runs converts the remaining days into a Stripe trial (`trial_period_days`, `missing_payment_method: pause`) | `MembershipManagement::getMembershipPaymentUrl()` | **Subscribing mid-trial loses nothing**: "Subscribe now, first payment on {trial end}". Already built, already exercised by vouchers. This is the single best conversion argument and it is free. |
| A voucher claimed during a grant stacks *after* it (`extendMembership()` base = `max(now, grantedUntil, endsAt)`) | `ClaimVoucherHandler` | Trial + voucher compose correctly. |
| The membership page hides the subscribe buttons while a grant is active | `templates/membership.html.twig` (`elseif membership.isActive(now)` branch) | **Must change for trials** — a trial user who is convinced on day 4 currently has nowhere to click. |
| `#membersExclusiveModal` in `base.html.twig` is opened from ~60 places | `base.html.twig:1106` | The highest-intent moment in the whole app: the visitor just tried to use a member feature. Best CTA surface, and contextual → not disturbing. |
| `MembershipStarted` is recorded in the `Membership` constructor → `NotifyWhenMembershipStarted` sends `membership_granted` | `NotifyWhenMembershipStarted` | A trial would send the wrong e-mail. Needs a branch. *(Side finding: that handler passes `membership->endsAt` as the expiry, which is `null` for every grant — the "granted" mail has never shown a date. Fix while there: use `grantedUntil`.)* |
| Native apps sell via App Store / Play billing | `membership.html.twig`, `Services/Billing/*` | See D7 — store review risk for a trial offered outside IAP. |

---

## 2. Product decisions

### D1 — Storage: on `membership`, not `player.trial_used_at`

The idea was a `trial_used_at NULL|timestamp` flag. Same spirit, different table:

```
membership.trial_started_at   timestamp NULL
membership.trial_ends_at      timestamp NULL
membership.trial_source       varchar(32) NULL   -- 'membership_page' | 'members_modal' | 'offer_modal' (funnel, D11)
membership.trial_ending_reminder_sent_at timestamp NULL   -- idempotence for the reminder cron (D6)
```

Why here rather than on `player`:

- Starting a trial *creates the membership row anyway* (it is a grant). A second flag on `player`
  would be a redundant copy of "row exists" that can drift.
- Eligibility becomes one rule with one source of truth: **no membership row**. It automatically
  covers "had a subscription", "claimed a voucher", "was granted one by admin" and "already used the
  trial" — and the unique index makes it race-free.
- `trial_ends_at` is stored (not derived as start + 10 d) so changing the length later never rewrites
  history, and SQL stays trivial.
- `granted_until` can move past `trial_ends_at` (voucher claimed mid-trial); keeping both lets the UI
  say "trial" only while it *is* the trial.

"Is in trial right now" = `trial_ends_at > now AND billing_period_ends_at IS NULL` (once they
subscribe they are a subscriber in a Stripe trial, shown as "first payment on …").

### D2 — Length and end

10 days = constant `FreeTrial::DAYS` (one place). Ends exactly `now + 10 days`; UI shows the date and
"X days left". No grace period, no extension, no pause.

### D3 — No card, no auto-conversion

The trial simply ends. This is the honest version and the copy should lean on it:
**"No card. Nothing to cancel. It ends by itself."** It removes the #1 reason people refuse trials
(fear of a forgotten charge) and costs nothing in Stripe fees, disputes or support.

### D4 — Where the offer appears (and where it does not)

Shown **only** when `logged_user.profile.freeTrialAvailable` (= no membership row). Members, ex-members,
voucher users and past trial users never see it.

1. **Membership page** — primary card, *above* "Choose your subscription":
   headline, three-word reassurance line, one button. The paid plans stay directly underneath, so
   nobody who wants to pay is slowed down.
2. **`#membersExclusiveModal`** — when eligible, the primary button becomes
   "Try it free for 10 days", with "See membership options" as the secondary link. The visitor hit a
   wall on a feature they wanted; the trial is the answer to *that* moment. After activation they are
   returned to the page they were on (D5), now unlocked.
   No age gate here: a freshly registered player who clicks a locked feature asked for it.
3. **One-time offer modal** — "Try membership free for 10 days", shown *once per player, ever*, on a
   normal page view, and only to players who are past the newcomer stage. Rules, timing and the
   system that remembers who saw what: [§3 Announcement modals](#3-announcement-modals).
4. **Nowhere else.** No site-wide banner, no toast, no nav badge, no interstitial after registration,
   no e-mail campaign to the existing base in v1. (A one-off newsletter mention is a separate
   marketing choice and needs no code.)

**The offer never expires and we say so.** No countdown, no "only today". A brand-new account with
two logged times gets little from insights/statistics, and a trial burned on day one is a wasted
trial → the membership-page card carries a quiet tip: *"Tip: membership shows the most once you have
a few times logged. The trial waits for you — start it whenever you like."* This is the opposite of
pressure and it raises conversion quality.

### D5 — Activation flow

- One click, `POST` + CSRF, no confirmation modal (the card itself states the terms; a once-per-lifetime
  action is protected from *accidents* by being a deliberate button on a page about membership, not by
  a nag dialog). In the members modal the button is the same POST form.
- Response is always a redirect (Turbo drops 200 answers to full-page POSTs):
  - came from the modal → back to `?return=` (validated via `ReturnUrl::tryFrom()`), with a success flash
    "Your 10-day trial is on — enjoy until {date}";
  - came from the membership page → **welcome page** `free_trial_started`.
- **Welcome page = the conversion work.** A trial nobody explores converts nobody. Short page:
  "You have everything until {date}", then 5–6 deep-linked cards to the member features with the
  player's *own* data (Insights on their profile, predictions, puzzle picker member filters,
  custom collections, collection display mode, statistics/charts). Same cards are reused in the
  welcome e-mail.

### D6 — During and after the trial

- **Membership page while in trial:** status "Free trial — X days left (until {date})", then the
  subscribe buttons *stay visible* with the line "Subscribe now and keep your remaining trial days —
  first payment on {date}". (Existing `trial_period_days` logic makes that literally true.)
- **Topbar:** the existing "Membership" link gets a small muted pill "Trial · 7 d". That is the only
  persistent indicator. Optional — see open decisions.
- **One reminder e-mail, 3 days before the end** ("Your trial ends on {date}" + what they keep /
  lose + subscribe link with the keep-your-days argument). Skipped when they already subscribed or
  hold a grant beyond the trial. Daily cron, idempotent via `trial_ending_reminder_sent_at`.
  The voucher free-period reminder mails (#210, pending) need the same cron shape — build one command
  for both if #210 lands first.
- **At the end: nothing is sent.** No "your trial ended" mail, no notification. The membership page
  swaps the red "Membership expired" for a neutral "Your free trial has ended — hope you enjoyed it"
  above the plans. Member-only data created during the trial follows today's lapsed-member behaviour
  (see `implementation-plan.md` Step 0).

### D7 — Native apps: CTA web-only in v1

Apple guideline 3.1.1/3.1.2 wants unlocks and trials offered through IAP; a custom "start free trial"
button inside the iOS app is a plausible review rejection. So both CTA surfaces render only under
`is_web()`. A trial started on the web works in the apps (membership is account-based), and the
in-trial status shows everywhere. Revisit with store-native introductory offers later if wanted.
Known wrinkle: buying via App Store/Play *during* the trial does not carry the remaining days over
(only the Stripe path does) — acceptable, state it in the in-app status text.

### D8 — E-mails

- `trial_started` (replaces `membership_granted` for trials): end date, the feature cards, "no card,
  ends by itself".
- `trial_ending` (D6).
- Branch in `NotifyWhenMembershipStarted` on `membership->trialEndsAt !== null`.
- **All 6 locales** (decided 2026-09-20): offer modal, members-modal CTA, membership-page cards, welcome
  page and both e-mails. Written in English first, then the `missing-translations` skill; the flag is
  not switched on before the pass is done.

### D9 — Abuse

- Vector: delete account → re-register → new trial. Cost to the abuser: all their data, every 10 days,
  to save €6. Accept in v1.
- Cheap guard worth having: require `user_account.email_verified_at` to start a trial (throwaway
  unverified accounts get nothing). Show "verify your e-mail first" in place of the button.
- Rejected: keeping an e-mail hash after account deletion (still personal data under GDPR, contradicts
  the account-deletion promise).

### D10 — Side effects of being a "member" for 10 days

| Area | Behaviour | Action |
|------|-----------|--------|
| Feature gates, API `has_active_membership` | true during the trial | none — intended |
| Referral program | no revenue → no payout | none |
| `activity_daily_summary.active_members` | trial users would inflate it | add `active_trials` column; exclude trials from `active_members` |
| Supporter badge | to verify — must not be awarded for a trial | check the awarding query, exclude `trial_ends_at IS NOT NULL AND stripe_subscription_id IS NULL AND granted_until <= trial_ends_at` |
| Voucher claimed mid-trial | stacks after the trial | none |
| Lifetime voucher mid-trial | `grantLifetime()` overwrites `granted_until` | none |
| Admin `GrantMembership` to a past trial user | throws `PlayerAlreadyHaveMembership` today (row exists) | pre-existing limitation, now hit more often → make the handler extend an existing row (small, separate change) |
| GDPR delete | membership row already removed by `DeletePlayerHandler` | none |

### D11 — Measurement

The feature is a marketing bet; decide up front how to judge it.

- `trial_source` column → which surface works.
- Report SQL (in this doc once built; admin page only if the numbers earn it):
  trials started / week · % subscribed during trial · % subscribed within 30 days after ·
  baseline: % of same-age non-trial accounts subscribing.
- Success criterion to agree before launch (suggestion: trial→paid ≥ 8–10 % within 30 days, and no
  drop in direct subscriptions from eligible players — the cannibalisation check).

### D12 — Kill switch

Feature flag `FREE_TRIAL_ENABLED` (document in `docs/features/feature_flags.md`). Off = both CTAs
and the start route disappear; **running trials keep running** (they are plain grants). Lets the
trial ship dark, be switched on together with a newsletter, and be paused without a deploy.

---

## 3. Announcement modals

### What exists today

`player.modal_displayed` — one boolean (born as `wjpc_modal_displayed`, renamed 2025-02), read through
`PlayerProfile::$modalDisplayed`. `base.html.twig` renders `#global-modal` (`autoshow-modal` Stimulus
controller) when it is `false`; `HideModalListener` flips it on the first HTML response via `HideModal`.
The block is currently disabled with `and false`.

Why it cannot carry the trial offer:

- **One flag, one modal, no memory.** A new announcement means `UPDATE player SET modal_displayed = false`
  and the knowledge of who saw the previous one is gone. Two modals cannot coexist.
- **No targeting and no timing.** It is "everyone, on their next page view" — a player who registered
  ten seconds ago gets it on their first page.
- **It records *rendered*, not *seen*.** The listener fires on any non-redirect, non-JSON main response —
  Turbo Frame and Live Component responses, 404s, pages where the modal was not even rendered. Right now,
  with the block disabled, every new player is still marked as "displayed" on their first request
  (one wasted Messenger write per registration).

### Replacement: per-player, per-modal impressions + a resolver

```
player_modal_impression
  id            uuid
  player_id     uuid  FK player, ON DELETE CASCADE
  modal         varchar(64)      -- AnnouncementModal enum value
  displayed_at  timestamp        -- claimed at render, decides display
  seen_at       timestamp NULL   -- browser-confirmed, measurement only
  UNIQUE (player_id, modal)
```

- **`Value/AnnouncementModal`** enum — one case per modal, first case `FreeTrialOffer = 'free_trial_offer'`.
  Each case owns a partial `templates/modals/announcements/_{value}.html.twig` that **stays in the code
  permanently**; whether it is shown is decided purely by rules, never by editing `base.html.twig`.
- **`Services/AnnouncementModals/ResolveAnnouncementModal`** — returns *at most one* modal for the current
  page view, or null. Order of checks, cheapest first:
  1. viewer is signed in, request is a full-page `GET` (no `Turbo-Frame` header, not a Live Component call),
     `is_web()` (D7);
  2. route is not on the **quiet list** — pages where an interruption is rude or harmful: membership,
     buy/checkout/billing, claim voucher, add/edit time, stopwatch, login/registration/password/account
     deletion, any page opened with `?return=` mid-flow, admin;
  3. **global cool-down**: no announcement modal shown to this player in the last 14 days (matters from
     the second modal on — guarantees nobody is ever stacked with popups);
  4. per-modal rule (`AnnouncementModalRule` interface, one tagged service per enum case), in enum order.
- **No extra query on ordinary page views.** `GetPlayerProfile` (already loaded on every signed-in request,
  already `LEFT JOIN membership`) gains `registered_at` and one subselect returning the player's
  impressions as JSON → `PlayerProfile::$modalImpressions`. Anything heavier is evaluated lazily, only
  after all cheap checks pass — and stops for good once the modal has been shown.
- **Claimed at render, atomically** — see "Never twice" below. Unlike the old listener, the claim happens
  only when the modal is really going into the HTML of a full page, never on frames, 404s or pages
  without the modal. `autoshow-modal` additionally POSTs to `/-/modal-seen` on `shown.bs.modal`
  (`MarkAnnouncementModalSeen`, 204) to fill `seen_at` for the funnel.
- Relation to **hint dismissing** (`dismissed_hint`): deliberately separate. Hints are inline banners on
  one page that the *player* closes; announcement modals are *system-scheduled*, site-wide, arbitrated
  against each other and recorded when shown, not when closed.

### Rule for `FreeTrialOffer`

Shown when **all** hold:

| Condition | Source | Why |
|-----------|--------|-----|
| `FREE_TRIAL_ENABLED` | flag | kill switch (D12) |
| no membership row (`freeTrialAvailable`) | profile | never to members, ex-members, voucher users, past trials |
| e-mail verified | profile | the button must work when clicked (D9) |
| registered **> 24 hours** ago | profile `registered_at` | not in somebody's first session (decided 2026-09-20; to be raised later) |
| not shown before | `modalImpressions` | once, ever — see "Never twice" below |

The age gate is one constant, `FreeTrialOfferRule::MINIMUM_ACCOUNT_AGE_HOURS = 24`. Raising it later is a
one-line change and needs no data fix: players who already saw the modal stay recorded, players who have
not simply wait longer. No activity condition (solving-times count) for now — it keeps the rule free of
any extra query; add it together with a higher age gate if the modal turns out to reach empty accounts.

### Never twice

"Once, ever" is a hard guarantee, so the impression is **claimed before the modal is rendered**, not
reported afterwards by the browser:

1. All rule checks pass → the resolver dispatches `ClaimAnnouncementModalImpression(playerId, modal)` (sync).
2. The handler does `INSERT … ON CONFLICT (player_id, modal) DO NOTHING` and returns whether a row was
   written.
3. Only `true` renders the modal. Two tabs loading at the same moment, a double request, a retried
   response: exactly one of them wins, the others render nothing.

The cost of this direction: a page that was rendered but never looked at (tab closed while loading)
still uses the impression. That is the right side to err on — the offer stays on the membership page and
in the members modal — and the strict pre-checks (signed-in, full-page `GET`, no `Turbo-Frame`, not a
Live Component call, not a quiet route) keep such losses rare. A browser-side report cannot give the
guarantee: any lost POST means a second display.

`seen_at` (nullable) on the same row is still filled from `shown.bs.modal` — purely for measurement
(rendered vs actually seen vs trial started), never consulted for display.

**Launch-day effect:** every existing player without a membership row (most of the ~10k base) qualifies at once and sees
the modal on their next visit. That is the intended marketing push; it is also a burst of trial starts
and `trial_started` mails — all within the transactional mail limits, but worth timing (not the same
day as a newsletter).

### The modal itself

Friendly, small, leaves instantly:

- Title: **Try membership free for 10 days**
- Body: two sentences on what unlocks, with the player's first name; then "No card. Nothing to cancel.
  It ends by itself."
- Primary: **Start my free trial** (POST form, explicit `action`, `source = offer_modal`, `return` = current
  path → they land back on the same page with the success flash).
- Secondary link: *What do I get?* → membership page.
- Tertiary: **Maybe later** + the ✕. Both just close. No "don't show again" needed — it never shows again.
  The line under the buttons says where to find it: "You can start it any time from the Membership page."

### Legacy clean-up (same change)

Remove `player.modal_displayed` (generated migration), `Player::markModalAsDisplayed()`, `HideModal` +
handler, `HideModalListener`, `PlayerProfile::$modalDisplayed` + the two SQL columns, the disabled
`#global-modal` block and the `global_modal.*` keys in all locales. No backfill: the old flag says nothing
about the new modal.

---

## 4. UX copy (English draft)

| Key | Text |
|-----|------|
| `free_trial.offer.title` | Try membership free for 10 days |
| `free_trial.offer.reassurance` | No card. Nothing to cancel. It ends by itself. |
| `free_trial.offer.button` | Start my free trial |
| `free_trial.offer.tip` | Tip: membership shows the most once you have a few times logged. The trial waits for you — start it whenever you like. |
| `free_trial.offer.once` | One trial per player. |
| `free_trial.modal.button` | Try it free for 10 days |
| `free_trial.modal.secondary` | See membership options |
| `free_trial.offer_modal.title` | Try membership free for 10 days |
| `free_trial.offer_modal.text` | %name%, you have been logging times for a while — see what membership adds: insights into your solving, time predictions, full statistics and more. |
| `free_trial.offer_modal.later` | Maybe later |
| `free_trial.offer_modal.what_do_i_get` | What do I get? |
| `free_trial.offer_modal.find_it_later` | You can start it any time from the Membership page. |
| `free_trial.flash.started` | Your free trial is on — everything is unlocked until %date%. |
| `free_trial.status.active` | Free trial — %days% days left |
| `free_trial.status.keep_days` | Subscribe now and keep your remaining trial days — your first payment would be on %date%. |
| `free_trial.status.ended` | Your free trial has ended. We hope you enjoyed it! |
| `free_trial.verify_email_first` | Verify your e-mail address to start the free trial. |
| `free_trial.not_available` | The free trial is for players who have not had a membership yet. |

---

## 5. Implementation plan

File-level build plan, ordered steps, tests and rollout: [`implementation-plan.md`](implementation-plan.md).

---

## Open decisions

1. **D1** — OK to store on `membership` instead of `player.trial_used_at`? *(recommended: yes)*
2. **D4** — ~~Members modal as a second CTA surface~~ **decided 2026-09-20: yes, for everyone eligible incl. fresh accounts; plus a one-time offer modal for established players (§3).**
   **Decided 2026-09-20: age gate = registered > 24 h (constant, to be raised later), no activity condition, never displayed twice (claimed at render).**
   Still open: the **14-day global cool-down** between announcement modals (only matters once a second modal exists).
3. **D6** — Topbar "Trial · 7 d" pill: yes/no? *(recommended: yes, it is the only persistent cue and it is tiny)*
4. **D6** — One reminder e-mail 3 days before the end, nothing at the end: agreed? 
5. **D7** — Web-only CTA in v1 because of store review risk: agreed?
6. **D8** — ~~Translate to all 6 locales~~ **decided 2026-09-20: yes, all locales.**
7. **D9** — Require verified e-mail to start? *(recommended: yes)*
8. **D11** — Success criterion and review date (suggest: look at the numbers 6 weeks after launch).
9. **D12** — Ship behind `FREE_TRIAL_ENABLED`? *(recommended: yes)*
