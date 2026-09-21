# Free trial — technical implementation plan

Companion to [`README.md`](README.md) (product decisions D1–D12, announcement-modal design). This file is
the build order: what to create, what to change, how to test it, how to roll it out.

Status: **built 2026-09-21** in one change (steps 1-5 together, one migration). What remains is the
rollout at the bottom. Deviations from this plan are listed in README "As built".

## Shape of the work

| Step | Content | Depends on | Ships |
|------|---------|-----------|-------|
| 0 | Verifications that can change the plan | — | nothing |
| 1 | Trial domain: columns, `StartFreeTrial`, read side, flag | 0 | dark (no UI) |
| 2 | Trial UI: membership page, members modal CTA, welcome page, start route, e-mail | 1 | behind flag |
| 3 | Announcement modals: impressions table, resolver, offer modal, legacy flag removal | 1 (start route from 2 for the button) | behind flag |
| 4 | Lifecycle: ending reminder cron, `GrantMembership` on existing row | 1 | behind flag |
| 5 | Measurement, translations, docs, switch on | 2–4 | **launch** |

Steps 2, 3 and 4 are independent of each other once 1 is in. Each step ends green on the full gate:
`phpstan`, `cs-fix`, `phpunit --testsuite "Project Test Suite"`, `doctrine:schema:validate`, `cache:warmup`.

Two migrations in total (Step 1, Step 3), both **generated** with `doctrine:migrations:diff` against a
scratch database built from the committed migrations (the dev DB carries branch drift and would emit
unrelated `DROP`s). Never run `migrate` — Jan does.

---

## Step 0 — verify first

1. **Lapsed-member behaviour of member-only data.** A trial multiplies how often a membership lapses.
   Confirmed so far: `CreateCollectionController` refuses creation for non-members,
   `ResolveCollectionDisplay` falls back when `activeMembership` is false. To read before building:
   what a lapsed member sees of *existing* custom collections (detail, add/move forms, API
   `collections` providers) and of `player.collection_display_mode`. Required outcome: nothing deleted,
   data hidden or read-only, everything back on subscribe. If any path deletes or 500s → fix first,
   as its own commit.
2. **Supporter badge** — checked: on `main`, `BadgeType::Supporter` is only read (`GetBadges`), nothing
   awards it. No action here; leave a note in the badges work that a trial (`trial_ends_at IS NOT NULL`
   and `stripe_subscription_id IS NULL`) must not earn it.
3. **Checkout from a trial grant** in Stripe test mode: start trial → subscribe → Checkout shows
   "N days free" → webhook `trialing` → membership page says "first payment on …". Include the
   `< 24 h left` case: `$now->diff()->days` is `0`, no Stripe trial is applied and the player is
   charged immediately — the keep-your-days line must be hidden when `freeTrialDaysLeft < 1`.

---

## Step 1 — trial domain (dark)

### New

| File | Content |
|------|---------|
| `src/Value/FreeTrial.php` | `final class`, `public const int DAYS = 10;` `endsAt(DateTimeImmutable $start): DateTimeImmutable` |
| `src/Value/FreeTrialSource.php` | `enum: string` — `MembershipPage = 'membership_page'`, `MembersModal = 'members_modal'`, `OfferModal = 'offer_modal'` |
| `src/Message/StartFreeTrial.php` | `readonly final`: `string $playerId`, `FreeTrialSource $source` |
| `src/MessageHandler/StartFreeTrialHandler.php` | see below |
| `src/Exceptions/FreeTrialNotAvailable.php` | `#[WithHttpStatus(409)]` |
| `src/Exceptions/FreeTrialRequiresVerifiedEmail.php` | `#[WithHttpStatus(409)]` — only if D9 is confirmed |
| `src/Services/FreeTrialSettings.php` | `isEnabled(): bool` — the single decision point for the flag (pattern: `SocialLoginSettings`, `CoPuzzlerPicker::isEnabled()`) |

### Changed

**`src/Entity/Membership.php`** — four nullable properties (declared like `$stripeDiscountCouponId`, outside
the constructor, so every existing `new Membership(...)` call keeps compiling):

```php
#[Column(nullable: true)] public null|DateTimeImmutable $trialStartedAt = null;
#[Column(nullable: true)] public null|DateTimeImmutable $trialEndsAt = null;
#[Column(length: 32, nullable: true, enumType: FreeTrialSource::class)] public null|FreeTrialSource $trialSource = null;
#[Column(nullable: true)] public null|DateTimeImmutable $trialEndingReminderSentAt = null;

public static function startFreeTrial(UuidInterface $id, Player $player, DateTimeImmutable $now, FreeTrialSource $source): self
{
    $endsAt = FreeTrial::endsAt($now);
    $membership = new self($id, $player, $now, grantedUntil: $endsAt);   // records MembershipStarted
    $membership->trialStartedAt = $now;
    $membership->trialEndsAt = $endsAt;
    $membership->trialSource = $source;

    return $membership;
}
```

**`StartFreeTrialHandler`** — validate everything before touching the entity manager
(a rolled-back handler's entities leak into a later flush):

1. `FreeTrialSettings::isEnabled()` false → `FreeTrialNotAvailable`.
2. `playerRepository->get()` (throws `PlayerNotFound`).
3. `membershipRepository->getByPlayerId()` found → `FreeTrialNotAvailable`.
4. (D9) `UserAccount` for `player->userId` has `emailVerifiedAt === null` → `FreeTrialRequiresVerifiedEmail`.
5. `membershipRepository->save(Membership::startFreeTrial(Uuid::uuid7(), …, $this->clock->now(), …))`.

The unique index on `membership.player_id` is the race backstop: a concurrent second start dies with
`UniqueConstraintViolationException` inside the transaction → surfaces as `HandlerFailedException` →
the controller treats it like `FreeTrialNotAvailable`.

**Read side**

- `src/Query/GetPlayerProfile.php` — both statements (`byId`, `byUserId`) already `LEFT JOIN membership`. Add:
  `(membership.id IS NULL) AS free_trial_available`, `membership.trial_ends_at`, `player.registered_at`
  (the last one is used in Step 3; add now to touch the row shape once).
- `src/Results/PlayerProfile.php` — `bool $freeTrialAvailable`, `null|DateTimeImmutable $freeTrialEndsAt`,
  `DateTimeImmutable $registeredAt`; extend the `PlayerProfileRow` phpstan type + `fromDatabaseRow()`.
  *Every* hand-built `PlayerProfile` in tests needs the new arguments — grep `new PlayerProfile(`.
- `src/Query/GetPlayerMembership.php` — select `membership.trial_ends_at`.
- `src/Results/PlayerMembership.php` — `null|DateTimeImmutable $trialEndsAt` and:
  - `isInFreeTrial($now)`: `trialEndsAt > now && billingPeriodEndsAt === null`
  - `freeTrialDaysLeft($now)`: `(int) ceil(seconds / 86400)`, min 0
  - `isEndedFreeTrialOnly($now)`: `trialEndsAt !== null && trialEndsAt <= now && stripeSubscriptionId === null && !isActive($now)`

**Flag** — `.env`: `FREE_TRIAL_ENABLED=0`; `config/services.php`: parameter `freeTrialEnabled` =
`%env(bool:FREE_TRIAL_ENABLED)%`, bound into `FreeTrialSettings`; `config/packages/twig.php`: global
`free_trial_enabled`. It is a Twig global → must stay resolvable in the committed `.env` (operational
note in `feature_flags.md`). Add the `feature_flags.md` entry in this step.

### Tests

- `tests/MessageHandler/StartFreeTrialHandlerTest.php` (use `OverridesFeatureFlagEnv`, mock clock):
  eligible `PLAYER_REGULAR` → row with `grantedUntil == trialEndsAt == now + 10 d`, source stored;
  `PLAYER_WITH_STRIPE` → throws; voucher-granted player → throws; second start → throws; flag off → throws;
  unverified e-mail → throws (D9).
- `tests/Results/PlayerMembershipTest.php`: in trial; ended trial only; trial + voucher beyond it
  (not "ended", still active); trial + subscription (`isInFreeTrial` false); days-left rounding.
- `tests/Query/GetPlayerProfileTest.php`: `freeTrialAvailable` true for `PLAYER_REGULAR`, false for a member
  and for an ended trial.
- Fixtures: `PlayerFixture` — `PLAYER_IN_FREE_TRIAL`, `PLAYER_FREE_TRIAL_ENDED`; `MembershipFixture` rows for
  both; document in `.claude/fixtures.md`. `rm tests/.database.cache` after changing fixtures.

---

## Step 2 — trial UI

### New

| File | Content |
|------|---------|
| `src/Controller/StartFreeTrialController.php` | `POST`, route `start_free_trial`, path `/membership/start-free-trial` (no locale variants — never linked, never indexed; locale for the redirect comes from a hidden `_locale`-aware `return`/route param), `#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]` |
| `src/Controller/FreeTrialStartedController.php` | `GET`, localized route `free_trial_started`; not in trial → redirect `membership` |
| `templates/membership/_free_trial_offer.html.twig` | offer card + POST form |
| `templates/membership/_free_trial_status.html.twig` | "Free trial — X days left", keep-your-days line |
| `templates/membership/_subscription_plans.html.twig` | the web / iOS / Android plan buttons, **extracted unchanged** from `membership.html.twig` |
| `templates/membership/_free_trial_start_form.html.twig` | the one POST form (button label + source + return as parameters) reused by card, members modal, offer modal |
| `templates/free_trial_started.html.twig` | welcome page |
| `templates/membership/_free_trial_feature_cards.html.twig` | deep-linked feature cards (welcome page + e-mail share the list) |
| `templates/emails/free_trial_started.html.twig` | inky, same skeleton as `membership_granted` |

**`StartFreeTrialController::__invoke`**

1. `FreeTrialSettings::isEnabled()` false → `createNotFoundException()`.
2. `isCsrfTokenValid('start_free_trial', …)` false → 403.
3. `source` = `FreeTrialSource::tryFrom()` ?? `MembershipPage`.
4. dispatch `StartFreeTrial`; catch `HandlerFailedException`:
   previous is `FreeTrialNotAvailable` / `UniqueConstraintViolationException` → flash `free_trial.not_available`,
   redirect `membership`; `FreeTrialRequiresVerifiedEmail` → flash `free_trial.verify_email_first`, redirect
   `edit_profile`; anything else → rethrow. Log at `info` with `'exception' => $e` (a second click is routine,
   must not become a Sentry issue).
5. Success → `ReturnUrl::tryFrom($request->request->get('return'))` → redirect there with flash
   `free_trial.flash.started`; otherwise redirect `free_trial_started`.

Always a redirect — never a 200 (Turbo drops it; `TurboDriveFormResponseSubscriber` + the existing
200-POST guard test cover this).

### Changed

**`templates/membership.html.twig`** — restructure the card body:

```
lifetime                         → unchanged
membership.isInFreeTrial(now)    → _free_trial_status  +  _subscription_plans  +  voucher link      (NEW branch, before isActive)
membership.isActive(now)         → unchanged
else                             → [ended trial ? neutral 'free_trial.status.ended' : red 'membership_expired']
                                   [free_trial_available and free_trial_enabled and is_web() ? _free_trial_offer]
                                   _subscription_plans + voucher link
```

The in-trial branch must come **before** `isActive` — today an active grant hides the plan buttons, which is
exactly what a trial user must not hit. `MembershipController` passes nothing new (`membership` and `now`
carry it) except `account_email_verified` for D9 (`$user->emailVerifiedAt !== null`, the pattern of
`EditProfileController`).

**`templates/base.html.twig`, `#membersExclusiveModal`** — signed-in branch:

```twig
{% if free_trial_enabled and is_web() and logged_user.profile.freeTrialAvailable %}
    {{ include('membership/_free_trial_start_form.html.twig', {source: 'members_modal', label: 'free_trial.modal.button'|trans, return: app.request.requestUri}) }}
    <a href="{{ path('membership') }}" class="btn btn-link btn-sm">{{ 'free_trial.modal.secondary'|trans }}</a>
{% else %} …existing CTA… {% endif %}
```

No age gate here (decided). The form sets an explicit `action` and `data-turbo-frame="_top"` is not needed —
it is not inside `modal-frame`. `return` is validated at the point of use (`ReturnUrl::tryFrom`).

**Topbar** (`base.html.twig:550`, if approved) — pill next to `menu.membership` when
`logged_user.profile.freeTrialEndsAt` is in the future.

**`src/MessageHandler/NotifyWhenMembershipStarted.php`** — first branch: `trialEndsAt !== null` →
`free_trial_started` mail (context: end date, feature cards) and return. Same change fixes the old bug in
the granted branch: `membershipExpiresAt` must come from `grantedUntil`, not `endsAt` (always null for a
grant; render the line only when the date exists and is not lifetime).

**Translations** — `translations/messages.en.yml` `free_trial.*` (README §4), `translations/emails.en.yml`
`free_trial_started.*`.

### Tests

- `tests/Controller/StartFreeTrialControllerTest.php`: success from membership page → 302 `free_trial_started`;
  with `return=/en/puzzle/…` → 302 there; `return=//evil.com` → falls back; bad CSRF → 403; member → 302 +
  flash, no second row; flag off → 404; anonymous → login.
- `tests/Controller/MembershipControllerTest.php`: eligible sees the offer card; member / ended-trial player
  does not; in-trial player sees status **and** plan buttons; flag off → no card.
- Members modal: eligible player's page contains the trial form, a member's does not.
- `tests/MessageHandler/NotifyWhenMembershipStartedTest.php`: trial → trial mail; grant → granted mail with a date.
- `MembershipManagement` — one test: trial membership → `trial_period_days` in the checkout payload
  (mock `StripeClient` as the existing voucher tests do).

---

## Step 3 — announcement modals

### New

| File | Content |
|------|---------|
| `src/Value/AnnouncementModal.php` | `enum: string` — `FreeTrialOffer = 'free_trial_offer'`; `template(): string` → `modals/announcements/_{value}.html.twig` |
| `src/Entity/PlayerModalImpression.php` | `id`, `player` (ManyToOne, `onDelete: CASCADE`), `modal` (enumType), `displayedAt`, `seenAt` nullable; `#[UniqueConstraint(columns: ['player_id','modal'])]` |
| `src/Services/AnnouncementModals/AnnouncementModalRule.php` | interface: `modal(): AnnouncementModal`, `isEligible(PlayerProfile $viewer, DateTimeImmutable $now): bool`; `#[AutoconfigureTag]` |
| `src/Services/AnnouncementModals/FreeTrialOfferRule.php` | `MINIMUM_ACCOUNT_AGE_HOURS = 24`; flag on ∧ `freeTrialAvailable` ∧ `registeredAt <= now − 24 h` ∧ (D9) e-mail verified |
| `src/Services/AnnouncementModals/ResolveAnnouncementModal.php` | see below; implements `ResetInterface` |
| `src/Message/ClaimAnnouncementModalImpression.php` + handler | atomic claim, returns `bool` |
| `src/Message/MarkAnnouncementModalSeen.php` + handler | sets `seen_at` where null |
| `src/Controller/MarkAnnouncementModalSeenController.php` | `POST /-/modal-seen`, 204, signed-in, `AnnouncementModal::tryFrom` else 400 (pattern: `DismissHintController`) |
| `src/Twig/AnnouncementModalTwigExtension.php` | function `announcement_modal()` → `null|AnnouncementModal` |
| `templates/modals/announcements/_free_trial_offer.html.twig` | the modal |
| `docs/features/announcement-modals.md` | "adding a modal = enum case + partial + rule" |

**`ResolveAnnouncementModal::forCurrentRequest(): null|AnnouncementModal`** — memoised per request
(`reset()` clears it; FrankenPHP worker mode), checks cheapest first:

1. main request, `GET`, no `Turbo-Frame` header, not a Live Component request (`_live_component` route attribute / `/_components`), route name present;
2. `PlatformDetector::isWeb()`;
3. `RetrieveLoggedUserProfile::getProfile()` not null;
4. route ∉ `QUIET_ROUTES` (constant list: `membership`, `buy_membership`, `billing_portal`, `stripe_checkout_success`,
   `claim_voucher`, `free_trial_started`, add/edit time, stopwatch routes, `login`, registration, password reset,
   account deletion, `edit_profile`) and route name does not start with `admin_`; request has no `return` query parameter;
5. global cool-down: no impression in `viewer.modalImpressions` younger than `COOLDOWN_DAYS = 14`;
6. first rule (enum order) with no impression for its modal and `isEligible()` true;
7. **claim**: dispatch `ClaimAnnouncementModalImpression`, read the bool via `HandledStamp` (pattern:
   `ClaimVoucherController`). `false` → return null.

The claim handler is the one place that writes on a `GET`. It uses DBAL, because Doctrine cannot express it:

```sql
INSERT INTO player_modal_impression (id, player_id, modal, displayed_at)
VALUES (:id, :playerId, :modal, :now)
ON CONFLICT (player_id, modal) DO NOTHING
```

`executeStatement()` returns the affected rows → `=== 1` is the result. It runs inside the
`doctrine_transaction` middleware like any handler, so the row commits before the response is built; two
concurrent requests serialise on the unique index and exactly one gets `1`. This is the **never twice**
guarantee — display is decided by this row only, never by the browser.

**E-mail verification without a query** — the rule needs it only under D9: the resolver passes
`Security::getUser()` (`UserAccount::$emailVerifiedAt`) alongside the profile; nothing is fetched.

**`GetPlayerProfile`** — one correlated subselect (empty for almost everyone, index-only on the unique index):

```sql
(SELECT json_object_agg(modal, displayed_at) FROM player_modal_impression WHERE player_id = player.id) AS modal_impressions
```

→ `PlayerProfile::$modalImpressions` (`array<string, DateTimeImmutable>`; JSON string from PDO → decode in
`fromDatabaseRow`, `null` → `[]`). Ordinary page views gain **no query**; assert it with `QueryCountAssertions`.

**`templates/base.html.twig`** — replace the disabled `#global-modal` block:

```twig
{% set announcement = announcement_modal() %}
{% if announcement %}{{ include(announcement.template) }}{% endif %}
```

Must sit outside any cached fragment and render only on full pages. Signed-in responses are already
`private`, so `AnonymousCacheHeadersSubscriber` never shares a page carrying a modal.

**`assets/controllers/autoshow_modal_controller.js`** — add values `seenUrl`, `modal`; on `shown.bs.modal`
→ `fetch(seenUrl, {method: 'POST', body, keepalive: true})`. No display logic in JS. (Dev: the PWA service
worker caches `/build/app.js` hard — clear it before judging the change.)

**The modal partial** — title, text with `%name%`, reassurance line, `_free_trial_start_form` with
`source: 'offer_modal'` and `return: app.request.requestUri`, link "What do I get?" → `membership`,
"Maybe later" (`data-bs-dismiss`), footer line `free_trial.offer_modal.find_it_later`.

### Removed (same commit, same migration)

`player.modal_displayed` + `Player::markModalAsDisplayed()`, `src/Message/HideModal.php`,
`src/MessageHandler/HideModalHandler.php`, `src/Services/HideModalListener.php`,
`PlayerProfile::$modalDisplayed` + `modal_displayed` in both `GetPlayerProfile` statements,
`global_modal.*` in all six `messages.*.yml`. Grep `modalDisplayed|modal_displayed|HideModal|global_modal`
must come back empty (migrations excluded).

### Tests

- `FreeTrialOfferRuleTest` — mock clock: registered 23 h 59 min ago → no; 24 h 1 min → yes; member → no;
  ended trial → no; flag off → no.
- `ClaimAnnouncementModalImpressionHandlerTest` — first call `true`, second `false`, one row.
- `ResolveAnnouncementModalTest` — quiet route, `Turbo-Frame` header, POST, native platform, `?return=`,
  cool-down, already claimed → null each.
- Functional `AnnouncementModalTest`: established player loads `/en/` → modal in HTML; loads it again → not;
  loads `/en/membership` first → not there, and the impression is **not** consumed; fresh player → never.
- **Fixture trap:** `PlayerFixture` registers everybody at `clock->now()`, so under the 24 h gate *no fixture
  player qualifies*. Add `PLAYER_ESTABLISHED_NO_MEMBERSHIP` with `registeredAt = now − 30 days` instead of
  moving the clock in functional tests; keep `PLAYER_REGULAR` as the "fresh" case.
- Query-count assertion on a plain page for a player with no impression.

---

## Step 4 — lifecycle

| File | Content |
|------|---------|
| `src/Query/GetFreeTrialsEndingSoon.php` | `trial_ends_at BETWEEN :now AND :now + 3 days`, `trial_ending_reminder_sent_at IS NULL`, `stripe_subscription_id IS NULL`, `granted_until <= trial_ends_at`, `player.email IS NOT NULL` → membership ids |
| `src/Message/SendFreeTrialEndingReminders.php` + handler | dispatches one `SendFreeTrialEndingReminder(membershipId)` per id |
| `src/Message/SendFreeTrialEndingReminder.php` + handler | re-checks the conditions on the entity, sends `emails/free_trial_ending.html.twig` in `player.locale`, sets `trialEndingReminderSentAt` — one transaction per player, so one failure cannot block or double-send the rest |
| `src/ConsoleCommands/SendFreeTrialEndingRemindersConsoleCommand.php` | `myspeedpuzzling:send-free-trial-ending-reminders` — dispatch only |
| `templates/emails/free_trial_ending.html.twig` | end date, keep-your-days argument, link to `membership` |

Cron (lily.srv, class-C row → installs via the 5-min timer): `0 9 * * *`.

`GrantMembershipHandler` — when a row exists and has no running subscription, extend it
(`grantedUntil = max(existing, message.endsAt)`) instead of throwing; keep throwing for an active
subscription / lifetime. Separate commit, own tests — it changes ops behaviour beyond trials.

Tests: query boundaries (3 d + 1 min out, already reminded, subscribed meanwhile, voucher beyond the trial);
handler sends once, second run sends nothing (test the handlers, not the command).

---

## Step 5 — measurement, translations, launch

1. **Analytics** — `activity_daily_summary.active_trials` (generated migration, third and last);
   `SnapshotActivityDailySummaryHandler`: `active_trials` = `trial_ends_at > :now AND billing_period_ends_at IS NULL`,
   and exclude the same rows from `active_members`. Update `docs/features/activity-analytics.md`.
2. **Funnel SQL** in README: impressions claimed / seen (`player_modal_impression`), trials by `trial_source`,
   subscribed during trial, subscribed ≤ 30 days after, vs baseline.
3. **Translations** — `missing-translations` skill for `free_trial.*` (messages + emails) into cs, de, es, fr, ja.
4. **Docs** — README "PLAN" → as-built; `CLAUDE.md` entries (Free trial, Announcement modals);
   `docs/features/stripe.md` ("a trial is a grant; checkout converts remaining days");
   `docs/features/feature_flags.md` final wording.
5. **Rollout**
   1. Deploy with `FREE_TRIAL_ENABLED=0`; Jan runs the migrations (they arrive on web boot in prod).
   2. Smoke on prod with the flag off: no card, no modal, `POST /membership/start-free-trial` → 404.
   3. Install the reminder cron in lily.srv.
   4. Flip `FREE_TRIAL_ENABLED=1` via Infisical — **not** on a newsletter day: every player without a
      membership row and older than 24 h gets the modal on their next visit, and each started trial sends a mail.
   5. Day 1: Sentry clean, `SELECT trial_source, count(*) FROM membership WHERE trial_started_at IS NOT NULL GROUP BY 1`,
      `SELECT count(*), count(seen_at) FROM player_modal_impression`.
   6. Review the funnel at +6 weeks (D11); then decide on raising `MINIMUM_ACCOUNT_AGE_HOURS`.

## Risks worth keeping in view

| Risk | Guard |
|------|-------|
| Write on `GET` (the claim) on a hot path | runs only after every cheap check passed, i.e. once per player ever; index-only insert |
| Twig-global flag unresolvable → every page breaks | defined in committed `.env`; `cache:warmup` in the gate |
| Modal on a cached/shared response | claim + render happen only for a signed-in profile; signed-in responses are `private` |
| New `PlayerProfile` constructor args break hand-built instances in tests | grep `new PlayerProfile(` in Step 1 |
| Turbo swallowing the start POST | redirect-only controller, covered by the 200-POST guard test |
| Open redirect via `return` | `ReturnUrl::tryFrom()` at the point of use + test |
| Launch burst of mails | transactional transport, no newsletter that day |
| iOS review | both CTAs and the modal are `is_web()` only |
