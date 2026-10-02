# Player header (issue #224)

Status: **built 2026-10-03**, variant "A + B" of the design canvas.

- Issue: https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/224
- Design canvas: https://claude.ai/artifact/M45MsP7nY6Hmm7HMDr6NQU (today, variant A on phones, its edge cases,
  alternatives B and C, A on desktop)
- Reference: the puzzle page header (`docs/features/puzzle-leaderboard-chart.md` "Update 2026-10-02",
  canvas https://claude.ai/artifact/Pwno1G7wGRf3VvecqLnbbD)

## What it replaced

A sticky one-line breadcrumb (`avatar · tier icon · name (cut at 145 px) · #CODE / [Profile ▾]`) whose dropdown was
both the page switcher and the action menu (Favorite, Compare, Block hidden in it), plus three rows on the profile page
alone: the membership badge, "Send message", and the owner's share-link input. Used by 24 templates.

## Jan's decisions (2026-10-03)

| | Decision |
|---|---|
| Layout | **A + B**: the header of variant A, the bar of variant B (it carries the page tabs on phones too) |
| Tab labels | short: Profile · Statistics · Calendar · Library · Favorites (`player_header.tabs.*`) |
| Country | the flag on the avatar only - the meta line is just `#CODE` |
| Tier chip | opted out of rankings = **no chip**; not ranked yet = "No tier yet" (members only); see the rules below |
| Owner-only pages | a one-line strip instead of the header (reasoning below) |
| "Tag us @MySpeedPuzzling" | dropped with the share input |
| Favorite button | **filled while the player is in your favorites** (a toggle that shows its state), outline otherwise |
| Repeated page titles | "Puzzle library of X" and "Activity calendar" are visually hidden (kept as the H1 for search engines and screen readers) - the header and its tab say it |
| Favorites | **personal**: the tab and the page exist only for the owner, and the page also lists who has *you* in favorites (see "Favorites page") |

**Why the strip on owner-only pages:** the header answers *whose page is this*, *what can I do with this person*, and
*where else can I go*. On the owner's own forms and histories (edit a collection, sell/swap settings, export …)
the answer to the first is always "mine", the actions (Share, Edit profile) have nothing to do with the form, and
navigation is the back button's job. The full header would push the form ~200 px down on a phone and bring a compact
bar with it while scrolling a form. Visitors never reach these pages (403/404). The strip keeps the orientation for
44 px: `avatar · Name › Library`, both links.

## The header

```
┌ avatar ┐  Name (20 px; H1 where the page has none)
│ + flag │  #CODE                                   (14 px, $lb-secondary)
└────────┘  [◆ Expert] [♥ Member] [🕶 Private profile]
[ ★ Favorite ][ ✉ Message ][⋯]          own: [ ⇪ Share ][ ✎ Edit profile ][⋯]
 Profile   Statistics   Calendar   Library   (Favorites - owner only)
```

- Avatar = `_player_avatar.html.twig` size `xl` (64 px phones, 72 px tablets, 96 px desktop): photo with the flag on
  its corner, the round flag without a photo, the initial without either, incognito for a hidden profile.
- From 768 px the actions sit right of the name, unstretched; from 992 px avatar 96 and name 28 px.
- Buttons are 44 px; a label too long for 320 px (German) moves the next button to its own line instead of being cut.
- Tabs fill the row when they fit and scroll sideways otherwise, the hidden side fading; the current tab is scrolled
  into view (`scroll_tabs_controller.js`). Statistics carries `rel="nofollow"` (SEO plan 2026-10).
- **Compact bar**: avatar · name · `#CODE` · ⋯ with the tabs on a row under them (phones) or between them (992 px+).
  It is the puzzle page's bar, generalised: `compact_bar_controller.js`, `.compact-bar*` (`_compact-bar.scss`),
  `--compact-bar-height`. Its `head` target is the tab row (the actions row on a hidden profile).

### Who sees what

`isHidden` = `player.isPrivate and not own` (`isPrivate` already means *hidden from this viewer* -
`PrivateProfileAccess` decides it in `GetPlayerProfile`). A player the viewer blocked is a 404 before the header.

| Part | Own profile | Someone else's | Hidden private profile | Guest |
|---|---|---|---|---|
| Name | real name or `#CODE` | same | "Hidden Puzzler" | same rules |
| Chips | tier rules · Member · Private profile (if private) | tier rules · Member | none | locked tier · Member |
| Buttons | Share · Edit profile | Favorite · Message (if `canMessage`) | Favorite · Message (if `canMessage`) | Favorite (→ sign-in) |
| ⋯ menu | Share profile, Edit profile, Who can see my profile (if private) / Export data, Referral Program | Add to/Remove from favorites, Send message (if `canMessage`) / Compare times (not on private), Share profile / Block player | same | Add to favorites, Compare times, Share profile |
| Tabs | 5 | 4 (no Favorites) | none | 4 |

`canMessage` = signed in, not own, and `allowDirectMessages` or an accepted conversation (`HasExistingConversation`,
queried only when DMs are off). It moved from `PlayerProfileController` into the component, so Message works on every
page with the header.

### Tier chip (`PlayerHeader::tierChip()`, 500 pieces)

| Case | Chip |
|---|---|
| hidden private profile, or the player opted out of rankings | none |
| viewer is not a member (or a guest) | grey lock + "Skill tier", opens `#membersExclusiveModal` - not for a private profile, which has no tiers |
| member viewer, the player has a tier | the tier icon in its colour on a tint of it + the tier's name |
| member viewer, no tier yet | grey "No tier yet" - not for a private profile |

The skill query runs only for a member viewer (it used to run for guests and non-members too, who only saw a lock).

### Pages

| Template | `<twig:PlayerHeader …>` |
|---|---|
| `player_profile` | `tab="profile" :heading="true"` (the name is the page's H1) |
| `player_statistics` | `tab="statistics" :heading="true"` |
| `player_favorite_puzzlers` | `tab="favorites" :heading="true"` |
| `activity_calendar`, `puzzle_library` | `tab="calendar"` / `tab="library"` (their own H1, visually hidden) |
| list views: `collections/detail`, `wishlist/detail`, `unsolved_puzzles/detail`, `solved_puzzles/detail`, `sell-swap/detail`, `lend-borrow/detail`, `lend-borrow/private` | `tab="library"` (`aria-current="true"`, not `"page"`) |
| owner-only: `collections/{create,edit,edit_system,edit-item-comment}`, `wishlist/edit`, `unsolved_puzzles/edit`, `solved_puzzles/edit`, `sell-swap/{edit_settings,history}`, `lend-borrow/{edit_settings,history}` | `tab="library" :compact="true"` (strip) |
| `export-puzzler-data` | `:compact="true"` (strip without a section) |

`tab` is validated into `Value\PlayerHeaderTab` (`from()`: a typo fails the page), which owns each tab's route,
label key, owner-only flag and nofollow.

## Code

- `src/Component/PlayerHeader.php`, `src/Value/PlayerHeaderTab.php`
- `templates/components/PlayerHeader.html.twig` (header, bar, strip, block modal - rendered once),
  `templates/player/_header_actions_menu.html.twig` (⋯, in header and bar: no ids, `data-nosnippet`),
  `templates/player/_header_tabs.html.twig`, `templates/player/_share_profile_button.html.twig`
- `assets/controllers/share_link_controller.js`: the share sheet, otherwise copy + "Link copied" for 2 s (a menu stays
  open 1.2 s to show it). The shared title follows the player's own privacy setting, never the viewer.
- `assets/controllers/scroll_tabs_controller.js`, `assets/controllers/compact_bar_controller.js` (was
  `puzzle_bar_controller.js`)
- `assets/styles/_player-header.scss`, `assets/styles/_compact-bar.scss`, avatar `xl` in `_leaderboard.scss`
- Favorite/Remove links carry `?return=` (the current page): `ToggleFavoritePlayerController` goes back there
  (`ReturnUrl::tryFrom()`), otherwise to the profile as before.
- `PlayerProfileController` no longer runs `GetRanking::allForPlayer()`, `GetFavoritePlayers::forPlayerId()` and
  `GetTags::allGroupedPerPuzzle()` - the template had not used them.
- Tests: `tests/Controller/PlayerHeaderTest.php`; `PlayerBlocklistTest` counts the block action twice (header + bar).

## Favorites page (personal since 2026-10-03)

Jan: whom you follow is yours alone, and the page also shows who has *you* in favorites.

- `PlayerFavoritePuzzlersController` (`player_favorite_puzzlers`, `/en/player-favorites/{playerId}`): only the owner
  gets a 200 (`noindex, nofollow`, personal title); guests and other players get a **302** to that player's profile
  (old links and search results still land somewhere; never a 301 - the answer depends on the viewer). The Favorites
  tab exists only on one's own pages (`PlayerHeaderTab::isOwnerOnly()`).
- "Your favorites" = `GetFavoritePlayers::forPlayerId()`, now ordered by name, hidden private players last by code
  (this order applies to every caller). A followed private player who does not allow you stays listed by code.
- "Who has you in favorites" = `GetFavoritePlayers::followersOf()` → `Results\PlayerFollowers` (players + a count):
  players you blocked and players who blocked you are left out completely; a private follower hidden from you is
  only **counted** ("+ 2 private puzzlers") - the statement returns no id, code or name for them. Served by the GIN
  index on `favorite_players`.
- Rows: `templates/favorite_puzzlers/_puzzler.html.twig` (avatar with the flag, name, `#CODE`; a star on followers you
  have in favorites), `assets/styles/_favorite-puzzlers.scss`; two columns from 768 px.
- Tests: `PlayerFavoritePuzzlersControllerTest`, `Query/GetFavoritePlayersTest`, new methods in
  `BlocklistCanaryTest` and `PrivateProfileCanaryTest`.
