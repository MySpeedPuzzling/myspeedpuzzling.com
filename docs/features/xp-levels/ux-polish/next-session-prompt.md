Progression UX/UI polish for MySpeedPuzzling: orchestrate and drive it end to end, in PR #128, mobile first.
Principle for every decision: **usability and value to the player over design** — nicer, cleaner and more
interactive, but never at the cost of finding things, reading numbers or getting a task done.

## Where things stand
- PR #128 (`feature/dynamic-badges-system`) holds the whole progression launch: XP/levels, achievements + AP
  (members), karma, "How progress works" hub, recap progress card, player header ring, per-player Achievements tab,
  leaderboard pills, activity digest + "your week" page, anti-farming. CI is green. **Do not merge to main** — Jan
  decides the launch. Push to the PR branch only.
- Jan's local checkout `/Users/janmikes/www/speedpuzzling.cz` is ON `feature/dynamic-badges-system`, its dev DB is
  migrated and backfilled (launch switch on), so he reviews at http://localhost:8080 while you work (js-watch
  rebuilds assets; FrankenPHP worker needs `docker compose restart web` for template changes). Other sessions may
  also use this checkout: check `git status` / `git branch --show-current` before every commit and stage only your
  own files.
- Decisions of record: `docs/features/xp-levels/launch-2026-10.md` (wins over older docs) and the memory note
  `project_karma_and_progression_ux.md`. Don't re-ask decided things.

## Jan's request
"I want to still improve the UX and UI based on the research: nicer, more interactive, clean, good UX, mobile first.
The achievements page itself and the modal — the prototype had very nice pages and I want to follow its design,
researched for convenient usage. We need breadcrumbs and a back button, e.g. for the 'How XX works' sections and the
achievements pages."

## Read first (the design sources)
1. Prototypes — open the `index.html` files from disk, read each README, look at `screenshots/` + `demo.gif`:
   - `.claude/worktrees/badge-lab/poc/BK2-bakeoff/` — every badge surface in one page (profile strip, digest,
     **All achievements catalogue**, cabinet, reveal, **detail modal**, tier ladder). Jan chose grammar **F1** (seal
     ladder, no sparkles, no gloss arcs) — look at it with `?g=F1`.
   - `.claude/worktrees/badge-lab/poc/P3-holo-modal/` — the **achievement detail modal**: holo tilt (T4 foil rim,
     T5 "Masterpiece"), tap to turn over to the **medal back** ("No. 0042 of 1 318 · 3.2 % of puzzlers" + date, 6
     locales), rarity + serial, reduced-motion fallback. Write the effect from scratch in our code
     (`pokemon-cards-css` is GPL).
   - `.claude/worktrees/badge-lab/poc/P1-badge-cabinet/` — achievements as a jigsaw board (6×3 desktop / 3×6 phone,
     "Closest next steps"), `P2-last-piece-reveal/`, `RV2-app-reveal/`, `P5-animated-svg/` (T4/T5 shine).
   - `.claude/worktrees/badge-lab/review/FINAL.html` + `research/` (R1 tier systems, R4 gamification round 2, S1 MSP style).
2. `docs/features/badges-ui-handoff.md` — what ships today and the **fine-tuning backlog** (holo modal, animated
   shine, cabinet, real-device pass), with measured costs and gotchas.
3. Launch research + audit, committed in `docs/features/xp-levels/ux-polish/`: `research-gamification.md` (what
   works in gamification, celebrations, mobile best practice, accessibility — with sources), `touchpoints-audit.md`
   (all 74 touch points as of 2026-10-10), `karma-feasibility.md`, `drafts.html` (the approved drafts; artifact
   https://claude.ai/artifact/GNDydUC6pa6oAXSqCRgqdo). QA screenshots of the current state (local only):
   `.claude/worktrees/xpl-kit/screens/` (48 pages × 375/320/1280, guest/free/member).
4. Design system: `docs/design-system/README.md` (coral `#fe696a`, Rubik, neutrals), badge art rules in memory
   `feedback_badge_design_rules.md`, `tools/badge-art/README.md`, `.claude/symfony-ux-hotwire-architecture-guide.md`
   (modal-frame, Turbo gotchas), CLAUDE.md.

## Scope
1. **Achievements pages**: the per-player page (`player_achievements`, PlayerHeader tab) and the catalogue
   (`badges_overview`), redesigned after the prototypes: the F1 badges as the hero of each row, clear tier ladder,
   "Closest next steps", filters, rarity; consider the P1 cabinet as the visual for the player page (decide with Jan:
   yearly picture vs none, 17 → ~40 types on a phone). Holders page (`achievement_detail`) in the same family.
2. **Achievement detail modal** (`_achievement_info_modal.html.twig`, `AchievementInfoController`): P3 —
   big badge with holo tilt for T4/T5 (JS lazy-loaded only when the modal opens), tap to turn over to the medal back
   with rarity + serial + earned date (needs per-type holder counts and earn order — one aggregate query, cached;
   precompute the per-badge bitmap analysis in `tools/badge-art` at build time as the handoff suggests), tier list
   with requirement text and progress, "See who earned it". Works as a bottom sheet on phones.
3. **Navigation**: breadcrumbs + a back button on every progression page that is "inside" something — the hub's deep
   pages (How XP works, How karma works, methodology anchors, fair play), achievements catalogue/holders/detail, XP
   history, karma history, your week, the leaderboards. Reuse the site's existing breadcrumb pattern (events and
   organization pages already have crumbs — find and reuse), keep `?return=` semantics (docs/features/return-url.md,
   `ReturnUrl::tryFrom()`), and add `BreadcrumbList` JSON-LD through the `json_ld` filter where pages are public.
   The back button must go to the real previous place when known (return URL), else the logical parent.
4. **Leaderboards** (`/ladder`, MSP Rating, XP / Achievement Points / Karma boards — all reached through the pill
   row `templates/_leaderboards_nav.html.twig`): bring the puzzle page's "where am I" pattern to every board — the
   top rows, then a "⋯ N more" gap row and the viewer's own row with ±2 neighbours, the gap row reveals the rows it
   counts, "Show more" continues after the last shown row (see `docs/features/puzzle-leaderboard-chart.md`,
   `src/Component/PuzzleTimes.php` `revealGap` / `$pagedList` / `$belowOwnRow`, `_leaderboard_player`; reuse, don't
   fork). Keep the filters (country, favourites, karma kind/period) when switching boards where it makes sense.
   Differentiate each board slightly (its family colour/icon on the value, how the value reads: "Lv 34 · 2 140 XP",
   "640 AP", "37 karma", rating) so a glance tells which board you are on — but **value to the user comes first:
   finding yourself, the people around you and what to do next beats decoration.** One aggregate statement per
   list, query budgets pinned, privacy guards unchanged.
5. **Interactivity and polish** across the progression surfaces, guided by research.md §E + the handoff backlog:
   the shine (P5 "-once" in lists, loop only in the modal), reveal moments, micro-interactions on progress bars,
   empty states, skeletons — tasteful, never noisy, celebrations only for real milestones.
6. Keep everything that is decided: members-only achievements (non-members get the "find out which" tease), the
   launch switch (`xp_system_visible()`), opt-outs, privacy guards, no per-page query growth.

## How to work
- **Phase 0 — audit + design** (before code): compare the current pages (QA screenshots + your own renders) with the
  prototypes; write a short design spec per surface; publish ONE artifact with the proposed final state at 375 px (+
  desktop), with options where it is a real choice (cabinet or not; modal layout; breadcrumb style; leaderboard row look). Ask Jan to pick,
  then build. Keep the questions few and concrete.
- **Build directly on the current branch** in Jan's checkout `/Users/janmikes/www/speedpuzzling.cz`
  (`feature/dynamic-badges-system`) — no worktrees. Run gates the normal way from CLAUDE.md
  (`docker compose exec web composer run phpstan`, `… cs-fix`, `… vendor/bin/paratest --testsuite "Project Test Suite"`,
  `… doctrine:schema:validate`, `… cache:warmup`; the dev stack is up, the dev DB is migrated). Use subagents where
  they help (research, screenshots/QA, translations of the 5 other locales, a self-contained surface), but they edit
  the same checkout: give each a disjoint set of files, never two agents on one file at a time, and let only you
  commit. Commit small with explicit paths (`git add -- <paths> && git commit -m … -- <paths>`, check
  `git diff --cached --name-only` first), push to `origin feature/dynamic-badges-system`, keep CI green
  (`gh pr checks 128`). After template/config changes: `docker compose restart web` so Jan sees them.
- **Verify visually**: render pages as guest/free/member at 375, 320 and 1280 px (throwaway test → static HTML →
  own `docker run --rm` chromium; recipe in memory `reference_member_ui_screenshots_without_login.md`), compare
  against the prototype screenshots, fix, re-shoot. Do a reduced-motion pass. Hand Jan before/after screenshots.
- **Quality bar**: mobile first, ≥ 44 px targets, no hover-only info, WCAG 2.2.2 pause for motion > 5 s,
  `prefers-reduced-motion`, no modal on a modal, Turbo-safe controllers (clean up in `disconnect()`), heavy JS lazy,
  images via `badge_art()` (never downscale masters), all 6 locales for new keys, query budgets pinned, docs +
  `docs/TODO.md` updated, no real names in the public repo.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

Start with Phase 0 and come back with the artifact.
