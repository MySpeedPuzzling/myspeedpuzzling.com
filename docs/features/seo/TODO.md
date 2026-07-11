# SEO — Open Decisions & Jan's Action Items

Maintained by Claude during the SEO overhaul (2026-07-11). Everything here either needs Jan's decision or can only be done by a human. Code-side work is tracked in README.md phases.

## Needs Jan's action (no code)

1. **GSC after deploy** (~10 min): Search Console → Sitemaps → resubmit `https://myspeedpuzzling.com/sitemap.xml`. Expect index-coverage churn 2–4 weeks while per-locale URLs are picked up. Watch: Coverage report, hreflang errors (should trend to zero), and the "speed puzzling" query position weekly.
2. ~~Create WJPC series in admin~~ **SUPERSEDED (2026-07-11)**: a dedicated evergreen WJPC hub page now exists at `/en/world-jigsaw-puzzle-championship` (static content page pulling editions from the DB automatically — new yearly events appear on it as soon as they're added). Remaining editorial action: keep adding each year's WJPC event via the normal events flow. The same hub pattern can be replicated for USA/UK Nationals when wanted.
3. **Link outreach — the decisive factor for beating speedpuzzling.com** (I draft, you send):
   - Event organizers already using the platform (UK, Canada, Austria championships…): ask each to link "Results on MySpeedPuzzling" from their site.
   - Speed Puzzling News (runs a Media Recap that exists to feature community resources — lowest-hanging fruit), Jigsaw Junkies / Puzzle Warehouse blog, USAJPA "In the News"/resources page, speedpuzzle.eu, My Jigsaw Journal, puzzle podcasts.
   - September (WJPC week) = the press window: pitch the "Year in Speed Puzzling" data report to NPR/WaPo-tier journalists who already cover the sport.
4. **Cloudflare orange-cloud**: deferred by your call — benefits + rollout checklist in `cloudflare.md`. Revisit when bot load or non-EU speed matters.
5. **Review the three guide drafts** (live at `/en/guides` after deploy): the copy is data-driven and factual, but it's your brand voice — edit freely; the numbers are computed from the DB and update themselves.

## Needs Jan's decision (code ready to go either way)

6. **Puzzle URL slugs**: puzzle detail URLs are UUID-only (`/en/puzzle/{uuid}`) — zero keyword signal. A slug migration (`/en/puzzle/ravensburger-doodlecats-1000-{shortid}` with 301s from old URLs) is the single biggest remaining on-page lever, but it re-indexes 34k×6 URLs — some ranking turbulence for weeks is likely. Decide: do it in a quiet month, or accept UUID URLs.
7. **Dedicated "speed puzzling" marketing landing page**: current approach = restructured homepage + `/en/guides/what-is-speed-puzzling` pillar. An additional standalone landing (`/en/speed-puzzling`) risks cannibalizing the homepage for the head term. Recommendation: don't — strengthen homepage + guide instead. Overrule if you want a campaign-style page.
8. **"Hidden Puzzler" vs "Secret Puzzler"**: private profiles now mask names using the existing `secret_puzzler_name` key ("Hidden Puzzler"). Changing the wording changes it everywhere the key is used.
9. **Guide localization**: guides are EN-only by design. Localize only after they prove traffic (scaled thin translations risk the scaled-content policy).
10. **Annual "Year in Speed Puzzling" report**: I can build the stats generator + page anytime; needs your call on timing (September, pre-WJPC, is optimal) and what you're comfortable publishing.

## Known bugs (separate from SEO, found during the work)

11. **Chained puzzle-merge approvals** throw `ORMInvalidArgumentException` (statistics recalculation touches the deleted puzzle mid-flush) — pre-existing, reproduced on unmodified main. Needs its own fix.

## Post-deploy verification findings (2026-07-11, 22/23 checks passed)

12. **Anonymous responses still carry `Set-Cookie: PHPSESSID` + `Cache-Control: private`** — the flashes guard shipped, but something else starts the session on every page; prime suspect is the `<twig:GlobalSearch />` Live Component embedding a CSRF token in base.html.twig. Zero ranking impact today; it only blocks future edge HTML caching, so fix it together with the Cloudflare work (`cloudflare.md`). Don't rush it — touching Live Component CSRF affects the search UX.
13. **Puzzle-detail breadcrumb is 2 levels** (Database → Puzzle). Now that brand hubs exist, add the middle crumb (Database → {Brand} → {Puzzle}) with the brand-hub URL — small template change in `puzzle_detail.html.twig`.

## How Google ranks — and where we now stand (context for priorities)

Google's documented systems reward, in rough order of leverage for us: (1) **links/authority** (PageRank — still the gap vs speedpuzzling.com's exact-match domain; items 2–3 above), (2) **relevance + content quality** (helpful-content signals — homepage restructure, guides with unique measured data, hub pages), (3) **crawlability/indexability** (fixed: sitemaps, hreflang, canonicals, internal links, soft-404s), (4) **page experience** (CWV — improved; Cloudflare would finish it), (5) **engagement/brand signals** (your brand queries already dwarf competitors'; WebSite schema + crossroads root now feed the site-name system). On-page is now near-ceiling; the remaining distance to #1 is authority + content velocity, which are items 2, 3 and 10.
