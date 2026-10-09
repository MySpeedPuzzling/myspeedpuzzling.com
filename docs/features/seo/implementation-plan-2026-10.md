# SEO implementation plan — autumn 2026

Follows `research-2026-09.md`. Scope agreed with Jan on 2026-09-29:

| # | Item | Status |
|---|---|---|
| 1 | Remove the fake insight numbers; keep UI chrome out of snippets | **WS-A** |
| 2 | Real-facts summary paragraph + meta description on puzzle pages | **WS-A** |
| 3 | Stop crawl waste (QR modal, edit-collection-comment, player statistics, login): robots.txt **and** `noindex, nofollow` on the pages **and** `rel="nofollow"` on the links | **WS-B** |
| 4 | Links point at hubs, not filter URLs (footer, "View all", tag badges) | **WS-F1 / WS-F2** |
| 5 | Event titles (full name; "Results" after the event) | **WS-G** (approved 2026-09-30) |
| 6 | Brand pages listing every puzzle across numbered pages; brand + piece-count pages (+ A–Z brand directory) | **WS-F1** |
| 7 | Leaderboard capped server-side, everyone still reachable (show more / show all) | **WS-C** |
| 8 | "How long does a {N}-piece puzzle take" guides | **WS-D** |
| 9 | Outreach list | **WS-E** |
| 10 | Public difficulty lists (hardest / easiest), all 6 locales | **WS-H** (approved 2026-09-30) |
| 11 | Remaining small fixes from the research (hub, ladder, tracker page, meta descriptions, sitemaps) | **WS-I** ("solve all you can", 2026-09-30) |

**Not in scope:**
- Star ratings: no.
- App stores: planned separately.
- Slug URLs (#138).
- Guide localisation and live-component rendering for Googlebot (see "Open decisions").

**Conventions for every workstream:**
- Single-action controllers, ClockInterface, CQRS rules from `CLAUDE.md`.
- No migrations are needed, since everything here is read-side.
- **Language.** New strings on multi-locale pages are translation keys in **all 6 locales**, as in the July overhaul: SEO text on a `/de/...` URL has to be German. Guides stay **EN-only**, as they already are (routes pin `_locale => en`).
- **Canary tests.** Any new page that lists players must be added to `BlocklistCanaryTest` / `PrivateProfileCanaryTest`. No page in this plan lists players except the leaderboard, which already exists.

---

## WS-A — Puzzle page content (items 1 + 2)

Files:
- `templates/puzzle/_difficulty_section.html.twig`
- `templates/puzzle_detail.html.twig`
- a new read query, `src/Query/GetPuzzleSummary.php` or similar, plus its result class
- `src/Query/GetTags.php` (competition link of a tag)
- translations ×6
- `tests/Controller/PuzzleDetailControllerTest.php`, plus a query test

### A1 — Teaser without fake data
- The non-member branch (`_difficulty_section.html.twig:176-258`) must contain **no values** in its HTML: no "Challenging", no "12% harder than average", no "24 solves · Medium confidence", no "~48min", no "Range: 35min - 64min", no "1.3x"/"1.5x"/"Moderate".
- Replace it with a text-free blurred skeleton: bars and badges without words. Above the skeleton, show the *names* of what members get, using the existing translated keys (difficulty, time prediction, memorability, skill sensitivity). Keep the members CTA button.
- Wrap the whole insights block (toggle row plus collapse) in `<div data-nosnippet>`.
- **Acceptance:** a test fetches an anonymous puzzle page and asserts none of the strings above occur, and that `data-nosnippet` wraps the section.

### A2 — UI chrome out of snippets
Google uses the hidden dropdown text as the snippet today ("Suggest a change Missing/incorrect data or duplicate?").
- Put `data-nosnippet` on the `<div class="dropdown">` wrapper of the action menu in `puzzle_detail.html.twig`. `data-nosnippet` is only honoured on `div`, `span` and `section`, not on `ul`.
- Put it on the "not approved" alert.
- Put it on the return-back button include.

### A3 — Summary paragraph

Placement: a `<section class="puzzle-summary" …>` directly under the header block, above the insights toggle.

> **Update 2026-10-02:** the summary moved to the bottom of the page (2026-09-30), below the related puzzles. Both
> sections are rendered **for guests only** (`PuzzleDetailController`, `$user === null`): signed-in players have
> these facts higher up (Details, the leaderboard strip), so for them the sections only repeated the page, and
> `GetRelatedPuzzles` does not run for them. Crawlers visit signed out, so they see exactly what a guest sees.

Content is built from public data only. **No difficulty/percentile wording**: insights are the members' exclusive, per Jan.
- **S1 (always):** "{name} is a {pieces}-piece jigsaw puzzle by {brand}."
  - Brand links to the brand hub if the brand has a slug.
  - Add "EAN {ean}" and "product number {code}" when present, and only when `not is_image_hidden`, same rule as today.
  - Add "Also known as {alternative_name}." when set.
- **S2 (solo solves > 0):** "{soloCount} solo solves logged: median {median}, fastest {fastest}."
  - Source: `puzzle_statistics.solved_times_solo_count`, `median_time_solo`, `fastest_time_solo`. That is one cheap PK lookup; add it as a small query, not to `PuzzleOverview`, which is used everywhere.
  - Word it as "solves", because the leaderboard tab counts players and uses each player's best time, so the numbers differ by definition.
- **S3 (pairs/teams > 0):** "Pairs: {duoCount} solves (fastest {x}). Teams: {teamCount} solves (fastest {y})."
- **S4 (competitions):** "Used at {event links}."
  - Union of (a) tags whose `tag.id` = `competition.tag_id` / `competition_series.tag_id` and (b) `competition_round_puzzle`.
  - Only competitions passing `IsCompetitionPubliclyVisible::SQL_CONDITION`.
  - Links follow `_competition_badge.html.twig` rules: standalone → `event_detail`, series edition → `edition_detail`.
- **S0 (no solo solves):** "No solve times yet – log yours and be the first on the leaderboard." Link to `puzzle_add` with `rel="nofollow"`; it is robots-blocked.

### A4 — Title and meta description
- **Title key** `puzzle_detail.meta.title`: "{brand} {name} – {pieces} Piece Puzzle", localised naturally in every locale:
  - de "… – {pieces} Teile Puzzle"
  - fr "… – Puzzle {pieces} pièces"
  - es "… – Puzzle de {pieces} piezas"
  - cs "… – puzzle {pieces} dílků"
  - ja "… – {pieces}ピース パズル"
  - H1 stays as it is.
- **Meta description** (rewritten 2026-10-05, see "Puzzle meta description — facts first" below): "{brand} {name}
  ({pieces} pieces): {facts}. Compare your time.[ EAN {ean}.]"; without any time "{brand} {name} ({pieces} pieces): no
  solve times yet – log yours and be the first.[ EAN {ean}.]"
- Product JSON-LD keeps using the meta description.

### A5 — Tag badges link to events
- The tag badges (`puzzle_detail.html.twig:175-181`) link to the competition page (event or edition) when the tag maps to a publicly visible competition. Otherwise they render as a plain badge **without a link**; today the link goes to `/xx/puzzle?tag=` filter URLs.
- `GetTags::forPuzzle` gets the competition/series slugs. Check the other callers (`allGroupedPerPuzzle` for list cards): list cards should get the same treatment when their badges are links.

---

## WS-B — Crawl waste (item 3)

Files:
- `public/robots.txt`
- `templates/puzzle/_dropdown_actions.html.twig`
- `src/Controller/PuzzleQrCodeModalController.php`
- `EditCollectionItemCommentController` and its template
- `templates/_puzzle_library_item.html.twig`
- `templates/player_statistics.html.twig`
- `templates/components/PlayerHeader.html.twig`
- `templates/base.html.twig` (the menu link only)
- tests

1. **robots.txt**, in the existing `User-agent: *` group, a new commented section:
   - QR modal: `/puzzle/*/qr-kod`, `/en/puzzle/*/qr-code`, `/es/puzzle/*/codigo-qr`, `/ja/puzzle/*/qr-code`, `/fr/puzzle/*/code-qr`, `/de/puzzle/*/qr-code`, plus the PNG `/puzzle/*/qr-code.png`.
   - Edit collection comment, 6 paths: `/upravit-komentar-kolekce`, `/en/edit-collection-item-comment`, `/es/editar-comentario-coleccion`, `/ja/コレクションコメント編集`, `/fr/modifier-commentaire-collection`, `/de/sammlungs-kommentar-bearbeiten`.
   - Player statistics, 6 paths: `/statistiky-hrace`, `/en/player-statistics`, `/es/estadisticas-jugador`, `/ja/プレイヤー統計`, `/fr/statistiques-joueur`, `/de/spieler-statistiken`.
   - `/login`. Check first that no crawl-worthy route starts with `/login`.
   - Leave the `QrRedirect` controllers alone; those are printed-QR entry points.
2. **Pages:**
   - QR modal: the non-frame request becomes a **301** to `puzzle_detail` (today it is a 302). The frame response carries `X-Robots-Tag: noindex, nofollow`.
   - Edit-comment page: `<meta name="robots" content="noindex, nofollow">`.
   - Player statistics: robots meta changes from `noindex, follow` to `noindex, nofollow`.
   - Login and register already have `noindex, nofollow`.
3. **Links:**
   - `rel="nofollow"` on the QR dropdown item, on every `player_statistics` link (PlayerHeader, the period dropdown in `player_statistics.html.twig`, the base menu) and on the edit-comment link.
   - The edit-comment link renders **only for the collection owner**; check how `page_context == 'collection'` knows the owner.
4. **Tests:**
   - A new `RobotsTxtTest`. It generates every locale path of `puzzle_qr_code_modal`, `edit_collection_item_comment` and `player_statistics` from the router and asserts that robots.txt has a matching `Disallow` prefix, so a renamed route fails the test.
   - Controller tests for the 301 and the robots meta.

---

## WS-C — Capped leaderboard (item 7)

Why load-more rather than crawlable `?page=N`:
- Leaderboard pages 2..N would be near-duplicate URLs of the same puzzle page. Google cannot use them, and they would burn the crawl budget we are trying to free.
- Crawlers need the top rows plus the summary paragraph (WS-A); people need everyone.

Files: `src/Component/PuzzleTimes.php`, `templates/components/PuzzleTimes.html.twig`, translations ×6, component tests.

1. `public const int DEFAULT_LIMIT = 100` and `#[LiveProp] public int $limit = self::DEFAULT_LIMIT`.
2. `populate()` keeps building the **full** filtered/sorted list, exactly as now. Add:
   - a `ranks` map (timeId → rank, with today's tie rule) over the full list;
   - a `visibleTimes` slice of the first `limit` rows, keys preserved;
   - `ownRowBeyondLimit`: when the logged-in viewer's row exists but is not in the slice, render a "⋯" separator row and then their row with its real rank, so "jump to me" still works.
3. LiveActions:
   - `showMore()`: `limit += DEFAULT_LIMIT`.
   - `showAll()`: limit = total.
   - Buttons under the table, only when rows remain: "Show {n} more" and "Show all ({total})". They are `<button>`s, not links.
4. `limit` resets to `DEFAULT_LIMIT` on `changeResultsCategory` and whenever a filter prop changes (onlyFirstTries, onlyUnboxed, onlyFavoritePlayers, onlyMyTeams, country). Since 2026-10-09 through the list key `PuzzleTimes::$pagedList`, not an `onUpdated` hook - Live re-sends the country select with every request, so the hook undid every "Show more" after the first (docs/features/puzzle-leaderboard-chart.md).
5. Unchanged:
   - the chart, which still gets **all** rows;
   - median/average/"your rank X of Y", computed over the full list;
   - the per-player "show more times" toggle.
6. `data-nosnippet` on the category buttons and the filters row.
7. **Tests:**
   - mount with a small `limit` (e.g. 2) on a fixture puzzle with ≥ 3 solvers and assert slicing, ranks, showMore/showAll, the reset on a category change, and the own-row-beyond-limit case.
   - A controller test asserting the puzzle page HTML contains at most `DEFAULT_LIMIT` leaderboard rows.
8. **Effect:** the Bavarian Romance page goes from 2.8 MB / 816 rows to about 100 rows. London Postcard (8.2 MB) gets the same treatment.

---

## WS-D — "How long" guides (item 8, EN-only)

Files:
- `src/Query/GetSolveTimeDistribution.php`: add puzzling type solo/duo/team; the solo default keeps current behaviour.
- `src/Services/SolveTimeDistributionProvider.php`: cache per type.
- new controllers and templates in `templates/guides/`
- `SitemapGuidesController`
- the guides index
- `messages.en.yml` (EN keys only)
- tests

Data, from the local prod copy (solo solves / median):

| Pieces | Solo solves | Median |
|---|---|---|
| 100 | 6.7k | 8.8 min |
| 200 | 12k | 21.6 min |
| 300 | 30.7k | 38 min |
| 500 | 342k | 1h05 |
| 1000 | 18.7k | 3h16 |
| 1500 | 513 | 7h05 |
| 2000 | 358 | 10h48 |

Pairs: 500 has 46.9k solves (44.7 min), 1000 has 5.3k (1h46). Teams: 500 has 4k solves (34 min), 1000 has 8.8k (1h04).

1. **Piece-count guides** `/en/guides/how-long-does-a-{pieces}-piece-puzzle-take` for **100, 200, 300, 500, 1500, 2000**.
   - The requirement lists exactly those values; the existing 1000 page stays on its own route and URL.
   - Guard: 404 when the bucket has fewer than 300 solo solves at request time.
   - Content, all numbers live:
     - hero answer (median, middle 50%, 90% within, fastest, solves)
     - first attempt vs repeat
     - solo vs pair vs team, when there are ≥ 100 group solves for that size
     - comparison with the neighbouring sizes
     - short, size-specific "what changes your time" prose. No copy-paste blocks between pages: each page's prose must say something specific to that size.
     - links to the size's pieces hub and to the 1000 guide / pillar.
   - Title pattern "How Long Does a {N}-Piece Puzzle Take? (Real Data)". Keep it within 60 characters including the suffix; drop "(Real Data)" if needed.
2. **Pairs/teams guide** `/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people`: 500 and 1000 pieces, solo vs pair vs team medians, the speed-up factor, and a "with 4 people" section.
3. **Pillar** `/en/guides/average-puzzle-time-by-piece-count`: a table of every size (solo/pair/team medians, middle 50%) linking to each guide.
4. Add the pairs/teams section to the existing 1000 guide too.
5. `dateModified`: the date the cached distribution was computed, not the frozen 2026-07-11.
6. Guides index, sitemap and footer link, where the guides index already is.
7. **Tests:** each URL 200, disallowed sizes 404, sitemap lists them, JSON-LD present.

---

## WS-E — Outreach list (item 9, docs only)

Deliverable: the outreach list - **kept out of this public repository** (Jan, 2026-09-30: it names who we contact and how; Jan keeps it privately). Research uses organisations' **public** contact pages or channels only, never private people's data. **Nothing is sent**; Jan sends.

Sections:
1. **Results partners:** organisers whose events and results live on MSP, national associations, WJPF.
2. **Unlinked mentions** to convert: ulmer-puzzleschmiede.de, puzzletalk.substack.com, speedpuzzle.eu (old URLs).
3. **Resource pages and "getting started" articles:** puzzlewarehouse blog, bitsandpieces, notjustahobby, speedpuzzlingtips, restinpieces, mindthepuzzle.
4. **Manufacturers and retailers:** brand-hub angle, the "average solve time" widget idea.
5. **Podcasts.**
6. **Media / data PR:** outlets that already covered speed puzzling, plus the Czech angle.
7. **Wikipedia:** the conflict-of-interest note only.

For each target: URL, why they would link, which MSP URL to propose, the contact channel (public page), and priority. Add message templates (EN plus a CZ media pitch) and a tracking table (status, date, outcome).

---

## WS-F1 — Catalogue pages (items 4 + 6)

Files:
- `BrandPuzzlesController`, new `BrandPiecesPuzzlesController`, new `PuzzleBrandsDirectoryController`, `PiecesPuzzlesController`
- `GetBrandHub` (brand×pieces stats), a directory query
- templates `puzzle/brand_hub.html.twig`, `puzzle/pieces_hub.html.twig`, a new brand×pieces template, a directory template, and a shared `_pagination.html.twig`
- `SitemapBrandsController`
- translations ×6
- tests

1. **Pagination** of the brand hub and the pieces hub.
   - Page 1 stays at the current URL. Page N ≥ 2 is a **path** segment, localised:
     - `/en/puzzle/brand/{slug}/page/{page}`, cs `…/strana/{page}`, de `…/seite/{page}`, es `…/pagina/{page}`, fr `…/page/{page}`, ja `…/ページ/{page}`
     - same for pieces hubs.
   - Why a path segment: canonical/hreflang are built from route params, so every page self-canonicalises with correct alternates, which a `?page=` query would not.
   - `/page/1` → 301 to base. A page beyond the last → 404.
   - 48 puzzles per page, ordered most-solved then name (stable), via `SearchPuzzle::byUserInput(offset, limit)` + `countByUserInput`.
   - Crawlable `<a href>` pagination: first, last, current ±2, ±10 jumps, prev/next.
   - Pages ≥ 2: title/H1 "{…} – Page N", no stats blocks, same indexability as page 1 (`index, follow` when the hub is indexable).
   - Remove the "View all" buttons that point to `?brand=`/`?pieces=` filter URLs.
2. **Brand × pieces pages** `/en/puzzle/brand/{slug}/{pieces}-pieces` (+ `/page/{n}`). Localised suffixes as in the pieces hubs: cs `-dilku`, es `-piezas`, ja `ピース`, fr `-pieces`, de `-teile`.
   - 404 unless `pieces ∈ PiecesPuzzlesController::ALLOWED_PIECES` and the brand has ≥ 1 visible puzzle of that count.
   - Indexable when the brand hub is indexable, **and** the combination has ≥ 6 puzzles, **and** ≥ 1 solve; otherwise `noindex, follow`.
   - Content: H1 "{Brand} {N}-Piece Puzzles"; stats (puzzles, solves, median solo); paginated list; links to the brand hub, the global pieces hub, and the brand's other piece-count pages; Breadcrumb JSON-LD Database › Brand › Brand N pieces.
   - Title "{Brand} {N} Piece Puzzles – Solve Times", localised.
   - Stats cached 6 h per combination, like the hubs.
3. **Brand hub:** the "median by piece count" list links to the brand×pieces pages, instead of the global pieces hubs, where the combination page is indexable.
4. **Pieces hub:** the "popular brands" badges link to the brand×pieces page for that size where indexable, else to the brand hub.
5. **Brand directory:**
   - URLs: `/en/puzzle/brands`; cs `/puzzle/znacky`, de `/de/puzzle/marken`, es `/es/puzzles/marcas`, fr `/fr/puzzle/marques`, ja `/ja/パズル/ブランド`. Priority above `puzzle_detail`'s catch-all.
   - Lists every brand whose hub is indexable, grouped A–Z with puzzle counts, and a "most popular brands" block at the top.
   - Linked from `/en/puzzle` and from each brand hub.
6. **Sitemap brands:** add indexable brand×pieces pages and the directory, ×6 locales. Do not add paginated pages; they are discovered via links.
7. **Performance:** check the list and count queries on the prod-copy DB for Ravensburger (6k puzzles) at a deep offset. Budget: no page slower than today's hub.
8. **Tests:**
   - pagination 200/301/404
   - brand×pieces 404 / noindex / index rules
   - directory lists and links hubs
   - sitemap contents
   - canonical of page N is self

## WS-F2 — Site-wide links (item 4; after WS-A, WS-D and WS-F1 are on main)

Files:
- `templates/base.html.twig` (footer)
- `templates/puzzle_detail.html.twig` (breadcrumb, related module, piece-count link)
- `src/Query/GetRelatedPuzzles.php`
- `templates/_puzzle_item.html.twig`
- the pieces-hub, brand×pieces and guide cross-links
- the `/en/puzzle` page block
- translations ×6
- tests

1. **Footer "Popular searches"** (anonymous only): replace all 16 filter-URL links. Labels come from translation keys with parameters.
   - Ravensburger 500 / 1000, Trefl 500, Clementoni 500, Buffalo Games 500 → brand×pieces pages.
   - Cobble Hill, Educa, Galison, Masterpieces → brand hubs.
   - WJPC 2022/2023/2024 → their event pages. Also add WJPC 2025/2026, or link the WJPC hub.
   - BOTYP 1–3: check whether the tags map to events. If yes, link them; otherwise drop them.
   - 750 pieces → pieces hub.
   - New: "All puzzle brands A–Z" → directory; "How long does a 1000-piece puzzle take?" → guide.
2. **Puzzle detail:**
   - a visible breadcrumb "Puzzle database › {Brand} › {Brand} {N} pieces › {name}" (skip missing levels);
   - BreadcrumbList with the same levels (#141);
   - the piece count in the header links to the brand×pieces page when it is indexable, else the pieces hub.
3. **Related module "More {brand} {N}-piece puzzles":** 6 cards.
   - 3 most-solved of the same brand + piece count, plus 3 picked stably per page (`ORDER BY md5(p.id::text || :currentId)`) from the same combination with ≥ 1 solve. The goal is that link equity reaches the long tail instead of the same top 6 on every page.
   - Fall back to brand-only when the combination has fewer than 7 puzzles.
   - Footer link to the brand×pieces page.
4. **List cards** (`_puzzle_item.html.twig`): the brand name links to the brand hub (only when the brand has a slug).
5. **Cross-links:** the pieces hub and brand×pieces pages link to the matching "how long" guide; the guides link to the pieces hub; `/en/puzzle` gets a "Browse by brand / by size" block (directory, top brand hubs, pieces hubs).
6. **Tests:** footer has no `?brand=`/`?tag=`/`?pieces=` links; breadcrumb levels; related-module query test; list-card brand link.


## WS-G — Events (item 5)

Files: `templates/event_detail.html.twig`, `templates/edition_detail.html.twig`, `templates/competition_series_detail.html.twig`, `templates/round_results.html.twig`, their controllers (pass an `is_past` flag computed with ClockInterface — no `date('now')` in Twig), `WjpcHubController` + `wjpc_hub.html.twig`, translations ×6, tests.
1. **Titles** (event + edition): full event name, never shortened; drop the generic " – Speed Puzzling Competition" suffix. After the event (dateTo ?? dateFrom < today): "{name} {year} Results" (localised word order/wording, e.g. de "… Ergebnisse", fr "Résultats …", cs "… výsledky"); before/while: "{name} {year}". Year only when the name does not already contain a 4-digit year and a date exists.
2. **Meta descriptions**: past events say results are available (number of recorded results / rounds when cheap to get), upcoming keep date + location.
3. **Round results**: title leads with the event: "{Event} – {Round} Results" (localised).
4. **Event JSON-LD**: use a larger image than `puzzle_small` for `image` (check available imgproxy presets; the logo original via `uploaded_asset` is fine).
5. **Indexability**: series/editions that are unapproved or rejected get `noindex, nofollow` (same rule as events; today they are indexable).
6. **WJPC hub**: add a "Puzzles of every World Jigsaw Puzzle Championship" section — per edition (newest first) the puzzles used (from competition rounds and/or the WJPC tags), linked to puzzle pages, with public median/fastest when available. Targets "WJPC 2025 puzzles", "ravensburger world championship puzzles".
7. Tests: title variants (past/upcoming/year in name/no date), edition + round-results titles, noindex for unapproved series/editions, WJPC hub lists edition puzzles.

## WS-H — Public difficulty lists (item 10)

Data (prod copy): 6.3k puzzles have a difficulty score; 3,460 with `confidence` medium/high. Per size (medium/high): 500 → 2,561, 300 → 355, 1000 → 230, 200 → 76, 100 → 72. Per brand: Ravensburger 1,278, Trefl 182, Buffalo Games 144, Clementoni 116, Galison 115, Gibsons 102, Cobble Hill 94, Masterpieces 93, Educa 83, Boardwalk 75 …
Membership rule (Jan): difficulty is members-only and a membership CTA → **public page = ranked names only**; scores/tiers/full ranking = members.
1. **Pages** (localised paths, priority above `puzzle_detail`): per piece count `/en/puzzle/{pieces}-pieces/hardest` and `/easiest` (cs `…-dilku/nejtezsi|nejlehci`, de `…-teile/schwierigste|leichteste`, fr `…-pieces/plus-difficiles|plus-faciles`, es `…-piezas/mas-dificiles|mas-faciles`, ja `…ピース/難しい|簡単`); per brand `/en/puzzle/brand/{slug}/hardest|easiest` (same localised words). Exist only when ≥ 50 qualifying puzzles (size) / ≥ 40 (brand); otherwise 404.
2. **Qualifying**: approved, not hidden (`hide_until`), `difficulty_score` not null, confidence medium or high. Order by score desc (hardest) / asc (easiest), tie-break sample size desc, name.
3. **Public view**: H1 "The 25 Hardest 1000-Piece Puzzles" (localised); short intro how difficulty is measured (real solve times, relative to each solver's usual speed, same piece count — link to methodology); ranked #1–#25 with image, brand + name, pieces, public median solo time + solves (raw median is public per CLAUDE.md); a locked "difficulty" column/badge per row opening `#membersExclusiveModal`; after #25 a CTA "Full ranking of N puzzles with difficulty scores — members".
4. **Members view**: tier badge + existing `human_difficulty` wording per row, ranking up to 100.
5. **SEO**: titles/meta localised (e.g. "25 Hardest 1000-Piece Puzzles – Ranked by Solve Times"), BreadcrumbList, ItemList JSON-LD of the public 25 (url + name + position), `index, follow`; new sitemap child `sitemap-difficulty.xml` (register in `SitemapIndexController`) with every existing list ×6 locales.
6. Cache list data ~1 h (difficulty recalculates every 15 min).
7. Cross-links from pieces/brand hubs to these lists are added by WS-F2 (hubs are WS-F1's files).
8. Tests: 404 below thresholds, ordering, public view never contains scores/tier names, member view does, JSON-LD, sitemap.

## WS-I — Remaining small fixes (item 11)

1. `/en/hub` (all locales): `noindex, follow` and removed from `sitemap-static.xml` — it duplicates `/en/recent-activity` + ladder, has a "Welcome puzzler!" H1 and ranks for random puzzle queries (e.g. "puzzle ravensburger 500 pieces" #39). Keep it in the navigation.
2. Ladder: move the category dropdown out of `<h1>` (`ladder.html.twig:7`); per-piece-count ladder pages get titles/meta for the "fastest {N}-piece puzzle / record" intent (localised), e.g. "Fastest 1000-Piece Puzzle Times – Solo Records".
3. Tracker app page (`puzzle-tracker-app.html.twig`): contextual links (puzzle database, brand directory once it exists — else `/en/puzzle`, ladder, guides, stopwatch info) and live catalogue numbers (puzzles, brands, EANs, solve times — one cached query service reused by the homepage); homepage "Browse the Jigsaw Puzzle Database" section shows the same live numbers. Localised.
4. Meta descriptions for pages that fall back to the homepage one: puzzle-library, player-favorites, activity-calendar, blog, marketplace how-it-works (localised).
5. `players_per_country` with 0 players → `noindex, follow`.
6. Sitemaps: players sitemap only lists players with ≥ 1 public result; puzzle sitemap `lastmod` = latest of `added_at` / last solve (`tracked_at`) / approval; image sitemap uses the EN (x-default) URL and a large image (≥ 1200 px preset if one exists — check `config/packages/*imgproxy*`/`puzzle_image` presets; do not add imgproxy presets that need infra changes without reporting).
7. Tests for each.

Phase-2 leftovers (touch files owned by WS-A/WS-B, do after they are on main): puzzle `og:image` ≥ 1200 px; show the marketplace price ("from €X") next to the offers badge so Product `offers` are visible; `/cs/_components` robots rule mismatch.

## Puzzle names on puzzle pages (puzzle names phase 2, 2026-10)

The locale copies of a puzzle page differed only by their chrome (research §4.1). A puzzle with a name in the page's
language now carries it in what search engines read - always the **page** language, never a signed-in player's
country, so one URL is one HTML for every guest. Design of record: `docs/features/puzzle-names/README.md` "SEO".
- **Title** `puzzle_detail.meta.title_with_local_name`: "Ravensburger Circle of Colors: Seashells (Kruh barev: Mušle)
  – puzzle 500 dílků"; without a name in that language the title of A4. **No ` – MySpeedPuzzling` suffix on puzzle
  pages** (`title_suffix` block in `base.html.twig`, `og:title` follows; `og:site_name` stays).
- **Meta description** (and the Product description): the A4 sentences with "Main / Local" as the name
  (`puzzle_detail.meta.name_with_local_name_description`).
- **H1** stays the main title, the local name a second line inside it (`lang`); "Also known as" lists every name with
  its language - guests (crawlers) in "About this puzzle", signed-in players in Details.
- **Product JSON-LD** (still only with marketplace offers): `alternateName` = every other name; `gtin13` / `gtin8` =
  every stored code with a right check digit (`EanList::gtins()`, UPC-A padded to 13). Every JSON-LD value on the
  site goes through the `json_ld` filter (`<` `>` `&` `'` `"` as `\u` escapes - a name with `<!--<script>` broke the page).
- **Sitemap** `lastmod` = latest of added, approved, `names_changed_at`, last solve (`GetPuzzleIdsForSitemap`, both
  sitemaps; same plan, measured), never earlier than `PAGE_LAST_REBUILT_AT` ("Sitemap lastmod floor" below).
- Hreflang and canonical untouched. Follow-up: index coverage per language in Search Console (`docs/TODO.md`).

## Puzzle meta description — facts first (2026-10-05)

The A4 sentences put the EAN inside the parentheses, so on a phone (~120 visible characters) the times and the call
to action were cut for most puzzles; pair and team solves were never mentioned (1 solo + 1 pair said only "fastest
solo time … so far"); "median … from N solves" mixed a per-solver median with a solve count.

- `puzzle_detail.html.twig` builds a list of clauses from `PuzzleSummary` (no extra query) and joins them with
  `puzzle_detail.meta.facts_separator`:
  1. solo - several times `facts.solo_times` (fastest, median, solo solve count), one time (median = fastest)
     `facts.solo_single`, none but pair/team times `facts.solo_none`;
  2. `facts.pairs` when there are pair solves, 3. `facts.teams` when there are team solves (count + fastest).
- Clauses → `puzzle_detail.meta.description_with_facts`; none → `puzzle_detail.meta.description`.
- EAN **last** (`puzzle_detail.meta.ean`, " EAN {ean}."), first EAN only, never while the image is embargoed.
- "Main / Local" name as before (`name_with_local_name_description`); the Product JSON-LD reuses the description.
- Example: "Ravensburger The World of Trolls (150 pieces): fastest solo time 9min 33s so far; 1 pair solve in 21min
  53s. Compare your time. EAN 045570100330."
- Register follows each locale's puzzle page (cs "ty", de "Sie", es "tú", fr "vous"). "on MySpeedPuzzling" was dropped
  to leave the characters to the facts (the result shows the site name anyway).

## Sitemap lastmod floor (2026-10-05)

Between 2026-09-30 and 2026-10-05 **every** puzzle page changed its title, meta description, main content
("About this puzzle"), catalogue links and structured data - but the puzzle sitemaps' `lastmod` (latest of added,
approved, `names_changed_at`, last solve) never moved, so Google kept the 2026-09-28 copies.

- `GetPuzzleIdsForSitemap::PAGE_LAST_REBUILT_AT` is the floor of every puzzle `lastmod` (one more `GREATEST`
  argument in both the puzzle and the image sitemap; a later solve, approval or names change still wins).
- **Bump it only when every puzzle page changes its main content, title/meta, structured data or links** - to the
  deploy date of that change. Never for styling or chrome, never "today", never automatically: Google uses `lastmod`
  only while it stays accurate (a significant change = main content, structured data or links), and a sitemap that
  claims changes that did not happen teaches it to ignore the field.
- Set to 2026-10-06 for the first rebuild: the deploy came late on 2026-10-05 UTC, and Google had fetched pages
  earlier that day - a `lastmod` of the same day would not tell those copies apart from the rebuilt page.
- 2026-10-08 for the preview image below (deployed late on 2026-10-07 UTC, the same reasoning).
- Other sitemaps (players, events, brands, static, …) keep their own rules - their pages did not change that way.

## Preview image (2026-10-07)

Google showed the box of **another** puzzle next to puzzle pages: Clementoni "Lion King" (99 pieces) appeared in en,
de and es results with Clementoni "Braies Lake" (500 pieces). From 2026-07-11 to 2026-09-30 the related puzzles
module listed a brand's most-solved puzzles across every piece count, so Braies Lake (the brand's second most solved)
was on every Clementoni page, as a sharp picture next to a phone photo of the box on a table. Google picks the result
preview by itself among a page's `<img>` elements. Our pages gave it one hint, `og:image`: the Product JSON-LD with
`image` exists only with marketplace offers.

Requirement (Jan): a puzzle page's preview is its own picture - the one in `og:image` - never another one.

- **One URL, every signal.** `page_image` in `puzzle_detail.html.twig` (the `puzzle_large` preset, null when there is
  no picture or it is embargoed) feeds `og:image`/`twitter:image`, the Product `image` and an `ItemPage` JSON-LD with
  `primaryImageOfPage` on every puzzle page with a picture. Google documents `primaryImageOfPage` and `og:image` as
  the ways to name the preferred preview image ("Specify a preferred image with metadata",
  developers.google.com/search/docs/appearance/google-images). `ItemPage` has no rich-result requirements, so it
  does not repeat the Product-without-offers problem.
- **No other puzzle as an `<img>`.** The related puzzles' boxes are CSS backgrounds (`.puzzle-related-picture`,
  `image-set()` with the small/medium presets, URLs through Twig's `css` escaper), because Google does not index CSS
  images. They look the same. The price: no native lazy loading for those six small pictures at the bottom of a
  guest's page.
- Guard: `PuzzleDetailControllerTest::testThePuzzlesOwnPictureIsTheOnlyPreviewCandidate` - every `<img>` from the
  image host on a puzzle page is the puzzle's own picture, or a player's avatar in the rankings. A new module showing
  other puzzles must use backgrounds too.
- **Avatars are not crawlable.** The rankings' avatars stay `<img>` (CSS backgrounds would load a long leaderboard's
  avatars all at once), so the image host's `/robots.txt` (lily.srv `apps/myspeedpuzzling/nginx-imgproxy.conf`,
  2026-10-07) disallows `avatars/` behind every bucket path (`/*/plain/avatars/`, `/original/avatars/`,
  `/puzzle/avatars/`) for every crawler. Twitterbot, facebookexternalhit, LinkedInBot, Slackbot and Discordbot keep
  them: a shared player profile's `og:image` is the avatar. A new image path to the bucket needs its line there.
- A puzzle without a picture has no `page_image` and no `ItemPage`; `og:image` stays the site logo.
- Google's copies change only when it fetches the pages again: `PAGE_LAST_REBUILT_AT` moved to 2026-10-08.

---

## Event titles — rationale (now WS-G)

Recommendation: keep the **full event name**, because it is exactly what people search.
- Most of the length comes from the generic suffix " – Speed Puzzling Competition" plus " – MySpeedPuzzling". Drop the generic suffix.
- After the event ends: "{full name} {year} Results" (skip the year when the name already contains it).
- Before: "{full name} {year}".
- Google truncates long names at the end, which is fine because the important words come first.
- This is a translation-key plus date-condition change in `event_detail.html.twig` (and the edition page), about an hour of work.

## Open decisions (Jan)

- ~~Public difficulty lists~~: approved 2026-09-30, now WS-H (public = ranked names only, members = scores + full ranking).
- **Guide localisation** (DE/FR/ES/CS/JA): non-English "how long" results are weak shop blogs. Decide once the EN guides prove traffic.
- **Live components blocked by robots.txt** (`/{loc}/_components`): Google cannot render lazy blocks. Allowing GET component renders for Googlebot is a separate decision.

---

## Orchestration

**Phase 1 (parallel):** WS-A, WS-B, WS-C, WS-D, WS-E, WS-F1; added 2026-09-30: WS-G, WS-H, WS-I.
- Each agent works in its own git worktree based on **origin/main**, not the shared checkout, which other sessions use.
- Each agent runs the gates in its own container with its own test DB. The recipe is below.
- Each agent commits on its worktree branch and does **not** push.

**Phase 2:** WS-F2, after A, D, F1 and H are integrated (F2 also adds the hub → difficulty-list links); then the WS-I phase-2 leftovers.

**Integration (orchestrator), one workstream at a time:**
1. Rebase the branch onto the latest `origin/main` in a scratchpad integration worktree.
2. Run the full gates.
3. Check that `git log origin/main..HEAD` contains only this workstream's commits.
4. `git push origin HEAD:main`, which deploys via Tests → Release → webhook.

**Gates**, identical to CI, run from the worktree on the host:
```
docker run --rm --network speedpuzzlingcz_default -v <wt>:/app -v <main>/vendor:/app/vendor:ro -v <main>/public/build:/app/public/build:ro -w /app \
  -e APP_ENV=test -e "DATABASE_URL=postgresql://postgres:postgres@postgres:5432/speedpuzzling_test_<ws>?serverVersion=16&charset=utf8" \
  --entrypoint php ghcr.io/myspeedpuzzling/web-base-php85:main vendor/bin/phpunit --testsuite "Project Test Suite"
```
- PHPStan: run `APP_ENV=dev php bin/console cache:warmup`, then `vendor/bin/phpstan --memory-limit=2G analyse`.
- `vendor/bin/phpcbf` then `vendor/bin/phpcs`.
- `php bin/console doctrine:schema:validate` and `php bin/console cache:warmup`.
- The test DB name must be distinct per worktree, because `tests/bootstrap.php` drops it. Afterwards: flip `<db>_template` `datistemplate=false` and drop both DBs.

**After deploy:**
- Check production HTML in Chrome: no fake strings, the summary paragraph, robots.txt, the new pages, row count on a big puzzle.
- Watch GSC per `research-2026-09.md` §9.
- Mark #141 done once the breadcrumb ships.
- Speed check of every page and query of this round on production-sized data, with fixes: `performance-2026-10.md`.
