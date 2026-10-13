# Phase review: SRCH + FOOT

- **Scope:** one commit in the delivery worktree (`feature/navigation-redesign`), reviewed at `5030f5d4`:
  - SRCH-01: "Pages & settings" in the top bar's search (`assets/controllers/nav_search_controller.js`, `assets/navigation/nav_search.js`, `templates/components/GlobalSearch.html.twig`), `tests/TopBarPageSearchTest.php` + `tests/top-bar-search-harness.mjs`;
  - FOOT-01: the footer columns from the registry (`FooterColumn`, `FooterLink`, `FooterPlacement`, `FooterBuilder`, `nav_footer()`, the placements in `NavigationRegistry`, `templates/_footer.html.twig`, `templates/navigation/_new_badge.html.twig`, `assets/styles/_footer.scss`), the generated Footer table in `docs/navigation.md`, `docs/features/site-footer.md`, CLAUDE.md, `footer.all_pages` × 6, `SiteFooterTest`, `FooterPlacementsTest` and the adjusted tests.
- **Checked against:**
  - `requirements.md`: Conventions, SRCH-01, FOOT-01, MENU-03 (one list), MENU-04, NAV-12.
  - `implementation-plan.md`: §3, the SRCH-01 and FOOT-01 sections, decisions 1 (R1), 2 (R2), 38, 76, the §6 rows of the tests FOOT-01 touches.
  - `README.md` §4.11, §4.12 "Search" / "Weight", §4.10 (auth layout), D1–D22 not reopened.
  - `delivery/state.md`: the FOOT-01 and SRCH-01 decisions of 2026-10-13 (label renames, six sign-in pages kept, nine badges, em breakpoints; the top-bar Menu path and "Sign in to see …") and Removal Q1 (whole-page weight reading). Behaviour changes logged there are not findings.
  - `docs/features/site-footer.md` and the old footer (`5030f5d4^:templates/_footer.html.twig`).
  - `CLAUDE.md`: guest HTML shared-cacheable, translations, SEO never costs UX, the newsletter footer rules, the sign-in code page rule (no other text input on `/verify-code`).
- **Method:**
  - **Trees.** `git archive` of `5030f5d4` and `5030f5d4^` into the scratchpad; every file below read there, the diff read in full.
  - **Old vs new footer.** Every link of the old template compared with the registry placements (route, `rel`, `data-nav`, badge, audience); the HTML dumps the delivery left in `var/navigation-delivery/weight/` (`faq-{guest,member}-before-foot.html` vs `-now.html`, fixture DB) diffed line by line.
  - **SRCH-01.** `nav_search.js`, `nav_search_controller.js`, `nav_search_lists.js`, `help_openers.js`, `menu_links.js`, `NavigationSearchIndex`, `NavigationGoController`, `HelpTwigExtension`, `HelpPageContextFactory`, `NavigationViewer(Factory)`, `NavVisibility`, `MenuGroup` and the registry's entries read; every `signed_in` entry's route checked for `#[IsGranted]`; every entry whose phone and dock placements differ listed.
  - **Tests.** The new and changed tests read against `WebTestCase` / `KernelTestCase` boot rules; the browser logs `var/navigation-delivery/SRCH-FOOT-run2.log` / `-rerun.log` and `scripts/checks/{SRCH-01,FOOT-01}.py` read.
  - **Weight.** `ChromeWeightBudgetTest` read; the growth recomputed from the dumps; `git diff 524a3305 5030f5d4` of `/en/faq`'s own template and keys.
  - **Contrast.** White on `#d63c42` computed (WCAG relative luminance).
  - No tests, builds, docker or containers were run.
- **Result:** 0 blocker · 0 major · 5 minor · 7 questions.

## Findings

### F1 · minor · `assets/navigation/nav_search.js:36-39, 140-143`; `src/Navigation/NavigationRegistry.php:715-731` (`what_next`), `:1163-1178` (`send_feedback`), `:1190-1203` (`where_did_it_go`)
**Problem.** A guest is offered "Sign in to see …" for three entries whose link never asks anybody to sign in.
- `offersSignIn()` offers every `signed_in` entry but Log out. Three of them open without signing in:
  - **What next?** → `puzzle_picker`, the public, indexed guest landing (no `#[IsGranted]` on `PuzzlePickerController`). The same guest's footer links it plainly as "What next?" (followed, New badge), and so do the Puzzles chips (D6). The page shows "Sign in to see What next?" and "What next?" for one URL.
  - **Where did it go?** → `where_did_it_go`, a public page. R2 (decision 2) and README §4.12 hide it from guests on every surface; the footer and the Help sheet do, the top-bar search now offers it to them.
  - **Send feedback** → the Help sheet, where the guest is asked to sign in a second time ("Sign in to see Send feedback" → "Sign in to send feedback").
- In each case the label promises a sign-in that does not happen.

**Reproduction.** As a guest on `/en/faq` (360 px), in the top bar's field:
1. "what next" → first row `[data-nav-search-sign-in]` "Sign in to see What next?", `href="/en/what-to-solve-next"`; tap → 200, the guest landing, no sign-in.
2. "where did" (or the synonyms "moved", "missing") → "Sign in to see Where did it go?", `href="/en/where-did-it-go"` → 200 for the guest.
3. "feedback" (or "contact", "bug") → "Sign in to see Send feedback" → the sheet with "Sign in to send feedback".

**Fix.**
- Never offer `x: 'help'` and `where_did_it_go` to guests (R2).
- What next?: offer it as a plain row to guests (a guests' half in `nav-search.json`, like Home and Add) or leave it out.
- Better as a rule than a list: mark in `nav-search.json` which `signed_in` entries make a guest sign in (`#[IsGranted]` on the route, or `nav_go`), offer only those, and add a test that requests every offered entry's `u` as a guest and expects the redirect to `/login`.

### F2 · minor · `assets/controllers/nav_search_controller.js:266-283` (`helpLink()` fallback), `assets/help_openers.js:18`
**Problem.** The Send feedback row a guest gets from the top-bar search (F1) is built by `helpLink()`'s fallback. That fallback is not a help opener, and it attaches the raw address.
- A guest's `#site-menu` has no Send feedback row, so the fallback builds `href = /…/help?url=<location.pathname + location.search>` and sets no `data-help-opener`. This path is reachable only since SRCH-01; before it, guests were never offered the entry.
- **Private links.** On a page whose URL holds a private token, every other opener on the same page carries the token-free path (`help_path()`, `data-help-opener="private"`, HELP review Q2): the top bar's ?, the end-of-page line and the footer. This row puts the token or signature into the `/help?url=` request instead. The server strips it again on arrival (`HelpPageContextFactory::fromUrlParameter()`), but the URL with the token is requested and logged a second time.
  - Guest pages of this kind with the top bar: `email_preferences`, the newsletter confirm/unsubscribe pages, the three e-mail unsubscribe pages (signed query), `verify_email` (all `base.html.twig`).
- **On the help page itself.** `help_openers.js` only handles `a[data-help-opener]`. So on `/en/help` this row opens the sheet over the page, which HELP review F5 forbade: `help/_content.html.twig` twice, with `id="help-language-label"` twice.

**Reproduction.**
1. As a guest open `/en/email-preferences/abc123`. Type "feedback" in the top bar. The row's `href` is `/en/help?url=%2Fen%2Femail-preferences%2Fabc123`; the footer's Send feedback on the same page is `/en/help?url=/en/email-preferences/{token}` with `data-help-opener="private"`.
2. As a guest on `/en/help`, type "feedback" and tap the row: the sheet opens over the help page; `document.querySelectorAll('#help-language-label').length === 2`.

**Fix.** If F1 keeps the entry for guests in any form, build the fallback like the server's openers:
- `data-help-opener="page"`, so `help_openers.js` handles it (current page at the tap; on the help page, its own box);
- on a private-link page, take the `?url=` of another opener already on the page (e.g. the top bar's `[data-bar-action="help"]`, rendered by `help_path()`) instead of `location`.

Dropping the entry for guests (F1) removes the fallback's only use.

### F3 · minor · `assets/controllers/nav_search_controller.js:184, 231`, `assets/navigation/nav_search.js:207-222`, `src/Navigation/NavigationRegistry.php:227-256` (`menuPath()`), `src/Navigation/NavigationSearchIndex.php:237`
**Problem.** The line under a top-bar result ("☰ Menu › …", SRCH-01's "where it lives in the Menu") is wrong for the entries that live in different places on phones and on desktop.
- The list carries one path per entry: `menuPath()` takes the phone placement when it is a row, else the dock one. Seven entries differ by surface:
  - **Phones:** Home, Puzzles, Community, Profile are `notInMenu(BAR_TAB)`, so their path is the dock row's ("☰ › Home"). The top bar on a phone says "☰ Menu" under Home, Puzzles, Community and Profile, which are bottom-bar tabs there. The same applies to children: `hub_guest` says "☰ Menu › Home".
  - **Desktop:** Inbox and Log out are phone-only rows (desktop: the top bar's Inbox tool, the avatar ▾ menu), so desktop says "☰ Menu" under them. Neither the docked column nor the overlay has them.
- The decision behind the line is "the path teaches where the page lives" (state.md, SRCH-01). For these seven it teaches the wrong place. `TopBarPageSearchTest::menuPathLine()` derives the expectation from the same `menuPath()`, so it cannot catch this.

**Reproduction.**
- Signed in, 360 px, type "home" in the top bar: "Home" with "☰ Menu" under it. ☰ → the panel has no Home row; Home is the first tab.
- At 1280 px type "inbox": "Inbox" with "☰ Menu" under it. The column/overlay has no Inbox row.

**Fix.** Either of:
- give the list both placements (e.g. `p` = phone path, `pd` = desktop path, or a `b` flag "a bar tab on phones / a top-bar tool on desktop") and let `menuPathOf()` pick by `PHONE_QUERY`, with "Bottom bar" / "Top bar" wording (LAUNCH-02 has `where_did_it_go.bottom_bar`);
- or show no path line for `RowKind::Place` rows and the phone-only rows.

Add a test row for Home at 360 and Inbox at 1280.

### F4 · minor · `assets/controllers/nav_search_controller.js:113-126, 139-157`, `assets/navigation/nav_search_lists.js:108-124`, `templates/components/GlobalSearch.html.twig` (`.nav-search-results` above `.global-search-results`)
**Problem.** "Nothing moves when the Live results arrive" (decision 38, plan §SRCH-01) holds only when the list is already loaded.
- The list is fetched on the field's first focus. Until it is there, `update()` renders nothing.
- The Live request leaves 150 ms after the last keystroke (`debounce(150)`). If its answer lands first, the players and puzzles show at the top of the panel.
- When the list then arrives, `handleListReady()` → `update()` → `render()` inserts the "Pages & settings" group above them. Everything the visitor was about to tap moves down by the group's height (title, up to three rows, "Show N more").
- This is the first search per tab and per deploy (the list URL carries `?v=`), on a slow or lossy connection. It is also the moment after a failed list load, which is asked for again only by the next keystroke.
- SRCH-01 makes this the search of every page, so the case is no longer limited to the Menu panel.

**Reproduction.** In a fresh tab (the list not loaded yet), in the console:
```js
const f = window.fetch;
window.fetch = (u, o) => String(u).includes('nav-search.json') ? new Promise((r) => setTimeout(() => r(f(u, o)), 3000)) : f(u, o);
```
Open the top bar's search and type "puzzle". The players/puzzles groups render. About 3 s later the page group appears above them and `.global-search-results` moves down (`getBoundingClientRect().top` before/after).

**Fix.** When the list arrives while the server's groups for the same query are on screen, do not insert the group above them: wait for the next input. Or start the load earlier (pointerdown/touchstart on the field, before focus) so the race needs a much slower list. Add a harness case with a delayed fetch.

### F5 · minor · `tests/TopBarPageSearchTest.php:154` (with `:246-253` `label()`, `:296-302` `results()`)
**Problem.** `testWhatAGuestAndANonMemberAreNotOffered` fails when it is the first test of the class to run.
- Its first statement evaluates `$this->label(self::LOG_OUT, $locale)` as an argument of `$this->case(...)`. `label()` calls `self::getContainer()`, which boots the kernel.
- `case()` → `results()` then calls `self::createClient()` while the static cache is still empty. `WebTestCase::createClient()` throws "Booting the kernel before calling createClient() is not supported" when the kernel is booted.
- In the declared order `testNoCaseThrewOrLoggedAnError` fills the cache first, so a whole-class run passes. A filtered run, a random or defects-first order, or ParaTest's per-method mode fail with a LogicException instead of a finding.

**Reproduction.** `vendor/bin/phpunit tests/TopBarPageSearchTest.php --filter testWhatAGuestAndANonMemberAreNotOffered` → LogicException from `createClient()` (read from the code, not run).

**Fix.** Call `self::ensureKernelShutdown()` at the top of `results()` before `createClient()`, or call `$this->results()` first in each test before anything touches the container.

## Questions

**Q1 (anchor text of sitewide links).** FOOT-01's decision renames the footer links to their registry labels. These links are followed and crawled from every guest page in six locales. The SEO-relevant renames (en):
- `/world-jigsaw-puzzle-championship`: "World Jigsaw Puzzle Championship" → "World championship";
- `/what-to-solve-next`: "What should I solve next?" → "What next?" (the indexed landing in `sitemap-static.xml`);
- `/methodology`: "Difficulty & Rating Methodology" → "Difficulty & ratings" (added to the footer for SEO, `docs/features/seo/README.md:144`);
- `/puzzle`: "Puzzle database" → "All puzzles";
- `/recent-activity`: "Latest Puzzle Times" → "Latest times".

The decision (and "SEO must never cost UX") favours one name per destination. Should Jan see this list with the release notes, or should a footer placement be allowed an SEO label? Related: the old footer label "What should I solve next?" (`puzzle_picker.title`) is not among `what_next`'s search keys, unlike every other renamed footer label (`legacyLabels`). "what should I solve next" finds nothing; cs has the equivalent synonym.

**Q2 (weight: honest, but thinner than it reads).** `/en/faq` member +30,097 B, guest +15,316 B match the dumps (117,951 − 87,854; 102,435 − 87,119).
- **The FAQ's own credit.** Since the P0-02 baseline, `/en/faq`'s own template lost 14 × 33 B = 462 B: NAV-07+11 F1 moved `style="scroll-margin-top: 6rem;"` into app.scss. The whole-page reading (Removal Q1) credits that to the chrome. Chrome-only, the member margin is ≈ 161 B, not 623 B.
- **Shaveable without behaviour change** (about 100 B for a member):
  - `rel="nofollow"` on the six sign-in links only in guest HTML, since crawlers never see a signed-in page (−90 B);
  - `{%- for` / `{%- endfor` trimming in the column loop (" <div>", " </ul>": −9 B);
  - the badge's leading space replaced by its margin (−9 B).
- Record the 161 B in the owner's final report, next to Removal Q1?

**Q3 ("Sign in to see %label%" wording, first shown by SRCH-01).**
- de "Melden Sie sich an für Passwort" lacks the article. "Anmelden, um %label% zu sehen" would fit any label.
- cs/es/fr put the capitalised label mid-sentence ("Přihlas se a uvidíš Heslo", "Inicia sesión para ver Contraseña", "Connectez-vous pour voir Mot de passe").
- The key comes from MENU-03. Reword now, or leave it for DOC-02's language pass?

**Q4 (the path line in the link's accessible name).** The line is inside the row's link, so the name is "Password ☰ Menu › Account › Settings". Some screen readers speak U+2630 by its Unicode name ("trigram for heaven") and "›" as "right-pointing angle quotation mark".
- The Menu panel's own search drops the "☰" (`locationOf()`); the top bar keeps it.
- Wrap "☰" in `aria-hidden="true"` (the text "Menu" stays), and the separators too? That needs the line built as nodes, not `textContent`. Only a real screen reader can confirm the symbol reading, hence a question.

**Q5 (the newsletter band is not "unchanged").** The commit message says the guest newsletter band is unchanged. Its Subscribe button changed from `$primary` #fe696a to #d63c42 (`.footer-newsletter-inner .btn-primary`). The reason is a WCAG fix: white on #fe696a is 2.8:1, on #d63c42 4.58:1 (computed), the transactional e-mails' button colour. The state.md decision names only the badges. Is the button colour Jan's call to note?

**Q6 (which owner-only pages a guest is offered).** SRCH-01 says "owner-only entries shown to guests as 'Sign in to …'". Only the `signed_in` audience is offered. Several own pages are never offered to a guest, although every new account sees them once XP is visible:
- Your achievements (`achievements_shown`), Level & XP (`xp_participant`), Your week (`xp_player`);
- Karma (`karma_holder`, only with karma) and Referral program (`member_or_referral`) are rightly left out.

Is leaving out the first three the intended reading, or should `xp_player` / `xp_participant` / `achievements_shown` be offered while XP is visible to guests?

**Q7 (browser coverage).** `checks/SRCH-01.py` covers member, guest and moderator at 360 and member at 1280. Not covered in a browser:
- tapping a "Sign in to see …" row end to end (sign-in, then back on the page; `NavigationGoControllerTest` covers `nav_go` → `/login?return=`);
- the guest's desktop dropdown;
- Tab order from the field through the page rows into the Live groups.

Worth one row each in the QA sweep?

## Checked and fine

**Footer: nothing lost.**
- **Routes.** All 25 routes of the old columns are still linked, for guest, player and member alike (`SiteFooterTest::testEveryRouteOfTheOldFooterIsStillLinked` per baseline role).
  - `feedback` became the Help sheet (`help_path()`, `help_opener_attrs()` with `rel="nofollow"`, guests asked to sign in inside). Its form still posts to `feedback`, registered "not in Menu: Help sheet form endpoint" (decision 76).
  - The popular searches' routes are registered as SEO hub entries.
- **`data-nav`.** All 25 P0-03 values are kept byte for byte, including `footer/learn/wjpc` now in the MySpeedPuzzling column. The new ones are well-formed: `footer/community/community_place`, `footer/learn/where_did_it_go`, `footer/bar/all_pages`. `FooterPlacementsTest` pins uniqueness and grammar.
- **`rel="nofollow"`.**
  - Kept on the six sign-in pages: Add, Stopwatch, Scan several boxes, Compare, Pairs & teams, Membership. Each route has `#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]`.
  - What next? stays followed (public landing).
  - Send feedback is nofollow through `help_opener_attrs()`.
- **Kept around the columns.**
  - The guest newsletter band: template untouched, `footer_newsletter` still emptied by `_auth_layout`.
  - The players' newsletter-off form.
  - Popular searches: four `<details>`, markup untouched, open from 48em through `::details-content`, summary inert at the same breakpoint.
  - Brand block, social links, credits and the language dropup.
- **Sign-in pages.** The footer gains no text input. Send feedback there still opens the sheet, because `modal-frame` lives outside `nav_layers`. "All pages" falls back to `/menu`, since there is no shell to open.
- **Privacy, improved.** The old guest feedback link embedded the absolute URI, a private token included. `help_path()` now gives the token-free path.

**SEO.**
- **New followed links:** `/community` (the new landing) and `/menu` (`noindex, follow`).
- **Gone:** `/feedback?url=…` (nofollow), replaced by `/help?url=…` (nofollow).
- **Popular searches:** unchanged, and still no filter-URL link on the page (`FooterPopularSearchesTest` untouched, still valid).
- **Anchor text:** see Q1.

**Audiences.**
- **The columns.** Guest, player, member, moderator and admin get the same columns. "Where did it go?" is for signed-in viewers only (R2, placement audience).
- **The XP pages.** They follow `XpFeatureGate::isVisibleFor()`, the same call as the old `xp_system_visible()`.
- **The native app.** No footer (`is_web()` in base).
- **Guest HTML** is the same for every guest. The footer's inputs are the locale, the URL (`help_path()`), the global XP gate and the clock (badges); the dumps differ only where expected. Guest `Cache-Control: public` is asserted.
- **Statements.** No new statement: `FooterBuilder` reads the registry (built once per container), the per-request viewer and the router.
- **Worker mode.** `FooterBuilder` is readonly and holds no state.

**New badges.**
- **The window.** `NavigationLaunch::newBadgesVisible()`: shown while `DATE` is null; with a date, until midnight Europe/Prague of `DATE` + 14 days. `SiteFooterTest` checks 23:00 on day 13 (shown) and day 15 (none) through a replaced service.
- **Which links.** Nine badges: the eight of 2026-10-02 plus Community, none on "Where did it go?" or "All pages".
- **Contrast.** White on #d63c42 = 4.58:1 (old `bg-primary` 2.8:1); the badge is 10.5 px, medium weight.
- **Link names.** "Puzzlers New", as before.

**SRCH-01 (apart from F1–F4).**
- **What a viewer is offered.**
  - Only `signed_in` entries are offered to guests. Members-only and role entries never are: role entries are absent from `nav-search.json`, and non-members never see `member` entries (checked in the browser as a moderator).
  - The guests' own halves of Home and Add are never duplicated (`visibleIds`). Log out is never offered.
- **The list.** One list (`nav-search.json`, fetched on focus, never inlined); the sign-in text is one attribute, the same for every guest.
- **Links.** `nav_go` pages lead through `/login?return=/…/go/<id>`. "Show N more" counts and focuses the first row it brings.
- **The zero-result beacon** is unchanged; sign-in rows count as results.
- **Folding** (katakana → hiragana, `search_fold.js`) is unchanged: Japanese katakana queries pass in the harness and the browser. No new synonym; no "rival" or "suspicious".
- **Cost.** A pages-only query costs the same two statements as any query (`GlobalSearchTest`); in the browser [4, 6] vs [4, 6].
- **Coverage.** `TopBarPageSearchTest` runs 5 queries × 6 locales on the real controller in jsdom, and `checks/SRCH-01.py` covers the same in Chromium (0 FAIL).

**Weight.**
- The measure is the whole page on the fixture DB, the second request, with the New badges on (the worst case).
- `_footer.html.twig` is on the ChromeWhitespace list.
- FOOT-01 itself costs +163 B (member) and +176 B (guest) over the page before it. Margin: see Q2.

**Accessibility.**
- **Landmarks and headings.** `<footer>`, the labelled `<nav>` of the columns and of Popular searches, `h2` headings.
- **Large text.** The em breakpoints give one column below 20em (320 px at 16 px), four from 48em and the brand column from 62em, and long words break (`overflow-wrap: anywhere`). The 200 % checks at 360, 1000 and 1280 pass.
- **Contrast and axe.** "All pages" has the legal links' contrast; axe-core 4.10.2 finds 0 serious/critical issues in the footer for guest and member at 360 and 1280.

**Tests and docs.**
- **Tests kept to their intent:**
  - `PuzzlePickerControllerTest`: the footer entry point with its badge, registry label;
  - `NavigationLabelHygieneTest`: the footer reads registry keys;
  - `NavigationMenuCompletenessTest`: comment only, `GUEST_FOOTER_ONLY` stays 6 per the decision.
- **`FooterPlacementsTest`.** 27 placements, unique positions, a route of its own for each, `nofollow` on sign-in pages.
- **Docs.** The generated Footer table, `site-footer.md` and CLAUDE.md match the code; the `_language_menu` sharing note is correct.
- **Translations.** `footer.all_pages` in 6 locales, none equal to English. The old `footer.*` link keys stay for LAUNCH-02.
