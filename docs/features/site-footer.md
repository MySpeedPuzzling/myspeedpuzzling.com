# Site footer

Redesigned on 2026-10-02. Before, it was one wrap of 26 links on the same pale grey as the content cards. The proposal, with a live demo and its sources, is at https://claude.ai/artifact/EbKodaYprinZ99Aiu9Acfi.

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
| Four columns: Discover, Track, Learn, MySpeedPuzzling | everyone | `_footer.html.twig` |
| Brand block: e-mail logo (white halo for dark backgrounds), tagline, Instagram, GitHub | everyone | `_footer.html.twig` |
| Popular searches: SEO hub links in four `<details>` groups | guests | `_footer.html.twig`, pinned by `FooterPopularSearchesTest` |
| Bottom bar: ©, legal, language switcher, credit line | everyone | `_footer.html.twig` |
| "The monthly news e-mail is off. Turn it on" | players with `newsletterEnabled = false` | `_footer.html.twig` → `newsletter_turn_on` |

### Popular searches

- **What it links:** real catalogue pages only, never `?brand=` / `?pieces=` / `?tag=` filter URLs (see the SEO docs). Leaderboard, Players and Latest puzzle times sit in the Discover column, so each page is linked from the footer once (`SiteFooterTest`).
- **How the groups open:** on phones the groups are folded. From `md` up they show open without JS via `::details-content { content-visibility: visible }`. A browser without that pseudo-element keeps them folded and clickable.

### Newsletter states

- **Players** are subscribed by default (opt-out, `Player::$newsletterEnabled`). A subscribed player sees no newsletter element at all.
- **A player who switched it off** did so on purpose. They get one quiet line in the bottom bar and never a banner. "Turn it on" posts `TurnOnNewsletter`, which sets the flag and pushes to Listmonk (an explicit user action, so it may re-confirm a Listmonk unsubscribe). The page redirects back with a flash message.
- **Auth pages** empty `footer_newsletter`: a sign-in page must hold no other text input, or iOS Password AutoFill misfires.

### Language switcher

The language list is `_language_menu.html.twig`, shared with the topbar. On phones the © line and the switcher share the first row of the bar, and the legal links go below them.

## Accessibility

- **Contrast on #2b3445:** links #c5ccd8 reach 7.7:1, muted text #98a2b3 reaches 4.9:1, and the light coral #ff8384 reaches 5.3:1. All pass WCAG AA.
- **Target size:** footer links are at least 24 px tall (WCAG 2.5.8).
- **Landmarks:** the link columns are a labelled `<nav>`, and so is Popular searches.

## Translations

The footer is on every page, so its strings are in all six locales. The keys are `footer.*` and `newsletter.footer.*`.
