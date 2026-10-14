# Phase review: LAUNCH (LAUNCH-01 … LAUNCH-05)

- **Scope:** two commits on `feature/navigation-redesign` (delivery worktree), reviewed at `42edd6526`. "Before" is `e4228b40`.
  - `32f71813` LAUNCH-04 `delivery/announcement.md` + LAUNCH-05 `delivery/launch-watch.md` (docs only).
  - `42edd6526`:
    - LAUNCH-01: `AnnouncementModal::NavigationWhatMoved`, `NavigationWhatMovedRule`, `templates/modals/announcements/_navigation_what_moved.html.twig`, quiet routes `help` / `feedback` / `where_did_it_go`;
    - LAUNCH-02: `FormerNavigation`, `FormerNavigationItem`, `FormerSurface`, `WhereDidItGoBuilder` / `Row` / `Section`, `WhereDidItGoController`, `templates/where_did_it_go/index.html.twig`, `where_did_it_go_controller.js`, `_navigation-launch.scss`;
    - LAUNCH-03: `HintType::NavigationLaunchPointer`, `NavigationPointers::byName('launch')`, `templates/navigation/_launch_pointer.html.twig` in `hub.html.twig` and `_menu.html.twig`, the change to `dismiss_hint_controller.js`;
    - tests, 30 keys × 6 locales (`where_did_it_go.placeholder` removed).
- **Checked against:**
  - `requirements.md`: the Conventions, and the Done when / Verify of LAUNCH-00…05.
  - `README.md`: §8, §4 (the surfaces the announcement must cover), §10.1, §11, D2, D3, D16, D17, D18. D1–D22 are not reopened.
  - `implementation-plan.md`: §3.2, §3.3, §3.5 (item 9), §3.6, §3.7, §3.10; sections LAUNCH-00…05, INB-05, FOOT-01 (the footer placement of Where did it go?); decisions 52, 57, 78; the expected-test-changes rows (`AnnouncementModalTest`, `HubControllerTest:162-169`).
  - `delivery/state.md`: the LAUNCH rows and the Decisions log. These are logged decisions, not findings: `lead_desktop`; a profile without its registration date gets no modal but does get the pointers; coverage pinned on the data plus "an admin renders every item"; XP-gated rows hidden; dismiss-hint removes every copy of a type; §4.14 not announced. Also logged earlier and respected here: SRCH+FOOT review F1/F2 (sign-in rows only for entries that really need sign-in; the guest Send feedback row is a real help opener).
  - `CLAUDE.md`, `docs/features/announcement-modals.md`, `docs/features/hint-dismissing.md`.
- **Method:**
  - Read both diffs in full and every touched file. Also read `NavigationLaunch`, `ResolveAnnouncementModal`, `MenuBuilder::urlFor()/href()`, `SignInLink`, `NavigationViewer::sees()`, `NavVisibility`, `NavigationRegistry` (`ON_BARS`, the `where_did_it_go` entry), `NavigationSearchIndex::variants()`, `menu_order.js`, `menu_layer_controller.js` (Tab handling), `autoshow_modal_controller.js`, `_icons.html.twig` (the sprite's symbols), `_topbar.html.twig`, `_places.html.twig`, `_menu_row.html.twig` and the special rows, `DismissHintController` / `DismissHintHandler`, `PlayerProfile::navigationHintsFromJson()`, `NavClickController`, `StartFreeTrialController`, `EmailAuditSubscriber`, and the entities behind every SQL of the watch list.
  - **Data.** Ran a read-only PHP script in a throwaway container (worktree mounted read-only, no kernel, no DB) that prints, for each of the 82 `FormerNavigation` items, the entry's visibility, kind, phone/dock placement, link entry and target, and the phone/dock Menu paths. The findings on "Now" texts and links come from that output.
  - **Bar-free pages.** Checked every template that empties `nav_chrome` (so has no sprite) against `QUIET_ROUTES`. Only the guest sign-in pages remain outside it (`sign_in_link_sent`, `password_reset_sent`). No finding.
  - **dismiss-hint.** Listed every `dismiss-hint` user. Each renders at most one element per type per page, so the new loop changes nothing for them.
  - **Translations.** Parsed the six catalogues with PyYAML:
    - 33 keys under `where_did_it_go.*` / `nav.launch.*`, present in all 6;
    - none equal to English, no `|`, no "rival"/"suspicious";
    - tone: cs ty, de Sie, es tú, fr vous;
    - `line_tabs` uses each locale's own tab words (`nav.bar.*`, place labels).
  - **Launch-watch.** Checked these against the code:
    - every SQL column;
    - every LogQL kind and field (`NavClickController`, `StartFreeTrialController`);
    - the "before" `data-nav` ids against `62c99662` (including `toolbar/tools/messages` in `messaging/_unread_badge.html.twig`);
    - the "after" ids against today's templates;
    - every GA4 route path against the controllers.
  - **CSS.** Measured the share of the launch CSS in the dev `public/build/app.css`.
  - I ran no tests, builds or browser. Browser evidence came from `state.md` (LAUNCH-01 15/15, LAUNCH-02 19/19, LAUNCH-03 17/17).
- **Result:** 0 blocker · 0 major · 10 minor · 5 questions.

## Findings

### F1 · minor · `src/Navigation/WhereDidItGoBuilder.php:142-147, 160-164`; `assets/styles/_navigation-launch.scss:329-341`
**Problem.** "Now" names an indirect place for the top-bar tools, and a wrong one on tablets.
- **Top-bar tools.** `now()` tries `menuPathOn(Phone)` first and falls back to the bar only when the Menu has no row. So `inbox` and `send_feedback` never show their top-bar place, although they are the top bar's own tools on phones (README D2) and on computers (D16, `ON_BARS`).
  - Phone rows for Notifications and Messages say "☰ › Inbox", although the Inbox icon with its badge sits next to ☰.
  - Send feedback (top strip and footer) says "☰ › Help & learn › Send feedback" on phones **and** computers, although ? / Feedback is on both top bars.
  - `testWhereItIsNow` pins "☰ › Inbox" for phones.
- **Tablets.** The computer text (`.wdig-now--desktop`) shows from 768 px. Place rows say "Left menu › …", but at 768–1023 px there is no left menu (D16):
  - Home, Puzzles and Community are in the compact top bar;
  - Profile is under the avatar ▾.
  - Rows affected: Hub, Puzzles overview and My profile.

**Why it matters.**
- README §10.1 names the bell and the top-strip feedback link among the habits the launch breaks. This page is where those players look, and for two of them it sends a phone into ☰ instead of to the icon beside it.
- An iPad in portrait (768–834 px) reads a place that does not exist.

**Fix.**
- In `now()`, for entries whose `ON_BARS` contains the bar of that surface and which are top-bar tools (`inbox`, `send_feedback`; logo and search already have explicit paths):
  - lead with "Top bar › <the bar's name>" (`nav.bar.inbox`, `nav.bar.help` on phones, the Feedback label on computers);
  - keep the Menu path in `data-search`, or as a second line.
- Add a third text for 768–1023 px (`.wdig-now--tablet`, shown between the phone and desktop queries):
  - places: "Top bar › Home / Puzzles / Community";
  - Profile: "Account menu (your picture) › View profile".
  - Or word the desktop text so it holds at every width: "Left menu or top bar › Home".

**Test.**
- `testWhereItIsNow`: the Notifications row's phone text is "Top bar › Inbox", and Send feedback mentions the top bar.
- A browser row at 820 px: the Hub row does not read "Left menu".

### F2 · minor · `src/Services/AnnouncementModals/ResolveAnnouncementModal.php:40-66`
**Problem.** `menu` is not a quiet route, but every "Now" link on Where did it go? leads to it (`/en/menu#menu-row-<id>`).

Scenario:
1. An old account's first page after the launch is a quiet one:
   - Where did it go?, opened from the newsletter post, which names it (`announcement.md` §1, §3 row 4);
   - or the Add form, then Help › Where did it go?
2. The player taps "☰ › Account › Your data" to find the row.
3. The Menu page opens with What moved on top of it. The player has to close the modal, and then find the anchored row again.

**Why it matters.** Decision 57 made `help`, `feedback` and `where_did_it_go` quiet because "a player looking for a moved item is in the middle of something". The Menu page reached from those rows is the same moment.

**Fix.** Add `'menu'` to `QUIET_ROUTES` with the decision-57 comment. The modal then comes on the next ordinary page.

**Test.** `AnnouncementModalTest::testWhatMovedWaitsOutHelpAndWhereDidItGo` also requests `/en/menu` and expects no modal and no impression. `testEveryQuietRouteExists` covers the route.

### F3 · minor · `src/Navigation/WhereDidItGoBuilder.php:104-108`; `src/Navigation/FormerNavigation.php:117`; `templates/where_did_it_go/index.html.twig:47`
**Problem.** The "Log out" row signs the viewer out.
- Its old name links `urlFor(logout)`, which is `/logout`. It is a plain `<a>`, so Turbo fetches it, and logout answers GET without a token (`config/packages/security.php:184-187`).
- Every other logout link in the chrome carries `data-turbo="false"` and is meant as the action. Here, a player scanning the table taps "Log out" to see where it went and is logged out, landing on `/`.

**Why it matters.** "Each row a working link" means "takes you to the item". For an action row, that should be its place, not the action itself.

**Fix.** For `RowKind::LogOut`, link the old name to `nowHref` (`/en/menu#menu-row-logout`) instead of the route. Also consider any other action-kind entry later added to `FormerNavigation`.

**Test.** `WhereDidItGoControllerTest`, as a member: the account-dropdown row "Log out" has no `a[href="/logout"]`.

### F4 · minor · `src/Navigation/FormerNavigation.php:84-85`; `src/Navigation/WhereDidItGoBuilder.php:31, 80-96`
**Problem.** Some rows describe a place the viewer does not have.
- **Signed-in viewers**, the page's audience (R2), get:
  - "Sign in → ☰ › Sign in", linking `/login`;
  - "Register → ☰ › Join free", linking `/register`.

  Both items have `audience: Everyone`, but they were guest-only in the old toolbar (baseline JSON: `register` and the sign-in `my_profile` are listed for guests only), and a signed-in Menu has the You card instead.
- **Guests** get "After signing in:" on pages they can open, and on entries that SRCH+FOOT F1/F2 settled as normal guest rows:
  - Membership (public page; guests have it in the footer);
  - What next? (`guestRoute` `puzzle_picker`, a guest landing);
  - Send feedback (a real help opener for guests).
- **Latent:** `MEMBERS` includes `PrivateMember` ("members **with a private profile**"). A member with a public profile who does not see such a row would get the "Members" lock although they are a member. No current item uses that audience; `profile_viewers` would.

**Why it matters.**
- Rows that do not apply are noise on a page that exists to be precise.
- The guest wording contradicts a logged decision.
- The latent case would mark a member's row "Members".

**Fix.**
- Give the two toolbar items `audience: GuestOnly`. The existing `GuestOnly` branch then hides them for signed-in viewers.
- Set `afterSignIn` only when a guest really needs an account: the link fell back to `SignInLink` (`urlFor()` returned null) or the entry has no guest route and its route requires sign-in. Same rule as `NavigationSearchIndex::variants()`.
- Mark "Members" only when `!$viewer->sees(NavVisibility::Member)`; otherwise hide the row like the other audiences.

**Test.**
- As a member: no "Sign in" / "Register" rows.
- As a guest: no `.wdig-after` on What next? and Send feedback.
- A unit case for a `PrivateMember` entry seen by a public-profile member: no Members mark.

### F5 · minor · `templates/where_did_it_go/index.html.twig:29`; `assets/controllers/where_did_it_go_controller.js:46-48`; `assets/controllers/dismiss_hint_controller.js:16-24`
**Problem.** Two accessibility gaps.
- **Filter.** The "Nothing matches" region is `role="status"` but starts `hidden`, and is unhidden with its text already in place. A live region added to the accessibility tree together with its content is usually not announced (NVDA/JAWS with Chrome, VoiceOver). While rows narrow down, nothing is announced at all.
- **Dismiss.** The × removes the element that holds the focused button. In the phone panel and the desktop overlay, focus falls to `<body>`, behind the layer. The next Tab is pulled back by `menu_layer_controller`'s document handler, but the screen reader gets no context.

**Why it matters.** WCAG 4.1.3 (status messages) and 2.4.3 (focus order), on a page and a pointer meant to help players who are lost.

**Fix.**
- Render the status element always, without `hidden`, and empty while rows match. `apply()` writes the no-match text, or optionally a count with a plural key in 6 locales, when the result changes.
- In `dismiss()`: if `this.element.contains(document.activeElement)`, focus the next sensible target before removing it:
  - in `#site-menu`, its first row link;
  - on the Hub, `main h1` with `tabindex="-1"`.

**Test.** A node harness for both controllers (jsdom):
- the status text changes while the element stays in the DOM;
- after a dismiss from a focused ×, `document.activeElement` is not `<body>`.

### F6 · minor · `templates/where_did_it_go/index.html.twig` (whole page); `src/Navigation/NavigationRegistry.php:1306-1321`
**Problem.** The page is copy about ☰ and the web bars, and it renders unchanged in the native app.
- Examples: "☰ › Account › Your data", "Bottom bar › Home", and "Nothing matches … look in ☰ Menu".
- The app's `menu` page (MENU-02b renders every row in `<main>`) and its `help` page both link it (registry `phone: $row(80)`, `helpSheet: 70`).
- Plan §3.5 item 9: "Copy about ☰/tab bar/'top right' renders only under `is_web()`". The modal (resolver) and the pointers (`is_web()`) follow this rule; this page does not.

**Why it matters.** It is a native-ready rule the phase owns. There is no app yet, so it is minor, but it is cheaper to fix now than to rediscover later.

**Fix.** Either:
- under `not is_web()`: keep the old names and their links, and drop the "Now" column and the ☰ wording (one line such as "In the app, use its tab bar and Menu");
- or keep the entry out of the app's Menu and Help (a registry flag the Menu and Help builders read).

**Test.** `WhereDidItGoControllerTest`, with a native UA: 200, and no "☰" or "Bottom bar" in `main`.

### F7 · minor · `assets/styles/app.scss:100`; `assets/styles/_navigation-launch.scss:1-341`
**Problem.** All launch CSS ships in the render-blocking `app.css` of every page, for every visitor and guest, also while `DATE` is null.
- Measured in the dev build: about 5.5 KB minified, about 1.5 KB gzip.
- Of that, the What moved schematics (`wm-*`) are about 3.8 KB / 1.1 KB gz. They are used by one modal, rendered once per player in their lifetime.
- `wdig-*` is used by one noindex page.
- Only `.nav-pointer*` (about 1.1 KB) belongs to the chrome.

**Why it matters.**
- `docs/performance-optimizations.md` treats the critical path carefully (selective Bootstrap imports; page-only entries such as `compete` and `online_rounds`).
- CLAUDE.md keeps page styles in their own entries.
- Every first visit pays for a modal it will never see.

**Fix.**
- Keep `.nav-pointer*` in `app.scss`.
- Inline the `wm-*` rules as a `<style>` in `_navigation_what_moved.html.twig`. It renders once per player ever, base already uses inline critical CSS, and nothing else pays.
- Move `wdig-*` into a `where_did_it_go` Encore entry, loaded through `{% block page_stylesheets %}`.

**Test.** `ChromeWeightBudgetTest`-style check, or a grep test: `app.scss` imports no `wm-`/`wdig-` rules, and the modal template carries its own style.

### F8 · minor · `tests/Controller/WhereDidItGoControllerTest.php:106-138, 140-151`; `assets/controllers/dismiss_hint_controller.js`; `tests/Navigation/ChromeQueryBudgetTest.php`
**Problem.** The tests would stay green if the feature broke in plausible ways.
- **Row links.** `testEveryRowLinksARoute` only checks that each href resolves to *a* route. The builder's final fallback is `/en/menu`, so a regression sending every old name to the Menu page passes it, and `testAnAdminSeesEveryItem` only counts rows. Only five rows are spot-checked.
- **dismiss-hint.** The new "remove every copy" loop has no committed test. Browser checks live in `var/` (LAUNCH-03 17/17), and many other hints depend on this controller.
- **Budget.** `/en/where-did-it-go` has no statement budget row, although the builder claims "No statement".

**Fix.**
- As admin, for each item (match rows by `FormerNavigation` order and id `wdig-<surface>-<n>`): the old-name href's route is in the item's `routes`, or its link entry's route or guest route, or `login` when marked after-sign-in, or `menu` only for items without a route (language) or with a `☰` path.
- A node harness (jsdom) for `dismiss_hint_controller.js`: two copies of one type plus one of another type; one click removes both copies, keeps the other, and POSTs once.
- `ChromeQueryBudgetTest` gains `/en/where-did-it-go` for guest and member at ≤ the `/en/faq` pins of the same roles.

**Test.** Mutation check: change `$href` to always `$this->urlGenerator->generate('menu')`. Today the suite stays green; with the new assertion it fails.

### F9 · minor · `docs/features/navigation/delivery/announcement.md:33, 71, 85`
**Problem.** The post describes the safeguards less precisely than they were built.
- Line 71 says the What moved note starts "Everything is in ☰ Menu, top right, where the ☰ always was". On a computer it starts "Everything is in the ☰ Menu on the left" (logged decision, `lead_desktop`).
- Line 85, the short version for social posts and the newsletter teaser, tells every reader "everything else is in ☰ Menu, top right". On a computer the ☰ is top **left** and the Menu is a docked column or rail.
- Line 71 "The first time you visit after the change, a short 'What moved' note explains it": only accounts created before `DATE` get it, never new players.
- Line 33 "For the first month a short note in the Inbox points there": only players who follow somebody (INB-05).

**Why it matters.** Newsletter readers on a computer are told to look top right. The post is meant to describe what the branch actually built (its own rule, line 3).

**Fix.**
- Line 71: "… starting with 'Everything is in ☰ Menu, top right, where the ☰ always was' (on a computer: 'on the left')"; "if you had an account before the change".
- Line 85: "… on your phone, top right, where the ☰ always was; on a computer it is on the left".
- Line 33: "if you follow other puzzlers".

### F10 · minor · `docs/features/navigation/delivery/launch-watch.md:26, 38, 65, 167, 413`
**Problem.** Three gaps in the watch list.
- **(a) Trial starts.** What moved is an impression, so the free-trial offer modal waits 14 days after it for every account created before `DATE` (decision 57, `ResolveAnnouncementModal::firstModalFor()` cooldown). Accounts that turn 7 days old around the launch (`FreeTrial::MINIMUM_ACCOUNT_AGE_DAYS`) get the offer up to two weeks later. So `trial_source = 'offer_modal'` dips inside L, and row #12's alarm (week < 0.80) can fire because of the design. §2.3 "Known shifts" does not list it.
- **(b) Which day is `DATE`.** §2.1 says `NavigationLaunch::DATE` "should be the same day" as `<D>`, the deploy day + 1. README §11 says "at release set `NavigationLaunch::DATE`". Following §2.1:
  - old players use the new chrome for the rest of the deploy day with no modal and no pointers;
  - accounts registered on the deploy day, after the deploy, never saw the old navigation but get the "What moved" modal the next day.
- **(c) Stale notes.** Q7 and §7 say the LAUNCH-01/03 enum values were "still uncommitted". They landed in `42edd6526` and match (`navigation_what_moved`, `navigation_launch_pointer`, `navigation_inbox_pointer`).

**Why it matters.**
- A false alarm on trials in the launch fortnight.
- An owner following the watch doc sets the wrong day.

**Fix.**
- (a) Add to §2.3: "trial starts from `offer_modal` fall for 14 days after `DATE` (What moved starts the cooldown, decision 57)". Read row #12 on `trial_source <> 'offer_modal'` during L, or move its window to `<D+14>` … `<D+27>`.
- (b) In §2.1: `DATE` = the deploy day, with the deploy in the quietest morning hours so few accounts are registered before it that day; `<D>` = deploy day + 1 for measurement. The two days differ on purpose.
- (c) Drop the "uncommitted" remarks.

## Questions

### Q1 · What moved has no expiry
`NavigationWhatMovedRule` stays eligible forever for accounts created before `DATE` ("a player back after months still finds the bars new", rule docblock and the rule test's "months later" row). The pointers stop after 30 days. That behaviour is reasonable, but it is not in the Decisions log. Also, `docs/features/feature_flags.md` plans to remove `NavigationLaunch::DATE` "after the pointers/badges expire", and that would silently end the modal too.

**Recommendation.**
- Keep no expiry.
- Log it in `state.md` Decisions and in `announcement-modals.md` (DOC-01).
- In the feature-flags row, say that removing the launch service also needs a decision on this modal (keep the date for the rule, or retire the modal on purpose).

### Q2 · Instant Back and the autoshown modal
The Hub opts into the restore cache (GEST-15 `no-preview`), and it is the most likely page for What moved to appear first. Path:
1. What moved opens on the Hub.
2. The player taps "Where did it go?"; Turbo snapshots the Hub with `#announcement-modal.show`, the backdrop and `body.modal-open` in it.
3. Back restores that snapshot.
4. `autoshow-modal` reconnects and calls `show()` again: the "once ever" modal appears a second time, on a doubled backdrop.

GEST-14/15 are not built at this HEAD, so this cannot be a finding yet.

**Recommendation.**
- `autoshow_modal_controller.js` removes or disposes the modal on `turbo:before-cache`, or `disconnect()` hides it without animation.
- GEST-15's Verify gains this path.

### Q3 · Instrument "Where did it go?"
The page carries no `data-nav`, and its filter logs nothing. Which old items players look up is the most direct findability signal the launch has. Today the watch list gets it only from hand-tagged feedback (§4.7) and `search_zero`.

**Recommendation.**
- Add `data-nav="page/none/wdig_<entry id>"` on the old-name links and `page/none/wdig_now_<entry id>` on the "Now" links. These match the pattern, the existing delegated listener picks them up, and no endpoint change is needed.
- Optionally, a filter that leaves nothing sends `search_zero` with `surface: 'wdig'` (`NavClickController::SEARCH_SURFACES` + the script).
- Add an L-row to `launch-watch.md`.

### Q4 · The "Menu micro-label" rule compares against what the bars replaced
Row #8 / §5 decides the micro-label when phone ☰ opens per tab session in week 2 fall below 0.8 × the old ☰ + account-menu toggles. The old toggles were mostly used to reach Home, Puzzles, Profile and the bell, which now sit on the bars. A big drop is the design working, so the rule would fire for the wrong reason.

**Recommendation.** Decide from signals of a *missed* ☰:
- `cant_find` tags whose destination is a Menu-only entry;
- `search_zero` for Menu-only destinations;
- Menu row clicks for entries on no bar per tab session, against the old menus' clicks for the same entries (L3 mapping).

### Q5 · The Menu page does not mark the row a "Now" link opens
`/en/menu#menu-row-<id>` scrolls the row under the top bar (`scroll-padding-top` holds), but nothing marks it among about 50 rows. On phones the row lands at the very top, where the eye does not expect it.

**Recommendation.** A short highlight on `#site-menu[data-surface="page"] li:target` (the active pill colour, fading out; none under reduced motion). It costs nothing outside the `menu` page.

## Checked and fine

- **`DATE` null:**
  - the rule returns false and nothing is claimed (`AnnouncementModalTest` asserts 0 impressions);
  - `pointersActiveFor()` is false;
  - `_launch_pointer` outputs nothing, and the `_menu.html.twig` include trims its whitespace: chrome +0 B (state).
- **Guests:**
  - the resolver never runs a rule for a guest;
  - `NavigationPointers::isActive(null, …)` is false;
  - guest rows of Where did it go? depend on the URL only (`SignInLink`), so the page is `public` with no cookie and no token (tested).
- **Native app:** the resolver steps aside for every modal; the pointer is wrapped in `is_web()` (tested on the Hub and the `menu` page).
- **Boundaries:**
  - Prague midnight of the launch day; an account at or after it (the UTC vs Prague case is tested);
  - +30 days and its last second (tested);
  - the October DST change lies before any plausible date, and `add(P30D)` counts wall-clock days in Prague.
- **Order:**
  - XP reveal → What moved → free trial, through enum order;
  - both launch modals ignore the cooldown, and the free trial waits;
  - the claim happens once (`INSERT … ON CONFLICT`);
  - `help`, `feedback` and `where_did_it_go` exist and are quiet.
- **No new statement:**
  - the rule reads the profile row;
  - pointers read `dismissedNavigationHints` from the profile-row subselect (`LIKE 'navigation\_%'`, parsed with `tryFrom`);
  - Hub and FAQ budgets are pinned with a set date for every role;
  - `hint_type` is a string column, so no migration is needed.
- **Modal markup:**
  - every sprite symbol it uses is on every page where it can render (signed-in set; the bar-free pages are quiet routes or guest-only sign-in pages);
  - no `<img>`, pictures `aria-hidden`, labels from translation keys, five lines;
  - the Level 3 query comes through `nav-desktop`;
  - the 256 px phone picture fits exactly at 320 px.
- **FormerNavigation:**
  - 82 items over 6 surfaces map to exactly the 66 baseline routes;
  - every entry and link entry exists;
  - old label keys are in all 6 catalogues;
  - the admin key keeps English names;
  - moderator, time-verifier and admin rows show for those viewers only (tested);
  - Referral is marked Members for non-members only (tested).
- **Filter:**
  - hidden without JS, every row shown;
  - `data-search` is folded by `SearchText::fold()` and the input by `foldSearchText()`;
  - Enter is kept in the box;
  - `disconnect()` restores every row; nothing is fetched or stored.
- **dismiss-hint:**
  - the other users (sell-swap checklist, marketplace ×2, feature requests, `CountryNudge`, getting-started card, Inbox pointer) render one element per type per page, so their behaviour does not change;
  - `~=` matches elements with several controllers;
  - `menu_order.js` moves only `.menu-group` nodes with `insertBefore`, so the line stays first in the phone panel;
  - the handler is idempotent on a second POST.
- **Pointer contrast:** text 6.3:1, link 4.5:1 on `--nav-active-pill`; the × is 44 px.
- **Translations:** 30 keys × 6 locales, as listed under Method; the removed `where_did_it_go.placeholder` is referenced nowhere.
- **LAUNCH-04:**
  - every README §4 surface is mapped (§4.14 left out, logged), plus D5, D6 and §8;
  - the profile tab list matches `PlayerHeaderTab`;
  - no names, numbers or ids;
  - the "You said, we changed" template and the channel checklist are present.
- **LAUNCH-05:**
  - all 17 §8 metrics have a source, query and alarm;
  - the SQL columns and values exist:
    - `tracked_at`, `puzzling_type`, `seconds_to_solve`, `competition_series_id`;
    - `trial_started_at` and the three `trial_source` values;
    - `email_type = 'feedback'` from the template basename, with `status = 'sent'`;
    - `feature_request.created_at`, `player_activity_day.day`, `competition_round.starts_at`;
    - `player_modal_impression.displayed_at` / `seen_at` / `modal`, `dismissed_hint.dismissed_at`;
  - the LogQL kinds and fields match `NavClickController`, and `trial_start` matches `StartFreeTrialController`;
  - the GA4 paths match the routes (Czech `/clenstvi/` with its slash; messages at `/{_locale}/messages`);
  - the Live component prefix is `/{_locale}/_components`;
  - privacy grep is clean.
- **Project rules:**
  - single-action controller;
  - `WhereDidItGoBuilder`, `NavigationWhatMovedRule` and `NavigationPointers` are readonly, with no per-request state;
  - the clock comes only through `NavigationLaunch`.
