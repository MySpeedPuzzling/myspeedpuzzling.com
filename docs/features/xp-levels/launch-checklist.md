# XP / Levels / Achievement Points — Launch Checklist

Owner (Jan) deliverables and decisions for the XP & badges expansion. Keep this file up to date —
tick items as they complete, add new ones as they surface. The full business design will live in
`docs/features/xp-levels/README.md`; badges architecture is documented in `docs/features/badges.md`.

_Last updated: 2026-07-13 — implementation COMPLETE (all phases P0–P8 of implementation-plan.md); outstanding items below are Jan's._

## Design decisions locked (context)

- Levels are **numeric only (1–50)** — no bracket/level names.
- Level 50 = 3,160 XP total, curve v4 (levels 2–25 identical to v1; levels 26–50 stretched ~8.5%/step to absorb
  achievement XP — with achievements included, ~112 players instantly max, 1.6%, same share as solve-only v1;
  median-active time-to-max ~2.6–2.9 years).
- XP = activity (free for everyone) · Achievement Points + badges = members-only · MSP Points/Rating stay separate.
- **Weekly digest email — in scope** (un-deferred 2026-07-11). Rules:
  - Content: achievements earned last week, XP gained, levels gained.
  - Members get full achievement details; free users get the teaser instead ("X achievements are waiting for you" —
    marketing style, never specific badge celebrations).
  - **No-activity week: digest still sends**, in a warm encouraging tone (community voice: "we haven't seen a solve
    from you this week — a puzzle is always a good idea"), never guilt-based.
  - **Never two no-activity digests in a row**: after sending one, skip further digests until the player logs
    activity again (allowed pattern: activity → no-activity → activity → no-activity; forbidden: no-activity twice consecutively).
  - Opted-out players receive no digest.
  - Every digest email footer links to **notification settings** (plus signed one-click unsubscribe); the weekly
    digest is **disableable in notification settings** via the `contentDigestFrequency` preference (weekly/daily/none).
  - Sending mechanics/channel: **defined in `docs/features/content-digest/README.md`** (dedicated
    `digest_emails` Messenger queue + rate-paced consumer, `notifications` mailer transport).
- Milestone visuals (levels 10/20/30/40/50): **CSS-only gradient rings** from the brand palette — no art dependency.
- Level-up / launch share cards: **distinct new design** (background art needed from Jan, see §2).
- Freshly added catalog puzzles: **no pending-XP window** — full trust, XP settles immediately; junk cleanup relies on delete-cascading XP removal.
- Launch reveal email: **staggered transactional** (Symfony mailer + Messenger DelayStamp, badges-backfill pattern).
- Rollout: **feature-flagged big bang** (refined 2026-07-12). The whole system merges and deploys behind a
  **documented feature flag** (tracked in `docs/features/feature_flags.md` per project convention):
  - While flagged: visible to **admins only**; XP/achievement recalculation MAY run silently (backfill + verification
    in production), but **no emails, no digests, no notifications** leave the system.
  - Launch = remove the flag (deploy) → run the reveal-email command. Public launch and reveal email same day.
  - **Leak-surface inventory** — every one of these must be flag-gated and verified as a non-admin before merge:
    profile (level ring, chip, achievements strip) · header avatar ring · XP leaderboard · achievements catalog +
    holders directory pages · post-solve recap (XP receipt, celebrations, lazy achievement Live Component) ·
    puzzle-detail XP estimate · explainer + fair-play pages · share-card image routes (direct URL access!) ·
    achievement congratulation emails from the recalc handler (suppressed while flagged — the existing badge email
    path already sends on new earns!) · weekly digest sends (none while flagged) · in-app notifications ·
    activity feed entries · sitemap/SEO (new pages unindexed/unlinked while flagged) · digest preference
    visibility in settings. **Explicitly exempt (OK to leak): public API responses + Swagger docs.**
  - Pre-launch verification = admins review everything in production + re-run the calibration distribution queries.
- Launch timing: **as soon as it's built** (implementation + images + translations done).
- **Badge holders directory** (launch scope): global badges page lists every badge + tier; each badge links to a
  dedicated detail page showing who holds each tier (avatar, name, country flag, earned date), filterable by country,
  with holder counts, "first to earn" highlight and newest earners. Holder *lists* show members only (public badge
  display is a membership perk, consistent with profiles); holder *counts* include everyone ("+ N more puzzlers").
  Private profiles / opted-out players excluded from public lists.
- Achievement XP per tier — **locked**: Bronze 5 / Silver 10 / Gold 25 / Platinum 50 / Diamond 100
  (single-tier achievements: 25). Full ladder of one achievement = 190 XP.
- **Terminology — locked**: the system is called **Achievements**; "badge" refers only to the earned graphic
  medallion. Catalog page becomes "Achievements"; UI copy follows this rule.
- Existing admin-granted **Supporter** achievement renamed to **"Early Adopter"**.
- Achievements with non-obvious rules get a short rule-clarifying description (already supported by the
  per-achievement description + per-tier requirement translation keys — content task, not architecture).

## 1. Badges — goal: at least 10 badge types at launch

Currently shipped (PR #128): Puzzle Explorer, Piece Cruncher, Speed Demon 500, On Fire (streak),
Team Spirit — plus admin-granted Supporter.

- [x] **LINEUP LOCKED (2026-07-12)** — 16 tiered achievements + Early Adopter. Backfill holder counts from
  production (base 7,004 players) in parentheses per tier B/S/G/P/D:
  | Achievement | Tiers | Holders at backfill |
  |---|---|---|
  | Puzzle Explorer [shipped] | 10/100/500/1000/2000 distinct puzzles | 3721/931/64/2/0 |
  | Piece Cruncher [shipped] | 10k/100k/500k/1M/2M pieces | 3161/737/34/2/1 |
  | Speed Demon 500 [shipped] | <5h/2h/1h/45m/30m solo | 5855/5268/2642/1309/291 |
  | On Fire [shipped] | 7/30/90/180/365 day streak | 1058/69/10/6/3 |
  | Team Spirit [shipped] | 1/5/25/100/500 team solves | 3860/2558/1207/307/5 |
  | Zen Puzzler | 1/10/50/150/365 relax solves | 895/218/33/3/1 |
  | First Try | 5/50/200/500/1000 first-attempts | 3803/1207/271/41/1 |
  | Unboxed | 1/5/25/50/100 no-box solves | 1010/264/30/10/1 |
  | Brand Explorer | 3/10/25/50/100 manufacturers | 3779/1576/472/97/7 |
  | Marathoner | 1/5/15/40/100 solves of 2000+pc | 373/36/8/1/1 |
  | Photographer | 1/25/100/500/1000 photos | 2968/404/123/6/1 |
  | Steady Hands | 2/4/8/12/16 unbroken quarters | 4151/2030/689/124/25 |
  | Librarian | 1/5/20/50/100 accepted proposals | 214/51/9/2/1 |
  | Speed Demon 1000 | <8h/4h/2.5h/1h45/1h15 solo | 2086/1496/674/199/21 |
  | Weekend Puzzler | 10/50/150/**300**/**600** weekend solves | 2580/948/251/46/2 |
  | Cataloger | 1/10/50/150/**300** approved catalog adds | 3205/769/117/24/5 |

  **Final calibration verified (2026-07-12):** with this full lineup's achievement XP included, curve v4
  (Level 50 = 3,160) yields **exactly 115 instant-max players (1.6%)** — no curve adjustment needed.
- [ ] **Jan: provide badge images** (outstanding — medallion fallback covers absence) — decided: produced all at once **after the lineup locks**.
  **Launch approach (updated 2026-07-16): generated locally via ComfyUI on Jan's M3 Max** — replaces the
  ChatGPT pipeline; model research + candidate stacks + bake-off protocol in
  `docs/design-system/badge-generation-comfyui.md`. Visual spec still `docs/design-system/prompts/badges.md`.
  - [ ] Run the model bake-off (4 stacks) → pick per-sub-task winners
  - [ ] 5 AI-generated puzzle-piece tier frames (socket→tab progression) — ONE image/grid
    (single-generation consistency, technique #1 from `docs/design-system/badges-conversation.md`) or
    control-image-guided geometry, then crop
  - [ ] 1 center icon per badge type (grid batches + verbatim style prefix from `docs/design-system/prompts/badges.md`) — 10+ icons;
    never include puzzle-piece shapes inside icon subjects
  - Template falls back to tier-colored medallions, so missing art blocks polish, not functionality.

## 2. Other artwork

⚠ Known AI limitation (same insight as the V4 badge pipeline): ChatGPT draws unnatural puzzle knobs.
Both prompts now carry a strict knob-geometry block + "fewer, larger pieces" rule.

- [x] Launch email + reveal page hero illustration — **done** (generated 2026-07-12, approved). Master +
  web-optimized variants in `docs/features/xp-levels/assets/` (`xp-hero-1200.png` 186 KB TinyPNG-optimized for
  email, `xp-hero-1200.webp` 49 KB for the reveal page). Prompt: `docs/design-system/prompts/subjects/xp-launch-reveal-hero.md`.
- [x] Level share-card background — **done, AI-generated** (SVG route explicitly declined for now). Master +
  optimized variants in `docs/features/xp-levels/assets/` (`share-card-background-800.png` 129 KB TinyPNG-optimized
  source for the card pipeline, `.webp` 30 KB). Prompt: `docs/design-system/prompts/subjects/level-share-card-background.md`.

## 3. Copy & policy (Jan reviews, Claude drafts) — drafts DONE, review outstanding

- [ ] **Jan: review/approve fair-play page** — `templates/xp_fair_play.html.twig` (marked `COPY:pending-jan-approval`; texts in `translations/messages.en.yml` under `xp.fair_play.*`)
- [ ] **Jan: review/approve XP explainer page** — `templates/xp_explainer.html.twig` (`xp.explainer.*` keys)
- [ ] **Jan: review launch email + reveal page copy** — `translations/emails.en.yml` `xp_reveal.*` + `templates/xp_launch_reveal.html.twig` (`xp.reveal.*` keys)

## 4. Operations (Jan) — step-by-step commands in `launch-runbook.md`

- [ ] T-7 teaser + launch posts in Facebook groups (timed to implementation readiness)
- [ ] Add production cron entries (`README.md` §Cron: settle-xp-bonuses, recalculate-badges, weekly digest) + `digest-consumer` compose service + deploy.sh workers line (content-digest README §13)
- [ ] Run launch sequence: `myspeedpuzzling:xp-backfill` → `myspeedpuzzling:xp-distribution` (verify ≈115 Lv50 ±10, median L13–14) → remove flag + deploy → `myspeedpuzzling:send-xp-reveal-emails`
- [ ] Ask Seznam support for the Email Profi outbound ceiling (blocks full digest volume, not ramp-up)
- [ ] Dev environment: run `doctrine:migrations:migrate` locally (7 pending migrations from this branch, incl. pre-existing badge.tier)
- [x] Content-digest decisions (2026-07-12): weekly digest **default-on** for existing players ·
  **daily digest deferred completely — weekly only for v1** (Sunday-overlap question moot) ·
  unread-messages digest **stays separate**

## 5. Translations — required before launch

Everything (UI texts, emails, explainer, fair-play policy, badge names/descriptions) translated to
**all supported locales: cs, de, en, es, fr, ja**.

- [x] Build in English first (project convention)
- [x] Final pass: fill all locales (missing-translations workflow, 379 keys × 5 locales)
- [ ] **Jan: badge/achievement names reviewed by a native for cs at minimum** — the cs names to
  review live in `translations/messages.cs.yml` under `badges.badge.*` (16 names + Early Adopter
  = "Průkopník"). Translator flags for the review pass: (a) new gamification texts use informal
  "ty" while older settings/emails use "vy" — decide on a global voice; (b) "achievement" = "úspěch"
  everywhere new, verify no leftover "odznak" wording reads oddly next to it (emails.badges_earned
  still speaks of "odznaky" = the medallions, which matches the locked terminology);
  (c) `emails.badges_earned.title` rendered count-neutrally as "%count%× nový odznak!".

## Ideas noted for technical planning

- **Lazy achievement check on the result page**: a lazy-loaded Live Component on the post-solve recap page that
  checks whether the (async) recalculation granted any achievement/level-up and pops the celebration when it lands —
  bridges the gap between the synchronous page render and the async badge evaluation. (Jan, 2026-07-12)

## 6. Deferred to GitHub issues — CREATED 2026-07-13

- **Competitor achievement** — [#148](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/148)
- **Night Owl achievement** — [#149](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/149)
- **Timezone handling for solve timestamps** — [#150](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/150)
- **SVG badge tier frames** — [#151](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/151)
- **Weekly quest board** — [#152](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/152)
- **Leagues / weekly tables** — [#153](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/153)
- **Puzzle Passport** — [#154](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/154)
- **Year in Puzzling** — [#155](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/155)
- **Secret achievements** — [#156](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/156)
- **Abuse admin tooling** — [#157](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/157)
- **Daily content digest (Phase 3)** — [#158](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/158)
