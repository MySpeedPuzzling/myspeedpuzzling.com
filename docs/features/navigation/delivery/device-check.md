# Device check (QA-06, owner)

**Purpose.** The scripted checks (QA-01 screenshots, QA-02 WebDriver behaviour checks) run in emulated Chromium and cannot show what only real phones show: Safari's floating toolbar and its colour extension, the on-screen keyboard, home indicator and safe areas, the system back gestures and their previews, real haptics, native-dialog suppression after a Back, the installed apps, low-end GPUs and large system text. This checklist covers those on real devices. It merges `research/bar-design.md` §6, `research/gestures-native-feel.md` §8 and `research/platform-native-feel.md` §10 (DC1–DC16; the research files are local only, not in git) with the extras of requirement QA-06. Where the research and the README differ, **the README wins** and the row says which section decided it.

## How to fill it

- **Who:** the owner (or a tester the owner asks) with real devices. Nothing here runs in automation.
- **Build under test** (fill in before starting):
  - host: ________ · commit: ________ · date: ________
  - `assets/instant_back.js`: `INSTANT_BACK` = ____ · `INSTANT_BACK_IOS` = ____ (decision 66: true only once GEST-13 is verified; the owner may flip it after DC1/DC7)
  - `NavigationLaunch::DATE`: ____ (while `null` there is no "What moved" modal and no launch pointers - expected, not a failure)
  - Use a build the phones can reach over HTTPS: the installed-app tables (C, E) need it.
- **Test accounts, by role only** (no credentials in this file): **guest** (signed out or a private tab), **player** (signed in, no membership), **member** (active membership; scanner, multiscan, custom collections), **organiser** (organises an event with live results entry; one online test round if available), **moderator** (at least one waiting item in a moderation queue, so the ☰ dot shows).
- **Test data to prepare:** a stopwatch you may delete (Menu › Add & track › Stopwatch); a second test player you may block and unblock again (Menu › Account › Blocked players); a throwaway API token you may revoke (search "API tokens" in the Menu); a puzzle box whose barcode MSP knows and one barcode it does not; a member account with more than 50 results (Results "Show more") and a Hub with "Show more"; the native-dialog repro page of Appendix A hosted where the phones can reach it.
- **Record per device:** model, OS version, browser and version, Safari tab layout (Settings › Apps › Safari › Tabs) or Chrome address bar position (Chrome › Settings › Address bar), Android navigation mode (gesture navigation), page language.
- **Settings used by the rows:**
  - Reduced motion: iOS Settings › Accessibility › Motion › Reduce Motion; Android "Remove animations" (Pixel) / "Reduce animations" (Samsung) in the accessibility settings. **Every phone table has its reduced-motion rows (marked RM): run those once with the setting on.**
  - iOS System Haptics: Settings › Sounds & Haptics › System Haptics.
  - Android: ringer silent; Touch feedback off (Sound & vibration › Vibration & haptics); battery saver on.
  - MSP's own switch: Menu › Account › Settings › Preferences › **Vibration** (shown only on Android).
  - Large text: iOS Settings › Accessibility › Display & Text Size › Larger Text (Larger Accessibility Sizes, maximum); Android font size maximum (or 200 %) and Chrome › Settings › Accessibility › Text scaling 200 %.
  - Slow connection (DC10): remote DevTools throttling, iOS Network Link Conditioner, or a weak mobile signal.
  - Reading `data-os` (table I): a remote inspector (Safari › Develop on a Mac for iPhone/iPad, `chrome://inspect` for Android), console `document.documentElement.dataset.os`. Without a computer the Settings › Preferences "Vibration" switch is a visible proxy: it is shown only for `android`.
- **Result column:** `pass` (as expected) · `fail` (differs) · `odd` (works but feels wrong) · `n/a` · `?` (could not tell). Leave it blank until run.
- **Reporting a failure:** write `fail` or `odd`, one line in notes, and keep a screenshot or screen recording named `<table>-<row>` (for example `A1-28.mp4`) with: device model, OS version, browser and version, tab layout / address bar position, page language, role, URL and the local time (the time finds the matching `navigation` channel lines in Loki). **Keep recordings outside the repository** - the repo is public and recordings show accounts.
- **Source tags** in brackets: `bar §6` = bar-design §6; `V1`–`V17` = gestures-native-feel §8; `DC1`–`DC16` = platform-native-feel §10; `G1`–`G17` = README §4.13; `extra` = QA-06's own additions; "decision n" = `state.md` decisions log or `implementation-plan.md` §8.

## A1 · iPhone · iOS 27 · Safari · Compact tab layout (full run)

Run the whole table on the iPhone with the newer iOS; A2 on the same phone, A3 and A4 on the other major version. With only one iPhone, run A1 and A2 on it and write its real version into the headings; mark the missing version's tables `n/a`. Language English unless a row says otherwise.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [bar §6] As a player, open the Hub. | Top bar: logo far left · search field (scan button inside) · ? · Inbox · ☰ last at the right edge. Bottom bar: Home · Puzzles · Add · Community · Profile, every label visible, Add a coral tile, Profile shows your avatar, Home active (filled icon on a light coral pill). Solid white bars with a hairline, nothing translucent (README D15). | | |
| **2** [bar §6] Scroll All puzzles down and up, slowly and fast, watching the strip around Safari's floating tab bar. | The area under and around Safari's toolbar stays white (Safari extends MSP's white bar); no page content or colour band shows between MSP's bar and Safari's toolbar; no flicker. | | |
| **3** [bar §6] Keep scrolling while Safari's toolbar shrinks and grows. | MSP's bottom bar stays fixed at the bottom edge the whole time: never hides, never stops halfway, no jump or gap when Safari's toolbar changes size (README §4.2: `position: fixed`, never sticky). | | |
| **4** [README §4.1] Scroll down past the first screen, then a little up. | The top bar slides away on scroll down (~0.2 s) and comes back on scroll up (~0.3 s), with a soft shadow once scrolled; the status-bar strip stays white, no colour flip when the bar hides. | | |
| **5** [bar §6] Open and close, one after the other: the Menu (☰), the Help sheet (?), a player card (tap a name on a leaderboard), the search view (tap the field), MSP's confirm dialog (delete a stopwatch, then Cancel). | While open, the scrim or panel covers the page cleanly, including the bands next to Safari's toolbar and the status bar. After closing, no tint or transparent band is left and Safari's toolbar area is white again (README §4.2: closed overlays are `display: none`). | | |
| **6** [bar §6] Look at the bottom bar's lower edge. | The tab labels sit fully above the home indicator; the white bar continues under it. | | |
| **7** [V12] Swipe sideways and swipe up on MSP's bottom bar, just above Safari's tab bar. | Only MSP reacts (a tab tap at most); Safari does not switch tabs or open its tab overview. Record what Safari does. | | |
| **8** [README D16, §4.1] Turn the phone to landscape on the Hub and on a puzzle page. | The phone bars stay (no desktop top bar, no rail); with the page this short, the top bar scrolls away with the page instead of floating. | | |
| **9** [G7] On a long page scrolled far down, tap the top edge of the screen, then tap it again. | The page scrolls to the top and the top bar is back (the document stays the scroller). | | |
| **10** [V17, bar §6] Tap into the top-bar search, the add form's puzzle field and the Help sheet's feedback box; close the keyboard each time (Done, or tap outside). Repeat 3×. | The bottom bar is hidden while the keyboard is open and back at the very bottom after it closes: no drift upwards, no gap, never floating mid-screen. | | |
| **11** [extra, README §5, G5] Tap into every input you meet: top-bar search, the Menu panel's search, the Help feedback box, the add form (puzzle picker, time fields, date, comment), Results search, the "Where did it go?" filter, and signed out the sign-in e-mail and 6-digit code fields. | Safari never zooms the page when an input gets focus. | | |
| **12** [extra, README §4.5] Signed in as a player, tap ? in the top bar. | The Help sheet opens with the feedback box on top. Record **1 tap** (keyboard opened by itself with the cursor in the box) or **2 taps** (one more tap on the box was needed). Both are accepted; more than one extra tap is a fail. | | |
| **13** [G5, V9] Double-tap quickly on a tab, on "Show more" (Hub) and on the stopwatch buttons; pinch the page and pinch over the top and bottom bars; pinch over a chart (Profile › Statistics). | No double-tap zoom anywhere; pinch zooms the page everywhere, including over the bars. Over a chart: a known exception (charts take the touch, `canvas { touch-action: pan-y }`, decision 59) - record what happens. | | |
| **14** [README §4.12] Tap ☰. | A full-screen Menu panel rises with a fade (~0.25 s): sticky header × · Menu · logo · search field; it ends above the bottom bar, which stays visible; the page behind does not scroll. | | |
| **15** [extra, README §4.2, G1 (d)] With the Menu open, tap the Community tab; then swipe back from the left edge. | The Menu closes and Community opens; one Back returns to the page the Menu was opened on (never to the Menu, never the same page twice). | | |
| **16** [extra, README §4.2] On a Puzzles page open ☰ and tap the Puzzles tab. | Only the Menu closes; the page stays where it was. | | |
| **17** [README §4.12] In the open Menu scroll to the end (Log out visible) and keep swiping up and down. | The panel scrolls by itself; the page behind never moves; no page bounce or pull-to-refresh starts. | | |
| **18** [README §4.12] Type `export` in the Menu's search field; then Back. | "Pages & settings" results appear inside the panel (Your data first); no second layer opens; one Back closes the Menu. | | |
| **19** [V4, extra, G1] Open each layer and close it with an edge swipe from the left: Menu panel, Help sheet, player card, search view, an achievement (Profile › Achievements › a medal), a result detail (tap a time on a puzzle's leaderboard). | Only the layer closes; the URL stays the same; no reload (scroll position and anything typed on the page stay); the next Back leaves the page. | | |
| **20** [V4] Repeat row 19 with Safari's back button. | As row 19. | | |
| **21** [G1 (d)] Open the Menu, tap Results, then Back. Open search, tap a puzzle result, then Back. | Each Back returns in one step to the page the layer was opened on. | | |
| **22** [V5, G2] On the Help sheet, the player card and an achievement: drag down from the handle; drag the content while it is at its top; scroll the content down and drag (it must scroll); pull slowly past half the height; flick down fast and short; pull a little and let go. | The sheet follows the finger and closes when pulled more than ~100 px, released past half its height, or flicked; a short slow pull springs back (~0.2 s); a scrolled body scrolls instead of closing; × is always visible. Tune note for Q7 if a threshold feels wrong. | | |
| **23** [V6, README §4.5] Type text in the Help feedback box. Close by swiping down and reopen; close by tapping the scrim and reopen; close with Back and reopen. Then start a drag inside the box. | The text is back every time; a drag that starts inside the box never moves the sheet. | | |
| **24** [README §4.5] Send a short feedback ("device check, please ignore"), then close the sheet. | Confirmation inside the sheet; afterwards the page responds normally without a reload. | | |
| **25** [V7, G3] Hub scrolled down → tap Home. At the top of the Hub → tap Home. On a puzzle page → tap Puzzles. On Add with something typed → tap Add. With a player card or the search view open → tap the current place's tab. | Smooth scroll to the top and the top bar returns; then the feed refreshes in place (no page load); then the Puzzles start page (last used section) opens; Add keeps the typed input; with a layer open the tab only closes the layer. | | |
| **26** [V8, G4] Touch and hold briefly: tabs, ☰ / ? / Inbox, Menu rows, Help sheet rows, chips (Community, Puzzles sections), puzzle cards, link rows. | A light coral pressed layer shows as the finger lands and fades ~0.15 s after release; no grey iOS tap-highlight box; no ripple on iPhone (README §4.13 table). The tab pill moves to the tapped tab before the page arrives. The research's "without the `touchstart` listener" half needs a code change and is not part of this check. | | |
| **27** [G4, decision log "GEST review F2"] Press the Add tab. | A visibly darker press on the coral tile. | | |
| **28** [DC1, G13] Open the Menu and close it with an edge swipe, then delete a stopwatch. Repeat with: block the test player (their profile › ⋯ › Block; unblock afterwards), revoke the throwaway API token. Repeat once with the result-detail modal instead of the Menu. | MSP's in-page dialog appears every time (Cancel left, the action right); Cancel keeps the item, confirming removes it. Never a native Safari dialog, never "nothing happens". | | |
| **29** [DC1] Repeat row 28 after a Back to a restored page (Hub → puzzle page → Back → Menu → edge swipe → delete). | As row 28. | | |
| **30** [DC1] Appendix A repro page in Safari: tap "1. pushState", swipe back, tap "3a. alert()" and "3b. confirm()". | Record only: dialog shown yes/no and the page's "returned … after … ms" line. A dialog = Safari no longer suppresses; no dialog and under ~50 ms = still suppressed. Answers Q1. | | |
| **31** [DC2, G10] Menu › Account › Settings and the e-mail preferences page: tap a switch; tap its label; touch and hold the thumb ~0.2 s and drag it across. Same with the add form's First try switch. | A light tap is felt each time it toggles; the switch looks exactly as before (size, colours, position) and toggles normally. | | |
| **32** [DC2] System Haptics off, repeat row 31. Turn it back on. | No tap is felt; the switches still toggle. | | |
| **33** [G10, README §4.13] Open Settings › Preferences; as a member scan a box and save a time. | No "Vibration" switch on iPhone; no haptics anywhere except switches. | | |
| **34** [DC11, G16] Make a tab label not fit: French (`/fr/…`), then raise the text size (row 49 settings) or iPhone Display Zoom until a tab shows only its icon. Hold that tab 0.5 s without moving. | A bubble with the full label appears just above the tab; no iOS link preview or menu; releasing does not navigate; the bubble goes on release or after ~1.5 s; a short tap navigates. A tab whose label is fully shown gets no bubble. | | |
| **35** [DC9, G14] Home, signed in: swipe Favorites ↔ Everyone; start a vertical scroll diagonally; swipe from the left screen edge; tap the two tabs. | The panes snap fully; the selected tab follows once a pane settles and does not flip back while the finger is still down (Platform feel F1); vertical scrolling never switches; the edge swipe is Back, not a feed switch; a tab tap scrolls to its pane; the page below does not jump; the feeds run edge to edge (F2); the "new" count does not flash. | | |
| **36** [DC9] VoiceOver on: switch the feeds with the tabs. | Both tabs are announced and work; the hidden feed is not read. | | |
| **37** [DC10, G15] On fast Wi-Fi tap puzzle cards in All puzzles, My library and search results; tap a leaderboard row. | The card's thumbnail grows into the puzzle page's box image (~0.3 s), never stretched; a table row just opens the page. | | |
| **38** [DC10] Slow the connection and tap cards again; then Back. | No morph when the page arrives late (plain swap); Back never morphs. | | |
| **39** [DC4, G12] Switch places quickly 10× (Home, Puzzles, Community, Profile, …). | Instant switches on iPhone (no fade); the bars stand still; no tap is lost. | | |
| **40** [DC6, V1, G9] Scroll All puzzles far down, open a puzzle, swipe back. Repeat for: the Hub after "Show more", a leaderboard scrolled down, your Results after "Show more", the events agenda. | **`INSTANT_BACK_IOS` true:** instant, same scroll position, the extra rows still there, no white flash or reload, no second animation after Safari's own swipe, no smooth-scroll animation; filter selects and charts appear once. **False:** one full reload (today's behaviour) - record where it lands. | | |
| **41** [DC6] Repeat row 40 with Safari's back button. | As row 40. | | |
| **42** [DC6, G9] Open a puzzle from All puzzles, lock the phone for more than 10 minutes, then Back. | A fresh page load. Landing at the top is expected, except Results, which returns to your row (README §4.7). | | |
| **43** [G9] Back to a page that is not a browse page: a puzzle page, the Inbox, Settings. | A normal full load, as before the release (README §4.13 G9: only browse pages opt in). | | |
| **44** [G9, decision 65] On another player's Overview tap Favorite (☆), open one of their puzzles, Back. On the events agenda follow an event, open it, Back. | The page shows the new state (star filled, event followed), never the old copy. | | |
| **45** [G9, G13] After a Back that came back instantly (row 40), tap a one-tap action on that page: follow an event on the agenda; Compare in Puzzlers › Suggested for you. | Works on the first tap; no dead button; no native dialog. | | |
| **46** [DC7, V15, GEST-14] On the Hub scrolled down, open an external link (a social link in the footer) and come back with Back. Then the same from the running stopwatch page and from a conversation (Inbox › Messages). | Hub: same place, no reload when `INSTANT_BACK_IOS` is true (otherwise one reload). Stopwatch page and conversation: always reload, fresh state. | | |
| **47** [GEST-14, decision 64] Signed in on the Hub, Menu › Log out, then Back. | The page reloads as a guest; the signed-in page never shows again. | | |
| **48** [bar §6, NAV-11] VoiceOver: swipe through the bottom bar and the top bar. | The bottom bar is a navigation landmark and the active tab is announced as the current page; ☰ is "Menu, button" with expanded/collapsed; Inbox says what is new ("Inbox, 2 new messages"); every control has a name. | | |
| **49** [DC16, bar §6] Largest text (Larger Accessibility Sizes at maximum; if the bar does not change, Safari's aA page zoom 200 % - record which one you used). Check the bottom bar in en, de, fr, ja and the Menu rows. | **README D14 / §4.2 wins over the research ("truncate"):** labels step down 11 px → 10 px → icons with only the current tab's label; never cut with "…", never overlapping or spilling out of the bar; hidden labels show on long-press (row 34); Menu row labels wrap and never truncate (§4.12). | | |
| **50** [RM, README §4.1, §4.12, G2, G3, G8] Reduce Motion on: hide/show the top bar; open and close the Menu; open, drag and close a sheet; tap the active tab when scrolled; tap the Home feed tabs. | The top bar fades instead of sliding; the Menu fades without the rise; sheets appear without sliding but still follow the finger; scroll to top and feed switches are instant. | | |
| **51** [RM, G15] Reduce Motion on: tap a puzzle card. | No morph. | | |
| **52** [RM, G9] Reduce Motion on: repeat row 40 once. | As row 40; MSP adds no animation. | | |

## A2 · iPhone · iOS 27 · Safari · Bottom tab layout

The rows that depend on Safari's tab layout or version (the same set as A3 and A4). Settings › Apps › Safari › Tabs › Bottom.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [bar §6] Hub as a player (A1-1). | Both bars complete, labels visible, solid white. | | |
| **2** [bar §6] Scroll All puzzles; watch the strip around Safari's bottom address bar (A1-2, A1-3). | White under and around Safari's toolbar, no content band, no flicker; MSP's bottom bar fixed at the bottom edge, no jump or gap while Safari's toolbar changes size. | | |
| **3** [README §4.1] Top bar hide/show (A1-4). | Slides away on scroll down, back on scroll up; status-bar strip stays white. | | |
| **4** [bar §6] Open and close Menu, Help sheet, player card, search view, confirm dialog (A1-5). | Scrim covers cleanly incl. the toolbar bands; nothing left tinted after closing. | | |
| **5** [bar §6] Bottom edge (A1-6). | Labels above the home indicator. | | |
| **6** [V12] Swipe sideways / up on MSP's bottom bar right above Safari's address bar (A1-7). | Only MSP reacts; record what Safari does. | | |
| **7** [V17, bar §6] Keyboard open/close 3× in search, add form, Help box (A1-10). | Bar hidden while open, back at the bottom after; no drift. | | |
| **8** [extra, README §5] Focus every input (A1-11). | No zoom on focus. | | |
| **9** [extra, README §4.5] Tap ? signed in (A1-12). | Record 1 or 2 taps; both accepted. | | |
| **10** [extra, README §4.2] Menu open → tap Community → Back; Menu open on a Puzzles page → tap Puzzles (A1-15, A1-16). | Menu closes and Community opens, one Back to the opener page; the current place's tab only closes the Menu. | | |
| **11** [V4, G1] Every layer closed by edge swipe and by Safari's back button (A1-19, A1-20). | Only the layer closes; URL unchanged; no reload. | | |
| **12** [DC1] Menu → edge swipe → delete a stopwatch; also after a Back to a restored page (A1-28, A1-29). | MSP's in-page dialog every time. | | |
| **13** [DC1] Appendix A repro (A1-30). | Record dialog shown yes/no and the "returned … after … ms" line. | | |
| **14** [DC6, G9] Back to the scrolled catalogue and the Hub after "Show more", by edge swipe (A1-40). | As A1-40 for the recorded `INSTANT_BACK_IOS`. | | |
| **15** [DC10] Morph on fast and slow connection (A1-37, A1-38). | Morph when fast, plain swap when slow, never stretched. | | |
| **16** [G7] Scroll-to-top by the top edge (iOS 27: tap, then tap again; iOS 26: double-tap the top edge). | Page scrolls to the top. | | |
| **17** [RM] Reduce Motion on: A1-50 and A1-52 in short. | Fades only; instant scrolls; Back as row 14. | | |

## A3 · iPhone · iOS 26.x · Safari · Compact tab layout

The version-dependent rows (the second iOS version; Safari 27 changed sticky flicker, view-transition snapshots and the top-inset colour extension). Write the exact version: ____.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [bar §6] Hub as a player (A1-1). | Both bars complete, labels visible, solid white. | | |
| **2** [bar §6] Scroll All puzzles; watch the strip around Safari's tab bar (A1-2, A1-3). | White under and around Safari's toolbar, no content band, no flicker; MSP's bottom bar fixed at the bottom edge. | | |
| **3** [README §4.1] Top bar hide/show (A1-4). | Slides away and back; status-bar strip white; no flicker of sticky sub-bars after scrolling. | | |
| **4** [bar §6] Open and close Menu, Help sheet, player card, search view, confirm dialog (A1-5). | Scrim covers cleanly incl. the toolbar bands (WebKit 300965 fixed in 26.2; styled backdrops 303167 still open - record any tinted band). | | |
| **5** [bar §6] Bottom edge (A1-6). | Labels above the home indicator. | | |
| **6** [V12] Swipe sideways / up on MSP's bottom bar right above Safari's tab bar (A1-7). | Only MSP reacts; record what Safari does. | | |
| **7** [V17, bar §6] Keyboard open/close 3× (A1-10). | Bar hidden while open, back at the bottom after; no drift (the iOS 26 report - Q12). | | |
| **8** [extra, README §5] Focus every input (A1-11). | No zoom on focus. | | |
| **9** [extra, README §4.5] Tap ? signed in (A1-12). | Record 1 or 2 taps; both accepted. | | |
| **10** [extra, README §4.2] Menu + bottom bar (A1-15, A1-16). | As A1-15 and A1-16. | | |
| **11** [V4, G1] Every layer closed by edge swipe and by Safari's back button (A1-19, A1-20). | Only the layer closes; URL unchanged; no reload. | | |
| **12** [DC1] Menu → edge swipe → delete a stopwatch; also after a Back to a restored page (A1-28, A1-29). | MSP's in-page dialog every time. | | |
| **13** [DC1] Appendix A repro (A1-30). | Record dialog shown yes/no and the "returned … after … ms" line. | | |
| **14** [DC6, G9] Back to the scrolled catalogue and the Hub after "Show more", by edge swipe (A1-40). | As A1-40 for the recorded `INSTANT_BACK_IOS`. | | |
| **15** [DC10] Morph on fast and slow connection (A1-37, A1-38). | Morph when fast, plain swap when slow, never stretched or jumping (record any difference from iOS 27). | | |
| **16** [G7] Double-tap the top edge of the screen on a long page. | Page scrolls to the top. | | |
| **17** [RM] Reduce Motion on: A1-50 and A1-52 in short. | Fades only; instant scrolls; Back as row 14. | | |

## A4 · iPhone · iOS 26.x · Safari · Bottom tab layout

Same rows as A2, on iOS 26.x.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [bar §6] Hub as a player (A1-1). | Both bars complete, labels visible, solid white. | | |
| **2** [bar §6] Scroll All puzzles; watch the strip around Safari's bottom address bar (A1-2, A1-3). | White under and around Safari's toolbar, no content band; MSP's bottom bar fixed, no jump or gap; it does not stop where Safari's toolbar used to be. | | |
| **3** [README §4.1] Top bar hide/show (A1-4). | Slides away and back; status-bar strip white. | | |
| **4** [bar §6] Open and close Menu, Help sheet, player card, search view, confirm dialog (A1-5). | Scrim covers cleanly incl. the toolbar bands; nothing left tinted. | | |
| **5** [bar §6] Bottom edge (A1-6). | Labels above the home indicator. | | |
| **6** [V12] Swipe sideways / up on MSP's bottom bar (A1-7). | Only MSP reacts; record what Safari does. | | |
| **7** [V17, bar §6] Keyboard open/close 3× (A1-10). | Bar hidden while open, back at the bottom after; no drift. | | |
| **8** [extra, README §5] Focus every input (A1-11). | No zoom on focus. | | |
| **9** [extra, README §4.5] Tap ? signed in (A1-12). | Record 1 or 2 taps; both accepted. | | |
| **10** [extra, README §4.2] Menu + bottom bar (A1-15, A1-16). | As A1-15 and A1-16. | | |
| **11** [V4, G1] Every layer closed by edge swipe and by Safari's back button (A1-19, A1-20). | Only the layer closes; URL unchanged; no reload. | | |
| **12** [DC1] Menu → edge swipe → delete a stopwatch; also after a Back to a restored page (A1-28, A1-29). | MSP's in-page dialog every time. | | |
| **13** [DC1] Appendix A repro (A1-30). | Record dialog shown yes/no and the "returned … after … ms" line. | | |
| **14** [DC6, G9] Back to the scrolled catalogue and the Hub after "Show more", by edge swipe (A1-40). | As A1-40 for the recorded `INSTANT_BACK_IOS`. | | |
| **15** [DC10] Morph on fast and slow connection (A1-37, A1-38). | Morph when fast, plain swap when slow. | | |
| **16** [G7] Double-tap the top edge of the screen on a long page. | Page scrolls to the top. | | |
| **17** [RM] Reduce Motion on: A1-50 and A1-52 in short. | Fades only; instant scrolls; Back as row 14. | | |

## B · iPhone · Chrome iOS

Chrome on iOS uses WebKit: the iOS rules apply (`data-os="ios"`, no ripple, no place fade, no `navigator.vibrate`). Run once with Chrome's address bar at the top and rows 2 and 3 again with it at the bottom.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [DC13] Read `data-os` (or open Settings › Preferences). | `ios`; no Vibration switch. | | |
| **2** [bar §6] Hub as a player; scroll All puzzles down and up. | Both bars complete and solid white; MSP's bottom bar stays fixed and clear of Chrome's toolbar, no overlap or gap while Chrome's toolbars hide and show. | | |
| **3** [bar §6] Address bar at the bottom: repeat row 2. | As row 2; record how the two bars move together. | | |
| **4** [V17] Keyboard open/close 3× in search, add form, Help box. | Bar hidden while open, back at the bottom after; no drift. | | |
| **5** [extra, README §5] Focus every input (A1-11). | No zoom on focus. | | |
| **6** [extra, README §4.5] Tap ? signed in. | Record 1 or 2 taps; both accepted. | | |
| **7** [extra, README §4.2] Menu + bottom bar (A1-15, A1-16). | As A1-15 and A1-16. | | |
| **8** [V4, G1] Close every layer by edge swipe and by Chrome's back button. | Only the layer closes; URL unchanged; no reload. | | |
| **9** [DC1] Menu → edge swipe → delete a stopwatch; block/unblock; revoke a token. | MSP's in-page dialog every time. | | |
| **10** [DC1] Appendix A repro in Chrome iOS. | Record dialog shown yes/no (the research believes the suppression is a Safari-app policy). | | |
| **11** [DC6, G9] Back to the scrolled catalogue, the Hub after "Show more", a leaderboard. | As A1-40 for the recorded `INSTANT_BACK_IOS`. | | |
| **12** [DC7] External link from the scrolled Hub, then Back; same from the running stopwatch page. | Hub as A1-46; the stopwatch page reloads. | | |
| **13** [V8, G4] Pressed states on tabs, Menu rows, chips, cards (A1-26). | Light pressed layer at once, no grey highlight, no ripple. | | |
| **14** [DC11] Long-press a tab whose label is hidden (A1-34). | Bubble with the label; no Chrome or iOS link preview; release does not navigate. | | |
| **15** [DC10] Catalogue cards on fast and slow connection. | Morph when fast, plain swap when slow. | | |
| **16** [DC2] Toggle a Settings switch (tap, label, drag). | Record whether the light tap is felt in Chrome iOS; the switch looks as in Safari. | | |
| **17** [V11] Pull down at the top of the Hub, a puzzle page and Results. | Chrome's own pull-to-refresh works on every page; MSP draws nothing of its own in a browser tab (G6). | | |
| **18** [DC9] Home feed swipe (A1-35). | As A1-35. | | |
| **19** [RM] Reduce Motion on: A1-50 in short. | Fades only, instant scrolls. | | |

## C · iPhone · Home Screen app

Safari › Share › Add to Home Screen, then open MSP from the Home Screen (iOS 26 and later open it as a web app). Signed in as a member.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [bar §6] Open the app; scroll a long page so the top bar hides. | No Safari interface; a solid status-bar strip (the page starts below it, status-bar style `default`); content never shows under the clock while the top bar is away. | | |
| **2** [bar §6, README §4.2] Look at the bottom bar. | Labels sit above the home indicator; the white bar continues under it. | | |
| **3** [README §4.1] Open a puzzle page; then the Hub, Puzzles, your library, Add, Community and your profile. | A back button left of the logo on the puzzle page (it goes back one page); none on those root pages. On an iPhone narrower than 390 px the search field becomes an icon while the back button shows. | | |
| **4** [V3] Swipe from the left edge on a puzzle page. | Record: does it go back? (Unverified for iOS 26/27; the back button covers it either way.) | | |
| **5** [V4, G1] If row 4 goes back: open the Menu, the Help sheet, a player card and the search view, edge swipe each. | Only the layer closes; no reload. | | |
| **6** [DC12, G17, G6] Pull down from the top of the Hub, slowly; then past the point and let go; then a short pull. | A bare spinner (no disc, no arrow) fades and turns in with the pull and sits under the top bar; releasing after ~128 px of finger travel reloads the page and the spinner spins; a short pull springs back. | | |
| **7** [V10, G6, decision log "GEST review Q2"] Try to pull while the Menu, a sheet, a player card or the search view is open; starting in a chip row, the player tabs strip or a wide table; on the Add form with something typed. | No pull starts in any of these. | | |
| **8** [V10, README §4.5] Help sheet (?) › "Reload page". | The page reloads. The row exists only in the app (not in Safari tabs). | | |
| **9** [DC14] Scroll hard to the top and to the bottom edge of a long page. | No bounce (by design, `overscroll-behavior-y: none` in standalone). Note whether it feels "dead" (Q10). | | |
| **10** [V3] Tap the top edge of the screen (iOS 27: tap, then tap again); pinch the page. | Record: does the page scroll to the top? Does pinch zoom? | | |
| **11** [DC1] Add the Appendix A repro page to the Home Screen and run it; then in MSP: Menu → edge swipe (or back button) → delete a stopwatch. | Repro: record dialog shown yes/no. MSP: the in-page dialog every time. | | |
| **12** [DC6, G9] Back (back button or edge swipe) to the scrolled catalogue and the Hub after "Show more". | As A1-40 for the recorded `INSTANT_BACK_IOS`. | | |
| **13** [G9] Send the app to the background for more than 15 minutes, then return. Then again for about 2 minutes. | After 15+ minutes the page reloads (fresh); after 2 minutes it is as you left it. | | |
| **14** [V17, extra] Keyboard open/close in search, add form, Help box; focus every input. | Bar hidden while the keyboard is open and back after; no drift; no zoom on focus. | | |
| **15** [extra, README §4.5] Tap ? signed in. | Record 1 or 2 taps; both accepted. | | |
| **16** [RM] Reduce Motion on: A1-50 in short; pull to refresh once. | Fades only, instant scrolls; the pull still follows the finger. | | |

## D · Pixel (≥ 8 GB RAM) · Chrome · gesture navigation

The full Android run. Chrome 141 or later shows a back-gesture preview on phones with ≥ 7.2 GB RAM. Run with the address bar at the top; rows 5 and 6 with it at the bottom. Signed in as a member unless a row says otherwise.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [bar §6] Hub as a player (A1-1). | Both bars complete, labels visible, solid white. | | |
| **2** [DC13] Read `data-os`, or open Settings › Preferences. | `android`; the Vibration switch is shown with its explainer. | | |
| **3** [bar §6, F-04] Look at Chrome's address bar and the status bar. | White (theme-color `#ffffff`), never coral. | | |
| **4** [bar §6, README §4.2] Scroll All puzzles down and up, slowly and with a fling. | Chrome's bottom chin slides away and MSP's bottom bar follows smoothly: no gap, no jump, no stutter; labels stay above the gesture handle. | | |
| **5** [bar §6, V13] Chrome › Settings › Address bar › Bottom; reload; scroll down and up. | MSP's bar sits directly on Chrome's bar; both hide and show with scroll without overlapping or leaving a gap; Chrome's bar is white. Record how they animate (unverified in the research). | | |
| **6** [V13] Address bar still at the bottom: swipe sideways on MSP's bottom bar. | No Chrome tab switch. Then set the address bar back to the top. | | |
| **7** [README §4.1] Top bar hide/show (A1-4). | Slides away on scroll down, back on scroll up, shadow once scrolled. | | |
| **8** [README D16] Landscape on the Hub and a puzzle page (A1-8). | Phone bars stay; the top bar scrolls with the page. | | |
| **9** [V17, bar §6] Keyboard open/close 3× in search, add form, Help box. | Bar hidden while the keyboard is open, back at the bottom after; no bar riding up on the keyboard. | | |
| **10** [G5, V9] Double-tap and pinch (A1-13). | No double-tap zoom; pinch zooms everywhere except the chart exception (record). | | |
| **11** [V14] Drag the player tabs strip, a chip row and a wide table starting close to either screen edge. | Android's back may fire near the edge - acceptable; nothing important starts there. Record how close it fires. | | |
| **12** [README §4.12] Tap ☰ (A1-14). | Same panel and motion as on iPhone (rise with a fade, ~0.25 s); bottom bar visible. | | |
| **13** [extra, README §4.2] Menu open → tap Community → back gesture; Menu open on a Puzzles page → tap Puzzles (A1-15, A1-16). | Menu closes and Community opens, one Back to the opener page; the current place's tab only closes the Menu. | | |
| **14** [README §4.12] Scroll the open Menu to the end (A1-17). | Panel scrolls by itself; page behind still; no pull-to-refresh. | | |
| **15** [V4, G1] Close every layer (Menu, Help sheet, card, search view, achievement, result detail) with the back gesture from the left edge and from the right edge. | Only the layer closes; URL unchanged; no reload; next Back leaves the page. | | |
| **16** [DC8] Open a sheet, start the back gesture slowly and hold; release. Then the same with the Menu, and once cancel the gesture by sliding back. | The preview shows the page without the layer; release closes only the layer; a cancelled gesture leaves the layer open. | | |
| **17** [G1 (d)] Menu › Results → Back; search → result → Back (A1-21). | One Back to the opener page. | | |
| **18** [V5, V6, G2] Sheet drags and the Help draft (A1-22, A1-23). | As A1-22 and A1-23. | | |
| **19** [README §4.5] Tap ? signed in. | Record whether the keyboard opens at once (Android normally does). | | |
| **20** [V7, G3] Tap the active tab (A1-25). | As A1-25. | | |
| **21** [DC5, G11] Tap tabs, ☰ / ? / Inbox, Menu rows, chips, Help sheet rows, link rows. | A ripple spreads from the finger (covers the target in ~0.45 s, stays at least ~0.2 s, fades ~0.15 s after release) on top of the light pressed layer; on the bars at once, inside scrolling content after ~0.15 s; not on Bootstrap buttons in page content. | | |
| **22** [DC5] Start a scroll on a Menu row and on a list row. | No ripple once the touch becomes a scroll (more than ~10 px of movement). | | |
| **23** [state.md open notes] Rest a finger on a tab and on a Menu row without lifting it. | Record whether the light pressed layer shows while the finger rests (Chromium may apply `:active` only at the tap); the ripple is the feedback while held. | | |
| **24** [G4, decision log "GEST review F2"] Press the Add tab. | A visibly darker press and ripple on the coral tile. | | |
| **25** [DC3, G10] Multiscan (Menu › Add & track › Scan several boxes): scan a box MSP knows; the same box again; a barcode MSP does not know. | Known: one short tap (`confirm`, 20 ms). Same box again: a very short tick (`tick`, 10 ms). Unknown: two pulses (`reject`, 30-100-30). | | |
| **26** [DC3, G10] Add a time and save. Then submit a form the server refuses (for example a second first try on the same puzzle, or a value the page lets through but the server rejects). | Save: one short tap (`confirm`). Refused by the server (422): two pulses (`reject`). A form the browser stops by itself (an empty required field) sends nothing and plays nothing - record which kind you hit. | | |
| **27** [DC3, G10] Settings: turn a switch on, then off. | On: one very short pulse (`toggleOn`, 10 ms). Off: nothing. | | |
| **28** [G10] Stopwatch: start, pause, resume, finish. | A short tap on start, resume and finish; nothing on pause. | | |
| **29** [DC3, G16] Long-press a tab whose label is hidden (row 37). | 30 ms with the bubble. | | |
| **30** [DC3, G10] Organiser: live results entry, save a result. | One short tap. | | |
| **31** [DC3, G10] Tap tabs, ☰, Menu rows and links; open and close sheets and the Menu; Back; scroll. | Nothing, ever. | | |
| **32** [DC3] Settings › Preferences › Vibration off; repeat rows 25–30. Then on again. | Nothing at all. | | |
| **33** [DC3] Ringer silent, repeat rows 25 and 26; Touch feedback off, repeat; battery saver on, repeat. | Nothing in each case (Chrome's own rules). | | |
| **34** [DC3] Compare the feel of rows 25 and 26. | `confirm` is felt but short; `reject` is clearly different. If `confirm` is not felt: Q3 (20 → 30 ms). | | |
| **35** [decision 75, optional] Organiser with an online test round: the GO and time's-up alerts, also with MSP's Vibration off. | They vibrate with their own long patterns regardless of MSP's Vibration switch (functional alerts, not feedback). | | |
| **36** [DC4, G12] Switch places quickly 10×; open a puzzle from a list; Back. | Places fade through (old out fast, new in with a slight zoom, ~0.3 s), the bars stand still, no stutter, no lost tap. A content link gets no place fade; Back gets no fade. | | |
| **37** [DC11, G16] French plus the largest Display size / font size until a tab shows only its icon; hold it 0.5 s. | Bubble with the full label above the tab; no Chrome link menu, no text selection; release does not navigate; 30 ms vibration; a short tap navigates. | | |
| **38** [DC9, G14] Home feed swipe; vertical scroll with a diagonal start; edge swipe from both edges; TalkBack tab switch (A1-35, A1-36). | Panes snap; vertical never switches; an edge swipe is Back; tabs work with TalkBack; no height jump; edge to edge. | | |
| **39** [DC10, G15] Catalogue, library and search cards on fast and slowed connection; Back. | Morph when fast, plain swap when slow, never stretched; Back never morphs. | | |
| **40** [Platform feel F3] Open the search view, type, tap a result. | The results leave with the page; nothing freezes over the transition. | | |
| **41** [DC6, V1, G9] Back gesture (preview on) to: All puzzles scrolled, the Hub after "Show more", a leaderboard, your Results after "Show more", the events agenda. | Instant, same scroll position, extra rows still there, no white flash or reload, no second animation after Chrome's preview, no smooth-scroll animation; selects and charts appear once. | | |
| **42** [DC6, G9] Lock the phone for more than 10 minutes on a puzzle page, then Back (A1-42). | A fresh page load. | | |
| **43** [G9] Back to a puzzle page, the Inbox, Settings (A1-43). | A normal full load. | | |
| **44** [G9, decision 65] Favorite toggle and event follow, then Back (A1-44). | The new state, never the old copy. | | |
| **45** [G9, G13] One-tap actions on a restored page (A1-45). | Work on the first tap. | | |
| **46** [DC7, V15, GEST-14] External link from the scrolled Hub, then Back; the same from the running stopwatch page and a conversation. | Hub: same place, no reload. Stopwatch page and conversation: reload. | | |
| **47** [GEST-14, decision 64] Log out, then Back (A1-47). | Reloads as a guest. | | |
| **48** [V11] Pull down at the top of the Hub, a puzzle page and Results. | Chrome's own pull-to-refresh works on every page; MSP draws nothing of its own in a browser tab. | | |
| **49** [bar §6, NAV-11] TalkBack through both bars (A1-48). | Landmarks, current page, Menu expanded/collapsed, Inbox name. | | |
| **50** [DC16, bar §6] Font size maximum and Chrome text scaling 200 % (A1-49). | README D14 wins: fallback steps, never "…"; long-press shows hidden labels; Menu labels wrap. | | |
| **51** [RM, README §4.13 G8, G10, G11, G12, G15] Remove animations on: top bar, Menu, sheets, active-tab scroll, place switches, cards, a scan and a save. | Fades only and instant scrolls; places switch instantly; no ripple (pressed layer only); no morph. **Haptics still play** (README G10: reduced motion does not switch haptics off, except the holo-medal tick) - the research's "none with reduced motion" (V16) is replaced. | | |
| **52** [RM, V2] Remove animations on: back gesture from a puzzle page to the catalogue. | No Chrome preview; Back as row 41. | | |

## E · Pixel · installed app (Chrome)

Chrome ⋮ › Install app (or Add to Home screen), then open MSP from the launcher. Signed in as a member.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [bar §6] Open the app. | No browser interface; white status bar; both MSP bars as in Chrome. | | |
| **2** [README §4.1] Puzzle page vs root pages (C-3). | Back button left of the logo on non-root pages only. | | |
| **3** [DC12, G17, G6] Pull down from the top of the Hub, slowly past the point, release; then a short pull. | A white 40 px disc with an arrow follows the pull, becomes a spinner after release, reloads after ~128 px of finger travel; one tick (10 ms) each time the point is crossed; a short pull springs back; no second (Chrome) spinner. | | |
| **4** [V10, G6] Pull with a layer open, from a chip row / tabs strip / table, with typed form input (C-7). | No pull starts. | | |
| **5** [V10, README §4.5] Help sheet › "Reload page". | Reloads. | | |
| **6** [gestures §2] System back on the first page you opened; on a later page; with a layer open. | First page: the app goes to the background; later page: one page back; a layer: only the layer closes. | | |
| **7** [DC8] Slow back gesture with a sheet open and with the Menu open. | Record whether a preview shows (unverified in installed apps); release closes only the layer. | | |
| **8** [DC6, G9] Back to the scrolled catalogue and the Hub after "Show more". | As D-41. | | |
| **9** [DC7] An external link from the scrolled Hub, then system back. | Record where it opens; back in MSP at the same place, no reload. | | |
| **10** [G9] Background the app for more than 15 minutes, return; again for ~2 minutes. | 15+ minutes: reloads; 2 minutes: as left. | | |
| **11** [G10] Scan a known box, save a time, turn a switch on. | Same haptics as in Chrome (D-25 to D-27). | | |
| **12** [V17] Keyboard open/close in search and the add form. | Bar hidden while open, back after. | | |
| **13** [RM] Remove animations on: Menu, a sheet, a place switch, one pull. | Fades only, instant place switch; the pull still follows the finger. | | |

## F · Galaxy A (< 8 GB RAM) · Samsung Internet 30

The low-end device and Samsung's own toolbar. Record whether Samsung's bottom toolbar is on and where its address bar sits. Signed in as a member.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [DC13] Read `data-os`, or open Settings › Preferences. | `android`; the Vibration switch is shown. | | |
| **2** [bar §6] Hub as a player; look at the status and address bars. | Both MSP bars complete and solid white; Samsung's bars white, not coral. | | |
| **3** [bar §6] Samsung's bottom toolbar **on**: scroll All puzzles down and up. | MSP's bottom bar and Samsung's toolbar never overlap or stack oddly; when Samsung's toolbar hides, MSP's bar moves down to the edge smoothly; no jitter between the two. | | |
| **4** [bar §6] Samsung's bottom toolbar **off**: repeat row 3. | MSP's bar fixed at the bottom edge, no gap, no jitter. | | |
| **5** [bar §6] Address bar at the bottom (if offered): repeat row 3. | As row 3; record how the bars move. | | |
| **6** [bar §6] Fling the catalogue and the Hub; scroll while the top bar hides. | Smooth on this GPU; no stutter. | | |
| **7** [bar §6] Load a page fresh and look at the bars at once. | Bars in place right after load (the research saw a wrong first viewport height with the bottom address bar). | | |
| **8** [V17] Keyboard open/close in search, add form, Help box. | Bar hidden while open, back at the bottom after. | | |
| **9** [extra, README §5] Focus every input (A1-11). | No zoom on focus. | | |
| **10** [extra, README §4.2] Menu + bottom bar (A1-15, A1-16). | As A1-15 and A1-16. | | |
| **11** [V4, G1] Close every layer with the system back gesture and with Samsung's toolbar back button. | Only the layer closes; URL unchanged; no reload. | | |
| **12** [DC15, DC6, G9] Back to the scrolled catalogue, the Hub after "Show more", a leaderboard; record whether any back preview shows. | As in Chrome (D-41): instant, same place, no reload, no second animation. | | |
| **13** [DC7] External link from the scrolled Hub, then Back; the same from the running stopwatch page. | Hub: same place, no reload. Stopwatch page: reloads. | | |
| **14** [DC15, DC3] Scan known / same / unknown, save, refused form, switch on/off, stopwatch start. | As in Chrome (D-25 to D-28); nothing on tabs, Menu, sheets, Back. | | |
| **15** [DC3] Vibration off; ringer silent; Touch feedback off; power saving on - repeat a scan and a save in each. | Nothing in each case. Record if Samsung Internet differs from Chrome here (its settings are unverified). | | |
| **16** [DC15, DC4] Switch places quickly 10×. | As in Chrome (D-36): fade-through, bars still, no stutter, no lost tap. | | |
| **17** [DC5] Ripple on tabs and Menu rows; start a scroll on a row. | Ripple from the finger on taps; none when the touch becomes a scroll. | | |
| **18** [DC11] Long-press a tab whose label is hidden. | Bubble; no Samsung link menu or preview; release does not navigate; vibrates. | | |
| **19** [DC9] Home feed swipe (A1-35). | As A1-35. | | |
| **20** [DC10] Cards on fast and slowed connection. | Morph when fast, plain swap when slow; record if it never morphs. | | |
| **21** [V11] Pull down at the top of the Hub and a puzzle page. | Samsung Internet's own pull-to-refresh works; MSP draws nothing of its own. | | |
| **22** [README §4.5] Tap ? signed in. | Record whether the keyboard opens at once. | | |
| **23** [DC16] Samsung font size maximum (and Samsung Internet text size 200 %). | README D14 wins: fallback steps, never "…"; Menu labels wrap. | | |
| **24** [RM] Reduce animations on: D-51 in short. | Fades only; instant place switch; no ripple; haptics still play. | | |

## G · Galaxy A (< 8 GB RAM) · Chrome

The same phone in Chrome: the place fade, haptics and Back without Chrome's preview (RAM below its gate).

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [DC13] Read `data-os`, or open Settings › Preferences. | `android`; Vibration switch shown. | | |
| **2** [bar §6] Hub; scroll and fling All puzzles. | Bars complete and white; the bottom bar follows Chrome's chin smoothly; no stutter on this GPU. | | |
| **3** [DC4, G12] Switch places quickly 10× (this is DC4's named device). | Fade-through, bars still, no stutter, no lost tap; Back shows no fade. Record if it ever drops frames (Q4). | | |
| **4** [DC3] Scan known / same / unknown, save, refused form, switch on/off. | As D-25 to D-27; record whether the 20 ms `confirm` is felt on this phone's motor (Q3). | | |
| **5** [DC3] Vibration off; ringer silent; Touch feedback off; battery saver - repeat a scan and a save in each. | Nothing in each case. | | |
| **6** [DC5] Ripple on taps; start a scroll on a row. | Ripple on taps; none on scroll. | | |
| **7** [DC6, G9] Back gesture to the scrolled catalogue, the Hub after "Show more", a leaderboard. | Instant, same place, no reload (no preview on this phone - record if one shows). | | |
| **8** [DC7] External link from the scrolled Hub, then Back. | Same place, no reload. | | |
| **9** [DC8] Slow back gesture with a sheet open. | Record whether any preview shows (Chrome's needs ≥ 7.2 GB); release closes only the sheet. | | |
| **10** [DC10] Cards on fast and slowed connection. | Morph when fast, plain swap when slow. | | |
| **11** [DC11] Long-press a tab whose label is hidden. | Bubble, no Chrome link menu, release does not navigate, vibrates. | | |
| **12** [DC9] Home feed swipe. | As A1-35. | | |
| **13** [V17] Keyboard open/close in search and the add form. | Bar hidden while open, back after. | | |
| **14** [extra, README §4.2] Menu + bottom bar (A1-15, A1-16). | As A1-15 and A1-16. | | |
| **15** [RM] Remove animations on: D-51 in short. | Fades only; instant place switch; no ripple; haptics still play. | | |

## H · Desktop widths (computer, Chrome or Safari)

Size the window so the page's `innerWidth` (console) is the width in the row; start with cleared site data so the default layout shows. `data-os` is `other`: no ripple, no place fade, no morph, no haptics. 820 px is also an iPad in portrait: run rows 1–3 and 8 on an iPad if one is at hand (iPad = `ios`). The research's 1366 × 768 and 1536 × 864 sit in rows 6 and 7.

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [README D16, §4.3] 820, member: look at the top bar in en, de, fr, ja. | One row: ☰ Menu · logo · Home · Puzzles · Community · search icon · + Add · ? · Inbox · avatar; no rail, no column; nothing wraps or overflows. | | |
| **2** [README §4.3] 820: ☰. | The Menu slides in from the left as an overlay (~320 px) with a scrim; focus on its first row. | | |
| **3** [README §4.3, G1] 820 and 1024 overlay: close with Esc, a scrim click, ×, and the browser's Back. | Each closes the overlay; focus returns to ☰; Back does not change the URL or reload. | | |
| **4** [README §4.3] 1024. | An 88 px rail on the left (Home · Puzzles · Community · Profile, labels always visible); ☰ opens the overlay. | | |
| **5** [README §4.3] 1024 to 1199 vs 1200 and wider. | Feedback and Inbox are icons below 1200 px and get labels from 1200 px; both always have accessible names. | | |
| **6** [README §4.3, bar §6] 1280 and 1366 (first visit). | Rail by default; content at least 1156 px wide at 1280; ☰ docks the Menu; the choice survives a reload and Turbo visits without a layout jump on load. | | |
| **7** [README §4.3, bar §6] 1440, 1536 and 1920 (first visit). | Menu docked as a 256 px column by default, content to its right (at least 1148 px at 1440), the footer right of the column; ☰ folds it to the rail and the choice is remembered per device. | | |
| **8** [README §4.3, §4.12] 1440 docked: collapse a group, scroll the column, visit other pages, reload. | Groups stay collapsed; the column keeps its own scroll position across visits; the active row is highlighted; row labels wrap to two lines and never truncate (check de, fr, ja). | | |
| **9** [README §4.3] At each width open a puzzle page, a leaderboard, the events agenda, Puzzlers and All puzzles. | No horizontal scroll anywhere; top bar one row. | | |
| **10** [README §4.3] Scroll a long page; press Ctrl/Cmd+K; at 820 press it too; type a letter outside an input. | The top bar never hides; Ctrl/Cmd+K focuses the search (at 768–1023 it opens it); single letters do nothing. | | |
| **11** [README §4.3] Avatar ▾. | Name + #code · View profile · Settings · Membership (status) · Language · Log out; Log out works. | | |
| **12** [README §4.3] Footer "All pages" at 1440 docked, then at 1280 in the rail. | Docked: focus moves to the column's first row. Rail: the overlay opens. | | |
| **13** [README §4.5, V6] Feedback (top bar): type, close with Esc, reopen; Back. | The Help dialog with the feedback box on top; the draft survives; Back closes it. | | |
| **14** [README §4.8, G1] Click a player name on a leaderboard; Esc; outside click; Back; Ctrl/Cmd-click and middle-click a name. | An anchored popover; Esc, an outside click and Back close it; modifier and middle clicks open the profile. | | |
| **15** [DC6, G9] From a puzzle page Back to All puzzles scrolled, the Hub after "Show more", a leaderboard. | Instant, same place, no reload, extra rows kept (Instant Back is not phone-only). | | |
| **16** [G13] Delete a stopwatch; revoke a token. | MSP's in-page dialog; Esc cancels; focus returns to the button. | | |
| **17** [G11, G12, G15, README §4.13 table] Click places, cards and rows. | Instant switches, no ripple, no morph; pressed state on click, hover as before. | | |
| **18** [NAV-11] Tab through the page from the top at 1440 docked. | Skip link → ☰ → logo → search → … → avatar → the column's rows, with a visible focus ring on each. | | |
| **19** [README §4.12] Moderator at 1440. | A dot on ☰ while a queue holds items; Your roles rows with counts. | | |
| **20** [README §4.3] Guest at 1440 and 1280. | A Sign in button in the avatar slot; the rail shows Sign in instead of Profile; no Inbox. | | |
| **21** [bar §6, NAV-11] Large text: browser default font size "Very large" (or 200 % root font) at 1280 rail and 1440 docked. | No label clipped; rail labels stay visible (never icon-only); column rows wrap. | | |
| **22** [bar §6] Windows: a contrast theme (forced colours). | Bars, active tab or row, icons and the focus ring stay visible. | | |
| **23** [bar §6] VoiceOver (Mac) or NVDA through the top bar, rail and column. | Navigation landmarks; the active row or tab announced as the current page. | | |
| **24** [RM] System reduced motion on: open the overlay at 1024; tap Home on a scrolled Hub at 1440. | The overlay appears without sliding; the scroll to top is instant. | | |

## I · `data-os` across browsers (DC13)

| Step | Expected | Result | Notes |
|---|---|---|---|
| **1** [DC13] iPhone Safari. | `ios` | | |
| **2** [DC13] Chrome iOS. | `ios` | | |
| **3** [DC13] iPad Safari with its default desktop user agent. | `ios` | | |
| **4** [DC13] Pixel Chrome. | `android` | | |
| **5** [DC13] Samsung Internet. | `android` | | |
| **6** [DC13] Firefox Android. | `android` | | |
| **7** [DC13] Mac Safari. | `other` | | |
| **8** [DC13] Windows Chrome. | `other` | | |
| **9** [README §4.13] Chrome Android with "Desktop site" turned on. | `other` (README: Chrome's desktop-site mode counts as other). | | |

## Questions to answer

Answer from the tables above; each answer may change a constant or a TODO entry.

1. **DC1:** does Safari 26.x / 27 still suppress native dialogs after a history swipe (Appendix A, in Safari and as a Home Screen app)? Does Chrome iOS? After the release: do `dialog_suppressed` events arrive in production (Loki channel `navigation`), and on which paths?
2. **Decision 66:** with DC1 and DC7 passing on iPhone, should `INSTANT_BACK_IOS` be true (or stay true)?
3. **DC3:** is `confirm` (20 ms) felt on the Pixel and the Galaxy A? If not, raise it to 30 ms (README G10 allows it). Is `reject` clearly different?
4. **DC4:** does the place fade-through stutter on the Galaxy A? (The Platform feel review kept a view transition on every Android visit, Q2 declined; a stutter reopens that.)
5. **DC5 / open note:** does Chrome on Android show the pressed layer while a finger rests on a tab or row, or only at the tap? Is the ripple enough feedback while held?
6. **Pull-to-refresh (G6):** is ~128 px of finger travel right in both installed apps?
7. **Sheets (G2, V5):** do 96 px / half the height / 0.6 px/ms feel right on both platforms (the flick speed was the research's tuning candidate)?
8. **Help sheet "one tap to type" (README §4.5):** 1 or 2 taps on iOS 26.x, iOS 27, Chrome iOS and the Home Screen app?
9. **DC10 / G15:** does the morph work in WebKit (`animation: revert`)? Any difference between iOS 26.x and 27?
10. **DC14:** does the iOS Home Screen app feel "dead" without overscroll bounce? (Input for the `docs/TODO.md` entry "iOS standalone without overscroll bounce".)
11. **V3:** in the iOS Home Screen app, is there an edge swipe back, a top-edge scroll to top, pinch-zoom?
12. **Keyboard (bar §6):** does the iOS 26 "bottom bar drifts up after the keyboard closes" report reproduce on 26.x or 27?
13. **V12 / V13:** do Safari's or Chrome's own toolbar swipes ever take a gesture meant for MSP's bottom bar?
14. **Bottom address bars (bar §6):** how do Chrome's and Samsung Internet's bottom bars move together with MSP's bar - acceptable?
15. **DC16:** does iOS Larger Text change Safari's web text at all, or only the aA page zoom? Did any label get cut anywhere?
16. **DC8 / DC15:** does Chrome's back preview show the page without the layer? Does Samsung Internet (or the installed app) show a preview at all?
17. **V2 (optional):** in the `back` lines of the `navigation` channel (GEST-08) from the test devices, is `hasUAVisualTransition` true after an iOS swipe and after Chrome's preview, and false under reduced motion on Chrome?

## Appendix A · native-dialog repro page (DC1)

The plain repro of hotwired/turbo#637 (pushState → Back → native dialog), independent of MSP. Save it as a throwaway HTML file on any origin the phones can reach (never commit it), open it in Safari, in Chrome iOS and as a Home Screen web app.

```html
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DC1 native dialog repro</title>
<p><button id="push">1. pushState</button></p>
<p>2. Go back: swipe from the left edge (or the browser's back button).</p>
<p><button id="alert">3a. alert()</button> <button id="confirm">3b. confirm()</button></p>
<p id="out">not run yet</p>
<script>
  var out = document.getElementById('out');
  document.getElementById('push').onclick = function () {
    history.pushState({dc1: true}, '', location.pathname + '#pushed');
    out.textContent = 'pushed - now go back';
  };
  function timed(name, ask) {
    var started = Date.now();
    var answer = ask();
    out.textContent = name + ' returned ' + answer + ' after ' + (Date.now() - started) + ' ms';
  }
  document.getElementById('alert').onclick = function () { timed('alert', function () { return alert('DC1 alert'); }); };
  document.getElementById('confirm').onclick = function () { timed('confirm', function () { return confirm('DC1 confirm'); }); };
</script>
```

Reading it: a dialog you saw means Safari did not suppress it. No dialog and "returned … after 0–50 ms" means it was suppressed (`confirm` then returns `false`, which is what MSP's watchdog reports as `dialog_suppressed`). Run it once without step 2 as the control: the dialog must show.

Status: blocked: owner
