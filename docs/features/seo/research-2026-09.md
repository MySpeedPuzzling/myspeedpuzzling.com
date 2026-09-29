# SEO research — September 2026

Deep dive requested 2026-09-29, eleven weeks after the July overhaul (`README.md`, `TODO.md`). Question: where are the biggest keyword/content gaps, which keywords to add, what to change in the HTML, and what else would grow organic traffic.

**Sources**
- Google Search Console: exports for the last 3 months (2026-06-28 → 09-27), plus live reports for Performance (filtered), Page indexing, Sitemaps, Crawl stats, Links, Product snippets / Merchant listings / Events, Core Web Vitals, and URL Inspection (sample of 14 URLs).
- Live Google SERPs, checked in Chrome from CZ with `hl=en&gl=us&pws=0`. Positions are therefore indicative, not exact US rankings.
- Production HTML.
- Local prod-copy DB (data to 2026-09-25).
- Code audit of `main` @ `becd98ec`.
- Competitor sweep: about 150 searches, about 40 competitor pages inspected.

---

## 1. Summary

1. **The July overhaul worked.**
   - Daily clicks went from about 230 to 450–500, and daily impressions from about 2.5k to 7k.
   - "speed puzzling" in the US moved from avg #3.4 to #2.3.
   - Growth now comes mostly from the **puzzle long tail**: `/puzzle/` URLs went from 305 to 800 clicks/week (×2.6) in 12 weeks, and more than 1,000 different puzzle pages earn clicks.
2. **The head terms are small.**

   | Query | Impressions (3 mo) | Rough monthly | Notes |
   |---|---|---|---|
   | "speed puzzling" | 5.8k | ~1.9k/mo worldwide | ~1k/mo in the US |
   | "puzzle tracker (app)" | ~1.1k | | |
   | "(jigsaw) puzzle database" | ~210 | | |

   Winning them is worth hundreds of clicks a month, not thousands, and every one of them is now mostly an **authority** fight (links), not an on-page one.
3. **The biggest volume is the 40.6k puzzle pages, but three things cap them.**
   - (a) **Indexation:** only about 45% of sitemap URLs are indexed (135k of about 301k). 115k are "discovered – not indexed" and 51k "crawled – not indexed". English puzzle pages are mostly indexed (9/10 in the sample); the ×6 locale copies of language-neutral content are what Google skips.
   - (b) **SERP intent:** puzzle-name searches are shopping searches. Sponsored products and an AI Overview sit above organic, so **even at #1 organic we get ~0–5% CTR**. Example: "ravensburger bavarian romance", we were the first web result in my check (GSC average position 3.5), 70 impressions, 0 clicks.
   - (c) **Page quality signals** (next point).
4. 🚨 **Bug: every puzzle page shows anonymous visitors, and so Googlebot, invented insight numbers.** The members-only teaser is blurred dummy text: "Challenging · 12% harder than average · ~48min · Range 35min–64min…". It is identical on ~244k URLs and Google indexes it as fact:
   - `site:myspeedpuzzling.com "12% harder than average"` returns about 1,300 results.
   - Google uses that text as the **search snippet**, e.g. "Suggest a change Missing/incorrect data or duplicate? Puzzle Insights. Difficulty. Challenging 12% harder than average…".
   - It hurts CTR, adds duplicate boilerplate, and is exactly the kind of "fact" an AI Overview would quote. Source: `templates/puzzle/_difficulty_section.html.twig:176-258`.
5. **Most of the catalogue has no crawl path.**
   - Brand hubs link only the top 24 puzzles. Ravensburger has about 6,100.
   - "View all", tag badges and the footer "Popular searches" all go to `/xx/puzzle?brand=…` / `?pieces=…` / `?tag=…` filter URLs. Those render an empty, lazy, robots-blocked component for crawlers and are canonicalised away (GSC: 145k "alternate page with proper canonical", mostly `?brand=`).
   - The related module shows the same top-6 puzzles on every page of a brand. **About 73% of puzzles are reachable only through the sitemap.**
6. **Crawl budget leaks.**
   - **10% of all Googlebot requests are 302s**, mainly `/puzzle/{id}/qr-code` in 6 locales (a crawlable `<a href>` in the puzzle dropdown that 302s back to the page).
   - **149k noindexed URLs** are mostly player-statistics `?month=&year=` variants, a crawl trap.
   - The most popular puzzle pages are 2.8–8.2 MB of HTML because the leaderboard is unbounded. Bavarian Romance has 1,284 links: 816 to player profiles and 17 to other puzzles.
7. **Authority is the binding constraint.**
   - GSC counts **454 external links from 92 domains**; 230 are from reddit and roughly 35 of the domains are Mastodon/fediverse instances. 348 of the 454 point at the homepage.
   - Nothing links from USAJPA, WJPF, speedpuzzling.com, the national associations, Ravensburger or the retailers.
   - For a 250k-URL site this link profile explains most of the indexation ceiling.
8. **Keyword gaps worth building for** (all backed by data only MSP has):
   - "how long does a {N}-piece puzzle take" (incl. pairs/teams)
   - "list of all {brand} puzzles" / "{brand} {N} piece puzzles"
   - competition puzzles ("WJPC 2025 puzzles")
   - difficulty lists (hardest/easiest)
   - records (fastest times)
   - a public puzzle timer
   - positioning as *the* jigsaw puzzle database. IPDb currently owns that phrase; Google's AI Overview calls it "the most comprehensive, 40k puzzles", and MSP has 41k puzzles, 2.2k brands and 523k solve times.

---

## 2. Where the traffic comes from (GSC, 3 months)

| Segment | Clicks | Impressions | CTR | Avg pos | Notes |
|---|---|---|---|---|---|
| Whole site | 30.0k | 463k | 6.5% | 7.4 | mobile CTR 8.6% vs desktop 3.5% |
| Brand queries (myspeedpuzzling, my speed puzzle…) | ~10.3k | ~12k | 85% | 1.4 | about 1/3 of all clicks |
| Puzzle URLs `/puzzle/` (detail + brand hubs, all locales, page-aggregated) | 6.68k | 185k | 3.6% | 7.2 | 305 → 800 clicks/week, still climbing |
| – EN detail pages (UUIDv7 ids only) | ≥4.37k | ≥121k | 3.6% | 7.1 | |
| – DE / FR / ES detail | 694 / 444 / 267 | 17k / 9.4k / 8.5k | 3.2–4.7% | ~7 | locale copies do earn clicks where indexed |
| – brand hubs (EN) | 435 | 15.4k | 2.8% | 8.0 | winners are niche brands (Playful Mario 98 clicks at #4.8); Ravensburger hub at #10.4 |
| – pieces hubs `/en/puzzle/{N}-pieces` | ~6 | ~70 | – | ~5–10 | e.g. 500-pieces had 9 impressions in 3 months |
| WJPC hub `/en/world-jigsaw-puzzle-championship` | 430 | 37k | 1.2% | 6.5 | navigational to worldjigsawpuzzle.org |
| Event pages (72 in top 1000) | 1.8k | 54k | 3.3% | ~8 | titles too long, no "Results" |
| Guides (4) | 62 | 3.45k | 1.8% | 6.6 | "how long does a 1000 piece puzzle take": #8.3, 156 impressions, 0 clicks |
| Google Images | 487 | 57k | 0.9% | 27.6 | 400-px `puzzle_medium` images |
| Discover | 6 | 200 | – | – | |

Weekly clicks/impressions on `/puzzle/` URLs:

| Week of | 06-28 | 07-12 | 08-02 | 08-16 | 08-30 | 09-13 | 09-20 |
|---|---|---|---|---|---|---|---|
| Clicks | 305 | 428 | 392 | 547 | 676 | 708 | 800 |
| Impressions | 7.8k | 10.5k | 11.6k | 14.4k | 17.4k | 20.1k | 23.9k |

**Top-1000 queries by intent** (these cover 15.7k of the 30k clicks; the rest is anonymised long tail, mostly puzzle names):

| Intent | Clicks | Impressions | CTR | Avg pos |
|---|---|---|---|---|
| Brand / navigational | 10.3k | 12k | 85% | 1.4 |
| "speed puzzling" family | 3.2k | 23k | 14% | 5.5 |
| Competitions / WJPC / events | 0.8k | **45k** | 1.8% | 6.8 |
| Specific puzzle / brand / artist | 0.6k | 29k (+ most of the anonymised 322k) | 2.1% | 8.0 |
| Generic competition ("puzzle competition near me") | 0.4k | 13k | 2.9% | 8.5 |
| Tool / app / database / stats | 0.3k | 9k | 2.9% | 8.3 |

---

## 3. Keyword map: gaps and targets

Demand = our own GSC impressions (3 mo) where we are on page 1, which is a lower bound. No volume tool was used.

| Cluster | Demand evidence | We are | Who wins | Verdict / action |
|---|---|---|---|---|
| speed puzzling / speedpuzzling / speed puzzle | 5.8k + 1.5k + 1.6k + 1.2k | #2–3 | speedpuzzling.com (#1 with sitelinks); AI Overview already cites MSP | Keep. Only links move it (§7). Upside at #1 ≈ +300 clicks/mo |
| speed puzzling leaderboard / rankings / times | – | #1 (6 of 10 results) | – | Owned |
| puzzle tracker / puzzle tracker app / jigsaw puzzle tracker | 732 / 334 / 12 | #4–5 | "Puzzle Tracker" listings on Play + App Store, Reddit | Store listings take #1–2. Only an app-store presence wins it (§8 P3). Link the tracker page (it is a dead end) |
| (jigsaw) puzzle database | 129 / 85; DE "puzzle datenbank" #1 | #3–7 | IPDb (ipdb.plus + its app listings) | State the size everywhere (41k puzzles, 2.2k brands, 24k EANs, 523k solve times); barcode lookup page |
| puzzle timer / jigsaw puzzle timer | 246 / 54 | #3–8 (via tracker page) | Play Store "Jigsaw Puzzle Timer", Reddit, Puzzle Pace | Public timer tool page (no login) |
| **{brand} {puzzle name}** | hundreds of queries; "ravensburger" alone: 650 queries, 5.8k impressions, 380 of them at pos ≤ 10, but 1.4% CTR | often #1–6 | Shopping ads + AI Overview above organic; Amazon, eBay, official shops | Content + snippet quality (§4); stars (ratings); EAN in snippet |
| {brand} {N} piece puzzles / puzzle ravensburger 1000 | "puzzle ravensburger 500 pieces" 265 at **#39, ranking page = `/en/hub`** | #39–60 | Ravensburger, Amazon, Target, seriouspuzzles | Brand×pieces catalogue pages (not winnable head-on, but they give the long tail a crawl path) |
| **list of all {brand} puzzles / {brand} puzzle list** | Facebook post "is there a website that lists all Ravensburger puzzles" ranks #2 | absent | seriouspuzzles "List of all …" (fewer puzzles than ours), puzzlesbyliza, jigsaw-wiki | **Full crawlable brand catalogues** |
| EAN / barcode lookups (e.g. `4005555043997`) | several with clicks | sometimes #2, often absent | shops, IPDb ("Barcode …" in title), speedpuzzle.eu (EAN in URL) | EAN in meta description / visible spec block |
| **how long does a {N}-piece puzzle take** (+ "with 2 people", "with 4 people", 500/300/2000) | 1000-piece variants ≈ 330 impressions at #8 | #8 for 1000; absent for the rest | Reddit, manufacturer blogs guessing "4–20 h" | **Cluster with our real medians** (500 = 1h05 from 345k solo solves, 1000 ≈ 3h15 from 24k); only we have pair/team data |
| hardest / easiest puzzles, difficulty rating | "ravensburger puzzle difficulty ratings", "puzzle difficulty rating" | absent | Medium, retailer blogs, Reddit | Public top-N difficulty lists (members-only decision) |
| competition puzzles ("WJPC 2025 puzzles", "ravensburger world championship puzzles", "usa jigsaw nationals puzzles 2026") | 91 at #9.9; 28 at #3.4 … | weak | Substack, Medium, thepuzzlefit (FR), WJPF | **Competition-puzzles hub** from the existing competition tags |
| fastest 500/1000 puzzle, speed puzzling records | 141 + 63 + 36 + 53 | #7–11 | Guinness, Wikipedia, blogs | Records page per piece count (solo/pair/team) |
| WJPC {year}, WJPC {year} results | 5.7k + 6.6k + 4.1k ("wjpc") | #4–9 | worldjigsawpuzzle.org, Wikipedia | Better titles/snippets; "results & winners" summary per edition |
| {country} championship {year}, puzzle weltmeisterschaft, campeonato mundial de puzzles | hundreds of queries | #5–10 | official organisers, news | Titles with year + "Results"; localised event names (event name is English in every locale) |
| speed puzzling near me / puzzle competition near me | 283 + 167 + 277 | #5–10 | speedpuzzling.com in-person, usajigsaw, meetup | Events by country/region pages |
| player names (andrea peng, conner delaat, puzzledkiwi…) | ~10 queries, some clicks | #2–12 | WJPF `/player/{slug}` | Profile titles for top players; link champions from event results |
| puzzle swap / used puzzles (DE "gebrauchte puzzle kaufen") | – | absent | swap sites, kleinanzeigen, eBay | **Later**: only 1,609 active listings, not enough inventory |

---

## 4. The long tail: why 40.6k puzzle pages underperform

### 4.1 Indexation
- **Submitted (sitemap) URLs:** 135k indexed; 115,493 "discovered – currently not indexed" (never crawled); 50,603 "crawled – currently not indexed". Technical errors among submitted URLs are ~0 (24 soft 404s, 20 noindex).
- **URL Inspection sample:**
  - EN puzzle pages: 9 of 10 indexed (puzzles with 30–94 solves 3/3, with 3–5 solves 3/3, with 1 solve 3/4).
  - Locale copies: 2 of 4 indexed (FR with 5 solves and DE with 68 solves were "crawled – not indexed").
  - The "discovered/crawled – not indexed" samples are spread evenly over fr / ja / de / es / cs / en puzzle pages plus player profiles.
- **Reading:** Google indexes the EN page and treats many locale copies as not worth indexing. The name, times and player list are identical; only the UI chrome is translated. So 6 × 40.6k = 243.7k puzzle URLs compete for a crawl budget of about 9.2k requests/day.
- Sitemap `lastmod` is `puzzle.added_at` (`src/Query/GetPuzzleIdsForSitemap.php:43,90`). It never changes when new solves arrive, so there is no recrawl signal. The image sitemap lists only the Czech URL and the 400-px thumbnail.

### 4.2 Content and snippets
- **Fake insight block.** See summary point 4. Fix: render the locked teaser as text-free skeleton bars, or show the real public median plus a lock. Never put numbers in the HTML that are not true for that puzzle. `data-nosnippet` alone is not enough, because the text is still indexed.
- **UI chrome is used as the snippet.** "Suggest a change / Missing/incorrect data or duplicate?" appears in about 356 indexed snippets. Put `data-nosnippet` on the action dropdown and similar UI.
- **No descriptive text at all.** A 0–2-solve page has about 160 words in `<main>`, and 55% of approved puzzles have 1–2 solves (0: 3,647 · 1–2: 22,473 · 3–9: 9,296 · 10–49: 3,747 · 50+: 1,460). A short **data-driven summary paragraph** would give every page unique, true, quotable text (for snippets and AI Overviews):

  > Ravensburger Bavarian Romance is a 500-piece jigsaw puzzle (EAN 4005555013815, art. 12001381) used at WJPC 2024. 816 puzzlers logged solo times: median 1h 01m, fastest 27:46 — solved faster than 68% of 500-piece puzzles.

  Also show `alternative_name` (1,229 puzzles, currently never shown).
- **Titles.** `{Brand} {Name} – {N} pieces` never contains "puzzle" or "jigsaw", although many queries do ("ravensburger fourth wing puzzle", "…national parks puzzle"). 43% of titles are over 60 chars. Proposal: `{Brand} {Name} – {N} Piece Puzzle`, dropping the site suffix when the title is too long (Google drops it anyway).
- **Meta description.** Include EAN, catalogue number and competition use. For 0 solves, write an honest "no times yet" variant.

### 4.3 Weight and link dilution
- The leaderboard renders every time with no cap (`templates/components/PuzzleTimes.html.twig:328-489`).
  - Bavarian Romance: 2.8 MB of HTML and 19k DOM nodes. It has 1,284 links: 816 to player profiles, 17 to other puzzles, 2 to the brand hub.
  - London Postcard: 8.2 MB. 71 puzzles have more than 1,000 solves.
- **Recommendation:** cap the server-rendered leaderboard at the top 50–100, and move the full list to a paginated or "show all" view.

### 4.4 Internal linking: the long tail has no path
- **Brand hub** (`src/Controller/BrandPuzzlesController.php:27`, `GRID_LIMIT = 24`): links 24 puzzles, no pagination. 188 indexable brands have more than 24 puzzles, so about 29.8k puzzles are unreachable from their hub. Example: "Ravensburger The Wave" is #437 by solves in its brand.
- **"View all", tag badges and footer "Popular searches"** (`templates/base.html.twig:1005-1108`) all point to `?brand=` / `?pieces=` / `?tag=` URLs. For crawlers these are empty lazy components under robots-blocked `/_components`, with a canonical back to `/en/puzzle`. The link equity lands on a canonicalised shell.
- **Related module** (`src/Query/GetRelatedPuzzles.php:38-43`): the same 6 most-solved puzzles of the brand on every page. None by piece count, none "solvers also solved", none for the same competition.
- **List cards** do not link the brand. The piece count is not linked to its hub. There is no brand directory page. Breadcrumbs are 2 levels and not visible (#141).

### 4.5 SERP and CTR
- **Puzzle-name SERPs are commercial.** "ravensburger bavarian romance" shows a Sponsored products carousel, then an AI Overview with product specs and a Ravensburger panel, and only then organic (MSP #1: "…fastest time 27min 46s, average 1h 6min from 2053 solves").
- **Retail category searches** ("ravensburger 500 piece puzzles") are pure shopping and are not a realistic target.
- **Rich results are essentially absent.**
  - Product markup is emitted only when marketplace offers exist: 742 puzzles, 1.8%. GSC counts **56 valid Product-snippet items**.
  - The marked-up prices are not visible on the puzzle page, which conflicts with Google's policy.
  - Missing aggregateRating everywhere. Community star ratings (enjoyment/quality) would unlock review stars and add unique UGC. This is a product decision.
  - Merchant-listing warnings: no shipping details or return policy, 9 invalid `sku`s.

---

## 5. Technical and HTML findings

| # | Finding | Evidence | Fix |
|---|---|---|---|
| T1 | Fake insight numbers in anonymous HTML, used as snippets | `_difficulty_section.html.twig:176-258`; ~1,300 results in `site:` | Text-free skeleton or real public data |
| T2 | 10% of Googlebot requests are 302s: QR modal links + edit-collection-comment → `/login` | Crawl stats: 832k requests / 90 d. The 302 sample: ~73% `/{loc}/puzzle/{id}/qr-code` (`PuzzleQrCodeModalController`, linked from `_dropdown_actions.html.twig:64`), ~11% edit-comment | robots.txt `Disallow` for the qr-code / qr-kod / codigo-qr / code-qr paths and the edit-comment paths; `rel=nofollow` on those links |
| T3 | Crawl trap: player-statistics `?month&year` | ~70% of the 149k "excluded by noindex" sample | Disallow the parametrised statistics URLs (noindex does not stop crawling) |
| T4 | Filter URLs soak up internal links | 145k "alternate with proper canonical", ~85% `?brand=` | Link hub URLs instead (§4.4) |
| T5 | robots.txt blocks `/{loc}/_components` | Google cannot render: the homepage "Live from the community" block, hub widgets, ladder pair/team tables, and every filtered list. `Disallow: /_components` matches nothing and `/cs/_components` is open | Server-render important blocks or allow the GET component routes; fix the cs rule |
| T6 | Unbounded leaderboard | §4.3 | Cap it |
| T7 | Sitemap lastmod = creation date; image sitemap on CS URLs with thumbnails; og:image 400 px | `GetPuzzleIdsForSitemap.php:43,90`; `SitemapImagesController.php:49-57` | lastmod = latest solve or change; EN URLs + large image (≥1200 px) |
| T8 | Event titles: 111 of 113 over 60 chars, 96 over 70 (WJPC 2026: 84); past events never say "Results"; event image is the small thumbnail; `eventStatus` always Scheduled | `event_detail.html.twig:3-9,46,69-71` | `{Event} {Year} Results` after the event, ≤60 chars |
| T9 | Ladder H1 contains the whole dropdown ("Speed Puzzling Leaderboard Overview Overview Solo - 500 pieces…") | `ladder.html.twig:7` | Move the dropdown out of `<h1>` |
| T10 | `/en/hub` is indexable, the first nav link and in the sitemap; its H1 is "Welcome puzzler!"; it ranks for random puzzle queries (e.g. "puzzle ravensburger 500 pieces" #39) | GSC | Decide: noindex, or give it a real purpose ("Latest speed puzzling results") |
| T11 | Tracker app page is a dead end (only `my_profile` and `#features` links) | `puzzle-tracker-app.html.twig` | Link to database, timer, guides, ladder |
| T12 | Pages without meta description (puzzle-library, player-favorites, activity-calendar, blog, marketplace how-it-works); unapproved series/editions indexable; empty country pages 200 + indexable; guide `dateModified` frozen 2026-07-11 | code audit | Small fixes |
| T13 | Core Web Vitals | 3,246 URLs "good" on mobile and desktop, 0 poor | No action (Cloudflare is not needed for SEO) |

---

## 6. Competitors (short)

- **speedpuzzling.com** (Weebly): #1 for "speed puzzling". Results are published only as PDFs and Google Sheets, so they are not crawlable, and there are no player pages. MSP's crawlable results are the structural advantage.
- **IPDb (ipdb.plus)**:
  - About 45k puzzle URLs. ID-only URLs, but keyword-list titles: artist, year, series, cut type, barcode.
  - Owns "puzzle database" through its own site plus App Store, Play and Microsoft Store listings.
  - No solve data and no schema. MSP has more puzzles and far richer data, but IPDb is what Google's AI Overview names.
- **seriouspuzzles.com / puzzlewarehouse.com**:
  - "List of all {Brand} puzzles" pages rank #1 for brand lists.
  - Keyword slugs, full Product schema, by-artist/by-theme pages, discontinued pages kept live.
  - Blog content ranks for "how long" and puzzle sizes.
- **Puzzle Tracker (proactivebit)**: app-store listings own "puzzle tracker app".
- **Hey Puzzlers (heypuzzlers.com)**: new app copying MSP's model (70k-title catalogue, solo/pairs/teams leaderboards, public profiles; v3.0 shipped Sep 2026). Barely indexed yet; watch it.
- **puzzle-1000-pieces network** (EN/DE/FR/NL/CZ/ES domains): wins the non-English "how long / sizes / records" informational queries with thin blog content. MSP is absent in those languages.
- **WJPF (worldjigsawpuzzle.org)**: official results; `/player/{slug}` pages rank for champions' names.

---

## 7. Authority (links)

GSC Links report: **454 external links, 92 linking domains**.
- **Top linkers:** reddit.com (230 links from 8 pages), blitzpuzzle.com, czjpa.cz, puzzlerush.se, orqivon.com, speedpuzzle.eu, piecehouse.co.nz; then roughly 35 Mastodon/fediverse instances; also faz.net, vg.no, familiejournal.dk, yanoman.co.jp, completingthepuzzle.com, ncjigsaw.org, wijigsaw.org, thepuzzleguzzle.co.uk, sciencedirect.com.
- **Link targets:** 348 point at `/`, 42 at `/en/puzzle`.

What is missing, and how to get it (the same list as `TODO.md` item 3, now concrete):
1. **Results partners.** Every organiser whose results live on MSP gets a "Results on MySpeedPuzzling" link or badge and an embeddable results widget that carries a link. WJPF already pairs accounts with MSP, so ask for a "times on MySpeedPuzzling" link on player pages. Also usajigsaw.org results, `/puzzle-swaps` and `/other-events`.
2. **Unlinked mentions:**
   - ulmer-puzzleschmiede.de: the post "Puzzle-Events 2026 …" names MSP as a source without a link.
   - puzzletalk.substack.com ("MySpeedPuzzling is definitely trending").
   - speedpuzzle.eu/Resources links the old `http://` and `speedpuzzling.cz` URLs.
3. **Associations and clubs:** puzzleverein.de, aepuzz.es, svenskapusselforbundet.se, puzzledernegi.com (EJPC 2026 host), viennapuzzleclub.at, jucari.it, mopepuzzle.hu, ecjp.eu, floridajpa.org, speedpuzzling.nl.
4. **Manufacturers and retailers:** brand hubs are a "see how fast people solve your puzzles" asset (Yanoman already links). An embeddable "average solve time" widget for retailer product pages would earn links at scale.
5. **Data PR:**
   - The annual report (#144) on 523k solves.
   - Outlets that already covered the sport: OPB (Apr 2026), Axios SF (Feb 2026), Irish Examiner, FAZ, VG, EFE/Infobae.
   - Czech angle: the platform is Czech-built, which gives Czech media a local hook around each WJPC.
6. **Podcasts:** Piece Talks, The Puzzle People Podcast, Niche to Meet You, The Puzzle Podcast.
7. **Wikipedia:** there is no "Speed puzzling" article in en/de/es/cs/nl/ja, and the WJPC articles cite only WJPF. This is a conflict of interest: independent editors may cite MSP, but MSP must not add itself.

---

## 8. Action plan

### P0: this week (bugs, low effort, high confidence)
1. **Remove the fake insight numbers** from anonymous HTML. Add `data-nosnippet` to the UI chrome: the dropdown, "suggest a change", filter labels.
2. **Data-driven summary paragraph** on every puzzle page (§4.2). Rework the meta description around the same facts, EAN included, and give 0-solve pages an honest variant.
3. **Stop crawl waste:** robots.txt disallows for QR-modal and edit-comment paths in all locales and for parametrised player statistics; fix the `/cs/_components` rule.
4. **Replace filter-URL links with hub links:** footer "Popular searches", hub "View all", tag badges.
5. **Event titles:** ≤60 chars, "{Event} {Year} Results" once the event is over.
6. **Ladder H1**, **tracker page links**, and a decision on **`/en/hub`**.

### P1: catalogue architecture (1–3 weeks)
7. **Full crawlable brand catalogues:**
   - `/en/puzzle/brand/{slug}?page=N` with real `<a href>` pagination and a self-canonical on each page.
   - A–Z brand directory.
   - **Brand×pieces pages** where a brand has at least N puzzles of that size, e.g. `ravensburger/500-pieces`.
   - Visible 3-level breadcrumb plus BreadcrumbList (#141).
   - Brand-hub title with "All … Puzzles".
8. **Puzzle page:**
   - Cap the leaderboard.
   - Related modules: same brand + same size; "solvers also solved"; same competition; other piece-count versions.
   - Link the piece count to its hub.
   - Title adds "Puzzle".
9. **Sitemaps:** lastmod from the latest solve; image sitemap on EN URLs with the large image; og:image ≥1200 px.
10. **Locale experiment for puzzle pages:**
    - Localise the summary paragraph.
    - List non-EN puzzle URLs in the sitemap only when the puzzle has ≥5 solves or activity in that market; keep pages and hreflang as they are.
    - Measure index coverage for 6–8 weeks. Do not noindex locale copies: noindex inside hreflang clusters causes errors.

### P2: content only MSP can build (1–2 months)
11. **"How long does a {N}-piece puzzle take"** cluster:
    - Pages for 100 / 300 / 500 / 1000 / 1500 / 2000 pieces, plus pairs/teams ("with 2 people"), plus a pillar "average solve time by piece count" with an estimator.
    - Put the data in the title ("real data from 345k solves").
    - EN first; then DE/FR/ES/CS, whose SERPs are weak shop blogs. This revisits the EN-only guides decision.
12. **Competition-puzzles hub:** every WJPC / national-championship puzzle with winning and median times, and a list per edition ("WJPC 2026 puzzles"). The data already exists in the competition tags.
13. **Records page:** fastest solo/pair/team time per piece count.
14. **Public puzzle timer** (no login) with a "save your time" CTA.
15. **Difficulty lists:** hardest/easiest by piece count and for the big brands. Top-N public, full list for members. **Needs Jan's call**, since insights are members-only.
16. **Database positioning:** the numbers (41k puzzles · 2.2k brands · 24k EANs · 523k times) on `/en/puzzle`, the homepage and the tracker page, plus an "find a puzzle by barcode/EAN" landing page.
17. **Puzzle ratings** (stars) to unlock aggregateRating. Product decision.

### P3: authority and reach (ongoing, largest long-term lever)
18. The outreach programme in §7: results partners, associations, manufacturers, podcasts, data PR, the retailer widget.
19. **Optional, large:** publish the app in the stores ("MySpeedPuzzling – Puzzle Tracker & Timer"). This is the only route to #1–2 for "puzzle tracker app" and "puzzle timer", whose SERPs are store listings.

### Deprioritised
- **Puzzle slug URLs (#138).** IPDb and MSP both rank with ID URLs, and Google shows breadcrumbs, not paths. The benefit is marginal against the risk of re-indexing 244k URLs. Bundle it into a quiet month later, if at all.
- **Pieces hubs as commercial pages.** "500 piece puzzles" is a retail SERP. Repurpose them as catalogue and "how long" pages instead.
- **Marketplace SEO.** Not enough inventory yet (1,609 active listings).
- **Cloudflare for SEO.** Core Web Vitals are already all green.

---

## 9. Measuring

Baselines as of 2026-09-27. Re-check every 2 weeks.

| Metric | Baseline |
|---|---|
| GSC clicks/impressions on `/puzzle/` URLs (weekly) | 800 / 23.9k per week |
| Submitted URLs indexed | 135k indexed, 115k discovered, 51k crawled |
| Crawl stats: share of 302 responses | 10% |
| `site:myspeedpuzzling.com "12% harder than average"` | ~1,300 (target 0) |
| Product-snippet valid items | 56 |
| "speed puzzling" US position | #2.3 |
| Tracked query set | "how long does a 1000 piece puzzle take" #8.3; "jigsaw puzzle database" #3.0; "puzzle tracker app" #4.3; "ravensburger world championship puzzles" #9.9; "world jigsaw puzzle championship 2026" #8.5 |
| External linking domains (GSC Links) | 92 |

GSC filter tricks used:
- In performance URLs, `page=*%2Fpuzzle%2F` means "page contains /puzzle/".
- `query=*ravensburger` means "query contains".
- `query=!term` means exact match.
- The page-indexing report can be switched to "all submitted pages".
- Crawl stats → click "302" to see a URL sample.
