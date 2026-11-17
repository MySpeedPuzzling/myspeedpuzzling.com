# Displays: timer screens and stream overlays (competition toolkit D1-D8)

An event's **displays** are designed pages that run on their own secret link: a hall TV or projector (`screen`) or an
OBS browser source (`overlay`). The organiser designs them in an editor (backgrounds, logos, a timer, results, QR
codes, sponsor rotators), and the page stays live for hours without anyone signed in. Design:
`toolkit/D-displays-overlays.md`. Binding where they disagree: `toolkit/R-review-and-plan.md` §1-§3 (§3 cut the live
control, spotlight/message elements, copy from another event, ticker marquee, multi-select/align, sibling snapping, 3 of
the 7 fonts and the standings overlay to v2). Delivery: the D rows of `toolkit/STATUS.md`.

## What a display is

- **Per competition (edition)**, several per event, **at most 20** (`CompetitionDisplay::MAX_PER_COMPETITION`). Adds
  and duplicates hold the event's lock (`CompetitionDisplaysLock::key()`, `SerializedByLock`), so the cap is exact
  (`CompetitionDisplayLimitReached` → flash).
- **Kinds** (`DisplayKind`, fixed once added):
  - `screen`: opaque, fills the viewport letterboxed, wake lock, a full-screen button and a cursor only while the
    mouse moves (hidden after 3 s);
  - `overlay`: transparent by default, never any UI.

  Both kinds share one entity, one editor and one renderer.
- **Canvas** 16:9 only: a 1920×1080 logical stage scaled with CSS `transform`. Frames are stored in % of the canvas
  (2 decimals), font sizes in logical px at 1080p.
- **Round** (`DisplayFollowMode`): `current` follows `LiveResultsCurrentRound::pick()` among all rounds or a chosen
  subset (parallel halls; when every chosen round is deleted, all rounds are used). `pinned` shows one round; a
  deleted pinned round (`ON DELETE SET NULL`) behaves as `current`.
- **Texts** the display draws itself ("Starts in", "Time's up", "finished", "Table N"…) come from the display's own
  `locale` (one of 6), never from the viewer's.
- **Drafts:** a display works for a draft event (a rehearsal before publishing: the token is the organiser's
  capability). It is never listed or linked anywhere.

## Who may manage it

- Every organiser route needs `IS_AUTHENTICATED_REMEMBERED` + `CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE`
  (= `COMPETITION_EDIT`: every organiser of the event, admins).
  The voter is always asked on **the display's own event, loaded from the database** (`GetCompetitionDisplays::byId()`),
  never on an id from the request.
- The public display routes need no flag and no login. A display exists only if an admin or a flagged organiser made
  it (context rule 2). Referees have no access to the editor.

## Data (`competition_display`, `CompetitionDisplay`, migration `Version20261117005939`)

| Column | Notes |
|---|---|
| `id`, `competition_id` | FK `ON DELETE CASCADE`, index |
| `kind` | `screen` / `overlay`, immutable |
| `name` | ≤ 100 (`cleanName()`: control characters → space, trimmed, non-empty) |
| `token` | 22 chars, unique - `OnlineToken::generate()` (128 random bits, base58) |
| `locale` | `en/cs/de/es/fr/ja` (`checkLocale()`); the add form defaults to the request's locale |
| `design` (jsonb), `design_version` | normalised schema v1; +1 only when a save really changes something (`saveDesign()` compares key-order independently) |
| `follow_mode`, `pinned_round_id` (FK `SET NULL`), `follow_round_ids` (jsonb, null = every round) | `follow()` |
| `uses_results`, `uses_live_results` | derived on save from the **shown** elements (not hidden, ≥ 1 phase) - gate the results payload and the results signal |
| `created_at`, `updated_at`, `created_by_id` (FK `SET NULL`) | audit |

Spotlight and message (D §3.1) have no columns (v2).

**Messages** (all writes go through a handler):
- `AddCompetitionDisplay` (kind, name, locale, `DisplayPreset`, actor; the cap is counted under the lock);
- `RenameCompetitionDisplay`, `ChangeCompetitionDisplayLocale`;
- `ChangeCompetitionDisplayFollow`: every round id must be of the display's event, otherwise
  `CompetitionDisplayNotFound`. The subset is kept in the event's round order, and an empty subset or all rounds is
  stored as null;
- `DuplicateCompetitionDisplay`: same event (another event's display is not found under this lock), a new token, the
  name "Copy of …", the design version restarts at 1, and the image paths are shared;
- `RegenerateCompetitionDisplayToken`, `DeleteCompetitionDisplay` (images → after commit);
- `SaveCompetitionDisplayDesign`: locked per display (`CompetitionDisplaysLock::display()`), returns
  `SavedCompetitionDisplayDesign`;
- `DeleteCompetitionDisplayImages`: async, `DispatchAfterCurrentBusStamp`.

## Organiser pages

- **Hub card** "Displays & overlays" on `competition_toolkit` (`_card_displays.html.twig`): the table partial
  (`_displays_table.html.twig`: name, kind, followed round(s), copy link, open, edit design, settings, duplicate, new
  link, delete) + the "New display" form (`_display_add_form.html.twig`: kind, preset (must fit the kind), name,
  language), and a link to the full page. At 20 displays the form hides.
- **List page** `competition_displays` (`displays.html.twig`, `CompetitionDisplaysPage` builds both views).
- **Settings** `competition_display_settings`: name, language, follow mode, round subset or pinned round. It
  dispatches rename + locale + follow, then a `design` signal. 303 back (`?return=` via `ReturnUrl`), 422 on a refused
  form.
- Row buttons POST with a per-display CSRF token (`CompetitionDisplayActions::csrfTokenId()`), 303 + flash.
- **Links in:** the hub (itself linked from the event's edit page), "Displays" on the stopwatch control page
  (`manage_round_stopwatch.html.twig`) and the online control room (`online_rounds/control.html.twig`), each shown only
  while the voter grants it, the page byte-identical without it, and with `return`/`return_title` back.

## Routes

| Route | Path (en; cs `/sprava-obrazovek/…`, `/editor-obrazovky/…`) | Notes |
|---|---|---|
| `competition_displays` | GET `/en/manage-displays/{competitionId}` | list page |
| `add_competition_display` | POST `…/{competitionId}/add` | 303 → the editor; limit/invalid → back / 422 |
| `edit_competition_display` | GET `/en/display-editor/{displayId}` | the editor |
| `save_competition_display` | POST/PUT `…/{displayId}/save` | JSON, below |
| `upload_competition_display_image` | POST `…/{displayId}/image` | multipart, below |
| `competition_display_settings` | GET/POST `…/{displayId}/settings` | form |
| `duplicate_competition_display` / `delete_competition_display` / `regenerate_competition_display_token` | POST `…/{displayId}/duplicate` / `delete` / `new-link` | 303 + flash |
| `competition_display` | GET `/display/{token}` | the page (no locale prefix) |
| `competition_display_state` | GET `/display/{token}/state` | JSON state |
| `competition_display_design` | GET `/display/{token}/design` | `{designVersion, design}` |

**Save** (`SaveCompetitionDisplayDesignController`, via `OfficialResultsApi::authorise(..., attribute:
COMPETITION_TOOLKIT_MANAGE)`, header `X-CSRF-Token` = `csrf_token('official_results')`): body `{design, baseVersion,
liveConfirmed}` (decoded with objects as `stdClass`, so an object and a list stay apart), at most 256 KB.
- 200 `{designVersion, design, usesResults, usesLiveResults}`: the editor adopts the normalised design.
- 409 `{error: "conflict", designVersion}`: the version check runs first.
- 422 `{error: "invalid_design", errors: [{path, code, message}]}`: JSON pointers. `live_not_confirmed` is raised on
  every results element whose `source` became `live` and was not live in the stored design, unless `liveConfirmed:
  true` (context rule 5: the organiser confirms once).
- 400 `invalid_json`/`invalid_request`, 413 `too_large`, 404, plus the API's 401/403/415.

A save that changed the version forgets the presence cache and sends a `design` signal. A save that changed nothing
keeps its version, so open displays do not reload.

## Token URL security

- The **token is the capability**: an opaque key looked up by its unique index (the shape is checked first, a malformed
  one is a 404). It is stored in plain text, because the organiser must be able to copy it again. Regenerate = the old
  URL is dead at once (its next state fetch answers 404). Deleting the event cascades.
- The `/display/[base58]{22}(/state|/design)?` paths are on the **`stateless` firewall** (`config/packages/security.php`):
  no session, no cookie, no viewer. So nothing depends on who looks: the blocklist does not apply (a documented
  exemption), and names are the organiser's participant/team names only.
- Every answer is `Cache-Control: private, no-store` + `X-Robots-Tag: noindex, nofollow`. The page also sends
  `Referrer-Policy: no-referrer` + `<meta name="referrer" content="no-referrer">` (the token is in the path, and the
  pictures come from the img host), plus `<meta name="robots" content="noindex,nofollow">`.
- **What it reveals:** what the design shows. That means the event, the followed round, its stopwatch, the revealed
  puzzles, and results only as the design allows (below). It never carries participant, player, team or entry ids: row
  `key` = the first 16 hex characters of an HMAC-SHA256 of the entry ref, keyed with the token, so it is stable for DOM
  diffing and opaque.
- An unknown or regenerated token gets `not_found.html.twig` (standalone, 404). The kind is unknown then, so the page is
  transparent with one short "no longer valid" line for both kinds.
- **Rate limit `display_public`** (competition toolkit G3, `CompetitionDisplayRateLimit`, config/packages/rate_limiter.php):
  the page, `/state` and `/design` count together per **token + client IP**, sliding window **1200 a minute**, no lock
  (no Postgres advisory lock per request). Why that many: a screen fetches at most once per 1.5 s while results stream in
  (~40/min) plus the safety poll; 20 screens of one hall on one link behind one NAT address are ~820/min at the busiest,
  and reconnecting together adds 3 requests each. A loop or a script is held to 20/s. Over it: **429** with
  `Retry-After`, empty body, nothing read; logged at **info** (no token in the log). Another address, or another link
  from the same address, goes on. Test env: 100,000 (the 429 test uses a key of its own) - `CompetitionDisplayRateLimitTest`.

## Design schema v1, sanitizer and parity

- `DisplayDesignSanitizer` (PHP, the boundary) and `assets/displays/schema.js` (`normaliseDesign()`: the editor
  normalises what it saves, the renderer what it draws) implement the **same rules**. Both run over
  `tests/fixtures/display-design-cases.json` (42 cases: input → normalised output or error paths), from
  `DisplayDesignSanitizerTest` and `tests/display-schema-harness.mjs` (`DisplaySchemaScriptTest`). Change both
  together, errors in document order.
- Shape: `{schema: 1, canvas: {aspect "16:9", background: {type color|transparent|image|chroma, color, chroma, image:
  {path, fit, opacity}|null}}, theme: {font, color, accent}, elements: [...]}`. Each element is `{id /^[a-z0-9]{8}$/,
  type, name ≤ 60, frame {x, y, w ≥ 0.5, h ≥ 0.5, rotate -180..180}, z 0-999, hidden, locked, when {phases ⊆ idle,
  scheduled, running, times_up, stopped; results any|published|not_published; puzzle any|revealed|not_revealed}, style,
  props}`.
- **Structure errors refuse** (422): not an object, unknown schema, `elements` not a list, more than 60 elements, an
  element that is not an object, a bad or duplicate id, an unknown type, a missing frame, a picture that is not this
  event's upload (`foreign_image`), more than 30 pictures, more than 64 KB normalised.
- **Values are normalised:** clamped/rounded numbers, dropped unknown keys, bad colours (`#rrggbb(aa)` only) and enums →
  the default, plain texts (control characters removed, length capped). A style key (D §4.1 minus the cut fonts) is
  kept only with a valid value; missing = the theme's/type's default. There is no field for raw CSS, HTML, classes or
  URLs, except the event's own uploads and one `https://` QR link (≤ 300 characters).
- **15 element types** (`DisplayDesignSanitizer::ELEMENT_TYPES`, props in `props()`): `timer`, `status`, `progress`,
  `text`, `field`, `round_badge`, `image` (upload or the event/series/organization logo), `rotator` (≤ 30 pictures),
  `clock`, `puzzle` (+ `hiddenText`), `finished_count`, `leaderboard` (≤ 30 rows), `ticker` (latest finishers, ≤ 10,
  list only), `qr`, `box`. The results types (`RESULTS_TYPES`: `finished_count`, `leaderboard`, `ticker`) carry `source`
  published|live + `whenUnpublished` + `unpublishedText`.
- **Fonts (4):** `rubik`, `bebas`, `roboto_mono`, `courier_prime`. They are self-hosted woff2 latin + latin-ext
  (`public/fonts/rubik/`, `public/fonts/display/`, OFL), as `@font-face` "MSP Display …" in
  `competition_display/_fonts.html.twig`, which both the display page and the editor include. They are inline because
  the build's PostCSS turns `font-display: block` into `swap`, and a fallback flash on a projector is worse. Timers use
  `tabular-nums`. Japanese falls back to system fonts.

## Presets (`DisplayPreset`, `DisplayPresets`)

The 7 presets are compact definitions normalised through the sanitizer (`DisplayPresetsTest`: every preset normalises
to itself and keeps every value). They use only the event's own data:
- screen presets:
  - **Classic big timer** (`classic_timer`): event name, round badge, timer, status, progress;
  - **Timer with sponsor bar** (`timer_sponsor_bar`): + a box strip with the event logo, a sponsor rotator and an
    event-page QR;
  - **Timer with live board** (`timer_live_board`): timer left, live top 10 + finished count right;
- overlay presets:
  - **OBS lower third** (`obs_lower_third`): box + round name + timer + status, no spotlight line in v1;
  - **OBS corner timer** (`obs_corner_timer`);
  - **OBS leaderboard sidebar** (`obs_leaderboard_sidebar`): round name, finished count, live top 8, latest finishers;
- **Blank** (`blank`, either kind; an overlay starts transparent).

`showsLiveResults()` presets (the live board and the sidebar) start with `source: live`. That is their point, and the
editor badges those elements. `DisplayPreset::fits()` checks the kind, both in the form and in the handler.

## Read side (`GetCompetitionDisplayState::forToken()` → `CompetitionDisplayPayload`)

- **Statements:** 3 (display + event + series + organization; rounds; visible puzzles), plus 1 for results entries
  only when results may be shown now. Pinned by `CompetitionDisplayStateControllerTest` (4/3/3) and the page test.
- **Round + stopwatch:** a `RoundStopwatchState` read from the same row at the same moment, which is the public
  `/round-stopwatch` payload, so the display counts exactly like the projector page. `refreshAtMs` = the next moment
  the current-round pick may change by itself (a start, a scheduled start, the end of the 12 h "recent" window; not for
  a found pinned round) or the next reveal, whichever comes first.
- **Puzzles:** only `RoundPuzzlesVisibleNow`, the public rule for every viewer: the round reveal including the
  **stopwatch reveal mode** (opens only at a ticked start), image-only hiding, and the site-wide `hide_until` embargo.
  Nothing of a hidden puzzle is sent (name, picture, label, position, count); pick rounds' slots never are.
  `anyPuzzleRevealed` drives `when.puzzle`.
- **Results** only when `uses_results` and a round is shown, per type from `DisplayDesignSanitizer::resultsDemand()`
  (whether any shown element of the type is live, and the most rows any of them shows):
  - published round: everything the design asks for;
  - not published: only the types with an element the organiser switched to `live`. Otherwise
    `{published: false, finished: null, total: null, top: [], latest: []}`;
  - the renderer gates once more **per element**: a `published` element shows nothing (or its `unpublishedText`) even
    when another, live element brought rows;
  - **live rows keep their rank** (`OfficialResultsRanking`, ties included): the organiser opted in (the D3 decision).
- **Rows** (`DisplayResultRow`): `key, rank, name, members, countries, resultSeconds, piecesPlaced, didNotFinish,
  tableNumber, enteredAtMs`.
  - Names are the organiser's participant/team names; an unnamed team shows its members.
  - Country: the participant's own, else a linked player's only when the profile is public.
  - Did-not-start entries, unranked entries, removed participants and the waitlist are left out
    (`CompetitionParticipantGoing`). `finished` = ranked rows, `total` = entries without DNS.
  - `top` is sorted by rank, `latest` by `enteredAtMs`.
- **State JSON** (`CompetitionDisplayState` + payload): `displayId, kind, locale, designVersion, competition, round|null,
  stopwatch, puzzles, anyPuzzleRevealed, refreshAtMs, results, images {path: url}, followedRoundIds, texts, qr,
  rendererBuild, mercure, serverNowMs`.
- **QR codes** (`DisplayQrCodes`): for each shown `qr` element, the modules (`{size, rows}` of `0`/`1` strings,
  BaconQrCode, ECC M), cached 90 days per URL in the name-tag QR pool. The renderer draws one SVG path in the
  element's colours. Targets: `event_detail`/`edition_detail`, the round's public results page, or the custom
  `https://` link, absolute and in the display's locale. No slug = no code.
- `DisplayRendererBuild`: a 12-character hash of the `display` entry in `entrypoints.json` (null without the entry).

## Renderer (`assets/display.js` + `assets/displays/`)

- **Page** `competition_display/display.html.twig` is **standalone**: no `base.html.twig`, so no site chrome, service
  worker registration, self-heal scripts, Turbo or Bootstrap. Design + first state are inline (`json_encode|e('html_attr')`).
  An overlay gets inline `html,body{background:transparent!important}`; a screen gets black.
- **`render.js`** (`createRenderer()`: design + state + server now → DOM; shared by the display and the editor stage, so
  the editor is WYSIWYG by construction):
  - text only through `textContent`; the harness also scans `assets/displays/` for
    `innerHTML`/`outerHTML`/`insertAdjacentHTML`/`document.write`/`eval`;
  - the design is normalised by `schema.js` first, and every style value is re-checked (colour pattern, number type);
  - picture sources only from the state's URLs (images map, logos, revealed puzzles), http(s) only;
  - fixed class names (the one exception: a flag code checked against `[a-z]{2}`); QR rows digits only, ≤ 177;
  - absolute positions + `transform: scale`, flexbox without `gap` (OBS's CEF, TVs);
  - no timers or listeners: `nextChangeMs()` tells the runtime when the picture changes next.
- **`runtime.js`** (`DisplayRuntime`, DOM-free; `display.js` injects fetch, timers, Mercure and the renderer):
  - a state fetch on each `display.signal`, throttled to one per 1.5 s with one in flight;
  - polling: every 60 s as a safety net, every 5 s while the stream is down;
  - a fetch at `refreshAtMs` + ≤ 250 ms jitter, 2 s before and 1 s after a scheduled start, and when the page is shown
    again;
  - a stopwatch push of the shown round is applied at once (`isNewerState`); one of another followed round triggers a
    fetch (the server re-picks);
  - every fetch is a clock sample (`createServerClock`, the shortest round trip wins);
  - a newer `designVersion` fetches `/design` and rebuilds;
  - **404** → the invalid view, the stream closed, a check every 5 minutes;
  - **429** (`display_public`, `display.js` → `{status: 'busy', retryAfterMs}`) → the picture stays as it is, nothing
    is fetched until `Retry-After` has passed (kept within 1 s-10 min, 30 s when missing) + up to 5 s jitter, so a
    hall's screens do not come back at the same moment; signals and refreshes meanwhile fetch nothing (harness
    scenarios "a 429 …");
  - **reload** when `rendererBuild` changed (a deploy) or after 20 h of uptime, only in a calm phase (idle, stopped,
    times_up) and only right after a successful fetch;
  - wake lock for screens only (asked again when visible);
  - **no leaks:** one named slot per timer, no state history (harness: 10,000 state updates and 1,000 design swaps keep
    timers and DOM nodes constant).
- Mercure goes through `official_results_events.js` as is (fetch SSE with a Bearer token, renewal from each state's
  `mercure`).

## Live signals (D5)

- **Topic** `/competition-display/{competitionId}` (private; `OfficialResultsSubscription::forDisplay()` grants it +
  the followed rounds' public `/round-stopwatch/{roundId}`). The payload is `{"type": "display.signal", "what":
  "results|puzzles|round|design", "roundId"?, "displayId"?}` and carries no data.
- **`CompetitionDisplayLiveUpdates`** is called by controllers **after the dispatch returned (after commit)**, never
  from a handler, and logs a hub failure at `warning`. The call sites:
  - `resultsChanged` from `OfficialResultsLiveUpdates` (entries, refresh); `resultsChangedInCompetition` from the
    participants sheet;
  - `puzzlesChanged` from the reveal push (`puzzlesRevealed()`: stopwatch start with the tick, Reveal now, reveal
    settings), add/remove/arrange round puzzles, keep hidden, and the internal API puzzles;
  - `roundChanged` on round edit (UI + internal API) and results (un)publishing;
  - `roundsChanged` when a round is added, deleted or moved (UI, restructure, internal API);
  - `displayChanged` (what = `design`, always sent) on design save, settings, new link and delete.

  Stopwatch actions signal nothing: the public stopwatch topic already reaches the displays.
- **`CompetitionDisplaysPresence`** (`ResetInterface`) is the rule-1 guard. An event without displays publishes
  nothing, and once cached pays **0 statements**: answers per round and per event sit in the cache pool
  `competition_displays_presence_cache` (5 min, tagged per event) plus a per-request memo. A results signal also needs
  a display with `uses_results`. `changed()` forgets an event after a display is added, deleted or its design saved,
  and after a round moves between events.

## Editor (D6, `display_editor.html.twig`)

- `assets/controllers/display_editor_controller.js` (lazy Stimulus, this page only, `display_editor.scss`) + pure
  modules, all node-tested by `tests/display-editor-harness.mjs`:
  - `editor_state.js`: the document, selection, undo/redo of 100 steps, unsaved flag, catalogue groups
    clock/text/pictures/results/other, add/duplicate/delete/reorder/hide/lock;
  - `geometry.js`: move, 8 resize handles, ratio kept for image/QR, nudges 0.1 %/1 %, clamping, snap to the 1 % grid
    + canvas centre/edges only, hit test, `placeFrame()` avoids overlaps;
  - `editor_panel.js`: schema-driven property panel + layers list (eye, lock, actions), built with `createElement` only;
  - `sample_data.js`: the preview.
- **Toolbar** below the header: Undo/Redo, Data (**Sample** = the display's own state with a made-up clock, 20 sample
  results and 2 made-up puzzles; **This display** = the state the display shows now, inline from
  `CompetitionDisplayPayload` with secret puzzles under the public rule), preview phase (idle, scheduled, last seconds,
  running, warning, times up, stopped), Published and Revealed toggles, Snap, Save, plus Settings and Open display.
  Shortcuts: Ctrl+S, Ctrl+Z/Y, Ctrl+D, Del, Esc. An overlay shows a checkerboard behind the stage. Below md a hint says
  to edit on a computer.
- **Save is explicit** (no autosave: a half-edited design must never reach a hall screen), with a `beforeunload` guard.
  `live_not_confirmed` → ask once, resend with `liveConfirmed: true`. 409 → load the saved version (GET `/design`) or
  overwrite. Live elements carry a badge.
- **Images:** an upload in the panel or a file dropped on the stage (it is placed as an `image` element). No client-side
  compression.

## Images (D7)

- `UploadCompetitionDisplayImageController`: multipart field `file`, header `X-CSRF-Token` (`official_results`), the
  voter on the display's event.
  - Accepts JPEG/PNG/GIF/WebP by sniffed content (`DisplayImages::EXTENSIONS`, never SVG) up to 5 MB
    (`MAX_FILE_BYTES`).
  - Rate limiter `competition_display_image_upload`: 60/h per player.
  - `ImageOptimizer` strips EXIF and scales anything over 2000 px down, keeping alpha.
  - Answers 200 `{path, url}`; 413 `too_large`, 415 `unsupported_type`, 422 `no_file`/`invalid_image`/`upload_failed`,
    429 `rate_limited`.
- Stored at `competition-displays/<competitionId>/<uuid7>.<ext>` (`DisplayImages::newPath()`). The sanitizer keeps only
  this event's paths, so removing a picture never touches another event's file.
- **Delivery** `DisplayImages::url()`: `{nginxProxyBaseUrl}/preset:{DISPLAY_IMAGE_PRESET}/plain/<path>`; the `.env`
  default is `display` (`rs:fit:1920:1920`, alpha kept - defined in dev `compose.yml` and lily's imgproxy). Empty, or
  not a plain name, = the original upload `/original/` (`UploaderHelper::getPublicPath()`).
- **Lifecycle:** `GetStoredFileReferences` has 3 UNION branches over `competition_display.design` (background
  `canvas.background.image.path`, every element's `props.path`, rotator `props.images[].path`), and every row counts,
  so a duplicate keeps shared files. `DeleteCompetitionDisplayImages` (async, after commit) → `DeleteUnreferencedStoredFiles`
  (shared with page sections), only for `DisplayImages::isDisplayUpload()` paths. It is dispatched by a save (the
  pictures that dropped out), by display delete, and by event/series delete (`GetCompetitionDisplayImagePaths`).
  Never-saved uploads are orphans (`docs/TODO.md` prune item).

## Duplicate event, convert to series

- `DuplicateCompetitionHandler::copyDisplays()` (D8) copies every display: name, kind, locale, follow mode, flags, a
  **new token**, the pinned round and followed rounds remapped onto the copied rounds (an unmapped pin → null =
  current). Pictures are copied to the copy's own prefix (each file once); a missing one is dropped from the design
  with a `warning`. See `README.md` "Duplicating an event".
- `ConvertCompetitionToSeriesHandler` **refuses** an event with displays (`SeriesConversionBlocker` `displays`;
  `ConvertCompetitionForeignKeyCoverageTest` lists `competition_display.competition_id` as refused).

## Build and service worker

- Encore entry `display` (`assets/display.js` + `assets/styles/display.scss`) gets its own file without shared split
  chunks (`webpack.config.js`).
- `public/service-worker.js` `PRECACHE_SKIP_ENTRIES` includes `display`: never precached for every visitor
  (`ServiceWorkerInstallTest::testTheDisplayEntryIsNeverPrecached`). The display page registers no service worker.

## Known gaps

- Not walked in a real OBS browser source; no multi-hour soak (D4). The D5 live-signal browser walk is pending (the dev
  hub's origin) → H.
- English only so far (`competition_display.*`); the 5 other locales come with the TR package.
- v2 (R §3): live control (spotlight/message elements and page), copy from another event, ticker marquee,
  multi-select/align/distribute, sibling snapping, the standings/bracket overlay element, scenes, animations, 4:3 and
  portrait.

## Tests

- **Handlers** (`tests/MessageHandler/CompetitionDisplay/`): `CompetitionDisplayHandlersTest`,
  `CompetitionDisplayDesignHandlersTest`, `CompetitionDisplayImagesTest`.
- **Services** (`tests/Services/CompetitionToolkit/`): `DisplayDesignSanitizerTest`, `DisplayPresetsTest`,
  `CompetitionDisplayLiveUpdatesTest`.
- **Controllers** (`tests/Controller/CompetitionToolkit/`): `CompetitionDisplaysPagesTest`,
  `EditCompetitionDisplayControllerTest`, `SaveCompetitionDisplayDesignControllerTest`,
  `UploadCompetitionDisplayImageControllerTest`, `CompetitionDisplayControllerTest`,
  `CompetitionDisplayStateControllerTest` (incl. the 4/3/3 statements), `CompetitionDisplaySignalsTest` (hub spy),
  `DisplaysLinksTest`.
- **Secret puzzles:** `tests/CompetitionToolkit/DisplaySecretPuzzleTest.php` (incl. the stopwatch reveal at a ticked
  start, pick rounds), `SecretPuzzleRoutesTest`, `StopwatchRevealPagesTest` (trait `CreatesCompetitionDisplays`).
- **Read-side guards:** `BlocklistCanaryTest` (documented exemption: no viewer), `BlocklistQueryCoverageTest`,
  `PrivateProfileCanaryTest::testACompetitionDisplayNeverNamesHer`, `PrivateProfileQueryCoverageTest`,
  `DraftCanaryTest` (documented exemption), `DraftVisibilityCoverageTest`.
- **Other:** `DuplicateCompetitionHandlerTest`, `ConvertCompetitionToSeriesHandlerTest`,
  `ConvertCompetitionForeignKeyCoverageTest`, `SerializedByLockMessagesTest`, `ServiceWorkerInstallTest`,
  `ApplyParticipantSheetChangesControllerTest` (sheet signal).
- **Node harnesses:** `tests/display-schema-harness.mjs` → `DisplaySchemaScriptTest`, `display-render-harness.mjs` →
  `DisplayRenderScriptTest` (XSS corpus, innerHTML scan), `display-runtime-harness.mjs` → `DisplayRuntimeScriptTest`
  (throttle, polling, reloads, 404, wake lock, leaks), `display-editor-harness.mjs` → `DisplayEditorScriptTest`.
