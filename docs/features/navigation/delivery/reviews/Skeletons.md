# Phase review: Skeletons

- **Scope:** commit `885ab657` (COM-01a, ADD-05, HELP-01a, LAUNCH-02a, MENU-02a, PROF-00), 47 files.
- **Checked against:** `requirements.md` (Conventions + the six items' Done when / Verify), `README.md` §3, §4.5, §4.6, §4.7, §4.9, §4.10, §4.12, §4.14, §8; `implementation-plan.md` §2 (phase 2 row), §3, §4 (the six items), §7 (Help sheet transport), decisions 2 (R2), 41, 53, 69, 75, 100 (F14); `CLAUDE.md`.
- **Method:** read the diff, every new controller, template, service and test, the six translation fragments, `public/robots.txt`, `config/services.php`, and the code they rely on (`ReturnUrl`, `AnonymousCacheHeadersSubscriber`, `HiddenPlayers`, `PrivateProfileAccess`, `GetPlayerProfile`, the profile template, the Symfony redirectable matcher). No tests or docker commands were run (a gate is using the shared test DB).
- **Result:** 0 blocker · 0 major · 5 minor.

## Findings

### F1 · minor · `docs/features/seo/cloudflare.md:36` · resolved (orchestrator, skeleton review fix commit)
**Problem.** The HELP-01a plan entry (`implementation-plan.md:240`) asks for an owner note: "Cloudflare ignores `Vary` — owner note in cloudflare.md: any HTML edge cache needs a bypass on `Turbo-Frame`". The commit does not add it, and `grep -i "turbo-frame" docs/features/seo/cloudflare.md` finds nothing. `help` is the first route built to serve two bodies at one URL that is linked from every page (`help?url=<page>`). The guest full page is `public, s-maxage=60` and relies on `Vary: Turbo-Frame` to keep the two apart. An edge cache that ignores `Vary` would hand the cached full page to the sheet's frame fetch. Turbo would then take the page's empty `<turbo-frame id="modal-frame">` from `base.html.twig`, and the sheet would close empty. That is the silent-close failure described in `CLAUDE.md` §Turbo. Nothing caches HTML at the edge today, so this is latent.
**Fix.** Next to the native-app bypass in cloudflare.md step 8, add: "any HTML cache rule must also bypass requests that carry a `Turbo-Frame` header (the Help sheet `help`, and every `modal-frame` route, shares its URL with a public full page; Cloudflare does not key on `Vary`)."

### F2 · minor · `translations/messages.{cs,de,es,fr,ja}.yml` (add_intro.tracker_app cs:9010 de:8983 es:8984 fr:9003; community_page.puzzlers.description cs:8994 de:8967 fr:8987 ja:8957) · resolved (orchestrator, skeleton review fix commit)
**Problem.** Two wording problems:
- **The tracker-app link promises a download.** The link text reads cs "Stáhnout aplikaci…", es "Descarga la aplicación…", fr "Télécharger l'application…" and de "Die Puzzle Tracker App holen" (colloquial and awkward). The target `puzzle_tracker_app` is a landing page that describes the website ("Start Tracking Free"), with no download or install. A guest who follows "Download" finds nothing to download. The en "Get the … app" has a milder version of the same problem.
- **"On a roll" does not match the section it refers to.** The Puzzlers card says "see who is on a roll", which is the Players page section `on_a_roll`. Its existing labels are de "In Fahrt", fr "Sur sa lancée", ja "絶好調" and cs "V ráži", but the new values say "einen Lauf hat", "en pleine forme", "今好調な人" and "komu se zrovna daří". Only es ("en racha") matches its label.

Optional, for the owner's native-speaker check: the ja title "どこに移動しましたか？" reads like a question to the reader. A heading such as "移動した項目" would read more naturally.
**Fix.**
- tracker_app: en "About the Puzzle Tracker app" / cs "O aplikaci Puzzle tracker" / de "Mehr zur Puzzle Tracker App" / es "Descubre la aplicación Puzzle Tracker" / fr "Découvrir l'application Puzzle Tracker" / ja "パズルトラッカーアプリについて" (or similar wording with no download).
- Puzzlers description: reuse the section's own words (de "wer gerade in Fahrt ist", fr "qui est sur sa lancée", ja "絶好調の人", cs "kdo je zrovna v ráži").
- German keeps "Sie" and French keeps "vous" in all of these.

### F3 · minor · `src/Controller/PlayerResultsController.php:16` · resolved (orchestrator, skeleton review fix commit)
**Problem.** The docblock says "A player hidden from the viewer (blocked either way, unknown id) is a 404". `GetPlayerProfile::byId()` uses `HiddenPlayers::isHidden()`, whose ids come from `hidden_player_ids` = `SELECT … blocked_id FROM user_block WHERE blocker_id = player.id` (`src/Query/GetPlayerProfile.php:182`). That covers only players the **viewer blocked**. The blocked side sees the blocker normally, and must: per `docs/features/player-blocklist.md`, the blocked side must never be able to tell. The behaviour is correct and matches the profile; the comment is wrong. Someone could later "fix" the code or tests to match the comment, which would let a blocked player detect the block.
**Fix.** Change it to "A player the viewer blocked, or an unknown id, is a 404 (as on the profile; the blocked side sees the page)".

### F4 · minor · `src/Navigation/HelpPageContextFactory.php:36-56` · resolved (orchestrator, skeleton review fix commit)
**Problem.** `router->match()` runs on the FrameworkBundle `RedirectableCompiledUrlMatcher`. When the path differs from a route only by its trailing slash (or by scheme), the matcher returns that route plus RedirectController attributes: `_controller => …RedirectController::urlRedirectAction`, `path`, `permanent`, `scheme`, `httpPort`, `httpsPort` (`vendor/symfony/framework-bundle/Routing/RedirectableCompiledUrlMatcher.php:24-35`, merged in `CompiledUrlMatcherTrait.php:55/63`). The loop at :48-52 keeps every attribute without a `_` prefix, so `routeParams` picks up `path`, `permanent`, `scheme`, `httpPort` and `httpsPort`. Examples:
- `?url=/en/faq/` (trailing slash added by hand),
- `?url=/clenstvi` (the cs membership route is `/clenstvi/`),
- the legacy compare routes with trailing slashes.

The skeleton never uses `routeParams`. HELP-02 will, though: decision 55 makes the Help sheet's language row "switch the underlying page", and regenerating `routeName` + `routeParams` would then append `?path=…&permanent=1&scheme=…&httpPort=…` to the link.
**Fix.** Keep only the matched route's own variables: `$this->router->getRouteCollection()->get($attributes['_route'])?->compile()->getPathVariables()`. Alternatively, treat a match whose `_controller` is `RedirectController::urlRedirectAction` as its target `path` (or as the empty context). Add a `HelpPageContextFactoryTest` case, e.g. `/en/faq/` → route `faq`, `routeParams === []`.

### F5 · minor · `src/Navigation/NavigationRegistry.php:322` (community_place) vs `:795` (puzzlers) · resolved (orchestrator, skeleton review fix commit)
**Problem.** The new `community_place` entry uses icon `bi-people`, which the `puzzlers` entry already uses. Both are rows of the same docked-column group (Community / 0 and Community / 40, see `docs/navigation.md`). The place's icon will also be the Community tab of the bottom bar and the rail (NAV-01). So the Community landing will show a "Puzzlers" card with the same icon as the tab that opened the page, and the docked Community group will open with two people icons for different destinations. Same-icon-per-destination (README §4.12) holds, but two destinations become indistinguishable by icon. `bi-people-fill` is not an option: D15 reserves the filled icon for the active tab.
**Fix.** Give the Community place its own outline icon (a design call; e.g. `bi-globe2`), and regenerate `docs/navigation.md`.

## Checked and fine

- **Routes.** All six routes have paths in all 6 locales, exactly as the plan lists them. They do not collide: there are no `/{_locale}/{x}` or root-placeholder routes, and the existing `puzzle_add`, `player_profile` and `feedback` paths differ. All are GET-only and single-action. No route was removed or renamed. hreflang and canonical come from base: `CommunityControllerTest` pins the canonical, the 6 alternates and x-default.
- **COM-01a.**
  - 200 for guest and member; the guest response is `public` with no cookie and no `_token` in `<main>`; the page is `index, follow`.
  - It has the three cards with `community/community/<item>` data-nav.
  - Card titles and icons come from the registry. The "Players" title (legacy `menu.players`) is the planned interim label (decision 100 / F14). MENU-01 must update the pinned `['Leaderboards','Players','Events']` when it switches to `nav.row.*`.
  - The place map has `community => community*` (start page, no chips, decision 75). The registry row `community_place` has phone = bar tab and dock = Community/0, which matches README §4.3 "Community + its sections".
  - Sitemap and robots row are deferred to COM-01 and recorded in state.
- **ADD-05.**
  - The page is `noindex, follow` and `public`, with no form, CSRF token, flash or session. Join goes to `register` without `?return=` (decision 41).
  - Sign in goes to `login?return=<path('puzzle_add')>`: a constant same-site path generated on the server, which the login side validates through `ReturnUrl`. The link is `rel=nofollow`.
  - The tracker app link is present, a signed-in visitor gets a 302 to `puzzle_add`, and the guest statement count is ≤ `/en/faq`.
  - The registry `add` entry has `guestRoute: add_intro`, and the place map has `add_intro => add*`.
- **HELP-01a.**
  - `?url=` handling: only a relative path (`ReturnUrl`: `//`, `/\`, encoded variants, control characters) or an http(s) URL on the request's own host without user info is accepted, and only if the router matches it.
  - The puzzle id is kept only for `puzzle_detail` with a valid UUID.
  - The context is never rendered. The hostile-value test covers both bodies and every `href`. `help_path()` output is auto-escaped.
  - Frame vs page: a `Turbo-Frame: modal-frame` request gets only the `<turbo-frame>` (no header, `#site-menu` or footer), sent `private, no-store`. `AnonymousCacheHeadersSubscriber` skips every request with a `Turbo-Frame` header and every native UA.
  - The full page has no explicit cache-control, so it is `public` for guests. Both bodies carry `Vary: Turbo-Frame`. A native UA always gets the full page and is never public.
  - The member frame costs exactly 2 statements (the empty signed-in endpoint).
  - robots.txt disallows `help?url=` in all 6 locales and leaves the bare page crawlable (`RobotsTxtTest`).
  - `help_opener_attrs()` uses `layer_frame()` and adds `data-modal-scrim="own" rel="nofollow"`. `HelpTwigExtension` is listed in `NavigationServicesResetTest::EXTRA_CLASSES` and holds no mutable state.
  - The frame root carries `data-modal-size="" data-modal-sheet data-modal-scrollable data-modal-history`, which the existing `dynamic_modal_controller.js` already reads.
  - `send_feedback` now targets `help`; `help`, `menu` and `feedback` have not-in-Menu entries with reasons.
- **LAUNCH-02a.** The page is `noindex, follow`, `public` for guests, and has the placeholder. The registry row is SignedIn on Help & learn/80 (R2) and the page itself is public. Place: none.
- **MENU-02a.** `noindex, follow`, guest `public`, `nav.menu.title` created; the native row is in `NativeAppNavigationTest`. Rows per role are `verify-after: MENU-01`, as recorded in state.
- **PROF-00.**
  - Blocked: `byId()` throws the same `PlayerNotFound` for a player the viewer blocked as for an unknown id or a non-UUID, so the two 404s cannot be told apart.
  - Private: a private profile hidden from the viewer (guest, stranger) gets a 302 to `player_profile`. The owner and an allow-listed friend get 200. `isPrivate` is the viewer-relative value from `PrivateProfileAccess::sqlIsPrivate()`, so a block in either direction still outranks the allow list.
  - The head follows the player's own setting (`isPrivateProfile`, `noindex, nofollow` for others), as on the profile.
  - The list is the same `PlayerSolvedPuzzles` with the same `?team=&category=` reading. The test compares the row count with the profile.
  - Canaries: the `BlocklistCanaryTest` row GETs the new route and fails if the bystander does not see the player. The `PrivateProfileCanaryTest` rows cover the redirect, the friend's view and a pair row on a public player's Results page. The teammate-in-list Blocklist row is PROF-08's job (implementation plan §4 PROF-08).
  - `PlayerProfileQueryBudgetTest` is untouched. Place map: `player_results => profile|community` (own = Profile, others and guests = Community). Registry `results` is Your puzzling/10, SignedIn.
- **Guest caching.** Every new guest-visible route has a test asserting `public`, no cookies and no `_token` in `<main>`.
- **Translations.** All 44 new keys exist in all 6 locales with no key missing or extra. Values equal to English are only the allow-listed ones: de "Community", cs/fr "Menu".
  - German keeps "Sie" and French "vous" throughout; Spanish uses "tú" and Czech the "ty" form like the surrounding keys. Japanese is polite.
  - The terms match the catalogue (puzzleros, puzzleurs, Puzzler, パズラー, Bestenlisten, Veranstaltungen, Události).
  - `community_page.title` equals the README §4.2 tab labels in every locale. No hard-coded user-visible strings. No duplicate top-level YAML keys.
- **Registry and docs.** The structural guard covers the new entries. `docs/navigation.md` is regenerated: 132 entries. The rendered guard already collects the help frame and `[data-help-sheet]` / `.community-landing` links. `NavInstrumentationMarkupTest` covers the new containers with well-formed ids.
- **`config/services.php`.** The glob `{Navigation*.php,*Factory.php,*Builder.php}` picks up only `HelpPageContextFactory` and `HelpSheetBuilder` besides the existing services. `HelpPageContext` and the value objects/enums stay out, and a mismatching future class would fail the container build loudly. Both new services are `final readonly` and stateless; the XP gate they read is cached (`CachedXpLaunchReadiness`).
- **CLAUDE.md.** Single-action controllers, no state changes, no new SQL, PlayerNotFound bubbles, no feature flag, SCSS imported once, and DAMA isolation for the tests' `user_block` inserts.
