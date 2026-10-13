# Site footer

Redesigned on 2026-10-02. Before, it was one wrap of 26 links on the same pale grey as the content cards. The proposal, with a live demo and its sources, is at https://claude.ai/artifact/EbKodaYprinZ99Aiu9Acfi.

Its link columns were rebuilt by the navigation redesign (FOOT-01, `docs/features/navigation/README.md` §4.11): they follow the new places and come from the navigation registry.

## Decisions (Jan, 2026-10-02)

- **Dark, structured footer (option B), the same for everyone.** The background is the theme's `$gray-900` (#2b3445), so the page visibly ends.
- **Straight top edge.** The "puzzle-tab" bump was rejected; don't re-propose it.
- **The "New" badges stay** next to their links.
- **Phones show the brand block after the link columns.** It is after them in the markup; from `lg` up, CSS grid moves it to the left column.
- **Players get the same columns as guests.** Option C, a slim strip for players, was not chosen.

## Structure

| Part | Who sees it | Where |
|---|---|---|
| Newsletter band (form, double opt-in) | guests | `_footer_newsletter.html.twig`, inside `{% block footer_newsletter %}` in `base.html.twig` |
| Four columns: Puzzles, Community, Learn, MySpeedPuzzling | everyone | `_footer.html.twig`, from the navigation registry (below) |
| Brand block: e-mail logo (white halo for dark backgrounds), tagline, Instagram, GitHub | everyone | `_footer.html.twig` |
| Popular searches: SEO hub links in four `<details>` groups | guests | `_footer.html.twig`, pinned by `FooterPopularSearchesTest` |
| Bottom bar: ©, All pages, legal, language switcher, credit line | everyone | `_footer.html.twig` |
| "The monthly news e-mail is off. Turn it on" | players with `newsletterEnabled = false` | `_footer.html.twig` → `newsletter_turn_on` |

### The columns (navigation FOOT-01)

| Column | Links |
|---|---|
| Puzzles | All puzzles · Marketplace · What next? · Add · Stopwatch · Scan several boxes |
| Community | Community · Leaderboards · Puzzlers · Compare · Pairs & teams · Events · Latest times |
| Learn | Getting started · How progress works · All achievements · FAQ · Guides · Difficulty & ratings · Where did it go? |
| MySpeedPuzzling | Puzzle tracker app · World championship · Membership · Ideas & feature requests · Send feedback · For organizers · For developers |

- **From the registry.** Each link is a `NavigationRegistry` entry with a `FooterPlacement` (column, position, "New", `nofollow`, its own audience, the `data-nav` it had before). `FooterBuilder` (Twig `nav_footer()`) builds the columns; the label is the entry's label, the same name the Menu, the search and the bars use. The human-readable list is the "Footer" table of `docs/navigation.md`.
- **The same index for everybody.** Every route the footer linked before stays linked (README §4.11, NAV-12): a page behind sign-in (Add, Stopwatch, Scan several boxes, Compare, Pairs & teams, Membership) stays linked for guests too, with `rel="nofollow"` in guests' HTML only (crawlers never see a signed-in page - SRCH+FOOT review Q2 saved a player's page 90 B), and a link always goes to the entry's own route (the guests' Add is the Add page here; the Add tab and the Menu lead guests to its explainer). Only the XP pages follow the XP gate, and "Where did it go?" is for signed-in players only (R2).
- **`data-nav`.** Each link keeps the P0-03 value it had before the redesign (`footer/discover/puzzles`, `footer/track/compare`, …), so its clicks stay comparable across the launch; a link new in FOOT-01 is `footer/<column>/<entry id>`.
- **"New" badges** (`templates/navigation/_new_badge.html.twig`, one class `.nav-new`): the eight of 2026-10-02 plus the Community landing. They show while `NavigationLaunch::DATE` is not set and until 14 days after it (navigation decision 1).
- **Send feedback** opens the Help sheet (`help_path()`, `help_opener_attrs()`), the same as the Menu row and the end-of-page line; guests are asked to sign in there. `feedback` stays the sheet's form endpoint.
- **All pages** links the `menu` page; with JavaScript it opens the Menu instead - the phone panel, the desktop overlay or the docked column (`assets/navigation/menu_links.js`).

### Popular searches

- **What it links:** real catalogue pages only, never `?brand=` / `?pieces=` / `?tag=` filter URLs (see the SEO docs). Leaderboards, Puzzlers and Latest times sit in the Community column, so each page is linked from the footer once (`SiteFooterTest`). They stay hand-written; their routes are registered "not in Menu: footer SEO hub links".
- **How the groups open:** on phones the groups are folded. From 768 px up they show open without JS via `::details-content { content-visibility: visible }`. A browser without that pseudo-element keeps them folded and clickable.

### Newsletter states

- **Players** are subscribed when they ticked the newsletter box at registration (`Player::$newsletterEnabled`; players registered before 2026-10-12 count as subscribed). A subscribed player sees no newsletter element at all.
- **A player who switched it off** did so on purpose. They get one quiet line in the bottom bar and never a banner. "Turn it on" posts `TurnOnNewsletter`, which sets the flag and pushes to Listmonk (an explicit user action, so it may re-confirm a Listmonk unsubscribe). The page redirects back with a flash message.
- **Auth pages** empty `footer_newsletter`: a sign-in page must hold no other text input, or iOS Password AutoFill misfires.

### Language switcher

The language list is `_language_menu.html.twig`, shared with the auth pages' header and the account menu. On phones the © line and the switcher share the first row of the bar, and All pages with the legal links go below them.

## Accessibility

- **Contrast on #2b3445:** links #c5ccd8 reach 7.7:1, muted text #98a2b3 reaches 4.9:1, and the light coral #ff8384 reaches 5.3:1. The "New" badges and the newsletter's Subscribe button are white on the deep coral #d63c42 (4.6:1): white on the theme's `$primary` (#fe696a) is only 2.8:1. All pass WCAG AA (axe-core, navigation NAV-11 / FOOT-01).
- **Large text:** the layout steps are in `em`, so they follow the browser's text size: with a large default font the footer keeps the narrower layout longer (one column below 20em, two below 48em, the brand column from 62em) instead of squeezing four columns; a word longer than its column breaks rather than widening the page.
- **Target size:** footer links are at least 24 px tall (WCAG 2.5.8).
- **Landmarks:** the link columns are a labelled `<nav>`, and so is Popular searches.

## Translations

The footer is on every page, so its strings are in all six locales. The keys are `footer.*` and `newsletter.footer.*`; the column links use their registry labels (`nav.row.*`, `menu.*`, …), the headings the places' labels (`nav.row.puzzles_place`, `community_page.title`) and `footer.column.learn`. The old `footer.column.discover` / `footer.column.track` and link keys stay for "Where did it go?" and the page search's old names.
