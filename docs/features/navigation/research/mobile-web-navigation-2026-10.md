# Mobile web navigation: bottom tab bar or not? (research, 2026-10-17)

The question: the phone design has a fixed top bar (logo · search · help · Inbox · ☰) and a fixed bottom tab bar (Home · Puzzles · + Add · Community · Profile). On a real iPhone (iOS 26 Safari, Top layout: address bar at the top, toolbar at the bottom) the bottom bar needs two taps once Safari has folded its toolbar, the workaround (padding by `100dvh - 100svh`) leaves a blank band, and the bar lags behind Safari's toolbar animation. Is a bottom tab bar right for a mobile **web** app at all?

Confidence labels used below: **High** = seen directly (rendered page, source code, spec, vendor doc), **Medium** = seen partially or in one credible secondary source, **Low** = inference or a single forum report, **Unverified** = could not be checked here.

---

## 1. Executive summary

**Recommendation (confidence: medium, high after the device checks in §6):**

1. **In the browser (Safari, Chrome): keep the bottom bar, but stop it from ever being on screen while the browser has folded its toolbar.** The bar slides away when the visitor scrolls down and comes back together with the browser's own toolbar (scroll up, page top, page end). The top bar stays always visible (search, Inbox, ☰), as the owner wants. While the bar is hidden, nothing of ours sits in Safari's "tap here to show the toolbar" strip, so the swallowed first tap cannot happen; there is also no padding band and no fixed element trailing Safari's animation. This is option **E** in §5 ("bottom bar in step with the browser toolbar"). It is what Booking.com and Bluesky do on the web today, and it matches how iOS 26 native tab bars behave (they may minimize on scroll down).
2. **In the installed web app (Home Screen, `standalone`/`fullscreen`) and the future Hotwire Native apps: a permanent bottom bar.** There is no browser toolbar there, so none of the problems exist. Since iOS 26 every site added to the Home Screen opens as a web app by default, so this audience can grow.
3. **Do not move to an inner scroll container** ("fixed top + fixed bottom, only the middle scrolls"). It does stop Safari from folding the toolbar, but the costs are high for a server-rendered Turbo Drive site: scroll position on Back, pull-to-refresh, status-bar tap to top, keyboard scrolling and Chrome's URL-bar hiding all have to be rebuilt or are lost, and Safari keeps its full toolbar on screen forever (§3.5).
4. **Fallback if the device checks fail:** option **B** (no bottom bar in the browser: a top app bar plus a scrollable section row, bottom bar only in the installed app and native apps). It is the most reliable option on iOS Safari and what most content and commerce sites use (Amazon, GitHub, Wikipedia, BBC, Reddit, Goodreads). Its price is reach: "+ Add" moves to the top bar or to a lifted floating button (option C).

Why not simply drop the bottom bar? The owner's feeling that bottom bars "work for native apps but are poor on the web" is half right. The web-specific problems are real and old (Safari has reserved a bottom strip since at least 2016, and Apple called it "expected behavior" in 2019), but the big app-like websites still use bottom bars in the browser: YouTube, Instagram, Threads, Spotify, Airbnb, Booking.com, Bluesky. What they do differently from our current build is **how** they handle the browser's toolbar: hide on scroll (Booking.com, Bluesky), or an inner full-screen scroller (Instagram, Threads), or simply accepting it (YouTube, Spotify). Content and shopping sites use the top.

### Top findings

1. Safari treats a strip at the bottom of the viewport as "show the toolbar" while the toolbar is folded; reported as 44 px (2016), ~72 px on an iPhone 11 (2020 comment), ~40 px (2023). Apple: "This is expected behavior" (WebKit bug 194235, 2019). No Safari 26.x release note changes it. **High** that it exists, **Low** on its exact size in iOS 26.
2. iOS 26 Safari places `bottom: 0` above its floating controls on purpose and fills the area below with a guessed colour ("The bottom color fill is a heuristic", Apple engineer, WebKit bug 297779). Fixed elements jumping ~10 px when scroll direction changes was "a bug in a system component", fixed in 26.1 for some, still reported afterwards. **High**.
3. Safari 26 tints its own toolbars from fixed/sticky elements near the edges; a bottom bar's own background colour becomes Safari's toolbar tint, and hidden fixed elements at `opacity: 0` still count. **Medium** (community reverse engineering, no Apple documentation).
4. Chrome 135+ on Android goes edge-to-edge with a retracting "chin" and documents an exact CSS pattern for bottom bars using `safe-area-max-inset-bottom`; Chrome also lets people move its address bar to the bottom (stable 2025), which creates the same "two bottom bars" stack. **High**.
5. NN/g: hidden navigation is used less and found later; on mobile the penalty was smaller than on desktop and varied by site; recommend visible navigation for 4-5 top-level items. **High**.
6. Hoober: people prefer to look at and touch the centre; tabs belong along the top and bottom edges, tertiary items in a menu; centre targets can be 7 mm, corner targets need ~12 mm. A bottom bar is not automatically "in the thumb zone" for every grip. **High**.
7. Apple HIG: "Use a tab bar to support navigation, not to provide actions" (so "+ Add" as a tab is off-pattern; a separate button is the HIG way). iOS 26 tab bars may minimize on scroll. **High**.
8. Real-site survey: of the 18 sites I could render, 7 use a bottom bar in the mobile browser (all social/app-like or booking; Pinterest likely an 8th), 8 use a top bar with ☰ and/or a top tab row, 2 showed only logged-out marketing pages; 2 of the 7 hide the bar on scroll and 2 use an inner full-viewport scroller. **High** for what was rendered (logged-out pages, see method).
9. Chrome only gives an inner scroller the "page" behaviours (URL bar hiding, pull-to-refresh, overscroll) when it sits at (0,0) and matches the viewport exactly ("implicit root scroller", Chrome 73). A scroller between a fixed top and a fixed bottom bar does not qualify. **High**.
10. Since iOS 26 any site added to the Home Screen opens as a web app; `display-mode: standalone` is reported as `fullscreen` in some iOS installs (WebKit bug 264218), so a "standalone" CSS switch must also match `fullscreen`. **High**.

---

## 2. Real-site survey (mobile browser, not native apps)

**Method.** Each site was loaded on 2026-10-17 in headless Chromium 148 (Playwright) with an iPhone Safari user agent, a 390 × 844 viewport, touch and mobile emulation, logged out. A script listed every `position: fixed`/`sticky` element touching the top or bottom edge with its links, before and after scrolling ~1,200 px, and checked whether the window or an inner element scrolls. Screenshots were reviewed for ambiguous cases. Limits: this is Chromium layout with a Safari user agent, not real Safari; logged-out pages differ from logged-in ones; no installed-PWA mode was tested. Where a site blocked the headless browser, the row says so and nothing is claimed.

| Site | Bottom tab bar in the mobile browser? | What is at the top | Scroll behaviour of the bar / page | Confidence and source |
|---|---|---|---|---|
| m.youtube.com | **Yes**, fixed, 48 px in its CSS, frosted glass: Home · Shorts · You (logged out) | Fixed top bar: logo, search | Bar stayed after scrolling | **High**, rendered DOM + page CSS (`ytm-pivot-bar-renderer{position:fixed;bottom:0 ... height:48px}`) |
| Instagram (instagram.com) | **Yes**, icon-only: Home · Messages · Reels · Search · Profile (shown even logged out) | Logged-out profile: fixed "Log in / Open app" bar | Landing page scrolls an inner full-viewport `#scrollview` element, not the window | **Medium-High**, rendered DOM + screenshot; logged-in feed not checked |
| X (x.com) | Unverified (blank render, bot protection) | – | – | **Unverified** |
| Facebook (m.facebook.com) | Unverified (consent/login wall) | – | – | **Unverified** |
| Reddit | **No** | Fixed top header: ☰ "Open menu", Home, Open App, Answers, search, user menu | Window scroll, header stays | **High** (logged out), rendered DOM |
| TikTok web | **No** bottom bar seen (logged out) | ☰, "Open app", search over a full-screen video feed | Feed is full-screen | **Medium**, screenshot; logged in not checked |
| Threads (threads.com) | **Yes**: Home · Search · + · Activity · Profile | Fixed top bar (logged out: Log in / Get app) | Inner full-viewport `#scrollview` scrolls, not the window | **Medium-High**, rendered DOM + screenshot |
| Pinterest | Likely: a 52 px fixed bottom element exists (content hidden behind the login modal) | Sticky top: logo, Log in, Sign up | Stayed after scrolling | **Low-Medium**, rendered DOM |
| LinkedIn | Unverified (Cloudflare challenge) | – | – | **Unverified** |
| Airbnb | **Yes**: Explore · Wishlists · Log in; feature flags named `moweb_bottomnav_*` in the page | Sticky "Start your search" pill after scrolling | Bar stayed after a programmatic scroll (hide-on-scroll not observed in emulation) | **High** for the bar, rendered DOM + screenshot |
| Booking.com | **Yes**: Search · Saved · Sign in | No fixed top bar | **Bar moved off-screen after scrolling down** (hide on scroll) | **High** (logged out), rendered DOM |
| Amazon | **No** | ☰, logo, Sign in, cart, search field, horizontally scrollable link row (Deals, Lists, Video, Music, Best Sellers…) | Nothing fixed; the header scrolls away | **High**, rendered DOM + screenshot |
| eBay | Unverified (403 to headless) | – | – | **Unverified** |
| Strava | No (logged-out marketing site) | Sticky top with section links | – | **Medium**; logged-in web not checked |
| Goodreads | **No** | Fixed top bar: search, Home, My Books, Browse, … (top tabs) | Window scroll, top bar stays | **High** (logged out), rendered DOM |
| Letterboxd | Unverified (Cloudflare challenge) | – | – | **Unverified** |
| Duolingo web | No on the logged-out landing page | Fixed top bar | – | **Medium**; the logged-in app not checked |
| Spotify (open.spotify.com) | **Yes**: Home · Search · Your Library · Premium | Fixed top: logo, Open App, ☰ Menu | Bar stayed after scrolling | **High**, rendered DOM |
| Google Maps web | **No** tab bar | Search box with ☰ over the map, bottom sheet for content, app upsell dialog | Map, no page scroll | **High**, screenshot |
| GitHub | **No** | ☰ header, then a tab row (Code · Issues · More) | Nothing fixed | **High**, screenshot |
| Wikipedia (mobile) | **No** | Header with ☰ main menu + search | Nothing fixed | **High**, rendered DOM |
| BBC News | **No** | Sticky top bar with ☰ "Open menu" | Window scroll | **High**, rendered DOM |
| Discord web | Unverified (redirects to log in) | – | – | **Unverified** |
| Bluesky (bsky.app) | **Yes** when signed in: Home · Search · Messages · Notifications · Profile (logged out: a "Create account / Sign in" bar) | Top header with ☰ drawer that slides away on scroll | The web bottom bar is `position: fixed` and uses a "minimal shell" transform that slides it away while scrolling | **High**, open-source `BottomBarWeb.tsx` + rendered DOM; Bluesky's own issue tracker lists the bar hiding as having felt "janky" |

**What the survey says.**
- App-like, signed-in products (video, social, music, booking) **do** use bottom bars in the mobile browser. Content, reference, developer and shopping sites use a top bar, ☰ and often a horizontal row of sections (Amazon, GitHub, Goodreads).
- Of the bottom-bar sites, two hide the bar on scroll (Booking.com, Bluesky), two avoid Safari's folding by scrolling an inner full-viewport element (Instagram, Threads), and the rest keep a plain fixed bar (YouTube, Spotify, Airbnb as observed).
- Nobody uses a padding band like our `100dvh - 100svh` workaround.
- Installed-PWA behaviour was not testable here; no site in the list documents a different layout for its installed web app.

---

## 3. Platform findings

### 3.1 iOS Safari: the swallowed first tap

- **What happens.** When Safari has folded its toolbar, a tap near the bottom edge brings the toolbar back instead of reaching the page. Ben Frain (2016): Safari treats "the bottom 44px of the viewport" as reserved, links there need two taps; a 2020 commenter needed ~72 px of padding on an iPhone 11. Designary (2023): a ~40 px zone, "both in Safari and Google Chrome". Stack Overflow 21715917 (2014-2019 answers): no way to switch it off; workarounds are padding, or keeping the toolbar always visible. **High** that it exists; the size has grown with the toolbar and is **not documented** for iOS 26.
- **Apple's position.** WebKit bug 194235 "Bottom 44px area is not clickable and shows navbar" (2019): Apple's Simon Fraser: "This is expected behavior"; Tim Horton: it is Safari (not WebKit) behaviour, file it at Apple. Closed as MOVED. **High**.
- **iOS 26.** The toolbar is a floating Liquid Glass element in all three layouts (Compact default, Bottom, Top = address bar at the top and a toolbar at the bottom); in all three it shrinks or recedes while scrolling and comes back on scroll up. The owner's observation (first tap only restores the toolbar) is consistent with the old behaviour. I found **no** Apple note changing the zone in Safari 26.0-26.5 release notes. **Medium**.
- **Workaround cost.** Lifting the bar by the freed room (our `100dvh - 100svh`, capped at 84 px) moves the targets out of the zone but leaves a blank band while the toolbar is folded and makes the bar follow two animations (Safari's and ours). Any value is a guess per device and layout. No surveyed site does it.

### 3.2 iOS 26 Safari and fixed bottom elements

- **`bottom: 0` sits above Safari's controls by design.** In WebKit bug 297779 an Apple engineer explains that pages commonly have "interactive controls at the bottom of a `position:fixed; inset: 0`", so Safari keeps them above its controls and paints the strip below with a guessed colour: "The bottom color fill is a heuristic, and could be improved". **High**. Consequence: while Safari's toolbar is expanded, our bar is stacked directly on Safari's floating toolbar ("double bottom bar"), and the space under it is Safari's.
- **Fixed elements jumping.** WebKit 297779 "[iOS 26 Beta 7] Fixed elements move up and down when the scroll direction changes" (~10 px): "a bug in a system component", fix announced for 26.1 (covering WKWebView, so Chrome/Firefox on iOS too); later comments report it "not fully fixed" in 26.1 and still seen in Chrome on iOS 26.6.1 (Oct 2026). Status RESOLVED MOVED. **High**.
- **Other iOS 26 fixed-element reports.** Fixed bottom bars drifting after the keyboard closes (`visualViewport.offsetTop` not reset; Apple forums 800125/799216); `position: sticky; bottom: 0` not following the toolbar as it hides (forum 801028); fixed content cannot be drawn below the controls (forum 800798). Safari 26.1 notes: "Fixed a bottom gap appearing on layouts with viewport-sized fixed containers on iOS. (158055568)". **High** that these were reported; status after 26.1 mixed.
- **Toolbar tinting.** Safari 26 tints the status-bar area and its bottom toolbar by sampling fixed/sticky elements near the viewport edges (their own `background-color`/`backdrop-filter`), not `theme-color`; fixed elements with `opacity: 0` still count, so hidden overlays should be `display: none`; `viewport-fit=cover` and an explicit `html` background are recommended. A WebKit report on this tinting was closed "by design". Apple has published no rules. **Medium** (two independent community write-ups + GitHub PR).
- **Official Apple/WebKit guidance on bottom navigation in web pages:** none found. HIG covers native tab bars only; the WebKit blog posts for Safari 26/26.1 do not cover the toolbar's interaction with page content. **Medium** (absence of evidence).

### 3.3 Known solutions on iOS and their costs

| Approach | Removes the swallowed tap? | Cost |
|---|---|---|
| Padding/lifting the bar by the folded toolbar's room | Mostly (zone size is a guess) | Blank band, bar moves with two animations, device-specific numbers |
| Hide our bar while Safari's toolbar is folded (hide on scroll down, show with the toolbar) | Yes, by construction, if the two stay in step | Nav not visible while reading down; needs a reliable "toolbar is expanded" signal (§6) |
| Inner scroll container (toolbar never folds) | Yes | See §3.5: big costs for a Turbo Drive site |
| Installed web app (`standalone`) | Yes, no browser toolbar | Only for people who install; iOS reports `fullscreen` instead of `standalone` in some cases (WebKit 264218) |
| `minimal-ui` viewport keyword | No longer works (iOS 7.1 only) | – |

### 3.4 Android Chrome

- **Default layout.** Address bar at the top; it scrolls away. Chrome resizes the visual viewport, not the layout, and `position: fixed` elements track the visible area (Chrome "URL bar resizing", since Chrome 56). **High**.
- **Edge-to-edge since Chrome 135** (rolled out as a server-side variation, phones first): the viewport extends into the gesture-navigation area, covered by a "chin" that slides away on scroll. A bar with only `bottom: 0` ends up behind the gesture bar once the chin leaves. Chrome's documented pattern: grow the bar by `env(safe-area-max-inset-bottom, 36px)` and pull it down with `bottom: calc(env(safe-area-inset-bottom, 0px) - var(--safe-area-max-inset-bottom))`; do **not** put `env(safe-area-inset-bottom)` into `padding-bottom` ("layout thrashing, and Chrome stops sliding the chin away"). `viewport-fit=cover` skips the chin. `safe-area-max-inset-*` exists only in Chromium. **High**.
- **Address bar at the bottom** is a user option on Android since 2025 (stable rollout June 2025). Then our bar stacks on Chrome's bottom toolbar, the same "double bar" as Safari. Whether Chrome reveals its toolbar on a tap near the bottom like Safari: Designary says yes for Chrome, I found no Chrome documentation. **Low**, needs a device check.
- **Lag.** Chrome animates its chrome on the compositor and has a fast path for the `calc(env(...))` pattern; I found no Chrome reports comparable to WebKit 297779. **Medium**.

### 3.5 Deep dive: "fixed top + fixed bottom, only the middle scrolls"

The pattern: `html, body { height: 100%; overflow: hidden }`, a fixed (or grid) top bar and bottom bar, and one `overflow-y: auto` element between them that scrolls the content. Because the document never scrolls, Safari never folds its toolbar, so the bottom bar never sits in the reveal strip and never moves.

**Who uses it on mobile web today.**
- **Instagram and Threads (Meta):** both scroll an inner element `#scrollview` that sits at (0,0) and matches the viewport exactly (390 × 844 in the test), with a fixed bottom bar over it. **Medium-High** (rendered DOM, logged out). Note the exact-viewport size: that is precisely what Chrome needs to promote the element to the "implicit root scroller" and give it URL-bar hiding and pull-to-refresh (below). That is a different pattern from "the middle scrolls between two bars".
- **app.ft.com** was the classic example of "keep Safari's toolbar always visible" (cited by Ben Frain's commenters 2016 and Designary 2023). Current status not checked. **Low**.
- No other surveyed site uses it; YouTube's logged-out page did not scroll the window but the content was too thin to conclude anything.

**Costs, one by one.**

| Area | What happens with an inner scroller | Confidence |
|---|---|---|
| Safari's toolbar | Never folds: the expanded toolbar (iOS 26: the floating pill/toolbar) stays on screen permanently, and our bottom bar stacks on it because `bottom: 0` is placed above Safari's controls. Less content height on every page, all the time. | **Medium** (2016-2023 reports of the toolbar staying visible; iOS 26 stacking from WebKit 297779; needs device check) |
| Chrome Android | A scroller that is not exactly viewport-sized at (0,0) gets none of the page behaviours: the URL bar does not hide, no pull-to-refresh, no overscroll glow, no "tap the toolbar to scroll to top". Chrome promotes a scroller only if it is "at location (0, 0)", "match[es] the viewport size exactly", has no clipping ancestor, and is the only candidate (Chrome 73+). | **High** (Chrome root-scroller explainer) |
| Back/forward scroll position | The HTML spec says browsers should restore "all of document's scrollable regions", but implementations differ, and frameworks (Gatsby, TanStack Router, react-server) all register inner containers and restore them by hand. Turbo's own restoration saves and restores the **window** position only. This site turns Turbo restoration visits into full page loads (native Back), so it relies entirely on the browser restoring the window; with an inner scroller that becomes "whatever this browser does with nested scrollers". bfcache restores keep the DOM, including inner scroll offsets, but not every Back is a bfcache hit. | **High** for Turbo/spec, **Low** for per-browser behaviour (device check) |
| Turbo Drive visits | Turbo scrolls the window to the top on a new visit; the new `<body>` brings a new scroller at 0, so forward visits are fine. Anchor visits (`#id`) use `scrollIntoView`, which scrolls nested scrollers too. | **Medium** |
| Pull-to-refresh (iOS Safari 15+, Chrome) | Belongs to the document scroll. With a locked body there is none in Safari; WebKit has no switch for it either way (WebKit bug 275947). In Chrome only the full-viewport root-scroller case keeps it. | **Medium** |
| Tap the status bar to scroll to top (iOS) | iOS scrolls the one scroll view marked for it, the web view's own; an inner scroller is not it. Reports of it not working in web apps exist; no WebKit documentation. | **Low**, device check |
| Find in page, anchors, `scrollIntoView` | Browsers scroll nested scrollers to reveal the match/target. Works. | **Medium** |
| `position: sticky` inside | Sticks relative to the inner scroller; works, but every sticky element now depends on being inside that one scroller. | **High** |
| iOS rubber-band / scroll chaining | Each scroller bounces on its own; at the scroller's ends iOS tries to chain to the locked document, which historically produced "the whole page drags" effects; `overscroll-behavior` is supported in Safari 16+, but WebKit bug 275947 shows it does not control pull-to-refresh. | **Medium** |
| Height | The shell must be exactly the visible height: `100dvh` in iOS 26 had its own bugs (gap fixed in 26.1, more reports after). Classic `100vh` is wrong in both browsers. | **High** |
| Keyboard and inputs | With a fixed-height shell, the iOS keyboard shrinks only the visual viewport; fields near the bottom need scrolling inside the scroller, and fixed bars float over the keyboard area (iOS 26 reports of drifting after the keyboard closes). | **Medium** |
| Accessibility | Safari does not put a scrollable `<div>` in the keyboard focus order, so keyboard users (iPad with keyboard, desktop) cannot scroll it with arrows/space until something inside has focus; axe flags this (`scrollable-region-focusable`); fix: `tabindex="0"` + a label. Screen readers work, but VoiceOver's three-finger scroll and "scroll to top" act on the scroller you are in. | **High** (axe rule), **Medium** (VoiceOver detail) |
| Print | A fixed-height `overflow: auto` box prints only what fits; needs a print stylesheet resetting heights/overflow. | **High** |
| Performance | Since iOS 13 every `overflow: scroll` element scrolls asynchronously with momentum (no `-webkit-overflow-scrolling` needed); Chrome composites overflow scrolling too. No main-thread penalty in itself. | **Medium** |
| SEO | Content is in the DOM and indexed. Google renders with a very tall viewport (reported 12,140 px mobile), so a `100dvh` shell becomes that tall too and lazy content loads. Confirm with URL Inspection once. | **Medium** |
| iOS 26 specifically | An Apple forum reply suggested a child scroll container as a way around iOS 26 fixed-element gaps; Meta products use it. But iOS 26 adds nothing that makes the pattern cheaper: Liquid Glass content no longer flows under an always-expanded toolbar, and the tinting still samples the fixed bars. | **Low-Medium** |

**Verdict.** It is a legitimate pattern for full-screen **app shells** (chat, maps, editors, feeds built as SPAs with their own scroll restoration), and Meta uses the full-viewport variant precisely so Chrome still treats it as the page. It is **not** best practice for a server-rendered, document-per-page site like MySpeedPuzzling: it costs permanent screen space in Safari, URL-bar hiding and pull-to-refresh in Chrome (in the between-two-bars form), native Back scroll position (which this site deliberately relies on), status-bar tap to top, and keyboard scrolling unless patched. It fixes one bug by giving up several browser features. **Not recommended**; ranked below options E, B and D in §5.

---

## 4. UX research

- **NN/g, hidden vs visible navigation (2016 study, 179 participants, six sites).** Hiding navigation "makes it less likely to be used" and degrades UX on mobile and desktop; on mobile the effect "was not as big as on the desktop" and varied by site. Navigation use ranged from 89 % (combined visible + hidden) to 44 % (a hidden menu with a weak icon); time to first navigation 21-24 s vs 33 s. Recommendations: visible or combined navigation when there are 4-5 top-level items; a hamburger needs a visible label and clear tappable look. **High**.
- **NN/g, navigation pattern primer (2015).** The tab bar is "a close relative of the top navigation bar specific to apps", bottom on iOS, top on Android; navigation bars are inherited from desktop; hamburgers fit "content-heavy, browse-mostly sites". No recommendation for or against bottom bars on websites. **High**.
- **NN/g, sticky headers (2021).** Every sticky element costs space on every page; partially persistent headers (hide on scroll down, show on scroll up) "work moderately well"; trigger after a few pixels, animate 300-400 ms, finish the animation once started. The same logic applies to a bottom bar. **High**.
- **Hoober (UXmatters 2017, 1,300+ observations since 2013).** ~75 % of touches are by one thumb, under 50 % hold the phone one-handed, grips shift constantly; people prefer to view and tap the centre; accuracy needs ~7 mm targets in the centre and ~12 mm in corners. Guidance: primary content in the centre, "tabs and action buttons" along the top and bottom edges, tertiary functions in menus from a corner. So the top edge is not wrong for tabs, but the bottom is better for one-handed reach of frequent actions. **High**.
- **Material Design (Android guidance).** Navigation bar for 3-5 destinations on compact screens; a navigation drawer "isn't as ideal ... because users must reach for the top bar on compact sizes"; do not use a bottom bar on large screens. This is for apps; Material does not address the browser toolbar. **High**.
- **Apple HIG, tab bars (iOS 26).** "Use a tab bar to support navigation, not to provide actions"; keep it visible across sections (hiding it makes people forget where they are, except under modals); labels under icons; in iOS 26 the bar floats and may minimize when scrolling down. HIG has no web guidance. **High**.
- **Luke Wroblewski (2012).** Of four responsive patterns (footer anchor, toggle, select, top nav), a fixed bottom menu had touch-proximity benefits but "eats up screen space" and was unreliable in mobile browsers of the time. Historic but the trade-off stands. **Medium**.
- **Double bottom bars.** UX Movement: "a bottom navigation on a web app will have browser controls below it", recommend visual padding; Designary (2023): tapping near the bottom often brings up the browser toolbar "both in Safari and Google Chrome", fix with ~40 px spacing or keep the toolbar visible; The Hangline (2026): a fixed bottom nav "can overlap or get hidden" by the browser's bars. No controlled study found. **Medium**.
- **Hamburger vs bottom bar studies.** A University of Waikato thesis found users preferred a bottom bar to a top-left hamburger in mobile commerce; Baymard's benchmark notes mobile menus are hidden behind ☰ by default and that navigation is hard for users, without a published bottom-bar comparison. **Medium-Low**.
- **Thumb reach on large phones.** Covered by Hoober: reach depends on grip, which changes; the bottom-centre is reachable in the most grips; top corners are the hardest. **High**.

---

## 5. Options compared

Scoring from the evidence above; "iOS" means Safari on iOS 26.

| | A. Fixed bottom bar everywhere + mitigations | B. No bottom bar in the browser: top bar + section row, bottom bar only installed/native | C. Top navigation + floating "+ Add" button | D. Bottom bar that hides on scroll (own scroll direction) | **E. Bottom bar in step with the browser toolbar** (recommended) | F. Inner scroll container (middle scrolls) |
|---|---|---|---|---|---|---|
| Tap reliability on iOS | Poor: swallowed first tap unless padded; padding = blank band | **Excellent**: nothing in the reveal strip | Good if the button sits above the strip (~50-80 px up), else same problem | Good, but a small scroll up can show our bar before Safari's toolbar returns → swallowed tap again | **Good to excellent**: bar shown only while Safari's toolbar is expanded (needs a reliable signal, §6) | Excellent (toolbar never folds) |
| Motion / jank with browser chrome | Poor: fixed bar follows Safari's toolbar animation late (WebKit 297779, dvh updates late) | **None** at the bottom | One small element can still jump | Medium: our animation plus Safari's | Medium: both animate together; ours uses `transform` only | None, but toolbar is permanently on screen |
| Discoverability | Excellent: always visible, labelled | Good: visible section row (NN/g "visible"), ☰ for the rest | Good for sections at the top; Add always visible | Good: visible at load and on scroll up | Good: visible at load, page top/end and on scroll up | Excellent |
| One-handed reach | Best | Weakest (sections at the top) | Add reachable, sections at the top | Good | Good | Best |
| Screen space | Worst: ~56 px bar + Safari toolbar stacked + padding band | Best while reading (section row can scroll away; top bar stays) | Good | Good | Good | Worst: Safari toolbar never folds |
| Consistency with Hotwire Native tabs | Same tabs in all three | Different in browser vs app (bar only in app) | Different | Same tabs, hide behaviour differs | Same tabs; native iOS 26 tab bars also minimize on scroll | Same |
| Implementation risk | Low code, high device-specific tuning | **Lowest** | Low | Medium | Medium: depends on detecting Safari's toolbar state | **High**: scroll restoration, PTR, a11y, print, Turbo |
| Android Chrome | Fine with Chrome's edge-to-edge CSS; double bar if address bar at bottom | Fine | Fine | Fine | Fine | URL bar never hides (not full-viewport) |
| Seen on real sites | YouTube, Spotify, Airbnb, Instagram, Threads | Amazon, GitHub, Goodreads, Reddit, BBC, Wikipedia | – (none of the surveyed) | Booking.com, Bluesky | Booking.com and Bluesky are the closest | Instagram, Threads (full-viewport variant) |

**E in practice** (for the implementation discussion, not built here): show the bar at page load, near the page top and at the page end; on scroll down hide it with `transform: translateY(100%)` (compositor only, no layout); show it again only when the visitor scrolls up **and** Safari's toolbar is expanded again (signal candidates: `visualViewport.height`/`innerHeight` returning to the small viewport, `resize`/`visualViewport` `resize` events; measure which arrives first on iOS 26). Never pad by `100dvh - 100svh`. Hidden state must be `visibility: hidden` or moved fully off-screen so Safari 26's tint sampling does not keep using it (fixed elements at `opacity: 0` still tint). In `(display-mode: standalone), (display-mode: fullscreen)` and in Hotwire Native the bar is permanent. On Android use Chrome's documented `safe-area-max-inset-bottom` pattern. "+ Add" stays a tab-bar item for now; HIG would make it a separate button, worth revisiting for the native apps.

---

## 6. Open questions for a real-device check

1. **iOS 26 reveal strip size** in each Safari layout (Compact, Bottom, Top) on a small (SE/mini) and a large (Pro Max) iPhone: tap at 10, 30, 50, 70, 90 px above the bottom edge with the toolbar folded; which taps reach the page?
2. **Signal for "Safari's toolbar is expanded"** on iOS 26: does `visualViewport.height` (or `innerHeight`) change at the start or the end of Safari's animation, and does a `resize` event fire? Option E stands or falls with this.
3. **Option D/E timing:** after a short upward scroll that Safari does not answer with its toolbar, is our bar shown while the toolbar is still folded (the failure case)?
4. **Inner scroller in iOS 26:** confirm the toolbar never folds, check Back restores the inner position (with and without bfcache), status-bar tap, pull-to-refresh. Only needed if F is reconsidered.
5. **Installed web app on iOS 26** without a manifest `display` member: does `display-mode: standalone` or `fullscreen` match? With our manifest?
6. **Android Chrome with the address bar at the bottom:** does a tap near the bottom get swallowed like in Safari? Is the bar smooth with Chrome's edge-to-edge chin on a 2025+ phone?
7. **Tint:** with Safari 26, does our bottom bar's background colour become the colour of Safari's toolbar, and does a hidden bar stop tinting?
8. **Chrome on iOS and Firefox on iOS** (same WebKit, own toolbars): repeat 1 and 3.

---

## 7. Sources (all accessed 2026-10-17)

**Real-site survey**
- Own rendering run: headless Chromium 148 via Playwright, iPhone Safari user agent, 390 × 844, logged out; sites as listed in §2 (no URLs stored beyond those named).
- YouTube mobile CSS (`ytm-pivot-bar-renderer`): https://m.youtube.com/
- Bluesky web bottom bar source: https://github.com/bluesky-social/social-app/blob/main/src/view/shell/bottom-bar/BottomBarWeb.tsx and https://github.com/bluesky-social/social-app/blob/main/src/view/shell/bottom-bar/BottomBarStyles.tsx
- Bluesky quality umbrella issue (bar hide "janky"): https://github.com/bluesky-social/social-app/issues/1675

**iOS Safari**
- Ben Frain, "The iOS Safari menu bar is hostile to web apps: discuss" (2016, comments to 2025): https://benfrain.com/the-ios-safari-menu-bar-is-hostile-to-web-apps-discuss/
- WebKit bug 194235, "Bottom 44px area is not clickable and shows navbar": https://bugs.webkit.org/show_bug.cgi?id=194235
- Stack Overflow 21715917 (answers via API): https://stackoverflow.com/questions/21715917/prevent-mobile-safari-from-presenting-toolbar-when-bottom-of-viewport-is-tapped
- Designary, "UX Tip #13: Sticky buttons and navigation elements on mobile web" (2023): https://blog.designary.com/p/sticky-buttons-navigation-elements-in-mobile-web
- WebKit bug 297779, "[iOS 26 Beta 7] Fixed elements move up and down when the scroll direction changes": https://bugs.webkit.org/show_bug.cgi?id=297779 (full text: https://bugs.webkit.org/show_bug.cgi?format=multiple&id=297779)
- Apple Developer Forums 800798, fixed content below the controls: https://developer.apple.com/forums/thread/800798
- Apple Developer Forums 799216, bottom gap / child scroll container workaround: https://developer.apple.com/forums/thread/799216
- Apple Developer Forums 801028, sticky bottom does not follow the toolbar: https://developer.apple.com/forums/thread/801028
- Apple Developer Forums 800125: https://developer.apple.com/forums/thread/800125
- Discourse Meta, "iOS 26 bugs with fixed position elements": https://meta.discourse.org/t/ios-26-bugs-with-fixed-position-elements-in-discourse/382831
- Safari 26.1 release notes: https://developer.apple.com/documentation/safari-release-notes/safari-26_1-release-notes (also checked 26.0, 26.2-26.5 via the same documentation JSON)
- WebKit, "WebKit Features in Safari 26.0" (Home Screen web apps): https://webkit.org/blog/17333/webkit-features-in-safari-26-0/
- 9to5Mac, iOS 26 Safari layouts: https://9to5mac.com/2025/09/15/iphone-ios-26-safari-new-compact-design/
- pavel 1ar.ionov, "Safari 26 Liquid Glass: toolbar tinting, white bars, viewport bugs" (2026): https://1ar.io/updates/safari-26-liquid-glass-web/
- jahir.dev, "How to correctly tint Safari's toolbar in iOS 26": https://jahir.dev/blog/safari-toolbar
- Huxpro PR "Fix iOS 26 Safari Liquid Glass viewport tinting": https://github.com/Huxpro/hux.pro/pull/92
- MDN browser-compat-data, `display-mode` (iOS notes): https://github.com/mdn/browser-compat-data/blob/main/css/at-rules/media.json
- WebKit bug 264218, display-mode on full screen PWA: https://bugs.webkit.org/show_bug.cgi?id=264218
- WebKit bug 275947, overscroll-behavior does not disable pull to refresh: https://bugs.webkit.org/show_bug.cgi?id=275947
- Safari 13 accelerated overflow scrolling (quoted release note): https://developer.apple.com/forums/thread/124077

**Android Chrome**
- Chrome for Developers, "Chrome on Android edge-to-edge migration guide": https://developer.chrome.com/docs/css-ui/edge-to-edge
- Chrome for Developers, "Prepare for Chrome on Android going edge-to-edge": https://developer.chrome.com/blog/edge-to-edge
- Chrome for Developers, "URL bar resizing": https://developer.chrome.com/blog/url-bar-resizing
- 9to5Google, Chrome bottom address bar on Android (2025): https://9to5google.com/2025/06/24/chrome-bottom-address-bar-android/
- Chrome root scroller explainer (implicit root scroller criteria, M73): https://github.com/bokand/root-scroller/blob/master/explainer.md
- David Bokan on root scroller, csswg-drafts #4710 (2020): https://lists.w3.org/Archives/Public/public-css-archive/2020Jan/0788.html
- Chrome, overscroll-behavior: https://developer.chrome.com/blog/overscroll-behavior

**Scroll restoration, accessibility, SEO**
- HTML Standard, session history (restorable scrollable regions): https://html.spec.whatwg.org/multipage/browsing-the-web.html
- Gatsby, scroll restoration for containers: https://www.gatsbyjs.com/docs/scroll-restoration/
- Hotwired forum, Turbo scroll restoration: https://discuss.hotwired.dev/t/turbo-issue-with-scroll-position-after-restoration-visit/2189
- Deque axe rule `scrollable-region-focusable`: https://dequeuniversity.com/rules/axe/4.6/scrollable-region-focusable
- Search Engine Roundtable, Googlebot tall viewport: https://seroundtable.com/googlebot-scroll-26539.html

**UX research**
- NN/g, "Hamburger Menus and Hidden Navigation Hurt UX Metrics" (desktop): https://www.nngroup.com/articles/find-navigation-desktop-not-hamburger/
- NN/g, "Mobile: Hidden navigation…" (mobile results): https://www.nngroup.com/articles/find-navigation-mobile-even-hamburger/
- NN/g, study methodology: https://www.nngroup.com/articles/hidden-navigation-methodology/
- NN/g, "Basic Patterns for Mobile Navigation: A Primer": https://www.nngroup.com/articles/mobile-navigation-patterns/
- NN/g, "Sticky Headers: 5 Ways to Make Them Better": https://www.nngroup.com/articles/sticky-headers/
- Steven Hoober, "Design for Fingers, Touch, and People, Part 1" (2017): https://www.uxmatters.com/mt/archives/2017/03/design-for-fingers-touch-and-people-part-1.php
- Steven Hoober, "Design for Fingers and Thumbs Instead of Touch" (2013): https://uxmatters.com/mt/archives/2013/11/design-for-fingers-and-thumbs-instead-of-touch.php
- Android Developers, layout and navigation patterns: https://developer.android.com/design/ui/mobile/guides/layout-and-content/layout-and-nav-patterns
- Apple HIG, Tab bars: https://developer.apple.com/design/human-interface-guidelines/tab-bars
- Luke Wroblewski, responsive navigation deck (2012): https://static.lukew.com/omwe_04242012.pdf
- UX Movement, "Why Mobile Menus Belong at the Bottom of the Screen": https://uxmovement.com/mobile/why-mobile-menus-belong-at-the-bottom-of-the-screen/
- The Hangline, "Bottom Navigation Bar on Mobile Websites: Should You Use It?" (2026): https://www.thehangline.com/bottom-navigation-bar-on-mobile-websites-should-you-use-it/
- University of Waikato thesis, hamburger vs bottom bar in mobile commerce: https://researchcommons.waikato.ac.nz/entities/publication/3fa280bc-9c78-4449-b83c-6a39e93ee191
- Baymard, mobile navigation menu benchmark: https://baymard.com/mcommerce-usability/benchmark/mobile-page-types/navigation-menu

**Native apps**
- Hotwire Native iOS tabs: https://native.hotwired.dev/ios/tabs
- Hotwire Native Android tabs: https://native.hotwired.dev/android/tabs
