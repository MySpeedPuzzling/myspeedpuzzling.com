# SEO Overhaul — Implementation Plan

Produced 2026-07-10 from a 55-agent deep review: 14 codebase/live auditors (148 findings), 7 research agents, and adversarial verification of every load-bearing factual claim (13 confirmed, 1 refuted). Goal: become the #1 puzzle portal — boost "speed puzzling"/"speedpuzzling", brand queries, "puzzle tracker app", competitions, and puzzle-brand long-tail. White-hat only, no UX sacrifice. English first, then all locales.

## Verified ground rules (drive every decision below)

Every claim here survived two independent adversarial verifiers against primary sources (July 2026):

1. **Spelling**: Google near-synonymizes "speedpuzzling" and "speed puzzling" (substantially overlapping SERPs, same #1). The two-word form owns the generic autocomplete long tail (near me / competition / tips / times) and is what NPR, Axios, Ravensburger and USAJPA use. → **Use "speed puzzling" (two words) in EN titles/H1/copy; the brand token "MySpeedPuzzling" covers the compound on every page. Never build per-variant pages.**
2. **Title budget**: 51–60 chars total has the lowest Google-rewrite rate (39–42%); >70 chars is rewritten 99.9% of the time (Zyppy, 80,959 titles). Dash separators survive rewrites ~2× more often than pipes (19.7% vs 41% removal). Title↔H1 mismatch is a rewrite trigger; Google uses H1 as a title-link source.
3. **Meta descriptions**: no hard limit; ~150–160 chars desktop-safe (~120 visible mobile). Google rewrites 60–70% of them, and **explicitly endorses programmatic generation for database-driven sites** — green light for generated descriptions on 34k+ puzzle pages.
4. **Dead ends (do NOT invest)**: FAQPage rich results dead for all sites since 2026-05-07; sitelinks searchbox (SearchAction) retired 2024-11-21; ItemList carousels don't apply to leaderboards; meta keywords has zero effect. Breadcrumb display is desktop-only since 2025-01-23 (markup still supported, keep it cheap).
5. **Live rich-result opportunities**: **Product** (name + ONE of review/aggregateRating/offers — price NOT required; non-merchant "aggregator" pages explicitly supported; community ratings of third-party puzzles are NOT "self-serving") → star snippets on puzzle detail. **Event** (needs name, ISO startDate, physical venue + address; members-only/online-only excluded from the event experience). **ProfilePage** (built for community profile pages, launched 2023). **WebSite** name markup — but it only works on the **domain-root homepage**; a subdirectory homepage like /en/home cannot carry the site name.
6. **hreflang**: annotations pointing at non-canonical URLs are ignored and can degrade the whole cluster — alternates must reference canonical URLs exactly. Prefer language-only `cs` over `cs-CZ`. Dual delivery (link tags + sitemap) is allowed but has "no benefit" and guarantees desync — pick one. x-default is designed for language-selector pages.
7. **Programmatic pages**: Google's scaled-content/doorway policies target pages made "primarily to manipulate rankings, not help users." Data-dense hub pages in a browseable hierarchy with unique per-page stats (Strava/chess.com/BGG pattern) are the compliant shape. Gate indexability by data density; never mass-generate thin pages across locales.
8. **Refuted — no action**: speedpuzzling.cz **already 301-redirects URL-to-URL** (path+query preserving, apex+www) to myspeedpuzzling.com. Residual old-domain index entries are normal decay. No domain-consolidation work needed.

### GSC reality check (3 months to 2026-07, from Search Console exports)

- 14.5k clicks / 101k impressions on queries; **67% of clicks are brand queries** — non-brand organic is the growth surface.
- **"speed puzzling": avg pos 2.55 blended worldwide** (US-only ~#4), 5.3k impressions, 20.6% CTR. "speedpuzzling" pos 2.04. The #1 fight is a US fight (US = 129k impressions at avg pos 8.45, CTR only 3.2%).
- **Events are already the biggest non-brand asset and the biggest waste**: 46 event pages earn 69.8k impressions (more than all 811 puzzle-detail rows: 64.6k) but convert terribly — `/en/events/world-jigsaw-puzzle-championship-2025`: 26.5k imp, pos 8.0, **CTR 0.85%**; query "world jigsaw puzzle championship": 3.1k imp, pos 6.79, **CTR 0.29%**. Hundreds of "{country} championship 2026" long-tail queries sit at pos 4–10 with ~0–5% CTR. → Event titles/descriptions/schema/evergreen hubs are the single highest-ROI item in the whole plan.
- `/en/puzzle` listing: 32k impressions, pos 5.96, **CTR 1.41%** — it intercepts puzzle-name long-tail that should land on detail pages (generic title/snippet). Puzzle-name queries confirm the long-tail play works ("playful mario puzzle" 564 imp pos 6.1; dozens of "{brand} {name}" at pos 4–9).
- Head retail terms confirmed hopeless from real data: "ravensburger puzzle(s)" pos 40–75. Matches the plan's anti-tactics.
- German market outperforms: /de/start CTR 41.6% at pos 3.13; "speed puzzle meisterschaft 2026" 542 imp pos 8.5 CTR 1.5% → German event/locale work (Phase 6) has proven demand.
- Desktop underperforms mobile badly (CTR 3.2% vs 12.2%, pos 9.1 vs 7.2) — consistent with weak titles/site-name signals (Phases 2–3).
- Rich results today: "product snippets" appear with only 12 impressions — effectively zero structured-data footprint (Phase 3 upside).

### Baseline positions (US, July 2026 — re-verify in GSC)

| Query | Position | Who's ahead |
|---|---|---|
| speed puzzling | **#4** | speedpuzzling.com, usajigsaw.org, speedpuzzle.eu |
| speed puzzling app | **#1** (4 of top 10 own pages) | — |
| puzzle tracker app | #3–4 | Google Play / App Store listings ("Puzzle Tracker") |
| speed puzzling leaderboard | #2 (/en/ladder) | usajigsaw.org JPA-Rating |
| how long does a 1000 piece puzzle take | **absent** | manufacturer blogs guessing 5–20h |
| list of all Ravensburger puzzles / Ravensburger puzzle database | **absent** | small hobby sites (puzzlesbyliza.com, seriouspuzzles.com) — beatable |
| myspeedpuzzling / myspeedpuzzle | #1, SERP fully owned | — |

### Per-locale primary keywords (verified per market)

| Locale | Lead terms for titles/copy |
|---|---|
| en | speed puzzling, jigsaw puzzle + tracker/database/competition |
| de | Speed Puzzle / Speedpuzzeln ("Speed-Puzzeln"), Speed Puzzle Meisterschaft — NOT the English gerund |
| fr | speed puzzle / speed puzzling (borrowed), concours de puzzle (events) |
| es | speed puzzling + puzzles contrarreloj, campeonato de puzzles (events) |
| ja | スピードパズル, ジグソーパズル早組み (events) |
| cs | speed puzzling (borrowed, top cs completion), skládání puzzle na čas, mistrovství ve skládání puzzle (events) |

---

## Phase 1 — Stop the bleeding (critical + regressions) `~1–2 days`

### 1.1 ⚠️ Fix the in-flight puzzle-search rework (branch `puzzle-component-reworked`) — CRITICAL
The branch currently ships an SEO regression on the site's most important page:
- `templates/puzzles.html.twig` renders `<twig:PuzzleSearch loading="defer" />` → initial HTML contains an empty skeleton, **zero puzzle links** (main serves 20 crawlable anchors today). Fix: remove `loading="defer"` for the default (no-search) state — page-one data is already app-cached (`initial_puzzles_v2`, 3600s) so a synchronous render is cheap. Defer only when `?search` is present.
- Load-more became a JSON-fetching button → puzzles beyond page 1 invisible to crawlers. Fix: render the button as a real `<a href="{{ path('puzzles', {...criteria, offset: N}) }}">` (full-HTML fallback as on main) and let the Stimulus controller intercept click for the JSON-append UX.
- Acceptance: `curl -s https://…/en/puzzle | grep -c 'puzzle_detail href'` ≥ 20 pre-JS; load-more node is an `<a href>`.

### 1.2 Sitemap overhaul (currently a single 43.5 MB file, 67 s to serve, 87% of the 50 MB hard limit)
`src/Controller/SitemapController.php`, `templates/sitemap.xml.twig`:
- Convert to a **sitemap index** at `/sitemap.xml` → child sitemaps: `sitemap-static.xml`, `sitemap-puzzles-{n}.xml` (~10k URLs/file), `sitemap-marketplace.xml`, `sitemap-events.xml`, `sitemap-players.xml`, `sitemap-feature-requests.xml`.
- **Emit one `<url>` per locale** (today only the Czech URL is ever listed as `<loc>` — all EN pages, the primary target, are absent). Remove `xhtml:link` alternates from the sitemap entirely (hreflang stays page-level only — single delivery method, per ground rule 6); this offsets the 6× entry growth.
- Add `<lastmod>` (puzzles have timestamps), Twig whitespace control (`{%- -%}`), gzip.
- Remove: the bare `/` entry (it's a 302), `homepage_crossroads` redirect entry, `contact` (until rebuilt, see 2.6), zero-player `players_per_country` entries (emit only countries with ≥1 player; `GetPlayersPerCountry::count()` exists).
- Add missing public routes: `event_detail`, `competition_series_detail`, `edition_detail` (approved only — new `GetCompetitionSlugsForSitemap` query), public non-private player profiles, `blog`, `marketplace_how_it_works`, `fair_use_policy`.
- Serving: pre-generate to disk via cron/Messenger (fits project patterns) or at minimum drop `private` from Cache-Control so `s-maxage=21600` actually works at the proxy.

### 1.3 Canonical ↔ hreflang consistency
`templates/base.html.twig`:
- hreflang alternates currently merge query strings while canonical drops them → every filtered/paginated URL emits alternates pointing at non-canonical URLs. Fix now (small): **suppress hreflang link tags when the request has any non-route query params**, and stop merging `cleanQuery` into alternates — alternates must equal the canonical URL exactly.
- og:url: build from route+params like canonical (currently raw `app.request.uri` incl. `return*` params).
- Delete the dead byte-identical canonical override in `templates/puzzles.html.twig`.
- (Param-whitelisting canonicals for filter pages becomes moot once Phase 4 path-based brand/pieces pages exist.)

### 1.4 Kill soft-404s and recover merge equity
- Merged/deleted puzzles: `DeleteMergedPuzzlesOnMergeApproved` hard-deletes; `PuzzleDetailController` then 302s to `/puzzles` (soft-404, all merge link equity discarded). Add a `puzzle_redirect` table (old_id → survivor_id) written before removal; controller checks it and issues **301** to the survivor; genuinely unknown ids → **404** (or 410 for deletions). Same 404-not-302 fix for unknown players.
- `/wjpc-2024` + `/en/wjpc-2024` 404 since the controller was deleted → 301 to the current WJPC series page; delete orphaned `wjpc2024.html.twig` + `_wjpc2024_table.html.twig`. Policy: competition pages are never removed, only redirected (pattern exists in `EditionDetailLegacyRedirectController`).
- Bare locale roots 404: `/en`, `/es`, … → 301 to the locale homepage. Consider 301s for common slips (`/en/puzzles` → `/en/puzzle`).

### 1.5 robots.txt (`public/robots.txt`)
Add the missing `ja` disallow block; remove Yandex-only `Clean-param`; add `Disallow: /admin`, `/internal-api`; drop the two stale rules matching no route.

### 1.6 Privacy + index-quality: private player profiles
`templates/player_profile.html.twig` + `PlayerHeader.html.twig`: private profiles are indexable and leak the real name in title/H1/og:title. For `isPrivate` viewed by others: `noindex` + replace name with "Secret Puzzler #CODE" everywhere. (Privacy fix — ship regardless of SEO.)

### 1.7 Quick hygiene (one-liners, bundle into one PR)
- Remove the `meta keywords` tag (base.html.twig:10) and its per-route conditional.
- Unify brand string to **"MySpeedPuzzling"** in og:title suffix + og:site_name.
- Fix raw `&nbsp;` piped through `|raw` into puzzle-detail `<title>`/og:title (use a plain space).
- `avatar_small` imgproxy preset doesn't exist → supporter avatars 404 in production. Use `avatar` preset or add `avatar_small=rs:fit:100:100`.
- Noindex thin fragment fallbacks: `rating/player_ratings.html.twig` full-page fallback → redirect to profile (pattern in `PuzzleQrCodeModalController`); pending-proposals fallback → noindex; error pages should emit `noindex` robots meta.
- `puzzle_detail_qr` duplicate URL set → make it redirect (301) to `puzzle_detail`, or canonical to it.

---

## Phase 2 — Titles, descriptions, H1s (site-wide) `~2–3 days`

Rules: ≤60 chars incl. suffix; switch suffix separator to `– MySpeedPuzzling` (dash, not pipe); every indexable template gets a unique `meta_description` (~120–155 chars); H1 must contain the title's core phrase. All strings via translation keys in all 6 locales, localized per the keyword table above (not literal translations).

Priority templates (exact EN proposals — adjust freely):

| Page | Title (incl. suffix ≤60) | Notes |
|---|---|---|
| Homepage | `Speed Puzzling – Track Times & Compete – MySpeedPuzzling` (57) | Brand restored to the ONE page that had none — critical for brand queries |
| Puzzle detail | `{Brand} {Name} – {N} Pieces – MySpeedPuzzling`; truncate name to fit | **H1 must become `{Brand} {Name} ({N} pieces)`** — today H1 is bare name (rewrite trigger). Description generated: "Real solve times for {Brand} {Name}, {N} pieces: fastest {fastest}, median {median} from {count} solves. Track yours on MySpeedPuzzling." |
| Puzzles hub | `Jigsaw Puzzle Database & Solve Times – MySpeedPuzzling` (54) | H1: "Jigsaw Puzzle Database" (today: "Puzzles"); +151-char description |
| Event detail | `{Name} {Year} – Speed Puzzling Competition – MySpeedPuzzling` | skip year if already in name; description from date+location; edition pages get "– Results & Puzzles" (results intent is unserved today) |
| Ladders | wire the existing unused `ladder.meta.title`; per-pieces variants get distinct titles ("1000-Piece Puzzle Leaderboard – Fastest Times") | distinct titles per solo/pairs/groups × 500/1000 — free long-tail |
| Player profile | `{Name} – Speed Puzzler Profile – MySpeedPuzzling` | + generated description; visible H1 |
| Tracker app | `Puzzle Tracker App – Log Times & Collection` (61 total) | currently 74 chars, truncated on its own target query |
| Marketplace | move hardcoded English strings to translation keys (all locales) | `{Brand} {Name} Marketplace – Buy or Swap` |
| players_per_country | `Speed Puzzlers from {Country} – Rankings & Times` | + description; only indexable when ≥1 player |
| Methodology | `Puzzle Difficulty & MSP Rating – How It Works` | today "How We Calculate" (meaningless in SERP) |

Wire the ~10 existing-but-unused `*.meta.description` translation keys (faq, events, ladder, puzzle_overview, puzzlers, recent_activity) into their templates — content already written, just not connected to `meta_description` blocks.

---

## Phase 3 — Structured data `~2 days`

Implementation: a `templates/seo/` set of JSON-LD partials; **all dynamic values through `|json_encode`** (4 of 5 existing blocks interpolate with HTML autoescaping — quote-unsafe). Every marked-up value must be visible on the page.

1. **WebSite + Organization `@graph`** — name `MySpeedPuzzling`, alternateName `["My Speed Puzzling", "Speed Puzzling"]`. Site-name markup requires the **domain root**: make `/` serve the language-selector (`homepage_crossroads`) as a **200 self-canonical page** carrying this markup (today `/` is a 302 → /en/home, so no URL can carry the site name). Keep Accept-Language auto-redirect only as JS-optional enhancement or drop it. This also fixes the "strongest URL on the domain is a temporary redirect" problem.
2. **Product on puzzle detail** — name, brand, image (skip when `is_image_hidden`), `gtin13` (EAN already in `PuzzleOverview`!), mpn/sku (identification number), description, `additionalProperty` for pieces. Add **AggregateRating only where visible community ratings exist**; **no offers here**. Marketplace puzzle pages (real prices) are the only place for Product+offers.
3. **Event on event/edition detail** — approved events only; physical events get Place+address (data exists: location, countryCode, dateFrom/dateTo, isOnline, logo); online events get VirtualLocation (no rich-result expectation). **Delete the invalid fake `SportsEvent` on the events listing** (a listing marked up as one event with no date/location).
4. **ProfilePage on public player profiles** — mainEntity Person, avatar, dateCreated, solve-count as interactionStatistic.
5. **BreadcrumbList** on puzzle detail (Puzzles → {Brand} → {Puzzle}), events, marketplace.
6. Remove: `Dataset` markup on /puzzle (misrepresents a catalog page), keep FAQPage markup as-is (harmless, no rich result), do NOT add SearchAction.

---

## Phase 4 — Landing pages + internal links (the growth lever) `~1–2 weeks`

**4.1 Brand hub pages** — `/en/puzzle/brand/{slug}` (cs `/puzzle/znacka/{slug}`, localized slugs). Add `slug` to manufacturer (backfill from name; route requirement so it can't collide with `{puzzleId}` UUIDs). Content: H1 "{Brand} Puzzles – Solve Times & Difficulty", puzzle count, most-solved list, median solve time by piece count (real data!), links to puzzles. Title: `{Brand} Puzzles – Solve Times & Difficulty – MySpeedPuzzling`. Index only brands with ≥N puzzles+solves (thin-page guardrail). Targets the verified-winnable "list of all Ravensburger puzzles" / "{brand} puzzle database" SERPs owned today by small hobby sites.

**4.2 Piece-count pages** — `/en/puzzle/1000-pieces` (500/1000/1500/2000…): listing + real stats ("median 1000-piece time: {X} from {N} solves"). Combined brand+pieces at `/en/puzzle/brand/{slug}/1000-pieces` for the biggest brands only.

**4.3 Internal linking** (today the manufacturer name is plain text everywhere):
- Puzzle detail + list items: manufacturer name → brand hub link; tag badges → tag-filtered listings.
- "More {Brand} puzzles" / "Other 1000-piece puzzles" module (4–8 cards) at the bottom of puzzle detail.
- Footer: add tracker-app (currently a **complete orphan** — zero internal links to a page ranking #3–4 for its target query), methodology, guides, brand hubs; **remove `rel=nofollow` from the events footer link**.
- Ladders: drop `loading="lazy"` on the primary LadderTable per page (core ranking pages currently render zero content pre-JS); keep lazy only below the fold. Same for hub widgets feeding profile discovery.
- Strip `return`/`return_title` params from links into event/puzzle pages (duplicate crawlable URLs).

**4.4 Events**: evergreen series hubs (WJPC hub accumulating yearly editions instead of equity resetting), year in title, competitions calendar page (auto-updated from events data — strictly better than the static calendars currently ranking).

---

## Phase 5 — Content & data assets `~ongoing, start with 3 pages`

Guides hub (`/en/guides/...`, GuidesController + slug detail, translation-key or per-article partial content, Article + BreadcrumbList JSON-LD, in sitemap, footer link):
1. **"How Long Does a 1000-Piece Puzzle Take?"** — THE gap: zero MSP presence, every ranker is a manufacturer blog guessing 5–20h; MSP has the only measured distribution (median/percentiles/fastest, by piece count). One page per major piece count + a pillar "average solve time by piece count" study. Natural link magnet + featured-snippet material.
2. **"What Is Speed Puzzling? The Complete Guide"** — no community platform ranks for it; pairs with live tools (stopwatch, ladder) content sites can't match. Mention "(also written speedpuzzling)" once — reinforces the synonym.
3. **"Speed Puzzling Tips"** — autocomplete-suggested in multiple locales, owned by affiliate blogs today.

Homepage restructure: keep H1, add H2 sections (~60–100 words each) linking hubs: What Is Speed Puzzling / Track Your Times / Leaderboards / Competitions & Events (name WJPC) / Puzzle Database (name brands). Add "jigsaw" vocabulary (body currently never says it). Fix H1→H3 hierarchy skip.

FAQ: add SEO-relevant questions (what is speed puzzling, how long does X take, how competitions work) — for users/AI-search; no rich-result expectation.

Later (PR play): annual **"Year in Speed Puzzling"** data report (Strava Year-in-Sport model) + 2–4 stat studies/year. Outreach list (verified receptive): Speed Puzzling News (Media Recap), Jigsaw Junkies/Puzzle Warehouse, USAJPA "In the News", speedpuzzle.eu, My Jigsaw Journal, puzzle podcasts; mainstream (NPR/WaPo already cover the sport) around WJPC in September.

Anti-tactics (hard no): mass-generated best-of pages without data, translating thin pages across locales, chasing head retail terms ("ravensburger 1000 piece puzzle"), official-body head terms (WJPC — target "{event} results/times" long-tail instead), FAQ markup for rich results, buying links.

---

## Phase 6 — i18n refinements `~1 day`

- `cs-CZ` → `cs` in base.html.twig (sitemap alternates are removed by 1.2, resolving today's cs-CZ vs cs conflict between the two sources).
- x-default → the crossroads page once it's a 200 at `/` (Phase 3.1); until then keep en.
- `og:locale` per locale (`cs_CZ`, `en_US`, …) + `og:locale:alternate`.
- Localize title/meta keywords per the locale table (DE first — biggest divergence from English).
- `/` redirect: if crossroads-at-root is deferred, at least add `Vary: Accept-Language`.
- Rebuild `contact` page with translation keys (today hardcoded-Czech "this page doesn't work" prototype indexed in 6 locales — noindexed in Phase 1); same for `puzzle-service` (Czech-only at 6 locale URLs).

---

## Phase 7 — Performance & images `~2–3 days + ops`

- Remove the two icon-font preloads and subset both icon fonts (195KB at High priority for ~16 header glyphs — top mobile-LCP bottleneck; realistic subset 5–15KB).
- `<link rel="preconnect" href="https://img.myspeedpuzzling.com" crossorigin>`.
- Puzzle listing: first 4–6 result images eager + `fetchpriority="high"` on the first (position is hardcoded 999 today so the eager rule never fires; fold into the search-component branch).
- Puzzle-detail anonymous LCP is a CSS background chart placeholder → inline as data URI / CSS gradient / `<img fetchpriority=high>`.
- Ops decision: **Cloudflare is DNS-only** — orange-cloud static assets (watch Mercure SSE compatibility). Biggest lever for non-EU visitors (WJPC audience).
- Image SEO: dedicated image sitemap for 34.5k unique box photos (child of the 1.2 index); slugified filenames for new uploads (`{brand}-{name}-{pieces}-{shortid}.jpg` — legacy imports already do this, new uploads are UUID-timestamps); add manufacturer to marketplace/collection alt texts.
- 404 page: recovery links (home/puzzles/ladder/events), search trigger, shrink the 1024px illustration, translate error strings (EN-only today).
- Anonymous requests start a PHP session (Set-Cookie + Cache-Control: private on every crawler hit) — investigate lazy session start; prerequisite for any future edge-HTML caching.

---

## Measurement & follow-up

- Before Phase 2 ships: export GSC top queries + pages (last 3 months) to set baselines; note current CTR on homepage/puzzle-detail/ladder.
- After sitemap rework: submit the index in GSC; watch Coverage for the per-locale entries; expect temporary churn.
- Track weekly: positions for the baseline table above + brand-page/stat-page impressions as they launch.
- GSC "hreflang" / "Duplicate without user-selected canonical" reports should trend to zero after Phases 1.3/6.
- Re-run Lighthouse mobile on /en/puzzle and one puzzle detail after Phase 7 (targets: listing LCP 4.6s → ~2.5–3s, detail 6.2s → <3s).

## Decisions log (2026-07-11)

1. **Bot policy (decided)**: good bots welcome, bad bots blocked. robots.txt blocks SEO crawlers, aggressive scrapers, and AI *training-only* bots; search engines and AI *search/citation* agents stay allowed. Real enforcement against robots.txt-ignoring bots needs the edge → see `cloudflare.md`.
2. **Cloudflare orange-cloud (deferred)**: benefits + rollout checklist in `docs/features/seo/cloudflare.md`; revisit when bot load or non-EU CWV becomes pressing.
3. **Puzzle listing crawl strategy (decided)**: default view server-renders cached page 1 (content + 20 links); filtered/search states keep `loading="defer"`; deep pagination stays JSON-only — link equity for detail pages comes from brand/pieces hubs + related-puzzles modules (Phase 4).
4. **Crossroads-at-root 200 page (still open)**: needed for Google site-name (WebSite markup only counts on the domain root, and `/` is currently a 302). UX-sensitive — decide before Phase 4.
5. **Brand-hub URL scheme (still open)**: `/en/puzzle/brand/{slug}` vs `/en/brands/{slug}` — decide at Phase 4 kickoff.
6. **Scope through Phase 3 implemented on main** (2026-07-11): Phases 1–3 built directly on main per Jan's instruction; Phases 4–7 remain.
