# Progression UX polish: design spec (October 2026)

The design of record for the polish of the progression surfaces in PR #128, with the reason behind every decision.
Proposal with mockups: artifact "Progression Polish Proposal" (https://claude.ai/artifact/Fr7iJ5jMegcJqHuGrDojvh).
On 2026-10-11 Jan picked the recommended option for all four open questions and asked that every further choice
follow the research and its reasoning. This file holds those reasons.
Decisions of record that this file does not change: `docs/features/xp-levels/launch-2026-10.md`.

**The rule behind every decision:** usability and value to the player come before design. Finding yourself, reading
the numbers and knowing what to do next beat decoration. Where a prototype effect costs any of that, it moves to a
place where it costs nothing (the detail sheet), or it goes.

Sources cited below:
- `research-gamification.md`, as §A–§E and "Impl. n" for the numbered implications.
- `touchpoints-audit.md`, as "Audit §4.n".
- The badge-lab prototypes: BK2, P1, P3, P5 in `.claude/worktrees/badge-lab/poc/`.
- `docs/features/badges-ui-handoff.md`, as "Handoff".

---

## 0. Principles derived from the research

| # | Principle | Why (evidence) | Where it shows |
|---|---|---|---|
| P1 | **Always show the next reachable goal, with the remaining amount** | Goal-gradient effect (Kivetz et al. 2006): effort accelerates near the goal. The slump after reaching a goal needs the next goal on screen at once (Goes et al.). §E "Progress visuals" | "Closest next steps", the progress per shelf cell, "N XP to #1 250" on boards |
| P2 | **Start from what is already done** | Endowed progress (Nunes & Drèze 2006): 34 % vs 19 % completion with two stamps pre-filled. §E | Achievement count + tiers held at the top, earned seals first on the shelf |
| P3 | **Rarity is information, so show it as text** | Steam/PlayStation hunters treat the unlock % as standard information (§A5). Rarity "lives in text, never in the art" (P3 README, R4 §5) | % of puzzlers on cells, in the sheet and on the medal back |
| P4 | **Relative position beats an absolute list** | Low ranks demotivate some players, and tiered or relative boards help both ends. Being close to a boundary motivates (§A7 table). Duolingo's boards only worked once players saw people like themselves (§A2, §D) | "Where am I": your row with ±2 neighbours, the position line, the gap row |
| P5 | **Celebrate meaningful progress only, inline first** | Celebration ladder: inline → summary card → modal only for rare milestones (§E). Sign-up celebrations did nothing | Shine once in lists, the holo only in the sheet, no new modals or confetti |
| P6 | **1–2 status signals on compact surfaces** | Stack Overflow / Strava practice (§E "Profile density") | Leaderboard rows: one value + one secondary line, no chips on every row |
| P7 | **Text equivalents, no motion over 5 s, honour reduced motion** | WCAG 2.2.2 (AA) and 2.3.3 (§E "Accessibility") | Every animation is ≤ 1.6 s or user-driven, and none runs under `prefers-reduced-motion` |
| P8 | **No hover-only information, targets ≥ 44 px** | Audit §4.9: requirements hidden in `title=` and 28 px pills fail on touch | Requirements printed as text, 48 px holder rows, 44 px chips and pills |
| P9 | **One number means one thing everywhere** | Audit §4.10: naming collisions block understanding | The medal "No." is the same number on the medal back and the holders list; a position is the same rank rule on every board |
| P10 | **No dark patterns** | EU Digital Fairness Act consultation: no loss framing, countdowns or confirmshaming (§E) | Copy never says "you'll lose", empty states encourage |

---

## 1. Achievements pages (catalogue `badges_overview` + per-player `player_achievements`)

### Decision: the shelf grid (option 1A), not the P1 cabinet
- **What:** both pages share one layout, top to bottom:
  - the summary line;
  - "Closest next steps";
  - the filter chips;
  - the shelf: a grid of cells, each holding the F1 seal at 92 px, the name, the tier name with 5 pips, a thin bar to the next tier and its numbers.
- **Why not the cabinet (1B/1C):**
  - The cabinet hides every tier name and number behind a tap (a tooltip or bottom sheet per piece). That breaks P1 and P8 on the page whose job is "what do I have, what is next".
  - With the ~40 planned types it becomes a 3 × 14 board, two phone screens tall (P1 README, open question 3), and its badges shrink to 48 px on phones (P1: "48 px phone 375"). The shelf shows them at 92 px.
  - It costs 5–7 extra days plus a new picture every year (P1 README effort table), all for decoration.
  - The cabinet remains a possible later "trophy room" view on top of the same data; nothing in this design blocks it.
- **Why 92 px seals:** BK2 surface 3 showed 96 px is where the F1 silhouette ladder (circle → scallops → pleats → sunburst + crown) reads the tier without the label (rule 4 in `feedback_badge_design_rules`). Two columns at 375 px leave 163 px per cell, so 92 px plus padding fits with room for a two-line name. 92 rather than 96 keeps the cell gutter at 320 px (two cells of 140 px).
- **Why tier name + pips rather than "3 of 5" alone:** double encoding (badge rule 3). The pips read at a glance, and the word survives a colour-blind or greyscale view.

### Summary line
- **Member owner:** "13 of 16 achievements · 41 of 80 tiers" plus the AP chip linking to the AP board.
- **Visitor on a member's page:** the same line about that player.
- **Why tiers as well as types:** a player with every type at Bronze and one with every type at Diamond both read "16 of 16". The tier count is the honest progress number (P2).

### Closest next steps (members: catalogue + own page; anyone: a member's page)
- The 3 tiers with the highest progress percentage, excluding unrevealed tiers and complete types.
- Each row has:
  - the next tier's seal in grey at 52 px;
  - "Name → Tier";
  - the requirement as text;
  - the bar;
  - "640 / 1,000" or, for speed types, "4:31:20 → 4:00:00";
  - "+50 AP".
- **Why on someone else's page too:** it answers "how close is she to Diamond?", the natural question when looking at a friend's achievements. The data is the same catalogue the page already loads, so it costs no query.
- **Why 3:** three is what P1 tested. More would push the shelf below the first screen on a phone.
- From 992 px the block becomes a side column next to the shelf (desktop mockup), so the shelf starts at the top.

### Filters
- The chips are **All · Earned · Next up · Rarest**. Counts are on the chips, which are ≥ 40 px tall in a 44 px row.
- **Default = All**, sorted as:
  1. unrevealed tiers ("?") first, because they are an action;
  2. earned types next, highest tier first, rarer first within a tier;
  3. unearned types last, by progress, highest first.
- **Why All as the default:** the page is a collection, and P2 says start from what is held. "Next up" exists as a filter, and its top 3 are always visible in "Closest next steps".
- Non-members on the catalogue get **All · Rarest** only, as today.

### The cell
- **Seal:** the highest earned tier. When nothing is earned, the Bronze seal in the locked look (greyscale, 45 % opacity, the existing `.badge-art-locked`).
- **Unrevealed tier:** the rainbow "?" seal (the existing mystery look, now 92 px) and "New tier · tap to reveal". It links to the reveal page, as today.
- **"NEW" dot:** shown for a tier earned in the last 7 days, on the owner's page only. **Why 7 days and no stored "seen" state:** the reveal already marks what is unseen; "NEW" only helps the owner spot recent changes, and needs no new column or write.
- **Bar and numbers:** for the next tier. A complete type shows "x % of puzzlers" (P3).
- **Tap:** opens the detail sheet. From a player's page the link carries `player=<id>` (section 2).
- **T4/T5 seals shine once on page load:** a 1.6 s light sweep masked by the seal itself, CSS only, none under reduced motion.
  - **Why once:** P5 "-once" rule for lists (Handoff §3.3, P5, P7).
  - **Why CSS rather than the P5 self-animating SVG:** the SVG variant needs a Safari Reduce Motion device check (WebKit bug 283894) and a new build artefact. The CSS sweep works today: the art is same-origin, which `mask-image` requires, and it costs nothing.

### Non-member catalogue
- Every type shows its Bronze seal (not greyed: these are the real look, not "locked for you"), with "5 tiers · Bronze held by 53 %". The tease card sits on top with the existing copy ("Become a member to find out which").
- Nothing personal is read, as today.
- **Why Bronze in colour:** the catalogue is a shop window; grey seals on a page you can't act on read as "denied".

### Holders page `achievement_detail` (same family)
- **Hero:** seal 96 px, name, description, and for members "You hold Gold · No. 61 of 82".
- **The 5-seal ladder** with holder counts. Each seal is a link to its tier section (`#tier-3`). **Why:** today you scroll through five sections to compare tiers; the ladder gives every count in one row (P3) and doubles as navigation.
- **Holder rows of 48 px** replace the 28 px pills (P8): No. · avatar with flag · name · earned date. "First to earn" keeps the trophy.
  - The **No.** is the medal serial (section 2), not the position among the listed players. **Why (P9):** the number on someone's medal back and the number next to their name must be the same. Unlisted holders (private, opted out, blocked) leave gaps in the numbering; that is honest because the numbers are serials, not ranks.
- **Your own row:** when you are not among the first 30 listed, it appears after a "⋯" row, like the leaderboards (section 4).
- **Empty tier:** "Nobody has earned Diamond yet. The first one writes history." (the existing copy, P10).

---

## 2. Achievement detail sheet (`_achievement_info_modal`, `AchievementInfoController`)

### Decision: P3's medal in a bottom sheet, with the tier list (option 2A)
- **Phones (< 576 px):** the existing dynamic modal is shown as a **bottom sheet**: anchored to the bottom, rounded top, a grab handle, swipe down or × to close. **Desktop:** the same content in the centred modal.
  - **Why a sheet:** the thumb reaches the bottom of the screen, the page stays visible above (you know where you are), and it is the platform pattern for "details of the thing I tapped". It applies only to this content, opted in from the frame content. Other modals on the site are untouched (no global change).
- **Medal:** 200 px, shown in this order of preference: the tier that was tapped (`?tier=`), else the highest tier of the player in context, else Bronze.
  - **T1–T3 are still. T4 gets a foil rim. T5 gets the "Masterpiece" finish** (prism bands, glitter, a jigsaw etch in the light). The pointer or a drag tilts the medal: T4 up to 8°, T5 up to 12°.
  - **Why only T4/T5:** holo is the category jump of the top tiers (P3 README, R4 §5 rule 3). Making every tier shiny would erase the difference the art carries.
  - **Why no gyro:** iOS asks for permission, and a permission prompt inside a detail sheet is a bigger cost than the delight.
- **The medal turns over** on tap, Enter or Space (it is a `<button aria-pressed>`). The back shows:
  - the name;
  - the tier;
  - **No. 0042 of 334**;
  - **4.2 % of puzzlers**;
  - the earned date.

  **Why:** this is the one place where rarity and order become personal ("I was the 42nd"), the hunter motivation of §A5. It lives behind a tap, so it never costs reading time on lists.
- **Reduced motion:** no tilt and no intro sweep. The turn becomes a 0.2 s cross-fade (P7).
- **Tiers below the medal = the list (2A):** five rows of 52 px. Each row has:
  - the seal (40 px);
  - the tier name with ✓;
  - the requirement as text with % of puzzlers;
  - +AP;
  - the progress bar on the next tier.

  **Tapping a row shows that tier on the big medal** (not earned = greyscale, no back side).
  - **Why the list over the strip (2B):** comparing tiers is the main job here ("what does Gold need vs Platinum"). The list shows all five requirements at once; the strip needs five taps (P8).
- **Facts line:**
  - **Tier chip.**
  - **Rarity chip**, with bands from PlayStation:
    - Common ≥ 50 %
    - Rare ≥ 15 %
    - Very rare ≥ 5 %
    - Ultra rare < 5 %
  - **"Your record: 29:41"** for speed types, or the current count for count types.
- **Actions:**
  - **"See who earned it (334)":** the holders page; the count makes the button informative.
  - **"All achievements".**

### Whose medal (`?player=`)
- Opened from a player's achievements page or showcase, the sheet shows **that player's** tier, serial and date, and the tier list shows their ✓ marks. From the catalogue it shows the viewer's own.
- The parameter is honoured only when that player's achievements are visible to the viewer. That uses the same rules as `PlayerAchievementsController`: member, not hidden by privacy (`PrivateProfileAccess`), not blocked, not opted out. Otherwise it is ignored silently, and the sheet shows the viewer's own state.
- **Why:** today a visitor tapping Lucie's Diamond sees *their own* progress, which answers a question nobody asked.

### Serial number (no migration)
- **Definition (one rule, used by the sheet and the holders page):**

  ```sql
  ROW_NUMBER() OVER (PARTITION BY type, tier ORDER BY earned_at, id)
  ```

  It runs over **all** `badge` rows of that type and tier: every holder, not only the listed ones, and the same number for every viewer.
- **Total** = the holder count from the existing rarity aggregate (`AchievementRarityProvider`, cached 15 min).
- **Cost:** one indexed count when the sheet opens:

  ```sql
  COUNT(*) WHERE type = ? AND tier = ? AND (earned_at, id) <= (mine)
  ```

- **Why computed, not stored:**
  - Tiers already follow the current records and can drop (`BadgeEvaluator`). A stored serial would need rules for drops and re-earns, plus a backfill.
  - Computed, a drop by an earlier holder moves later numbers up by one. That is rare, and the result is truthful.
  - Backfilled tiers share `earned_at`, and the uuid7 `id` breaks the ties in creation order.

### Holo implementation constraints (Handoff §3.2, P3 README)
- **Written from scratch.** `pokemon-cards-css` is GPL, so none of it may be used.
- **The per-badge analysis is precomputed by `tools/badge-art` at build time** (Python, deterministic), for T4/T5 of every tiered type:
  - foil mask
  - rim mask
  - back silhouette
  - two sampled tones in the manifest

  **Why:** P3 measured 60–480 ms of analysis per badge in the browser. Precomputing removes that cost and every CORS/canvas question.
- **The JS is a lazy Stimulus controller** (`/* stimulusFetch: 'lazy' */`), loaded only when a sheet with a T4/T5 medal opens.
  - Only CSS custom properties change per frame, and the spring loop stops when the medal settles (no rAF while idle).
  - `disconnect()` releases every listener and frame (Turbo swaps the modal frame).
- **One live medal at a time.** Lists stay static.

### Non-members
- The medal is shown at Bronze with no back side (they hold no medals).
- The tier list keeps the requirements and rarity (that is useful to them) and drops ✓/AP.
- The existing tease line stays.

---

## 3. Navigation: breadcrumbs + back button (option 3A, one row)

### Decision: one row with a round back button on the left, then the trail of parents
- **Why one row:**
  - On a phone every line above the H1 pushes content down.
  - The round ‹ is the platform's back affordance (iOS/Android), so it needs no words.
  - The trail beside it says where the page lives.
  - The button carries `aria-label="Back to …"` with the real destination, and its `title` shows it on desktop.
- **Why parents only:** the H1 is the current page (the event pages already do this, `event_parts/_crumbs.html.twig`). Repeating it wastes the line.
- **On phones** a trail longer than two levels keeps the first and the last parent and folds the middle into "…". Links are ≥ 44 px tall through padding.

### One partial
`templates/_page_crumbs.html.twig` is built from the two existing patterns:
- the `.ev-crumbs` row look (it wraps well);
- `puzzle_detail`'s `{name, label, url}` levels;
- its `BreadcrumbList` JSON-LD through the `json_ld` filter.

The JSON-LD is emitted only on public, indexable pages, and the last item is the current page without `item`.

### The back button goes to the real previous place
1. `?return=` when present and valid (`safe_return_url()` / `ReturnUrl::tryFrom()`), labelled with `return_title`.
2. Otherwise the **logical parent** (the last level of the trail).

- **Why not `history.back()`:** it is unpredictable after a reload, after a deep link, or with Turbo's restoration handling (`app.js` intercepts restoration visits). A real URL is predictable and works without JS.
- **Entry points that start carrying `?return=` + `return_title`:**
  - the progression "?" links next to the profile chips;
  - the hub cards;
  - the profile showcase "See all";
  - the showcase seals (to the sheet → holders);
  - the "How progress works" links on the boards.

  **Why these:** they are the places people jump *from* into the deep pages, and coming back to them is the expected path.
- The canonical URL of every page stays without `return` (docs/features/return-url.md). Links carrying it are `rel="nofollow"`.

### Where each page sits

| Page | Trail (parents) | Back without `?return=` | JSON-LD |
|---|---|---|---|
| How XP works `xp_explainer` | How progress works | the hub | yes |
| Fair play `xp_fair_play` | How progress works › Level & XP | How XP works | yes |
| How karma works `karma_explainer` | How progress works | the hub | yes |
| Methodology `methodology` | How progress works | the hub | yes |
| Achievements `badges_overview` | How progress works | the hub | yes |
| Holders `achievement_detail` | How progress works › Achievements | Achievements | yes |
| Player's achievements | the player header tabs are the trail | only with `?return=` | – |
| XP history, Karma history, Your week, reveal pages | the compact player strip ("Lucie M. › XP history") is the trail | own profile | – (noindex) |
| Leaderboards (every board) | the pill row is the navigation | only with `?return=` | – |

**Why the methodology page goes under the hub:** the hub links it as the deep page for MSP Rating and Skill tier. The page already has a return-back button, which the partial replaces.

---

## 4. Leaderboards: "where am I" on every board (value option 4A)

### Decision: the puzzle page's pattern on every board
- **Which boards:**
  - Level (XP)
  - Achievement Points
  - Karma
  - MSP Rating
  - the per-pieces Times boards (`/ladder/solo|pairs|groups/500|1000-pieces`)
- **What each board shows:**
  - the top rows;
  - a **gap row** "⋯ 1 283 more puzzlers", a button that reveals exactly the rows it counts;
  - **your row with 2 above and 2 below**;
  - **"Show more"**, which continues after the last row shown.
- **Why:**
  - P4: relative position is what motivates both ends of a board. "Being close to a boundary motivates", so the neighbours are the people you can actually pass.
  - The puzzle page already teaches this exact pattern (docs/features/puzzle-leaderboard-chart.md, "Two buttons, two things"), so nothing has to be learned twice.
- **Position line above the list:** "#1 289 of 7 878 in Czechia · top 17 % · 46 XP to #1 250".
  - The next milestone rank comes from the same milestones as the puzzle page.
  - "Jump to me" appears when your row is below the first screen.
  - **Why:** P1, the next reachable goal with the remaining amount.
- **Ranks are shared on equal values** (`RANK()`), as on the puzzle page. The list order stays stable with an id tiebreak.
  - **Why:** two puzzlers with the same XP are in the same place. Today's `ROW_NUMBER` invents an order (P9).
- **A rank always refers to the list on screen:** "#12 in Czechia" when filtered by Czechia. This includes the MSP Rating, which shows the global rank inside a filtered list today.
  - **Why:** a number next to a list that does not match the list's own order reads as a bug (P9).
- **MSP Rating loses its numbered pages:** the window and "Show more" replace them, and search stays.
  - **Why:** page numbers answer "what is on page 13", which nobody asks. The viewer card already had to compute "your page" to compensate.
- **/ladder overview** (six top-10 tables): under each table, your own row after a "⋯", if you are on that board, plus "Full board, around you" linking to the per-pieces board.
  - The row joins the table's existing statement (top 10 ∪ me, the shape `GetXpLeaderboard::karma()` already uses). The query count per table is unchanged.

### Implementation constraints
- **The row-slicing logic leaves `PuzzleTimes`** as a small pure value object: limit, own position, neighbours, rows below, and the gaps it produces. `PuzzleTimes` uses it, so its tests guard the refactor ("reuse, don't fork").
- **One window statement per board** (top N ∪ positions around me ∪ the rows "Show more" asked for).
  - Privacy, blocklist (`HiddenPlayers`), private profiles (`PrivateProfileAccess`) and every opt-out stay exactly as they are.
  - A query budget is pinned per board (only XP and Karma have one today).
- **"Show more" and the gap row are buttons, not links**, so crawlers don't follow them (as on the puzzle page). The revealed rows belong to the list they were revealed on (the `$pagedList` rule), and a filter change resets them.
- **Filters follow you between boards:** the pill row builds each board's URL with the current country (and favourites where the target board has the filter and the viewer may use it).
  - One parameter name for country everywhere it is a query parameter. The Times boards keep their path segment.
  - Members-only filters are carried only to viewers allowed to use them.

### Value column: 4A, the coloured number
The board is told apart **only by the value column**. Rows, ranks and avatars are identical on every board.

| Board | Value | Secondary line | Colour (the existing family token) |
|---|---|---|---|
| Level (XP) | **2 140 XP** | Lv 34 | level indigo `$xp-indigo` |
| Achievement Points | **640 AP** | 13 achievements | achievements amber `$progression-achievements` |
| Karma | **37 karma** | the period's count when filtered | karma rose `$progression-karma` |
| MSP Rating | **1 642** | Skill tier | competition teal `$progression-competition` |
| Times | **29:41** | date | ink (unchanged) |

- **Why a coloured number rather than a chip (4B):**
  - A chip on each of 100 rows is noise (P6).
  - A chip looks tappable when it isn't.
  - Chips of different widths break the right-aligned number column that makes a board scannable (tabular numbers).
- **Why the existing family colours:** the hub, the chips and the profile already use them. Each colour meets AA (≥ 4.5:1) on white and on the own-row tint.

---

## 5. Interactivity and polish

| What | Rule | Why |
|---|---|---|
| Progress bars | Fill from 0 the first time they scroll into view, 1 s, once per page; none under reduced motion | Movement on the number that changed draws the eye to progress (§E: "the inline ring fills"), once, so it is never noise (P5, P7) |
| "Almost there" | A bar within 10 % of its goal gets a soft coral glow (static) | Goal gradient (P1): point out the step that is cheapest to take |
| Shine | T4/T5 seals sweep once on load in lists; the loop exists only in the detail sheet | P5 "-once" rule, WCAG 2.2.2 |
| Tap feedback | Seals and rows press-scale 0.97 for 120 ms | Touch has no hover; feedback confirms the tap |
| Haptics | `navigator.vibrate(10)` when a medal turns over, only where supported (Android), never under reduced motion | Delight at the one personal moment; iOS has no web vibration |
| Sheet skeleton | While the sheet loads (> 150 ms, the existing spinner rule): a medal disc + 5 grey rows sized like the content | Nothing jumps when the content lands (CLS) |
| Empty states | No achievements yet → "Your first one is close" + the nearest Bronze. Empty board in a country → "Be the first from Czechia" | P10, encourage; never a bare "nothing here" |
| Not done | No new confetti, modals, e-mails or notifications; the reveal flows are already shipped | P5, Impl. 8 |

---

## 6. Quality bar (applies to every surface)
- **Mobile first**, checked at 375 and 320 px, plus 1280 px.
- **Interaction:** targets ≥ 44 px, no hover-only information.
- **Motion:** reduced-motion pass done; WCAG 2.2.2 respected.
- **No modal on a modal.** The sheet's links leave the frame (`data-turbo-frame="_top"`).
- **Stimulus:** controllers clean up in `disconnect()`; heavy JS is lazy.
- **Images:** every image goes through `badge_art()` at the size it is shown; masters are never downscaled.
- **Copy:** new keys in all 6 locales. "Achievements" in copy ("badge" only for the picture), "Achievement Points (AP)", "MSP Rating", "Skill tier", "Karma", positions as "#3 of 40".
- **Performance:** query budgets pinned per page and per board; no per-page query growth.
- **Docs:** this file + `docs/TODO.md` updated, no real names in the public repo.

## 7. Delivery

| Step | Scope | Files (owner) |
|---|---|---|
| N | Crumb partial + back, entry points with `?return=`, JSON-LD | `_page_crumbs`, the explainer / hub / history / reveal templates |
| A | Shelf, closest next steps, filters, non-member catalogue, holders page, progress fill, shine once, empty states | `badges_overview`, `player_achievements`, `_achievement_cell`, `achievement_detail`, `AchievementCatalogueRows`, holders query |
| M | Bottom sheet, holo medal, medal back, serial, `?player=`, precomputed masks | `_achievement_info_modal`, `AchievementInfoController`, `GetAchievementMedal`, `holo_medal` controller, `tools/badge-art` |
| L | Window value object, the board statements, the boards' templates, filter carry-over, /ladder own rows | `PuzzleTimes` (refactor only), `GetXpLeaderboard`, `GetPlayerRatingRanking`, `GetFastest*`, the leaderboard templates, `_leaderboards_nav` |
| T | Five locales, docs, before/after screens | `translations/*`, docs |

---

## 8. Review amendments (2026-10-11): these override §1–§6 where they differ

An independent UX review checked the spec against current practice and against the dev database (a prod copy), and
found data problems no mockup shows. Every accepted change and its reason is below. The full review, with player
questions, measured contrast and benchmarks with sources, is kept with the delivery notes.

### 8.1 Launch-time data (must)
- **Every tiered achievement held at launch carries the backfill's timestamp.** All `badge.earned_at` of tiered
  types fall within the 17 minutes of the launch backfill. Taken at face value, that would make:
  - the medal "No." the order in which the backfill processed players;
  - "First to earn" go to whoever was processed first;
  - every date read "10 Oct 2026";
  - every cell carry "NEW" for a week.
- **Rule:** a tier whose `earned_at` ≤ `xp_settings.launch_ready_at` (or any tier while the marker is null) is **held at
  launch**.
  - **It shows "Held at launch"** instead of a number and a date: on the medal back, in the facts line and on the
    holders page.
  - **It never gets "NEW"**, nor the "First to earn" trophy.
  - **Single-tier Early Adopter** keeps its real grant dates.
  - **Why not invent precision:** a wrong "No. 42" or a wrong date is worse than an honest "held at launch" (P9).
- **Later work:** deriving the real date each requirement was first met in the backfill would give the old tiers real
  dates and numbers. It is a bigger change and goes to `docs/TODO.md`.
- **Serial for tiers earned after launch:**
  - `1 + count(holders of the type and tier with (earned_at, id) < mine)`, counting everyone ahead, including the
    holders at launch. The first new Gold after 79 holders at launch is truly No. 80.
  - **The total is `max(cached holders, serial)`,** so "No. 335 of 334" can't happen.
- **"NEW" dot:** shown for a tier earned in the last 7 days **and** after launch.

### 8.2 Holders page (must; replaces the "No." column of §1)
- **Holders are listed newest first, with the date.** Holders at launch show "at launch" and come after the dated
  rows. The list has **no "No." column.**
  - **Why:** about 80 % of holders are not listed (private, opted out, non-members), so a serial column would be mostly
    gaps.
  - "Newest first" is the only order that means something once launch ties exist.
- **The viewer's own status** stays in the hero ("You hold Gold · No. 80 of 82" or "You hold Gold · held at launch").
  There is no pinned own row.
- **"First to earn"** only when the first holder earned the tier after launch.

### 8.3 Detail sheet (must)
- **The facts line** shows tier, the earned date or "Held at launch", and No. (when it has one).
  - **The back repeats these facts; nothing lives only behind the flip.** Xbox and Apple Fitness show the date on the
    detail itself.
  - The medal button's accessible name keeps the facts (`aria-describedby`), so screen readers hear them.
- **Phones:**
  - **Size and scroll:** the sheet is capped at `92dvh` and scrolls inside, the page behind does not scroll, and the
    iPhone home bar gets a safe-area bottom padding.
  - **Swipe down closes** only from the handle or header, or when the content is scrolled to its top.
  - **Tilt:** **none on touch** (pointer type mouse / pen only, so a drag never fights scrolling).
  - **The Android back gesture closes the sheet** through `CloseWatcher` where available. It does **not** use
    `pushState`, because `app.js` turns restoration visits into full reloads.
  - **Focus** starts on the sheet heading, not the medal (Space would flip it), and returns to the tapped cell on close.
  - **On a failed load,** the sheet says so, with a "Try again" button.
- **Tapping a tier row** brings the medal into view (`scrollIntoView({block: 'nearest'})`) before switching the
  tier. Otherwise the change happens off screen and nothing seems to happen.
- **Whose medal wins:** with `?player=`, the subject's medal (tier, number, date) is shown to everyone allowed to
  see it. Being a non-member viewer only removes *the viewer's* ticks, progress and AP. The §2 "Non-members" rule
  applies to the viewer's own medal only.
- **On someone else's medal** the record line reads "Lucie's best: 29:41", not "Your record".
- **Rarity chip** only for Rare and rarer (< 50 %; Common is never labelled). A fresh Bronze is not labelled "Common". The share line
  always names its denominator: "4.2 % of puzzlers with XP".
- **The intro light sweep** (T4/T5, ≤ 1.5 s, once per open) stays; none under reduced motion.

### 8.4 Leaderboards (must)
- **The gap row reveals at most 50 rows per tap**: the next 50 after the top block, then it shows the remaining
  count. One tap could otherwise add ~7,800 rows.
  - **The button names its range:** "Show ranks 26–75 of 1 289".
  - **After the reveal,** focus moves to the first new row, and a polite live region announces it (WCAG 2.4.3,
    4.1.3). The same applies to "Show more".
- **Top block = 25 rows on the boards** (10 on the /ladder overview tables).
  - **Why not 100 as on the puzzle page:** on a board of 7,800 players the point is your own row. 25 tells who leads
    and keeps your row within a few screens. "Jump to me" covers the rest.
  - The puzzle page keeps its 100: its rows are the content that search engines index.
- **The position line:**
  - Always says its scope: "#203 worldwide" / "#12 in Czechia" / "#4 among your favorites".
  - "Top N %" only for the top half of the list.
  - The goal reads "46 XP to the top 1 250", because shared ranks skip numbers.
  - The total counts exactly the list on screen.
- **Not on this board:** a one-line state says why, with what to do:
  - a guest (sign in);
  - a private profile;
  - opted out (where to switch it back);
  - not qualified yet ("needs 20 first tries");
  - a non-member on the AP board;
  - an old `?page=` link, which lands on the window.
- **No striped background on the gap row:** the coral link on it measured 4.46:1.

### 8.5 Colour, numbers, copy (must)
- **Colours: the spec's tokens, never the mockup's hex values.**
  - **Muted text:** Bootstrap's `text-body-secondary` / the site muted token. `#7d879c` measured 3.6:1 and is never
    used for text.
  - **Family colours:**
    - Karma: rose `$progression-karma` (5.9:1)
    - AP: amber `$progression-achievements` (5.9:1)
    - MSP Rating: teal `$progression-competition`
  - **The "NEW" pill:** white on `#d63c42` (4.58:1), not on `#fe696a` (2.8:1).
- **One number formatter:**
  - Twig's locale-aware `format_number`, with no-break separators, for every count, XP, AP, rank and total.
  - Percentages through the locale's percent format, with one decimal under 10 %.
  - A held tier is never "0 %": "< 0.1 %", or "Nobody yet" when there are no holders.
- **The rank sign lives inside the translation** (`%rank%`), so each locale places it ("#12", "Nr. 12", "12位").
- **Explainers** (catalogue footnote + the sheet), three sentences:
  - what "% of puzzlers" counts;
  - that a tier can step back when a time is removed or awaits verification;
  - for lapsed members: "Your achievements are kept and come back with membership".

### 8.6 Achievements pages (should, accepted)
- **"Closest next steps"** appears only on the owner's own page and on the members' catalogue. It does not appear on
  other players' pages.
  - **Why:** Strava keeps unfinished trophies off profiles. A visitor came to see what someone *has*.
  - On phones it is compact: 40 px seal, "Name → Tier", the bar with the numbers right-aligned. The requirement is one
    tap away (the row opens the sheet at that tier).
  - Only its bars animate; the shelf's bars are static.
- **The Streak achievement** shows no progress numbers to visitors when its owner opted out of streaks. This is a
  pre-existing gap: the achievement ignored the streak opt-out.
- **Chip counts equal the cells shown** under that filter. 16 tiered types, plus Early Adopter when held.
- **"Rarest" becomes "Rarest first".** "Earned" is sorted newest first.
- **Chip and pill rows scroll sideways with a visible peek** of the next chip, never `overflow: hidden`.
- **Accessible names on cells and tier rows:** "Puzzle Explorer, Gold, tier 3 of 5, 640 of 1,000 toward Platinum".
  Progress bars carry their values.
- **Press-scale feedback** is off under reduced motion.
- **The "almost there" glow is dropped:** §5 noise, with no evidence of value.

### 8.7 Navigation (should, accepted in part)
- **The back button stays on every page with a trail,** falling back to the parent. Jan asked explicitly for a back
  button on these pages.
  - **Why it is not a duplicate:** it is the large 44 px target, and the trail's text links are small.
- **When `?return=` equals `document.referrer`** (same origin), the button uses `history.back()`, so the previous
  page keeps its scroll position and Back never ping-pongs. Otherwise it is a plain link.
- **On phones at 320 px** the first crumb truncates with an ellipsis before anything wraps.
- **The crumb label is the hub's title,** "How progress works", everywhere.

### 8.8 Decided where the spec and the mockup disagreed
| Point | Decision |
|---|---|
| Karma / MSP colours | spec: rose / teal |
| Shine in lists | CSS sweep, once (spec) |
| "NEW" | 7 days and after launch (8.1) |
| Crumb label | "How progress works" |
| Equal values | shared rank, always (the mockup's #1 289 / #1 290 at equal XP was wrong) |
| "All 17" vs "13 of 16" | chips count the cells shown; the summary counts the 16 tiered types (+ Early Adopter as its own line when held) |
| Rows before the gap | 25 on the boards, 10 on the /ladder overview, 100 on the puzzle page |
| Speed-type progress % | as `BadgeProgress` computes it today; the bar is decorative, the numbers ("4:31:20 → 4:00:00") are the information |
| Intro sweep in the sheet | kept, once, ≤ 1.5 s, T4/T5, none under reduced motion |

### 8.9 Rejected, with reasons
- **Weekly leagues or seasons:** out of scope. MSP Rating seasons are their own project.
- **Gyro tilt:** a permission prompt inside a detail sheet.
- **A Local Legends histogram** instead of the window: wrong for speed boards.
- **Removing the flip or the numbers:** fine once 8.1 and 8.3 land.
- **Chips on every row:** P6.
- **Back only with `?return=`:** Jan's explicit request (8.7).

---

## 9. Delivered (2026-10-11) and where the build departed from the spec

Built in PR #128 by five agents on disjoint files, integrated and gated by the orchestrator (full ParaTest suite green,
PHPStan clean). Where the build had to decide something the spec left open, or the spec turned out wrong:

| Point | What shipped | Why |
|---|---|---|
| Rarity chip threshold | Rare and rarer = under **50 %** (§8.3 said "< 15 %" by mistake) | "Rare and rarer" was the intent: never label a common tier, label everything else |
| Per-pieces Times boards by country | Stay **public** (the brief asked for the overview's members-only gate) | Those pages carry localised "fastest N-piece times (country)" titles (SEO landing pages, commit 4227c94f); a redirect would drop them |
| Sheet skeleton (§5) | Dropped; instead a generic "This couldn't be loaded · Try again · Close" state for every modal | The spinner only shows after 150 ms and the sheet renders fast; a network error used to close the modal silently, which looked like the tap did nothing |
| XP opt-out vs the medal | An XP opt-out does **not** hide a player's achievements in the sheet | The same rule as the achievements page already had (achievements stay on the profile) |
| "See who earned it (N)" | N = the Bronze holders = everyone who earned the achievement | The holders page lists every tier, and the evaluator fills the lower tiers |
| Medal backs | Every tier turns over (T1–T3 too); foil/rim masks only for T4/T5 | The facts are personal at any tier; only the finish is the top tiers' reward |
| Progress boards | Plain page with GET forms aimed at a Turbo Frame (not a Live Component); revealed states answer `X-Robots-Tag: noindex, follow` | The filters already were a GET form; buttons in forms are not crawled, and the page keeps its scroll |
| MSP search | Finds rows within the current list and keeps that list's ranks | Searching a name is finding someone, not a new list (P9) |
| MSP free players | Country / favourites from the URL are dropped on the server | Members-only filters were reachable by URL |
| Back button `history.back()` | Compared against the URL recorded on `turbo:before-visit`, not only `document.referrer` | Turbo never updates `document.referrer` |
| Holders hero serial | Uses `GetAchievementMedal` (one rule for the sheet and the page); badge index `(type, tier, earned_at, id)` | 9.3 → 1.7 ms for the serial count (EXPLAIN on the prod copy) |
| Visitors on a player's achievements page | Tiers and requirements only - no progress bars or numbers, no "Next up" filter (QA found the shelf showed them while the sheet hid them) | §8.6: a visitor came to see what someone has; one rule for the shelf and the sheet |
| Early Adopter | Counted for its holders: "4 of 17 achievements" (earned and total +1, tiers unchanged), the All/Earned chips +1, shelved with the earned ones on the catalogue, the player's page and the profile showcase; for everyone else the total stays the 16 that can be earned (Jan, 2026-10-11) | It is a real achievement its holders have; it can no longer be earned, so it may not be in anyone else's total |
| Medal back | The short share ("4.2 % of puzzlers"), bigger engraving (≥ 10 px); the facts line under the medal names the denominator | The long form was ~8 px on a 200 px medal |
| Under reduced motion | The Masterpiece keeps its glitter and jigsaw etch (still pictures); only tilt, sweep and the turn animation go | Reduced motion removes motion, not the look |
| Times boards' goal | "00:30:50 faster for the top 50" (`leaderboards.times.goal`) | "… to the top 50" read like a time remaining |
| The catalogue `/en/achievements` | **A public reference, the same for everybody** (Jan 2026-10-11): every achievement with all its tiers, what each takes and how many puzzlers hold each tier (+ Early Adopter with its holders); members get one line on top with their count and "My achievements". No shelf, filters or next steps there - those live on the member's own page | For a member the shelf duplicated their own page; what only this page does is the reference a guest, a free player or a search engine can read, and the way into the holders lists |
| The sheet's second button | None when opened from a shelf (`from=shelf`); the player's achievements from their showcase; "My achievements" for the viewer's own; the catalogue otherwise | "All achievements" from a shelf led to the same list with another header |
| "So far: N" in the sheet | Removed; a speed achievement keeps "Your record: 29:41" | A bare count said nothing - it is the "1 / 5" on the next tier's row |
| A player's achievements page, visitors | **No filter chips, one list rarest first**: Early Adopter first, then what the player holds by how rare their tier is, then the rest by how rare its Bronze is (Jan 2026-10-11). The owner keeps All · Earned · Next up · Rarest first | A visitor wants to see what is special about this player |
| Progress on others' achievements | **Shown again** - the bar and "X / Y" on the shelf and in the sheet, for every viewer (Jan 2026-10-11, over §8.6); only the streak numbers of a player who switched streaks off stay hidden. "Closest next steps" stays the owner's | Jan wants to see how far others are, not only what they hold |
| Colours on the achievements pages | Progress bars, the "17 of 17" count and "your tier" in the site's primary (coral); tier names and pips in the heading ink - **amber only on the Achievement Points chip and values**, where it tells AP apart from XP and the MSP Rating (Jan 2026-10-11). Cards get a small shadow | The family colour on everything read as "bronze", including on a Platinum |
| One rarity aggregate | `AchievementRarityProvider` caches holders + percentages together | One aggregate on a cache miss instead of two |
| Unrevealed achievements on the own profile | **One call-out** on top of the showcase card: up to three empty puzzles (the reveal page's tray picture), "15 new achievements to reveal", "You have already earned them - reveal each one to see what you got", one "Reveal them" button; the row shows only revealed medallions (Jan 2026-10-11) | A row of navy "?" discs said neither what they were nor what to do, and the discs were no part of the site's look |
| Unrevealed tier on the shelf | The same empty puzzle (`.achievement-cell-tray`) instead of the navy "?" disc | One picture for "waiting for its reveal" everywhere |
| Profile showcase card | The cards' small shadow (`$progression-card-shadow`, shared with the shelf as `$shelf-shadow`) | Like the other progression cards |
| Early Adopter sheet | Built like the tiered sheets: the facts line under the medal ("Can no longer be earned" chip, rarity chip + "Held by N puzzlers"), the buttons share the sheet's width on every screen (Jan 2026-10-11) | A centred sentence above a lone left button read as a layout of its own |
| How progress works: back button | Top-level, so no trail; the back button comes only with `?return=` - the user menu (the page it was opened on), the getting started tile, the explainer links and the leaderboards carry it (`rel="nofollow"`); the footer link stays clean (the hub's crawl path) | Readers arrive from many places - back should go to the one they came from |
| Reveal sound | **On by default** (a stored "off" is kept), as "ambient" audio where the browser offers the Audio Session API (the silent switch mutes it, music keeps playing); the toggle on the reveal page is a quiet link instead of an outlined pill (Jan 2026-10-11) | It only ever plays in answer to a tap and lasts about a second - the moment is the point; the silent switch and the toggle cover a quiet room |

Open follow-ups are in `docs/TODO.md` § "Progression UX polish".
