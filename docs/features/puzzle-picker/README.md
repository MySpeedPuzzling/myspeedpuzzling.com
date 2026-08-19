# Puzzle Picker — "What should I solve next?"

> Status: **implemented 2026-08-19** as four stacked PRs — #189 foundation, #193 insights layer,
> #191 precision filters / collections targeting / remembered filters / presets, #192 my times +
> predictions in collections (adds `player.collection_display_mode`, migration `Version20260819010848`).
> Rules kept in every stage: **all six locales** (cs, de, en, es, fr, ja) for every user-facing string
> and route path, **tests for everything** (query, value object, controller, handler), full check suite
> green. Roadmap items live in [`implementation-plan.md`](implementation-plan.md) "PR 5+".

## 1. What it is (and is not)

A *fortune wheel* for the player's own shelf: the player says what mood they are in (a few
filters), presses **Pick a puzzle**, and gets **one** puzzle card — with everything needed to
decide in five seconds (basic info, my times on it, my predicted time) and two buttons to act
(*Start stopwatch*, *Add time*). One tap shows **5 more**, one tap spins again.

It is **not** a search. The puzzle overview / search page already answers "find puzzle X". The
picker answers "I don't know what to solve — surprise me, but respect my criteria". Randomness is a
feature: the same filters must give a different answer on the next spin, and the same spin must be
shareable/reproducible (seeded random, see §6).

Secondary goal (Jan's motivation): **put time predictions in front of members in more places.**
Today they render on exactly two pages (`PuzzleDetailController` → `puzzle/_difficulty_section.html.twig`,
`AddedTimeRecapController` → `added_time_recap/_performance_summary.html.twig`). This feature adds
the picker card and the collection pages, and leaves hooks for the stopwatch and search listing.

Naming (decided) — feature/code name **Puzzle Picker** (`PuzzlePicker*` classes, route
`puzzle_picker`, EN path `/en/what-to-solve-next`), page title **"What should I solve next?"**,
menu label **"What next?"** (profile dropdown + a button on the puzzle library / collection pages).
Alternatives considered: "Suggest a puzzle" (sounds like the puzzle-submission form), "Next puzzle"
(too close to `next` pagination), "Spin" (cute, but opaque in six locales).

## 2. Who can use what — free core vs. members

The rule I propose is the one the site already uses everywhere else and can be explained in one
sentence: **everything derived from your own raw data or plain puzzle attributes is free; everything
from the Insights family (prediction, difficulty, gap-vs-prediction) is for members** — consistent
with `CLAUDE.md` ("All insights data is members-only except raw median, MSP Rating ladder,
methodology page") and with `PuzzleSearchCriteria`, which already strips difficulty filters/sorts
for non-members server-side.

| Free (core — must always work) | Members (Insights layer) |
|---|---|
| Source: my collection / not in my collection / any puzzle | Source: *specific* collections (multi-select) — custom collections are already members-only, so this is gated by nature |
| Solved state: any / never / solved before, solve-count range | Difficulty tier (1–6) |
| Last solved more than X days/weeks/months ago | Prediction on the card (value + range + "vs. your best") |
| My fastest / latest / first time `<` or `>` X | Predicted time range / "I have ~N minutes" using *my* prediction |
| Pieces (chips + custom range), brand (multi) | Prediction gap filter: "I'm slower / faster than predicted by ≥ X min or %" (both directions) |
| "I have ~N minutes" using the community solo average (`puzzle_statistics.average_time_solo`) | Pick order "largest gap first" (both directions) |
| Random pick, 5 more, spin again, presets built from free filters (incl. "Rating grind" — the MSP ladder itself is public) | Presets that need insights ("Beat my record") |
| Card: image, name, brand, pieces, community avg + solves, **my** solved count / fastest ⭐ / latest / first / last-solved-ago | Predictions in collection pages (toggle) |
| Exclude puzzles I lent out (default), include borrowed (default) | |

Gating UI: reuse the existing pattern — controls rendered locked (`ci-locked`, muted) with
`data-bs-toggle="modal" data-bs-target="#membersExclusiveModal"`; the modal is already on every
page (`base.html.twig:859-879`). To avoid "CTA spam", the members-only controls sit in **one**
group ("Insights filters") with a single lock header, and the card shows **one** compact locked
prediction row, not a blurred block. Server-side the criteria object drops members-only filters for
non-members exactly like `PuzzleSearchCriteria::fromUserInput()` does, so crafted URLs cannot
bypass it. Predictions additionally respect `player.time_predictions_opted_out` (28 players today).

## 3. Filters — full list

Grouped the way the filter sheet shows them. `★` = requested by Jan, `+` = proposed extra.

**Where to pick from**
- ★ In my collection: **Yes** (default when the player has ≥ 1 item) / **No** (discovery: things I don't own) / **Any**
- ★ Only these collections: multi-select of my collections (system "My puzzles" + custom) — *members*
- + Availability: exclude puzzles I currently **lent out** (default on — they are not on my shelf), include puzzles I **borrowed** (default on). Both come from `lent_puzzle` (`owner_player_id` / `current_holder_player_id`).
- + (later) Wishlist as source — "what to buy next"; cheap, `wish_list_item`.

**My history**
- ★ Solved: **Any** / **Never** / **Solved before**; expandable **between N and M times**
- ★ Last solved more than **N** days / weeks / months ago (only meaningful for solved puzzles; "Never solved" counts as ∞ and is included by default, with a checkbox to require "solved before")
- ★ My **fastest / latest / first** time **is under / over** hh:mm
- + Only solo solves count for time-based filters; team solves count towards "solved" (see §6.3)

**Puzzle**
- ★ Pieces: chips for the common counts (54, 100, 150, 200, 300, 500, 750, 1000, 1500, 2000+ — production distribution: 500 = 14k, 1000 = 11k, 300 = 3.5k) + custom min–max
- ★ Brand / manufacturer: multi-select (TomSelect, same `puzzle_search_filter_options` endpoint the search page uses)
- + "I have about **N** minutes" — time budget. Free: community solo average ≤ N; members: my predicted time ≤ N (same control, better engine, small "uses your prediction" badge). Fits the fortune-wheel mood perfectly ("it's 9 pm, I have an hour").
- + Community results: "few results (≤ 5) — be a pioneer" / "rated (≥ 20, the MSP-rating threshold)" / "popular (≥ 50)". `puzzle_statistics.solved_times_solo_count` (solo solves, the number the card shows).
- + (later) Tag — only 767 tag rows in prod, low value today.

**Insights (members)**
- ★ Difficulty tier: multi (VeryEasy … VeryHard, `DifficultyTier` 1–6). Note only ~5.9k of 34k approved puzzles have a score today (`confidence != 'insufficient'`), so this filter shrinks the pool a lot — the UI must say "only puzzles with enough data".
- ★ Compared to my prediction: **Any** / **I'm slower than predicted** (room to improve → PB chance) / **I'm faster than predicted** (I outperform on this one), optional **by at least N min / %**. Requires ≥ 1 solo solve on the puzzle.
- ★ Predicted time between … (or the time-budget control above)
- ★ Pick order: **Random** (default) / **Largest gap first (I'm slower)** / **Largest gap first (I'm faster)** / + **Longest not solved first** / + **Fewest solves first** (the last two are free)

**Presets (one-tap chips above the card — the mobile UX win)**
- "Surprise me" (my collection, any), "Something new" (my collection, never solved), "Quick one" (time budget 60 min), "Dust off the shelf" (not solved > 6 months), "Rating grind" (500 pcs, never solved, ≥ 20 community solvers — free data, see §7 for why this is the honest MSP-grinding proxy), 🔒 "Beat my record" (solved before, I'm slower than predicted, order: largest gap).
- A preset just fills the filter form (query params) — no extra backend concept.

## 4. UX / UI

Principles: clarity over fancy; mobile first; one primary action; zero page-builder feel. All server
rendered (Twig + Turbo Drive); the only JS is the filter sheet, chips and a tiny reveal controller.

```
┌────────────────────────────────────────────┐
│ What should I solve next?                  │
│ Tell me the mood, I'll pick from your shelf│
│                                            │
│ [Surprise me] [Something new] [Quick one]  │  ← preset chips, horizontal scroll on mobile
│ [Dust off] [Rating grind] [🔒 Beat my record]│
│                                            │
│ [ ⚙ Filters (2) ]  My collection · Unsolved ×   ← active-filter chips, × removes one
│                                            │
│ ┌────────────────────────────────────────┐ │
│ │ [img]  Ravensburger                    │ │
│ │        Colorful Owl · 1000 pieces      │ │
│ │        ● Challenging   (members)       │ │
│ │ ────────────────────────────────────── │ │
│ │ You: solved 3× · best 1:12:34 ⭐       │ │
│ │      last 1:20:10 (4 months ago)       │ │
│ │      first 1:40:02                     │ │
│ │ Community: avg 2:10 · 87 solves        │ │
│ │ ⏱ Your prediction ~1:15 (1:05–1:25)    │ │  ← members; "2 min slower than your best" /
│ │    → could beat your record by 2 min   │ │    "→ could beat your record"
│ │ ────────────────────────────────────── │ │
│ │ [▶ Start stopwatch]  [+ Add time]      │ │
│ │  Puzzle detail ›                        │ │
│ └────────────────────────────────────────┘ │
│  1 of 87 matching puzzles                  │
│ [ 🎲 Pick another ]     [ Show 5 more ]    │
└────────────────────────────────────────────┘
```

- **Filter sheet**: a Bootstrap modal (`modal-fullscreen-sm-down`; offcanvas is excluded from the
  SCSS build) containing a plain `<form method="get">` — Apply = submit, the URL *is* the state
  (bookmarkable, back button works, shareable, no Live Component state to reason about). Groups are
  collapsible; the members group is one locked block for non-members.
- **Card**: modelled on the puzzle detail page but compact — reuse `puzzle/_badges.html.twig`,
  the `puzzle_image('puzzle_medium')` filter, `compactTime`/`puzzlingTime` filters, and the
  wording of `PuzzleTimes.html.twig` "my attempts" (`puzzle_times.my_attempts.*` keys). CTAs use
  existing routes: `stopwatch_puzzle`, `puzzle_add`, `puzzle_detail`. Prediction row copies the
  copy/logic of `_difficulty_section.html.twig:65-98` (personalised vs. statistical wording).
- **Show 5 more**: v1 over-fetches 6 rows, renders 5 hidden (`d-none`) and reveals them with a
  10-line Stimulus controller (same trick as `MostSolvedPuzzles` `showLimit`). Zero extra requests,
  instant. If unlimited "more" is wanted later, switch to the chained Turbo-Frame pattern
  (`?seed=…&offset=6&limit=5`) — the seeded ordering makes that trivial.
- **Pick another**: link to the same URL with a fresh `seed` (server-generated, `rel="nofollow"`).
  Optional polish: 300 ms card shuffle animation.
- **Empty states**: "Your collection is empty → [Add puzzles] · [Pick from all puzzles]";
  "Nothing matches → remove a chip" (chips are the loosening UI); guests → login with `?return=`.
- **No remembered filters** (shipped in PR 3, removed 2026-08-19 on Jan's call): the URL is the
  only state — bookmark a combination if you want it back; presets cover the common moods. Keeps one
  mental model, no session writes from the picker, reset = the bare URL.
- **No share button** (dropped 2026-08-19): with personal filters a shared seeded URL shows the recipient *their* pick, not yours; the seed stays an internal mechanic (reproducible refresh/back, duplicate-free "5 more").
- **Entry points** (Jan, 2026-08-19): a very small button next to the H1 on the puzzle overview
  page (`/en/puzzle`, `puzzles.html.twig`), an item in the **My profile** dropdown
  (`base.html.twig`), a small button in the header of the **puzzle library** page
  (`puzzle_library.html.twig`, own profile only) and — from PR 3 — "Pick from this collection" on the
  collection detail pages (deep-links with that collection preselected). Later: hub mini card.

## 5. Predictions in collections (and other new places)

- **Where**: `puzzle_library.html.twig` (system collection) and `collections/detail.html.twig` —
  both render items through `_puzzle_library_item.html.twig`, so one partial change covers both.
- **What is shown** (Jan, 2026-08-19): predictions only make sense next to *my own times*, so the
  display mode is a three-step choice — **Off** / **My times** / **My times + predictions**
  (members). "My times" per item: *not solved yet* or *solved N×* + **fastest** ⭐ and **latest**
  (the latest line is omitted when it is the same result as the fastest), all free for everyone;
  "+ predictions" adds the compact `⏱ ~1:15` pill (title = range; personalised vs. estimate glyph),
  members-only, lock → `#membersExclusiveModal` for non-members. Off by default. No blur, no
  per-item CTA — the single Display control is the only place a non-member meets the upsell.
- **UI**: the existing filter bar gets a **Display ▾** control with the three options; the choice
  is applied server-side (the page re-renders through a small POST → redirect back).
- **Persistence**: `player.collection_display_mode` (string-backed enum
  `CollectionDisplayMode`: `off` | `times` | `times_predictions`), changed via a Messenger message
  (`ChangeCollectionDisplayMode`) from a small POST controller (model: `DismissHintController`,
  route `collection_display_mode`). Shipped (PR 4) **not** on `PlayerProfile`: that DTO is read on
  every signed-in page, so the column would have become a site-wide dependency on the migration —
  instead the tiny `GetCollectionDisplayMode::forPlayer()` serves the two collection pages and the
  POST, through the shared `ResolveCollectionDisplay` service. A player column follows the house
  pattern for opt-in/out flags, survives logout and syncs across devices; a non-member (or a
  predictions-opted-out player) asking for `times_predictions` is downgraded to `times`
  server-side — both when saving and when rendering (a member whose membership lapsed keeps the
  stored value but sees "My times").
- **Whose times / prediction**: always the *viewer's* (the control is labelled "My times") — on
  someone else's public collection a member sees "how did / would I do on these".
- **Cost**: collection pages render all items (up to ~1.7k for the heaviest player). "My times"
  is one aggregate query over the viewer's solves for the listed puzzle ids (`GetPlayerPuzzleTimes`,
  same CTE as the picker's `my_solves`); predictions come from the bulk service (§6.4): one query
  for the viewer's solves, one for improvement ratios, one batch fetch of `puzzle_difficulty` +
  `player_baseline` for the item ids, then PHP. Each only runs when its mode is on.
- **Later places** (roadmap, not in the first four PRs): stopwatch page ("predicted 1:12 — go beat
  it"), search listing pill behind the same toggle, hub mini-picker, wishlist, `/api/v1/me/…`.

## 6. Data & query design (read model, performance)

### 6.1 Facts (production, 2026-08-18)

- `puzzle` 38.5k (34.5k approved), `puzzle_solving_time` 482k, `collection_item` 76k (66.5k in the
  system collection, 9.5k in 281 custom collections, 1,751 players with items), `lent_puzzle` 932.
- Per player: solves median 12 / p95 349 / max 2,224; collection items median 25 / p95 436 / max 1,682.
- Prediction inputs: `player_baseline` (per player × pieces count, 16k rows, direct/interpolated/
  extrapolated), `puzzle_difficulty` (score present for 5.9k puzzles), `player_improvement_ratio`,
  `global_improvement_ratio`. **No per-(player, puzzle) table exists** — neither stats nor predictions.
- Useful indexes already there: `custom_pst_player_puzzle_type (player_id, puzzle_id, puzzling_type)`,
  `custom_pst_intelligence` (partial, solo & valid), `collection_item (player_id)`,
  `lent_puzzle (owner_player_id, puzzle_id)`, `puzzle (pieces_count)`, `puzzle (manufacturer_id)`,
  `puzzle_statistics (solved_times_count)`, `puzzle_difficulty (difficulty_tier)`, PKs on
  `puzzle_statistics.puzzle_id` / `puzzle_difficulty.puzzle_id`, unique `(player_id, pieces_count)`
  on `player_baseline`.

### 6.2 Approach: everything the query needs is bounded by *the player's own history* — aggregate it at runtime, no new tables

The read model is one raw-SQL query in `src/Query/GetPuzzlePickerSuggestions.php` (house style:
`readonly final`, DBAL `Connection`, heredoc SQL, `Result` DTO with `fromDatabaseRow`, null-tolerant
optional filters like `SearchPuzzle`), shaped **filter → sample → hydrate**:

```sql
WITH my_solves AS (                      -- ≤ 2.2k rows for the heaviest player, index-backed
  SELECT pst.puzzle_id,
         count(*)                                                        AS solve_count_any,   -- incl. duo/team rows I own
         count(*)      FILTER (WHERE pst.puzzling_type = 'solo')         AS solve_count_solo,
         min(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'solo') AS fastest_seconds,
         (array_agg(pst.seconds_to_solve ORDER BY COALESCE(pst.finished_at, pst.tracked_at) ASC)
            FILTER (WHERE pst.puzzling_type = 'solo'))[1]                AS first_seconds,
         (array_agg(pst.seconds_to_solve ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC)
            FILTER (WHERE pst.puzzling_type = 'solo'))[1]                AS latest_seconds,
         max(COALESCE(pst.finished_at, pst.tracked_at))                  AS last_solved_at
  FROM puzzle_solving_time pst
  WHERE pst.player_id = :playerId AND pst.seconds_to_solve IS NOT NULL AND pst.suspicious = false
  GROUP BY pst.puzzle_id
),
my_items  AS (SELECT puzzle_id, array_agg(collection_id) AS collection_ids      -- NULL = system collection
              FROM collection_item WHERE player_id = :playerId GROUP BY puzzle_id),
lent_out  AS (SELECT puzzle_id FROM lent_puzzle WHERE owner_player_id = :playerId),
borrowed  AS (SELECT puzzle_id FROM lent_puzzle WHERE current_holder_player_id = :playerId),
picked AS (
  SELECT p.id, s.*, mi.collection_ids, count(*) OVER () AS total_matching
  FROM puzzle p
  LEFT JOIN my_solves s  ON s.puzzle_id = p.id
  LEFT JOIN my_items  mi ON mi.puzzle_id = p.id
  -- joined ONLY when a members insights filter is active (conditional SQL fragment, like SearchPuzzle):
  -- JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id AND pd.difficulty_score IS NOT NULL AND pd.confidence <> 'insufficient'
  -- JOIN player_baseline  pb ON pb.player_id = :playerId AND pb.pieces_count = p.pieces_count
  -- LEFT JOIN unnest(:solvedIds::uuid[], :solvedPredictions::int[]) pp(puzzle_id, predicted) ON pp.puzzle_id = p.id
  WHERE p.approved = true AND (p.hide_until IS NULL OR p.hide_until < :now)
    AND CASE :source                                   -- 'mine' | 'not_mine' | 'any'
          WHEN 'mine'     THEN (mi.puzzle_id IS NOT NULL OR EXISTS (SELECT 1 FROM borrowed b WHERE b.puzzle_id = p.id))
          WHEN 'not_mine' THEN mi.puzzle_id IS NULL
          ELSE true END
    AND (:collectionIds::uuid[] IS NULL OR mi.collection_ids && :collectionIds)
    AND (:excludeLentOut = false OR NOT EXISTS (SELECT 1 FROM lent_out lo WHERE lo.puzzle_id = p.id))
    AND COALESCE(s.solve_count_any, 0) BETWEEN :minSolved AND :maxSolved
    AND (:notSolvedSince::timestamp IS NULL OR s.last_solved_at IS NULL OR s.last_solved_at < :notSolvedSince)
    AND (:minPieces::int IS NULL OR p.pieces_count >= :minPieces) AND (:maxPieces::int IS NULL OR p.pieces_count <= :maxPieces)
    AND (:brandIds::uuid[] IS NULL OR p.manufacturer_id = ANY(:brandIds))
    -- … my-time thresholds, community avg budget, difficulty tiers, prediction gap …
  ORDER BY md5(:seed || p.id::text)          -- or gap DESC / last_solved_at ASC when a "pick order" is set
  LIMIT 6 OFFSET :offset
)
SELECT picked.*, p.name, p.pieces_count, p.image, p.image_ratio, m.name AS manufacturer_name,
       ps.solved_times_solo_count, ps.average_time_solo,
       pd.difficulty_tier, pd.difficulty_score, pd.confidence, pd.indices_p25, pd.indices_p75,
       pb.baseline_seconds
FROM picked
JOIN puzzle p ON p.id = picked.id
LEFT JOIN manufacturer m       ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
LEFT JOIN player_baseline pb   ON pb.player_id = :playerId AND pb.pieces_count = p.pieces_count
ORDER BY md5(:seed || picked.id::text);
```

Why this shape:
- **Seeded random** (`ORDER BY md5(seed || id)`) instead of `random()`: deterministic per seed →
  "5 more" / offset paging never repeats or skips, the URL is reproducible/shareable, and it costs
  one hash per candidate row (measured, negligible).
- **Sample before hydrating**: manufacturer / statistics / difficulty / baseline are joined for the
  6 picked rows only. The measured difference on production for the worst pool ("any puzzle,
  unsolved by me", 32.5k candidates, heaviest player) is **244 ms → 75 ms**.
- **Conditional joins**: `puzzle_difficulty` / `player_baseline` are joined *before* the LIMIT only
  when an insights filter needs them (same trick as `SearchPuzzle.php:128-134`).
- `count(*) OVER ()` gives "1 of 87 matching" for free (the sort already touches every candidate).

**Measured on production (`EXPLAIN ANALYZE`, heaviest player: 2,224 solves + 611 items, cold-ish):**

| Pool / filters | Candidates | Time |
|---|---|---|
| My collection, any state (default) | 577 | **14 ms** |
| Solved by me (gap-capable set) | ~2k | 32 ms |
| Any puzzle, unsolved by me (worst) | 32.5k | 75 ms (244 ms before the two-phase rewrite) |
| Any puzzle, unsolved, 500–1000 pcs | 22k | 62 ms |
| Not in my collection, any state | 34k | 67 ms |
| Any puzzle + predicted-time budget (difficulty + baseline joined pre-LIMIT) | 4.7k → 2.6k | 57 ms |

For the median player (12 solves, 25 items) everything is single-digit ms. Budget: p95 < 100 ms.
No new index is required; if the "any puzzle" pool ever gets hot, a covering index
`puzzle (approved, pieces_count, manufacturer_id) WHERE approved` and/or `hashtext()` instead of
`md5()` are the cheap knobs. Verify with `EXPLAIN ANALYZE` on prod-size data before merging PR 1
(same script as this measurement).

### 6.3 Semantics to pin down (and test)

- **"Solved"** counts any solving time of the player, including duo/team rows the player owns *and*
  team rows where the player is a non-owner participant (`(team->'puzzlers')::jsonb @> …`, GIN
  index `custom_pst_team_puzzlers_gin`) — same meaning as the player's own "Unsolved puzzles" page
  (`GetUnsolvedPuzzles`, pinned by `tests/Query/GetUnsolvedPuzzlesTest.php`).
- **Times (fastest / latest / first, gap, prediction)** use **solo, non-suspicious, timed** solves —
  same as `GetPlayerPrediction` and the "my attempts" card. The card also shows "also solved 2× in a
  team" when applicable.
- **"When solved"** = `COALESCE(finished_at, tracked_at)` (house definition).
- **Hidden puzzles**: `approved = true AND (hide_until IS NULL OR hide_until < now)`; the image
  respects `hide_image_until` (idiom from `GetPuzzleOverview`).
- **Availability**: "my collection" = system + custom items − lent-out (unless included) + borrowed.

### 6.4 Prediction at list scale — bulk service, no materialisation (for now)

`GetPlayerPrediction::forPuzzle()` computes everything in PHP with 2–4 tiny queries per puzzle:
- never solved → **statistical**: `round(baseline_seconds × difficulty_score)` (+ p25/p75 range) — a
  pure join + arithmetic, **so it can live inline in SQL** (used for the predicted-time filter and
  the card of unsolved puzzles);
- solved before → **personal**: last time × improvement ratio (per attempt number & gap bucket) blended
  with Holt's damped trend for N ≥ 2 — not SQL-friendly, but its inputs are just *my* attempts on
  *my* puzzles (≤ 2.2k rows for the heaviest player).

So PR 2 adds `GetPlayerPredictions::forPuzzles(playerId, puzzleIds[]) : array<puzzleId, TimePredictionResult>`
(and `forAllSolvedPuzzles(playerId)`) that loads the player's attempts in **one** query, the ratio
tables in one query, and runs the existing per-puzzle math in a loop — a parity test asserts it equals
`forPuzzle()` for every fixture pair. The picker uses it (a) for the ≤ 6 cards and (b) when a
gap/prediction filter is active: personal predictions for the player's solved puzzles are passed
into the SQL as `unnest(:ids, :predictions)` so *one* query still does filtering, ordering and
sampling; `predicted = COALESCE(pp.predicted, round(pb.baseline_seconds * pd.difficulty_score))`.
Collections use the same service for their visible items. Cache the per-player result for a few
minutes (Symfony cache pool, keyed by player + latest solve id) so "5 more"/back navigation is free.

**Why not a `player_puzzle_prediction` table** (materialised by the 15-min intelligence cron +
the incremental solve handler)? It is the right move *if* list-scale predictions spread to many
pages or the public API — but it adds a write path, a staleness model (gap buckets flip at 30/90/365
days without any new solve, so a daily rebuild would be needed anyway) and ~350k rows, for a
computation that today costs ≤ 20 ms at runtime. Keep it as the documented escape hatch.

### 6.5 Members-only enforcement & privacy

- `PuzzlePickerCriteria::fromRequest(Request, isMember, predictionsAllowed)` normalises input, drops
  members-only filters for non-members, and drops prediction filters when
  `time_predictions_opted_out` — the same server-side pattern as `PuzzleSearchCriteria`.
- **Guests get an SEO landing page on the same route** (Jan, 2026-08-19): marketing-style
  explanation (what it is, how it works in three steps, what you can filter, what members get) plus a
  live **demo pick** from all approved puzzles — the same card, seeded random, "Pick another" works,
  and only the puzzle-attribute filters (pieces, brand) are enabled; the personal groups render
  disabled with a "Sign in to pick from your own shelf" note (conversion path). SQL is identical
  (LEFT JOINs go NULL for a null player; source forced to `any`).
- **Canonicalisation / no index bloat**: `base.html.twig` already sets `<link rel="canonical">` to
  the clean route URL and emits hreflang only on clean URLs; the picker additionally sets
  `<meta name="robots" content="noindex, follow">` whenever *any* query parameter is present (seed,
  filters), so only the six clean locale URLs are indexable. The route is added to
  `SitemapStaticController::STATIC_ROUTES` (all locales). Filter/spin links carry
  `rel="nofollow"`. Anonymous responses get the usual public `s-maxage=60`
  (`AnonymousCacheHeadersSubscriber`), which is fine for a demo card.
- Signed-in players get the personal tool on the same URL (`RetrieveLoggedUserProfile`), no
  `IsGranted` needed — the guest branch *is* the anonymous experience.

## 7. MSP rating: what "grind points" honestly means here

`MspRatingCalculator` (500 pcs only): rating = 0.75 × mean(top-100 **first-attempt** percentile
entries) + 0.25 × mean(top-100 **best-time** entries), each entry × difficulty weight × decay
(`exp(-max(0, ageMonths-3)/30)` — the best-time entry decays from the *latest* solve date). Only
puzzles with ≥ 20 public solvers and a difficulty score enter. There is no stored per-solve delta.

Consequences for the picker: (1) new first attempts on well-populated 500-piece puzzles are what
moves the rating — hence the "Rating grind" preset = *500 pcs, never solved, ≥ 20 community
solvers* (all free data, so the preset is free; members additionally see the prediction row); (2) re-solving
old 500-piece puzzles refreshes the best-time decay — "not solved for > 3 months, 500 pcs" is the
second grinding lever, and it is exactly the requested "not solved for X" filter. A real "expected
rating gain" number (predicted time → percentile among the puzzle's first attempts → top-100
replacement value) is computable with the calculator's internals but needs per-puzzle first-attempt
distributions at list scale — out of scope until someone asks for it; noted in the roadmap.

## 8. Decisions (resolved 2026-08-19)

1. Name / route: **Puzzle Picker**, "What should I solve next?", route `puzzle_picker`
   (`/co-skladat-dal`, `/en/what-to-solve-next`, `/es/que-puzzle-armar`, `/fr/quel-puzzle-faire`,
   `/de/welches-puzzle-als-naechstes`, `/ja/次のパズル`).
2. Free vs. members line as in §2 (Insights family + multi-collection + "Beat my record" preset are
   members; everything else free).
3. "5 more" v1 = over-fetch & reveal; unlimited paging later via Turbo Frames (§4).
4. ~~Last filters remembered in the session~~ — removed again 2026-08-19 (§4).
5. Collections display persisted as `player.collection_display_mode` (§5) — my times first,
   predictions on top for members.
6. "Last solved > X" includes never-solved puzzles by default (§3).
7. Guests: SEO landing + demo on the same route, only clean URLs indexable (§6.5).
8. Predictions computed at runtime via the bulk service, no materialised table for now (§6.4).
9. Every stage ships all six locales and its tests; entry points: puzzle overview button, profile
   dropdown, puzzle library, collection detail (§4).
