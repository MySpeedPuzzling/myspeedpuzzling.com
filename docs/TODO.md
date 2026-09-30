# TODO

Open follow-ups, one place to come back to. Tick an item when it ships, delete a section when it is empty.
Feature-sized plans keep their own checklist in `docs/features/<feature>/` - this file is for the loose ends
that would otherwise be forgotten. Newest section on top.

## Prediction history

Design: [`features/puzzle-intelligence/prediction-history.md`](features/puzzle-intelligence/prediction-history.md) (2026-09-30).
First delivery = store + backfill only; everything below is the UI that comes after it.

- [ ] Build it: columns + live recording + backfill command + daily cron (rollout steps in the design doc)
- [ ] Recap page and API `POST /api/v1/me/solving-times` read the stored prediction instead of recomputing it
      afterwards (today they see the new time in the baseline/difficulty and use later solves as "prior attempts")
- [ ] Per-time outcome in the player's history (puzzle page "my times", profile): range, "12 % faster than predicted", inside/outside the range, members-only, respects the predictions opt-out
- [ ] Mark `reconstructed` predictions in the UI ("reconstructed from your earlier solves") vs `live`
- [ ] Player-level accuracy: how often inside the range, average beat, trend over time (Insights section)
- [ ] Ideas for later: "beat the prediction" streaks/badges, API fields on result rows (additive: `prediction`, `faster_than_predicted_percent`)

## SEO site-wide links (WS-F2)

Shipped with WS-F2 of [`features/seo/implementation-plan-2026-10.md`](features/seo/implementation-plan-2026-10.md).

- [ ] The footer "Popular searches" are hand-picked, hardcoded links (5 brand × pieces pages, 4 brand hubs, the WJPC
      2022-2026 event slugs, the hardest 1000-piece list) - all checked live on the production copy 2026-09-30.
      Renaming one of those brand or event slugs breaks a link on every guest page; revisit the picks yearly (new WJPC)
- [ ] The "how long" guides are English-only: their links on the pieces hubs, brand × pieces pages, `/puzzle` and in
      the footer say "(in English)" in the other five languages - drop that when the guides get localised (plan, open decisions)

## Sign-in / sign-up UX redesign

Phase 1 shipped 2026-09-29: [`features/auth-ux-redesign.md`](features/auth-ux-redesign.md) §8. Phase 2 (6-digit code) shipped 2026-09-29: §9.

- [x] Phase 2: 6-digit code in the sign-in e-mail + code input on `/login-link/sent`, attempt cap, audit, e-mail subject with the code (§9)
- [ ] Real-device check of the code: iOS Mail / Gmail "one-time-code" autofill from the e-mail subject, paste from the notification, Instagram in-app browser end to end (request -> mail app -> back -> code)
- [ ] After a month: `auth_audit_log` `sign_in_code_used` vs `sign_in_link_used`, and `sign_in_code_failed` by `metadata.code` (lots of `locked_out`/`throttled` from one IP = somebody guessing - then add a `warning` log for clustered lock-outs, spec §6.4)
- [ ] Test the redesigned forms with 1Password, Bitwarden, iCloud Keychain and Chrome on Android (save on register/reset, fill on login) - §6.4
- [ ] Real-device check of the in-app notice: Instagram + Facebook on iOS and Android (UA tokens drift; "Open in Chrome" intent, copy link)
- [ ] Watch `auth_audit_log` sign-in-link use rate before demoting "Email me a sign-in link instead" to a text link (D7)
- [ ] 16px inputs site-wide, then drop `maximum-scale=1` - [#216](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/216)
- [ ] `/welcome?return=` (spec §5.4) - not built; registration still ends on the welcome page

## Difficulty on puzzle lists

Shipped with #214. Design: [`features/list-difficulty-and-my-list-filter.md`](features/list-difficulty-and-my-list-filter.md).

- [ ] Turbo-stream re-renders of list items (reserve, move, lend, ...) drop the difficulty icon, solve count and `data-difficulty-tier` until reload - pass `puzzle_insights` for the one puzzle in the stream callers if it ever matters

## Social login hardening

Shipped 2026-09-29 (flags still dark). Design: [`features/auth-hardening/README.md`](features/auth-hardening/README.md) §Hardening 2026-09-29.

- [ ] Stripe mails to Apple relay addresses: Stripe customers carry the account e-mail, so receipts to `@privaterelay.appleid.com` come from Stripe's domain and are likely dropped by the relay - Stripe custom e-mail domain + register it with Apple, or accept ([`setup-apple.md`](features/auth-hardening/setup-apple.md) §Open questions)
- [x] Translate the interstitial strings `auth.social.confirm.sign_in_and_connect` / `duplicate_accounts_help` and re-translate `auth.social.confirm.have_account` in cs/de/es/fr/ja (2026-09-29)
- [ ] Deliberately NOT done: Gmail dot/plus normalisation of provider emails when matching accounts (rule 2/3 compare the canonicalised address as-is)
- [ ] True account merge (two accounts, two players) stays a manual admin operation - write the runbook when the first request comes in
- [x] "Continue with Microsoft" (personal accounts, ~13 % of players have a Microsoft mailbox) - code shipped dark 2026-09-30 ([`microsoft-plan.md`](features/auth-hardening/microsoft-plan.md))
- [ ] Microsoft go-live: Jan's console work per [`setup-microsoft.md`](features/auth-hardening/setup-microsoft.md) (prod + `MySpeedPuzzling Local` registrations) → local test with the local values in `.env.local` → prod values to Infisical (`MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_CLIENT_SECRET_EXPIRES_AT`) + deploy = public → verify the publisher domain (`/.well-known/microsoft-identity-association.json`) → smoke test
- [ ] Box: `docker compose pull bot-blocker && docker compose up -d bot-blocker` so bot-blocker `7effa7c` (Microsoft's verifier always passes on `/.well-known/microsoft-identity-association.json`) is live before the publisher-domain verification
- [ ] Microsoft client secret rotation due: _fill in when the prod secret is created (creation + 23 months)_ - runbook in [`setup-microsoft.md`](features/auth-hardening/setup-microsoft.md) §Secret rotation; Sentry warns daily from 30 days before `MICROSOFT_CLIENT_SECRET_EXPIRES_AT`
- [ ] Upgrade path at the first rotation: certificate credential (`private_key_jwt`) instead of a client secret ([`microsoft-plan.md`](features/auth-hardening/microsoft-plan.md) §6)

## Puzzle approvals

Shipped 2026-09-25. Design: [`features/puzzle-approvals.md`](features/puzzle-approvals.md).

- [ ] Tell the adder when their puzzle is approved or merged (notification type + `GetNotifications` branch)
- [ ] Count approvals in `GetModerators` (reads `reviewed_by_id` today; `puzzle_moderation_decision` has everything)
- [ ] A read-only history page over `puzzle_moderation_decision` (who decided what, filter by player)
- [ ] 196 unapproved brands on production, 158 of them held as merge candidates (`/root/brand-merge-proposal-2026-09-17.json` on the box) - the queue now handles them one puzzle at a time

## Image storage (bucket audit 2026-09-22)

Full scan of the bucket against every DB image column; fixes shipped in lily.srv (imgproxy source limit 30 → 60 MP,
images-cache re-resolves imgproxy on Docker DNS). Lists in `~/Downloads/msp-image-audit-2026-09-22/` on Jan's Mac,
raw scan on the box in `/root/bucket-scan.csv` + `/root/db-image-refs.tsv`.

- [ ] Result share images (`players/<id>/results/<id>.png`, 800×800): 490k objects ≈ 420 GB, one per solving time, never
      pruned, regenerated on demand by `GetResultImage` - a lifecycle rule or a prune cron (e.g. older than 30 days)
- [ ] Handlers never delete the previous object when a puzzle image / finished photo / logo is replaced or the time is
      deleted - 6,862 orphans ≈ 16 GB today (`3-orphaned-objects-not-referenced.csv`); delete the old key in the handler
- [ ] One-off: delete the existing orphans after a spot check (avatars: `myspeedpuzzling:storage:delete-orphaned-avatars`,
      #213 - see [`features/account-deletion.md`](features/account-deletion.md); a deleted account's files now go automatically)
- [ ] Optional: downscale the 84 pre-`ImageOptimizer` originals above 30 MP to 2,000 px like today's uploads
      (`2-oversized-originals-over-30mp.csv`); not needed for serving since the limit covers them
- [ ] A large imgproxy preset (e.g. `puzzle_large=rs:fit:1200:1200`: WebP, metadata stripped; lily.srv `IMGPROXY_PRESETS`
      + local `compose.yml`). Until it exists the image sitemap and puzzle `og:image` stay on the 400 px
      `puzzle_medium` - never the raw originals (`/original/…`): pre-2026 uploads ≤ 2,000 px still carry EXIF,
      possibly GPS; the puzzle page's gallery link to the original has the same exposure. Then switch
      `SitemapImagesController`, the og:image and the event/edition/series JSON-LD `image` (Google wants
      event images ≥ 720 px wide) to it - see
      [`features/seo/implementation-plan-2026-10.md`](features/seo/implementation-plan-2026-10.md) (WS-I, WS-G)

## Multiscan

Shipped 2026-09-22. Design and plan: [`features/multiscan/README.md`](features/multiscan/README.md).

- [ ] Aggregated lending notification ("Anna lent you 6 puzzles") instead of one per puzzle
- [x] Tray persistence across a reload (`sessionStorage` mirror + `restore()` action) - shipped 2026-09-28 with the required quick-add photo
- [ ] Native apps: a multi-mode scanner in the iOS/Android shells (today the single-shot native scanner is re-opened per code)
- [ ] More actions: mark solved without a time (shared date), list for swap/free, remove from library
- [ ] "Undo last batch" for lend/return
- [ ] Watch the numbers after a few weeks: not-found rate, links created (`puzzle_change_request` rows with `proposed_ean` only), quick-adds

## Getting started / newcomer onboarding

Shipped 2026-09-20 (`1f59d530`). Design, research and rules: [`features/getting-started-guide.md`](features/getting-started-guide.md).
Mirrored in [#212](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/212).

**Not built yet**

- [ ] First-time celebration: after the very first saved time, a small "Nice!" moment (reuse the picker's confetti) with
      links to *My statistics*, *Leaderboard* and *Finish my profile* - the best moment to introduce those features
- [ ] Empty states with one sentence + one button on the owner's own empty profile, collections and wishlist
      (only empty statistics has one today); link to the guide's anchors `#track`, `#library`
- [ ] Welcome e-mail with the same first steps + the guide link (today only the verification mail goes out)
- [ ] Edit profile: basic form (name, photo, country…) to the top, developer cards (tokens, applications) folded at the bottom
- [ ] `membership.full_description` is out of date: no Insights / picker filters, "coming soon" items that shipped

**Verify / decide**

- [ ] One real registration on production end to end: name → welcome screen → Hub card at "1 of 5" → guide, click
      *My statistics* and check the step gets its tick (the `mark-seen` beacon was never clicked in a real browser)
- [ ] Native-speaker read of the es / fr / de / ja texts (`onboarding.*`), Japanese first
- [ ] Should Statistics / Leaderboard ticks also count visits through the menu, not only a click from the guide?
      (costs a check on every view of those pages)
- [ ] Remove the NEW badges on "Getting started" and "Pairs & Teams" when they stop being new (no expiry built in)

**Measure**

- [ ] Compare activation (share of registrations with a solving time within 7 days) before vs after 2026-09-20 -
      SQL in the feature doc. If people stall at the fifth checklist step, drop "Add a favorite puzzler"
