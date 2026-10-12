# Navigation redesign — baseline (P0-02)

Today's chrome, measured before any chrome change: statement budgets (pinned by three tests), HTML sizes, header heights, Lighthouse, CLS, screenshots and the frozen list of routes the chrome links to. Later items compare against these numbers; the [requirements](../requirements.md) Conventions say budgets only go **down**.

- **Baseline commit:** `a76cd36b` (`origin/main` `9347ccda` + the spec commit), measured 2026-10-12.
- **Committed:** `tests/Navigation/{ChromeQueryBudgetTest,HubQueryBudgetTest,PlayerProfileQueryBudgetTest,NavigationViewer}.php`, this file, [`baseline-chrome-routes.json`](baseline-chrome-routes.json).
- **Not committed** (git-ignored, `var/navigation-delivery/`): raw measurements `baseline/*.json`, Lighthouse reports `baseline/lighthouse/`, screenshots `baseline/screens/`, clone account ids and password `baseline/accounts.local.json`, the probes and `scripts/navlib.py`.

## Environment

| what | value |
|---|---|
| statement counts, chrome weight, route list | PHPUnit 13.2.6, PHP 8.5.11, `APP_ENV=test`, fixture DB `speedpuzzling_navsuite_test` (`var/navigation-delivery/tools/phpunit.sh`) |
| HTML sizes, browser measurements | `nav-web` (`php -S`, `APP_ENV=dev`) on the production clone `speedpuzzling_nav_test`, **dev** Encore build |
| Selenium | Grid 4.49.0, `selenium/standalone-chromium`, Chromium 152.0 |
| Lighthouse | 13.4.0 (`npx`), host Google Chrome 154.0.8037.98 headless=new, **production** Encore build on `nav-web-lh` (`frankenphp php-server`, classic mode, compression on, `APP_ENV=dev`, clone DB) |
| clone accounts | a public member (follows players, has results, account > 60 days, no roles) and a public non-member given `moderator_since` **on the clone only**; ids and password only in `accounts.local.json` |

## 1. Statement budgets (pinned exactly)

Counted on the **second** request (the first warms the caches and claims an announcement modal; no second request claimed one). Each signed-in row first asserts its role in the database (`NavigationViewer`). Roles: guest = no cookie; player = `PLAYER_PRIVATE` (non-member, no roles); player with favorites = `PLAYER_WITH_FAVORITES`; member = `PLAYER_WITH_STRIPE`; organiser = `PLAYER_REGULAR` (maintains competitions); moderator = `PLAYER_WITH_FAVORITES` + `GrantModeratorRole`; admin = `PLAYER_ADMIN`.

**`member` is member + event/organization owner:** `PLAYER_WITH_STRIPE` created organizations and events, and `added_by_player_id` gives owner rights (`GetCompetitionPermissions`, "You organize"). The P0-02 pins stay on it unchanged. For per-role checks (MENU-01/02, the chrome budget per role), use **member only** = `PLAYER_PRIVATE` + `GrantMembership` in the test (rolled back by DAMA, like the moderator grant; its role facts assert no events, organizations, maintainer rows or favorites). Its rows were added after the baseline and are measured on the same fixtures.

Chrome cost of a signed-in page: user refresh 1 + profile row 1 + toolbar badges 2 (unread conversations, unread notifications) = **4**; moderators and admins + the admin key's queue counts **1**. The guest chrome costs **0**. Every guest row also asserts `Cache-Control: public`.

**`ChromeQueryBudgetTest`** — `/en/faq` (its own statement: the 1000-piece distribution)

| viewer | statements |
|---|---|
| guest | 1 |
| player | 5 |
| member | 5 |
| member only | 5 |
| organiser | 5 |
| moderator | 6 |
| admin | 6 |

The spec names guest, member, moderator and organiser; player and admin were added (the player row isolates role effects, the admin row carries the full queue counts).

**`HubQueryBudgetTest`** — `/en/hub` (the eager `RecentActivity` feed counted; the lazy components are requests of their own)

| viewer | statements | page part |
|---|---|---|
| guest | 1 | the feed |
| player | 8 | feed + "Review your results" counts + getting-started progress + the viewer's best times |
| player with favorites | 8 | same (the Favorites tab is lazy) |
| member | 9 | + difficulty tiers of the feed |
| member only | 9 | same as member |
| organiser | 8 | |
| moderator | 9 | + queue counts |
| admin | 10 | + tiers + queue counts |

**`PlayerProfileQueryBudgetTest`** — `/en/player-profile/{id}` ("other" = `PLAYER_REGULAR`, a public non-member with results)

| view | statements |
|---|---|
| guest → member | 11 |
| guest → other | 14 |
| player → own | 17 |
| player → other | 19 |
| member → own | 23 |
| member → other | 24 |
| admin → member | 22 (= `ProfileProgressionTest::testTheProfilePageCost`, pinned in both) |

The spec's "own/other × guest/member": a guest has no own profile, so the guest rows are two "other" profiles.

## 2. HTML sizes (bytes, uncompressed / `gzip -9`)

Guest, toolbar stripped (the block between `<!-- START of Symfony Web Debug Toolbar -->` and `<!-- END … -->`), second request, on the clone (`scripts/html_sizes.py`):

| page | bytes | gzip -9 |
|---|---|---|
| biggest public profile (most results) | 375,364 (366.6 KB) | 40,584 |
| a ~100-result profile | 301,870 (294.8 KB) | 30,103 |
| `/en/faq` | 87,219 (85.2 KB) | 16,933 |

Profile selection (PROF-03 and PROF-05b reuse it for their "< 400 KB" checks):

```sql
WITH r AS (SELECT player_id pid FROM puzzle_solving_time WHERE puzzling_team_id IS NULL
           UNION ALL SELECT m.player_id FROM puzzle_solving_time t
           JOIN puzzling_team_member m ON m.team_id = t.puzzling_team_id WHERE m.player_id IS NOT NULL),
     c AS (SELECT pid, count(*) n FROM r JOIN player p ON p.id = r.pid WHERE p.is_private = false GROUP BY pid)
SELECT pid FROM c ORDER BY n DESC LIMIT 1;   -- biggest;  "~100": ORDER BY abs(n-100), pid LIMIT 1
```

**Chrome weight on `/en/faq`** (the MENU-01 baseline) — fixture DB, test env (no toolbar), second request, `scripts/BaselineChromeRoutesProbe.php`. `header` = `<header class="sticky-top">…</header>`, `footer` = `<footer class="site-footer">…</footer>`, outside `<main>` = everything but `<main id="main-content">…</main>` (includes `<head>` with the critical CSS):

| viewer | total | header | footer | outside main |
|---|---|---|---|---|
| guest | 87,119 | 11,967 | 13,507 | 65,623 |
| player | 87,358 | 18,470 | 6,462 | 65,750 |
| member | 87,854 | 18,460 | 6,462 | 66,369 |
| organiser | 87,891 | 18,460 | 6,462 | 66,283 |
| moderator | 89,720 | 20,832 | 6,462 | 68,112 |
| admin | 92,740 | 24,164 | 6,462 | 71,255 |

The guest footer is bigger by the newsletter band and "Popular searches". Cross-check: guest `/en/faq` on the clone = 87,219 bytes (above).

## 3. Header heights (px)

`navlib.header_metrics()`: `header.getBoundingClientRect().height`, computed `--header-height`, `.topbar`, `.navbar`; `/en/faq` and its 5 translations, guest and member, after a warm-up (fonts loaded). 360 = Pixel emulation, 1280 = desktop with `innerWidth` asserted.

| width | header | `--header-height` | topbar | navbar |
|---|---|---|---|---|
| 360, all 6 locales, guest + member | 97.19 | 97px | 41 | 56.19 |
| 1280, all 6 locales, guest + member | 102.31 | 102px | 46.13 | 56.19 |
| 1280, **es, member** | 116.69 | 117px | 46.13 | 70.56 (the navbar wraps) |

(The critical CSS guesses `--header-height` 101px below 992 px and 97px from 992 px; the Stimulus controller then sets the measured value.)

## 4. Lighthouse mobile (median of 5)

`scripts/lighthouse_runs.py`: `npx -y lighthouse@13.4.0 <url> --only-categories=performance --form-factor=mobile` (412 px, simulated throttling), fresh profile per run. Every request carries `X-Requested-With: XMLHttpRequest`, which keeps the dev toolbar (and the announcement modal) out of the page — the document then equals the toolbar-stripped HTML to within 2 bytes (checked on the Hub and the puzzle page). Member runs carry the clone member's session cookie. Puzzle = the most-solved approved puzzle of the clone (id only in `accounts.local.json`).

| case | score | CLS | LCP ms | TBT ms | FCP ms | SI ms |
|---|---|---|---|---|---|---|
| Hub, guest | 78 | 0.0001 | 4,733 | 22 | 2,855 | 2,855 |
| Hub, member | 78 | 0.0003 | 4,734 | 18 | 2,855 | 2,855 |
| puzzle page, guest | 72 | 0.0008 | 6,307 | 41 | 2,855 | 2,999 |
| puzzle page, member | 72 | 0.0006 | 6,006 | 44 | 2,854 | 3,773 |

Server: `scripts/lighthouse-web.sh` starts `nav-web-lh` on `127.0.0.1:18089` with the production build mounted over `public/build` (nav-web and the worktree keep the dev build). It deviates from the draft's `php -S` + `PHP_CLI_SERVER_WORKERS=4`: `php -S` serves without compression, so the 0.7 MB stylesheet dominated every score (one trial: Hub guest 59, FCP 6.6 s, LCP 11.4 s); `frankenphp php-server` compresses like production. QA-03 must use the same script, build mode and machine; a ≥ 5-point miss is re-measured on the synced main before it is called a regression.

## 5. CLS at 360 (GEST-16 / NAV-07 reference)

`scripts/baseline_measure.py cls`: Selenium Chromium, Pixel emulation 360×800, dev build; a buffered `layout-shift` observer registered before navigation (CDP), read 5 s after `load`; CLS = the largest session window of the shifts without recent input; median of 3 after one discarded warm-up load.

| page | guest | member |
|---|---|---|
| Hub | 0.0000 | 0.0000 |
| puzzle page | 0.0000 | 0.0000 |

Every run also logged tiny shifts that Chromium flags `hadRecentInput` (icon-font swap in the top bar and toolbar, the puzzle page's toggle buttons): Hub 0.0002 / 0.0003, puzzle page 0.0016 / 0.0011 (guest / member). They are recorded separately in `cls.json` and excluded, as CLS excludes them.

## 6. Frozen chrome routes (NAV-12)

[`baseline-chrome-routes.json`](baseline-chrome-routes.json) (schema 1) — fixture DB, roles as in §1 (without "player with favorites"), pages `/en/faq`, `/en/hub`, `/en/puzzle/{PUZZLE_500_01}` and the viewer's own profile. Links are matched with the router: `#`, external, `mailto:` and `javascript:` skipped; a parameter equal to the viewer's id is recorded as `"own"` (`params`); query keys kept (`query`: `feedback?url`, `progression_hub?return`); a language-switch link (the page's route in another locale) is `languageSwitch`, not a route (top bar and footer, every role). `NavigationOrphanTest` (NAV-12) and LAUNCH-02 read only `routes`.

Routes per surface × role (— = not rendered, reason under `absent`):

| role | topbar | admin_key | account_dropdown | toolbar | hamburger | footer | live_event_strip |
|---|---|---|---|---|---|---|---|
| guest | 3 | — | — | 5 | 9 | 37 | — |
| player | 3 | — | 15 | 4 | 9 | 29 | — |
| member | 3 | — | 15 | 4 | 9 | 29 | — |
| organiser | 3 | — | 15 | 4 | 9 | 29 | — |
| moderator | 3 | 4 | 15 | 4 | 9 | 29 | — |
| admin | 3 | 14 | 15 | 4 | 9 | 29 | — |

Absent pairs: `admin_key` for guest/player/member/organiser (rendered only with `PUZZLE_MODERATION_ACCESS`), `account_dropdown` for guests (Register / Sign in toolbar links instead), `live_event_strip` for everybody (the WJPC 2026 strip expired 2026-09-21). Every rendered pair has ≥ 1 route (`scripts/checks/P0-02.py`).

Already visible for F-01: guests are linked to `hub` today (hamburger), while D5 sends the guest Home to the homepage — F-01 must give guest `hub` a Menu row, a search-only entry or a "not in Menu" reason, or NAV-12 fails. The guest toolbar also links `notifications` (the bell renders for guests).

## 7. Screenshots

`scripts/baseline_measure.py screens`, en, 360 (Pixel emulation) and 1280 (`innerWidth` asserted), dev build, viewport shots, the toolbar removed. Image URLs of the clone (`http://localhost:19100`) are mapped to the Docker host in Selenium (`--host-resolver-rules`), so images load. At 1280 the account dropdown opens on hover (theme `_navbar.scss`), so it is opened with a real pointer move. The admin view comes from a fixture HTML dump served from a temporary `public/_nav-delivery/` (deleted).

Under `var/navigation-delivery/baseline/screens/`:
- closed: `{faq,hub,puzzle}@{360,1280}-guest-closed.png`, `{faq,hub,puzzle,profile-own}@{360,1280}-member-closed.png`, `faq@1280-moderator-closed.png`
- footer: `faq@{360,1280}-{guest,member}-footer.png`
- open: `faq@360-{guest,member}-hamburger-open.png`, `faq@{360,1280}-member-account-open.png`, `faq@1280-{guest,member}-language-open.png`, `faq@1280-moderator-adminkey-open.png`, `faq@1280-admin-fixture-adminkey-open.png`

## Method notes and how to re-run

- Budget tests: `var/navigation-delivery/tools/phpunit.sh -- tests/Navigation/`. A failing pin prints the request's SQL. A number may rise only with evidence that a synced main commit caused it (re-measured on the merge commit with the delivery's chrome reverted, comment "moved by main <hash>", logged in Decisions); the delivery itself never raises one. If a pin flaps around a date (announcement rules, fixture account age), freeze the clock in that test rather than loosening the pin.
- Chrome weight + route probe: `var/navigation-delivery/tools/phpunit.sh -- var/navigation-delivery/scripts/BaselineChromeRoutesProbe.php` writes `baseline/chrome-weight.json` and `baseline/chrome-routes.json`; only `FREEZE_ROUTES=1` writes the frozen list (P0-02), `DUMP_HTML=1` the HTML dumps.
- HTML sizes: `python3 var/navigation-delivery/scripts/html_sizes.py`. Header heights / CLS / screenshots: `python3 var/navigation-delivery/scripts/baseline_measure.py headers|cls|screens`. Lighthouse: `scripts/lighthouse-web.sh`, then `python3 scripts/lighthouse_runs.py 5`, then `docker stop nav-web-lh`.
- `navlib.py` (`var/navigation-delivery/scripts/`) is the shared WebDriver library for later checks: `session(profile, width)`, `login(session, role)`, `probe_arm()` / `probe_check()`, recorders (console errors, haptics, layout shifts) registered before navigation, `force_standalone()`, `reduced_motion()`, `geometry_checks()`, `result()` → JSONL; each later item's Verify is `scripts/checks/<ID>.py` with `run() -> list`, run by `python3 navlib.py check <ID>`.
