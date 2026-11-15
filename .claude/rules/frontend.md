---
paths:
  - "templates/**"
  - "assets/**"
  - "src/Component/**"
  - "src/Twig/**"
  - ".claude/symfony-ux-hotwire-architecture-guide.md"
---

# Frontend: Turbo, Stimulus, Live Components

## Turbo Configuration
- **Turbo Drive is globally enabled** for SPA-like forward navigation
- **Snapshot cache is disabled** via `<meta name="turbo-cache-control" content="no-cache">` — no stale content flashes
- **Link prefetch is disabled** via `<meta name="turbo-prefetch" content="false">`
- **Back/forward navigation uses native browser behavior** — restoration visits are intercepted in `app.js` and redirected to `window.location.href` for reliable scroll restoration and iOS swipe-back
- To disable Turbo on specific links or forms, use `data-turbo="false"`
- Turbo Frames still work as before: `<a href="..." data-turbo-frame="modal-frame">`
- **Forms inside the `modal-frame` MUST set an explicit `action:` on `form_start`.** Without it the browser posts to the hosting page URL (not the route that rendered the modal), and the hosting page's response usually includes an empty `<turbo-frame id="modal-frame">` from `base.html.twig` → Turbo swaps the empty frame in → modal silently closes, nothing saved, no error logged. See `.claude/symfony-ux-hotwire-architecture-guide.md` §Gotchas.
- Gate stream responses on the `Turbo-Frame: modal-frame` header, not just `getPreferredFormat() === TurboBundle::STREAM_FORMAT` — Turbo 8 sends stream-accept on every form submission, including full-page ones, so a stream-only check returns the stream for full-page flows too and the redirect is skipped. See Gotchas §2.
- **Full-page form POSTs must never answer 200.** Turbo Drive discards a 200 answer to a form submission (only a `console.error`) — no page, no flash, no field error; the visitor sees nothing happen. Redirect on success; re-render with 422. `render()` sets 422 only when handed the `FormInterface` itself (`'form' => $form`), never `$form->createView()`; a valid form re-rendered with a flash needs an explicit `new Response(status: 422)`. Frame submissions (`Turbo-Frame` header) are exempt. `TurboDriveFormResponseSubscriber` logs a warning (→ Sentry) for every such answer a real browser gets
- See `.claude/symfony-ux-hotwire-architecture-guide.md` for modal architecture patterns and the full Gotchas list


## Frontend Architecture
- **Stimulus Controllers** (`assets/controllers/`): Interactive components (stopwatch, barcode scanner, charts)
- **Live Components** (`src/Component/`): Server-rendered dynamic components
- **Twig Templates** (`templates/`): Server-side rendered views with reusable partials

## Performance Optimizations
See `docs/performance-optimizations.md` for details on LCP & CLS optimizations:
- Critical CSS strategy (inline styles for skeleton rendering, `<main>` not hidden)
- Font loading with `display=optional` (no FOUT)
- Dynamic imports for flatpickr and barcode scanner polyfill
- Selective Bootstrap SCSS imports (excluded: offcanvas, carousel, popover, tooltip)
- Skeleton placeholder height alignment for Live Components
