# Performance Optimizations (LCP & CLS)

Optimizations targeting Largest Contentful Paint (LCP) and Cumulative Layout Shift (CLS) on mobile, primarily for the hub page (`/en/hub`).

## Critical CSS Strategy (`base.html.twig`)

- **`<main>` is NOT hidden** while CSS loads. Instead of `visibility:hidden`, we use `opacity:0` with a quick transition so skeleton placeholders are visible immediately.
- Footer remains hidden until full CSS loads.
- Inline critical CSS in `<style>` block includes: `.row`, `.col-lg-6`, `.table`, `.nav-tabs`, `.placeholder-glow`, `.tab-content`, `.custom-table-wrapper`, and other layout primitives needed for above-the-fold skeleton rendering.

## Font Loading (Self-hosted Rubik, `display=optional`)

Rubik is self-hosted as a variable font (WOFF2) in `public/fonts/rubik/`, with only latin and latin-ext subsets (needed for Czech diacritics). The `@font-face` declarations are inlined in the critical CSS `<style>` block and use `display=optional` to eliminate FOUT.

Both font files are `<link rel="preload">`-ed so the browser starts downloading them at highest priority before CSS parsing. Combined with same-origin serving (no cross-origin DNS/TLS overhead), the font typically arrives within the ~100ms `optional` window even on first visit. On slow connections, the system font is used gracefully with zero layout shift.

The service worker precaches both font files on install, so repeat visits serve fonts instantly from cache.

## No Early Hints (removed 2026-10-06)

The app used to send `103 Early Hints` preloading the `app` CSS/JS. Measured 2026-10-05 in Chromium 152 with the real
guest puzzle page and assets (cold cache, shaped networks) and removed, so do not bring them back without new data:

- A working 103 changed LCP by -20 to -180 ms at our server think time (p50 ~67 ms, p95 ~170 ms) and made it 250-1050 ms
  *worse* on mobile at 250 ms think time (the preloads fill the slow link before the HTML arrives).
- Only ~1 % of page views could use it: a cold first visit after an asset change, Chrome 152+ (older Chrome drops a
  `Link` entry with `integrity`), no service worker yet (a page the service worker serves starts no early preloads).
  No measurable effect on CrUX p75, no SEO effect (Googlebot crawls over HTTP/1.1, where 103 is ignored).
- In production the 103 never reached browsers anyway - Traefik v3.7's retry middleware swallows 1xx - and the copy of
  the `Link` header PHP leaves on the 200 measured worse than no hints (the JS competed with the render-blocking CSS).

## Dynamic Imports

### Flatpickr (`datepicker_controller.js`)
- Flatpickr CSS (128KB) and JS are loaded dynamically via `import()` only when `.date-picker` elements exist on the page.
- This eliminates a large CSS chunk from initial load on 95%+ of pages.

### Barcode Scanner Polyfill (`barcode_scanner_controller.js`)
- `@undecaf/zbar-wasm` and `@undecaf/barcode-detector-polyfill` CDN scripts are no longer in `base.html.twig`.
- They are loaded dynamically in the barcode scanner controller's `scanLoop()` method, only when the scanner is actually activated.
- Saves ~25KB on every page load.

### LightGallery (`gallery_controller.js`)
- LightGallery JS (~46KB), plugins (fullscreen, zoom), and CSS are loaded dynamically via `import()` only when `.gallery` elements exist on the page.
- Moved out of the main vendor chunk into a separate lazy chunk.

### Google Analytics (`analytics_controller.js`)
- GA is deferred using `requestIdleCallback` (with 5s max timeout fallback) instead of a fixed 300ms timeout.
- This ensures GA doesn't compete with LCP resources — it loads when the browser is idle.
- User interaction (scroll, click, touch) still triggers immediate loading.

## Selective Bootstrap Imports

### SCSS (`app.scss`)
Instead of `@import 'bootstrap/scss/bootstrap'`, we import individual Bootstrap components. Excluded unused components:
- `offcanvas` (~16KB)
- `carousel` (~7.5KB)
- `popover` (~6.3KB)
- `tooltip` (~5.3KB)

Keep `accordion` (used on FAQ page).

### JS (`app.js`)
Instead of `import 'bootstrap'`, we import only used JS components:
- `modal`, `dropdown`, `collapse`, `tab`, `toast`
- Exposed on `window.bootstrap` for controllers that use `bootstrap.Modal` etc.
- Excluded: tooltip, popover, offcanvas, carousel, scrollspy, alert, button plugins

## CLS Fixes

### Live Component Skeletons
- All placeholder macros in `MostSolvedPuzzles`, `RecentActivity`, and `LadderTable` use `height: 80px` for image placeholders, matching the real `lazy-img-wrapper lazy-img-80` wrapper size.

### Hub Page First RecentActivity
- The first `<twig:RecentActivity>` on the hub page does NOT use `loading="lazy"` since it's above-the-fold. This eliminates the deferred AJAX round-trip and skeleton-to-content shift.

### Puzzle Detail Hero Image
- Hero `<img>` tags on puzzle detail pages have `fetchpriority="high"` for faster LCP.

### Lazy Live Components on the Hub
- A lazy component's placeholder has its content's exact shape: `MostActiveSoloPlayers` renders its numbered empty rows and the room of its "Show more" button (navigation Platform feel, Hub CLS). `MostSolvedPuzzles`' placeholder has not been checked yet (`docs/TODO.md`).
- The Hub's feed scroller (phones, `feed_swipe_controller.js`) sets a height only while a swipe runs, none at rest.

## Navigation chrome (`docs/features/navigation/`)

The bars, the Menu and the rail/column are on every page, so they must cost no layout shift, no statement and few bytes.

### Layout before the first paint
- **Every chrome length is set in critical CSS**, `templates/navigation/_critical_css.html.twig`, included at the end of `base.html.twig`'s critical `<style>`: `--header-height`, `--topbar-h`, `--bottom-nav-height`, `--tabbar-h`, `--nav-left`, decided by media queries and `<html>` attributes - never written by JavaScript (`NavLayoutCssTest`), so the bars, the rail and the docked column take their room at the first paint. Keep it plain CSS, one rule per line, no Twig output: `ChromeWhitespace::compactCss()` compacts it when the template compiles (neighbouring rules with the same media query merge).
- **Head flag scripts** (`templates/_head_flags.html.twig`, right after the viewport meta): `assets/head/platform_os.js` (`data-os`), `standalone.js` (`html.is-standalone`), `nav_layout.js` (`data-nav-layout`, collapsed Menu groups) set what CSS needs before the first paint. They are minified builds of readable ES5 sources (`docker compose exec js-watch npm run inline-scripts`, `InlineScriptsBuildTest`), and their text is the same on every page - Turbo re-runs a head script whose text changed.
- **The bottom bar's label step** (11 px → 10 px → current tab only) is chosen by CSS container queries from a measured table (`assets/styles/_nav-label-steps.scss`); JavaScript raises it after paint only where a device's font renders wider.
- **A Turbo visit lands at once:** `assets/navigation/visit_scroll.js` makes `<html>` scroll instantly from `turbo:before-render` until Turbo has scrolled (app.scss keeps `scroll-behavior: smooth` for in-page jumps, which would glide every new page from the old offset).

### Statements and bytes
- **0 statements per page view** for the chrome: everything rides on the viewer's profile row, the one Inbox badge statement or the memoised `admin_queue_counts()` (moderators/admins). Pinned by `ChromeQueryBudgetTest`, `HubQueryBudgetTest`, `PlayerProfileQueryBudgetTest`; lazy endpoints (`menu_counts`, `player_card`, the Help frame) are measured against an empty signed-in endpoint (`tests/Navigation/EmptySignedInEndpoint.php`).
- **Weight budget:** the chrome may grow `/en/faq`'s HTML by at most 30 KB for a member and 20 KB for a guest over the P0-02 baseline (`ChromeWeightBudgetTest`, the whole page uncompressed - a change to the FAQ moves it too). What keeps it lean:
  - `ChromeWhitespace` strips the indentation of the templates rendered around every page at compile time. Only such templates belong in its list (a partial shared with page content does not), and a listed template never holds `<pre>`, `<textarea>`, literal text styled `white-space: pre*`, a JS template literal, a `{% trans %}` block, a `{% set %}` capture, `{% verbatim %}` or `{% apply %}` (`ChromeWhitespaceTest`).
  - The icon sprite (`navigation/_icons.html.twig`) carries only the symbols the page's bars use: person icons for guests, Inbox/chevron for signed-in players, the back arrow on non-root pages. A Turbo frame, stream or Live re-render must not rely on one of these conditional symbols (use the `bi` font or an always-carried symbol). Paths keep the published Bootstrap Icons numbers (`SpritePathsTest`).
  - JSON in chrome data attributes is `data-x='{{ v|json_attribute }}'` (single-quoted), never `"{{ v|json_encode }}"`.
  - Data shared by many links rides on the page's first one only: the Inbox link strings (`inbox_link_strings()`, `InboxLinkStringsTest`), the Puzzles sections JSON.
  - The page search's list is never inlined: `/{_locale}/nav-search.json`, fetched on the first focus.
- **Page-only styles** go into their own Encore entry, never `app.scss`: `where_did_it_go` next to `compete` / `online_*`; the "What moved" announcement modal carries its styles inline (it renders once per player ever).
- Not done yet: the navigation's critical CSS (~13 KB of the member's growth) repeats `app.css` box rules (`docs/TODO.md`).

## Guidelines for Future Changes

- When adding new Bootstrap components, check if they need to be added to the selective imports in `app.scss` and `app.js` (+ `window.bootstrap` object).
- When creating new Live Component skeletons, ensure placeholder dimensions match the real rendered content dimensions.
- Prefer dynamic `import()` for heavy libraries that are only used on specific pages (datepickers, charts, scanners).
- Keep CDN scripts out of `base.html.twig` — load them dynamically in the controller that needs them.
