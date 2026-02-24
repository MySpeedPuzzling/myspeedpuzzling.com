# Performance Optimizations (LCP & CLS)

Optimizations targeting Largest Contentful Paint (LCP) and Cumulative Layout Shift (CLS) on mobile, primarily for the hub page (`/en/hub`).

## Critical CSS Strategy (`base.html.twig`)

- **`<main>` is NOT hidden** while CSS loads. Instead of `visibility:hidden`, we use `opacity:0` with a quick transition so skeleton placeholders are visible immediately.
- Footer remains hidden until full CSS loads.
- Inline critical CSS in `<style>` block includes: `.row`, `.col-lg-6`, `.table`, `.nav-tabs`, `.placeholder-glow`, `.tab-content`, `.custom-table-wrapper`, and other layout primitives needed for above-the-fold skeleton rendering.

## Font Loading (`display=optional`)

Google Fonts uses `display=optional` instead of `display=swap`. This eliminates FOUT (Flash of Unstyled Text) — if Rubik isn't cached, the system font is used for that pageview with zero layout shift. On repeat visits, Rubik is cached and used.

## Dynamic Imports

### Flatpickr (`datepicker_controller.js`)
- Flatpickr CSS (128KB) and JS are loaded dynamically via `import()` only when `.date-picker` elements exist on the page.
- This eliminates a large CSS chunk from initial load on 95%+ of pages.

### Barcode Scanner Polyfill (`barcode_scanner_controller.js`)
- `@undecaf/zbar-wasm` and `@undecaf/barcode-detector-polyfill` CDN scripts are no longer in `base.html.twig`.
- They are loaded dynamically in the barcode scanner controller's `scanLoop()` method, only when the scanner is actually activated.
- Saves ~25KB on every page load.

## Selective Bootstrap SCSS (`app.scss`)

Instead of `@import 'bootstrap/scss/bootstrap'`, we import individual Bootstrap components. Excluded unused components:
- `offcanvas` (~16KB)
- `carousel` (~7.5KB)
- `popover` (~6.3KB)
- `tooltip` (~5.3KB)

Keep `accordion` (used on FAQ page).

## CLS Fixes

### Live Component Skeletons
- All placeholder macros in `MostSolvedPuzzles`, `RecentActivity`, and `LadderTable` use `height: 80px` for image placeholders, matching the real `lazy-img-wrapper lazy-img-80` wrapper size.

### Hub Page First RecentActivity
- The first `<twig:RecentActivity>` on the hub page does NOT use `loading="lazy"` since it's above-the-fold. This eliminates the deferred AJAX round-trip and skeleton-to-content shift.

### Puzzle Detail Hero Image
- Hero `<img>` tags on puzzle detail pages have `fetchpriority="high"` for faster LCP.

## Guidelines for Future Changes

- When adding new Bootstrap components, check if they need to be added to the selective imports in `app.scss`.
- When creating new Live Component skeletons, ensure placeholder dimensions match the real rendered content dimensions.
- Prefer dynamic `import()` for heavy libraries that are only used on specific pages (datepickers, charts, scanners).
- Keep CDN scripts out of `base.html.twig` — load them dynamically in the controller that needs them.
