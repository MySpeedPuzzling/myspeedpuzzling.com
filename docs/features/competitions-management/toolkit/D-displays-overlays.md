# D - Competition displays: timer screens and stream overlays (design)

Architect D, 2026-11-17. Scope: organiser-designed **timer screens** (projector / TV in the hall) and **stream overlays**
(OBS browser source), both made of elements placed in an editor, each with its own unguessable URL. Design only -
grounded in the code as of `main` 0ad091b37. Cross-cutting rules: `context.md` (opt-in, flag
`COMPETITION_TOOLKIT_FOR_ORGANISERS`, CQRS, secret puzzles, unpublished results).

---

## 1. Findings (what exists, file:line)

**The round stopwatch today**

- Public projector page `round_stopwatch` (`src/Controller/RoundStopwatchController.php:32-65`,
  `templates/round_stopwatch.html.twig`) extends `base.html.twig` with the header and footer blocks empty
  (`round_stopwatch.html.twig:7-8`). It shows the event name, the round name and `HH:MM:SS` in a 6rem monospace
  font (`:30`), the last ten seconds of a countdown in 12rem (`:40`), and a status line. Organisers also get Start and
  Manage buttons (`:58-73`). Drafts answer 404 for non-organisers (`RoundStopwatchController.php:50-52`).
- State payload `RoundStopwatchState` (`src/Results/RoundStopwatchState.php:28-88`). It is the first render, the
  `GET /round-stopwatch-state/{roundId}` answer (`RoundStopwatchStateController.php:27-44`, which adds `serverNowMs`),
  and every Mercure update on the **public** topic `/round-stopwatch/{roundId}`
  (`RoundStopwatchLiveUpdates.php:40-60`). The controllers publish after the commit
  (`AbstractRoundStopwatchController.php:104-105`).
- Client: `assets/controllers/round_stopwatch_controller.js` (345 lines) uses the pure module
  `assets/round_stopwatch_clock.js`:
  - `createServerClock()` takes the clock offset from the state fetch with the shortest round trip;
  - `stopwatchView()` (`:72-105`) returns the phases `idle | scheduled | running | times_up | stopped`;
  - `isNewerState()` orders states by `asOfMs`.
  
  The controller keeps a `requestAnimationFrame` loop only while the clock is counting down or running, and writes
  text only when it changed (`setText`, `:321-326`). It falls back to polling every 3 s while Mercure is down, fetches
  2 s before and 1 s after a scheduled start, and fetches again on `visibilitychange`. The node harness is
  `tests/round-stopwatch-harness.mjs`. **I reuse this module as is.**
- `RoundClock` (`src/Value/RoundClock.php:25-160`) is the one reading of the stopwatch columns. `minutesLimit` is 0
  for imported rounds.

**OBS today**

- `docs/features/online-competitions/obs-youtube.md` sets the OBS browser source to 1920×1080 and 30 fps, with
  "Shutdown source when not visible" off. It says the projector page can be added as a second browser source,
  "cropped to the clock". That crop is a workaround for the missing overlay.
- Transparent page: `templates/online_rounds/watch.html.twig:28-31` puts inline CSS
  `html,body,main{background:transparent!important}` in the template on purpose. The comment explains why: "an OBS
  browser source may run a Chromium without `:has()`". **Lesson: OBS's CEF is behind current Chrome. The renderer
  must not use `:has()`, container queries or other recent CSS.**

**Live official results**

- Private topics. Organisers follow `/round-results/{roundId}`; referees follow `/round-results/{roundId}/referees`,
  which withholds private players (`OfficialResultsLiveUpdates.php:46-54, 102-133`). The withholding is
  `RoundResultEntry::forReferee()` (`src/Results/RoundResultEntry.php:64-94`). It still carries `participantId`,
  `resultEnteredByName`, `tableNumber` and `playerCode` of non-private players - **too much for a hall screen or an
  OBS feed**.
- Subscriber JWTs: `OfficialResultsSubscription` (`src/Services/OfficialResultsSubscription.php:27-135`) mints a
  subscribe-only token for an explicit topic list, valid for an hour. `forOnlineParticipant()` (`:91-94`) is the
  precedent for "no account, the link was checked". The client counterpart is `assets/official_results_events.js`
  (fetch-based SSE with `Authorization: Bearer`, reconnect backoff, silence detection, token renewal, no DOM, harness
  `tests/official-results-events-harness.mjs`). **I reuse it for the renderer.**
- What the public sees after publishing: `GetPublishedRoundResults.php:30, 407-419`. A private player keeps the
  organiser's participant name and shows nothing of the profile. The display uses exactly this masking (no viewer).
- "Which round is current": `LiveResultsCurrentRound::pick()` (`src/Services/LiveResultsCurrentRound.php:25-63`).
  It picks the running round (the latest started when several run), else the round started in the last 12 h, else
  the next one, else the last one.

**Secret puzzles**

- The reveal rule is `CompetitionRoundPuzzle::revealsAt()` / `isHiddenAt()` (`src/Entity/CompetitionRoundPuzzle.php:65-83`),
  with `RoundPuzzleReveal` (automatic = start + delay, scheduled, manual) and `PuzzleHideMode` (`image_only` /
  `entirely`).
- `GetEditionRounds::forCompetition()` (`src/Query/GetEditionRounds.php:77-150`) is the public read that applies it:
  - the round-level reveal;
  - the site-wide embargo (`hide_until` drops the row, `hide_image_until` nulls the image);
  - `image_only` keeps the name.
  
  The display's puzzle read must use the same rule. I propose extracting it so that a new "reveal when the timer
  starts" mode (another architect) lands in one place.
- `RevealRoundPuzzleNowController` dispatches `RevealRoundPuzzleNow` and publishes nothing live today.

**Uploads and images**

- Page-section pictures: `UploadPageSectionImageController.php`:
  - JPEG/PNG/GIF/WebP only, ≤ 5 MB (`:34-35, 87`), **no SVG**;
  - path `competition-pages/<owner>/<uuid7>.<ext>` (`:94`);
  - `ImageOptimizer` strips EXIF (`:97`);
  - per-player rate limiter (`:76`).
  
  The section keeps only paths under its own prefix (public-page.md "Security").
  `GetStoredFileReferences.php:34-46` lists every column and jsonb path that references a file, so the delete-after-
  commit message never deletes a referenced file. `DuplicateCompetitionHandler` copies the pictures to the copy's
  prefix (`:130`, public-page.md "Duplicating an event").
- `UploaderHelper` is a one-liner: `<nginx proxy>/original/<path>` (`src/Services/UploaderHelper.php:14-17`). The
  `thumbnail` filter builds `<nginx proxy>/preset:<p>/plain/<path>` (`src/Twig/ImageThumbnailTwigExtension.php:27-35`).
- imgproxy presets (`compose.yml:191`, the same on lily): `puzzle_small` 200, `puzzle_medium` 400 with enlarge,
  `avatar` 300, and `puzzle_large=rs:fit:1200:1200/f:jpg`. **`puzzle_large` forces JPEG and so loses logo
  transparency. There is no preset fit for a 1920-wide background or a transparent logo.**
- `Competition.logo` exists (`src/Entity/Competition.php:75`), and so do series and organization logos.

**Other reusable pieces**

- `OnlineToken` (`src/Value/OnlineToken.php:13-23`) is 128-bit base58, 22 characters, with no look-alike
  characters. It is "a database token rather than a signed URL: it can be shown again, revoked". This is exactly the
  display token.
- `NameTagQrCode` (BaconQrCode SVG, cached per URL) and `bacon/bacon-qr-code ^3` cover the QR element.
- `OnlineRoundsFeature` + `OnlineRoundsVoter` (`src/Services/OnlineRounds/OnlineRoundsFeature.php`,
  `src/Security/OnlineRoundsVoter.php`) are the flag + voter pattern to copy for the toolkit flag.
- Fonts are self-hosted: Rubik variable woff2 (`templates/base.html.twig:376-403`, `public/fonts/rubik`), plus
  CourierPrime and LazyDog in `assets/fonts`. No external font CDN and no CSP header.
- Frontend packages (`package.json`): Stimulus, Turbo, SortableJS, Coloris (colour picker), jsdom (harnesses). No
  React/Vue, and none is needed.
- Webpack entries (`webpack.config.js:23-27`): `app`, plus page style entries (`online_watch` …). A new
  standalone JS entry follows the same pattern.
- The service worker is registered from `base.html.twig`. A page that does not extend it gets neither the SW
  registration nor the stale-document and asset self-heal scripts. An already installed SW (scope `/`) still sees
  the navigation, but it is network-only for HTML and cache-first for `/build/*`, which is harmless here.

---

## 2. Decisions in one place

| Question | Decision |
|---|---|
| Ownership | **Per competition (= edition).** "Duplicate event" copies its displays. "Copy displays from another event" imports from any event the organiser can edit, which covers a series: copy last week's. A series-level design library is v2. |
| Kinds | `screen` (hall TV / projector: fills the viewport, letterboxed, opaque background) and `overlay` (OBS: transparent by default, 1920×1080, no cursor, no UI ever). The two kinds share one entity, one editor and one renderer. |
| Canvas | 16:9 only in v1, with a 1920×1080 logical stage scaled by CSS `transform` to the viewport. Element frames are stored in **% of the canvas**, font sizes in **logical px at 1080p**. It scales identically everywhere. 4:3, portrait and custom sizes are v2. |
| URL | `/display/{token}`: no locale prefix (the URL never changes) and no login. The token can be regenerated, which kills the old URL at once. Texts come from the display's own `locale`. |
| Round | `follow = current` (`LiveResultsCurrentRound::pick`, optionally among a chosen subset of rounds, for parallel halls) or `pinned` (one round; a deleted round falls back to current). |
| Live results before publishing | Allowed only per element, with the property `source: "live"`. The editor's default is `"published"`, and the editor marks every live element with a badge. A design without results elements gets **no results data at all** in its state. |
| Drafts | A display works for a draft event (rehearsal before publishing - the token is the organiser's capability). Its pages are `noindex`. DraftCanaryTest lists it as an exemption with that reason. |
| Default stopwatch | `round_stopwatch.html.twig` and its controller are **not touched**. The only change on existing pages is a "Displays" link on organiser-only pages, shown while `COMPETITION_TOOLKIT_MANAGE` is granted. |
| Rendering | One pure module renders a design + data into DOM for both the editor preview and the runtime (WYSIWYG by construction). It uses only `textContent` and validated style values, never `innerHTML`. |

---

## 3. Data model

### 3.1 `competition_display` (new entity `CompetitionDisplay`)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | `Uuid::uuid7()` |
| `competition_id` | uuid FK → competition, `ON DELETE CASCADE` | immutable |
| `kind` | varchar enum `DisplayKind` (`screen` / `overlay`) | immutable after create (duplicate as the other kind = v2) |
| `name` | varchar(100) | organiser label ("Hall A projector", "OBS lower third") |
| `token` | varchar(22), unique | `OnlineToken::generate()`; `regenerateToken()` |
| `locale` | varchar(5) | one of the 6 app locales; defaults to the creator's request locale |
| `design` | jsonb | normalised design v1 (§4), always stored at the latest schema version |
| `design_version` | int | +1 on every save; optimistic concurrency + renderer reload trigger |
| `follow_mode` | varchar enum (`current` / `pinned`) | |
| `pinned_round_id` | uuid FK → competition_round, `ON DELETE SET NULL` | null + `pinned` = behaves as `current` |
| `follow_round_ids` | jsonb list\<uuid\> nullable | subset for `current` (parallel halls); null = all rounds |
| `uses_live_results` | bool | derived on save: any element with `source: live` - gates the results payload and the signal |
| `uses_results` | bool | derived on save: any results element at all |
| `spotlight` | jsonb nullable | `{roundId, entryRef}` set from the live control (§8) |
| `message` | varchar(200) nullable | live text set from the live control |
| `created_at`, `updated_at` | timestamp | |
| `created_by_id` | uuid FK → player, `ON DELETE SET NULL` | audit only |

- Index `(competition_id)`; unique `(token)`.
- Caps: **20 displays per competition** (`AddCompetitionDisplay` is `SerializedByLock` per competition, like
  `AddPageSection`), 60 elements per design, 30 distinct images per design, 64 KB of normalised JSON.
- Entity methods: `saveDesign(array $normalised, int $baseVersion, bool $usesLive, bool $usesResults, DateTimeImmutable $at)`
  (throws `DisplayDesignConflict` on a stale `baseVersion`), `rename()`, `regenerateToken()`, `follow()`,
  `setSpotlight()`, `setMessage()`.
- There is no `ResetInterface` concern, because nothing is cached in services. The one exception is the request-level
  memo in §7.3, which implements `ResetInterface`.

### 3.2 Files

- Upload prefix `competition-displays/<competitionId>/<uuid7>.<ext>`. The sanitizer keeps only paths under the
  display's own competition prefix, so removing a picture can never delete another event's file.
- `GetStoredFileReferences` gets three more UNION branches over `competition_display.design`:
  - the background image `design->'canvas'->'background'->>'path'`;
  - `image` elements `el->'props'->>'path'`;
  - rotator images `el->'props'->'images'[*]->>'path'`.

  These are jsonb_array_elements patterns like the page-section lines `:45-46`.
- Deletion: `DeleteCompetitionDisplayImages(paths)`, dispatched after commit (`DispatchAfterCurrentBusStamp`), deletes
  only unreferenced paths. It is dispatched by save (paths that dropped out), by delete display, and by cascade.
  - Implementation: extract the body of `DeletePageSectionImages` into a shared `DeleteUnreferencedStoredFiles`
    service. Both handlers call it.
  - Orphans: an upload whose design is never saved, and pictures of a deleted competition. These join the existing
    TODO "prune unreferenced `competition-pages/` objects", which gets its prefix list extended.

### 3.3 Messages (all state changes)

| Message | Handler |
|---|---|
| `AddCompetitionDisplay(displayId, competitionId, kind, name, presetKey, locale, actorId)` | cap 20 under a lock, design = preset normalised, token generated |
| `SaveCompetitionDisplayDesign(displayId, design(array), baseVersion)` | `DisplayDesignSanitizer::normalise()` (throws `InvalidDisplayDesign` with paths), version check, derive flags, dropped image paths → `DeleteCompetitionDisplayImages` |
| `RenameCompetitionDisplay`, `ChangeCompetitionDisplayLocale` | trivial |
| `ChangeCompetitionDisplayFollow(displayId, mode, pinnedRoundId, roundIds)` | every round id must belong to the display's competition, otherwise `DisplayNotFound` (404) - never trust ids from the request |
| `DuplicateCompetitionDisplay(sourceId, newId, name)` | same competition, new token, the same image paths (same prefix, references counted twice - fine) |
| `CopyCompetitionDisplays(fromCompetitionId, toCompetitionId, displayIds)` | the voter is checked on **both** events in the controller; images are copied to the target prefix (missing ones are left out, as the page sections do); pinned rounds → current |
| `RegenerateCompetitionDisplayToken(displayId)` | new token |
| `DeleteCompetitionDisplay(displayId)` | remove + images |
| `SetCompetitionDisplaySpotlight(displayId, roundId\|null, entryRef\|null)` / `SetCompetitionDisplayMessage(displayId, text\|null)` | entry must belong to a round of the competition |

`DuplicateCompetitionHandler` gets `copyDisplays()` with the same rules as `CopyCompetitionDisplays`, plus round id
remapping. A pinned round becomes the copy's round, because the handler copies rounds itself. Spotlight and message
are cleared.

---

## 4. Design JSON - schema v1

PHP `DisplayDesignSanitizer` (the boundary) and JS `assets/displays/schema.js` (the editor's and the renderer's own
normaliser - defence in depth) implement the **same** rules. Both are fed one shared case file,
`tests/fixtures/display-design-cases.json` (input → expected normalised output or the error path), from PHPUnit and
from the node harness. If the two implementations drift, a test fails.

Normalisation policy:
- **Structure** errors reject the save (422 with a JSON-pointer path the editor highlights): unknown element `type`,
  a missing `frame`, more elements than allowed, a foreign image path, an unknown schema version, JSON over 64 KB.
- **Values** are normalised: numbers clamped to their range, unknown keys dropped, bad colours replaced by the
  default, unknown fonts replaced by the theme font. The response carries the normalised design, which the editor
  adopts.

```jsonc
{
  "schema": 1,
  "canvas": {
    "aspect": "16:9",                       // only value in v1
    "background": {
      "type": "color",                      // color | transparent | image | chroma
      "color": "#0d1017",                   // #rrggbb or #rrggbbaa
      "chroma": "#00b140",                  // used when type = chroma (switchers without alpha)
      "image": { "path": "competition-displays/<cid>/<uuid>.jpg", "fit": "cover", "opacity": 1 }
    }
  },
  "theme": { "font": "rubik", "color": "#ffffff", "accent": "#fe696a" },
  "elements": [
    {
      "id": "k3f9x2qa",                     // /^[a-z0-9]{8}$/, unique in the design
      "type": "timer",                      // §5
      "name": "Big timer",                  // editor label, ≤ 60 chars, never rendered
      "frame": { "x": 10, "y": 22, "w": 80, "h": 34, "rotate": 0 },  // % of canvas, 2 decimals; w,h ≥ 0.5; rotate -180..180
      "z": 3,                               // 0..999, ties by array order
      "hidden": false,                      // editor eye toggle
      "locked": false,                      // editor only
      "when": {
        "phases": ["idle", "scheduled", "running", "times_up", "stopped"],  // stopwatchView() phases; [] = never
        "results": "any",                   // any | published | not_published
        "puzzle": "any"                     // any | revealed | not_revealed (does the round have ≥1 revealed puzzle)
      },
      "style": { /* §4.1 */ },
      "props": { /* per type, §5 */ }
    }
  ]
}
```

### 4.1 Common style (every element; the sanitizer whitelists exactly these)

| Key | Type / range | Default |
|---|---|---|
| `font` | enum: `rubik`, `inter`, `oswald`, `bebas`, `roboto_mono`, `orbitron`, `courier_prime` | theme |
| `fontSize` | 8-900 (logical px at 1080p) | per type |
| `fontWeight` | 300 / 400 / 500 / 700 / 900 (clamped to what the family has) | 500 |
| `color` | `#rrggbb(aa)` | theme |
| `background` | `#rrggbb(aa)` or null | null |
| `gradient` | null or `{from, to, angle 0-359}` | null |
| `border` | `{width 0-40, color}` | none |
| `radius` | 0-540 | 0 |
| `padding` | 0-200 | 0 |
| `opacity` | 0-1 | 1 |
| `align` / `valign` | left·center·right / top·middle·bottom | center / middle |
| `shadow` | none · soft · strong · outline (text stroke for video overlays) | none |
| `uppercase` | bool | false |
| `letterSpacing` | -10…40 (logical px) | 0 |
| `lineHeight` | 0.8-2.0 | 1.15 |
| `fit` | none · shrink (text shrinks to fit its frame - names) · fill | per type |

The renderer turns these into style properties through a fixed mapping (`element.style.fontSize = n + 'px'`, colours
re-checked against the regex). No user string is ever concatenated into a CSS declaration or a `style` attribute,
and there is no free CSS, class or HTML field anywhere.

---

## 5. Element catalogue (v1)

All texts the renderer shows by itself ("Starts in", "Time's up", "finished", "Paused") come from the display's
locale, sent with the state as `texts`. Organiser text is plain text, ≤ 300 characters, with control characters
removed and `\n` allowed.

| Type | What it shows | `props` (type-specific) |
|---|---|---|
| `timer` | The round clock - the same `stopwatchView()` maths as the projector page. Never negative. | `mode`: `auto` (countdown before a scheduled start, then elapsed) · `elapsed` · `remaining` (limit − elapsed; 0:00 at times_up; falls back to elapsed when the round has no limit); `format`: `auto` (drops the hours while 0) · `hh:mm:ss` · `mm:ss` (minutes > 59 shown as 75:12); `bigCountdown`: 0-30 s (the last N seconds of a countdown as one big number; 0 = off); `phaseColors`: `{idle, scheduled, running, warning, times_up, stopped}` colours; `warningSeconds`: 0-3600 (remaining below this uses `warning` - the "last 5 minutes" red); `idleText`: `dashes` · `zero` · `blank` |
| `status` | The phase line: "Starts in 0:00:09" / "" / "Time's up" / "Paused" / "Not started" (the same labels as `round_stopwatch.html.twig:44-47`) | `texts`: optional organiser overrides per phase (plain, ≤ 80) |
| `progress` | A bar of elapsed / limit (hidden for a round without a limit) | `direction`: `fill` · `drain`; `orientation`: horizontal · vertical; `trackColor`, `barColor`, `warningColor`, `warningSeconds` |
| `text` | Static organiser text | `text` |
| `field` | A live value: `competition_name`, `round_name`, `round_category` (Solo/Pairs/Teams), `round_limit` ("90 min"), `round_start` (planned start in the round's zone), `location`, `event_date` | `field`, `prefix`, `suffix` (plain, ≤ 60) |
| `round_badge` | The round name as a pill in the round's own badge colours (`RoundBadgeColor`, as on the event page) | `useRoundColors` bool (else style colours) |
| `image` | One picture | `source`: `upload` · `competition_logo` · `series_logo` · `organization_logo`; `path` (upload); `fit`: contain · cover |
| `rotator` | Sponsor images cycling | `images`: [{`path`}] ≤ 30; `seconds`: 3-120; `transition`: none · fade; `fit` |
| `clock` | Local time of day | `zone`: `round` (the round's zone, `RoundTimezone`) · `device`; `format`: `24h` · `12h`; `seconds` bool |
| `puzzle` | **Revealed** puzzles of the round only (§9.3). With several puzzles in the round (architect A: pick 1 of N / K of N), every revealed one. | `layout`: row · column · grid; `show`: subset of `image`, `name`, `brand`, `pieces`; `max` 1-6; `whenHidden`: `nothing` · `text` (organiser text, e.g. "Revealed at the start"); `imageFit` |
| `finished_count` | "34 / 120 finished" (entries with a result or "did not finish" / entries in the round, DNS excluded from the total) | `format`: `fraction` · `count` · `percent`; `source` (§5.1) |
| `leaderboard` | Top N of the round: rank, name (team: team name, members below optionally), country flag, time or "pieces placed", table number | `rows` 1-30; `columns`: subset of `rank`, `flag`, `name`, `members`, `result`, `table`; `source`; `highlightLatestSeconds` 0-60 (a just-added row glows); `rowStyle`: `{background, altBackground, gap}` |
| `ticker` | Latest finishers ("Table 12 · Jane Doe · 1:02:44"), newest first | `rows` 1-10; `layout`: list · marquee (horizontal crawl, CSS animation, speed 20-400 px/s); `source` |
| `spotlight` | The entry the organiser chose in the live control: name, table, flags, members, live result if any | `show`: subset of `table`, `name`, `members`, `flag`, `result`, `rank`; `source` (for result/rank); `whenEmpty`: nothing · text |
| `message` | The organiser's live message ("Break - next round 14:30") from the live control | `whenEmpty`: nothing · text |
| `qr` | QR code, drawn on the server (BaconQrCode SVG → data URI in the state, cached per URL) | `target`: `event_page` · `round_results` · `custom`; `url` (custom: `https://` only, ≤ 300, validated with the same rule as page-section links); `fg`, `bg` colours, `quietZone` bool |
| `box` | A plain panel (background, border, radius, gradient) - the backdrop of a lower third or a sidebar | - |

### 5.1 Results sources

`source: "published"` (the default when an element is added): the element shows data only once the round's results
are published (`resultsPublishedAt`), otherwise its `whenUnpublished` (`nothing` / organiser text such as "Results
soon"). `source: "live"`: official results as they are entered, before publishing. The editor marks the element
"Shows results before they are published" and asks once for confirmation, the first time an element is switched to
live.

The presets "OBS leaderboard sidebar" and "Timer with live board" start with `live`, because that is their whole
purpose, and the badge is visible at once.

The projection is `DisplayResultRow {key, rank, name, members[], countries[], resultSeconds|null, piecesPlaced|null,
didNotFinish, tableNumber, enteredAtMs}`:
- `name` is the organiser's participant/team name, never a profile name, `#code`, avatar or id - the masking a guest
  gets on the published results page;
- `key` is an opaque hash of the entry ref per display (for DOM diffing), never `participantId`;
- did-not-start entries are left out;
- ranks come from `OfficialResultsRanking` (as `RoundResultEntry.rank`).

### 5.2 v2 ideas (not in v1)

- Scenes: several layouts per display, switched from the live control ("Starting soon / Live / Break / Podium").
- Element enter/exit animations; an auto-paging leaderboard.
- Standings across rounds (the World Series sum) and the qualification bracket - both depend on the spider/sum
  architect's model.
- Per-puzzle split timers of a multi-puzzle round.
- A "next round" countdown to an arbitrary moment (break timer); a podium element; a camera-grid frame.
- Uploaded fonts; 4:3, portrait (9:16 vertical TV) and custom canvases.
- A series-level design library; YouTube chat; a weather/clock widget set; a judge activity feed.

---

## 6. Presets (create from)

The presets live in PHP (`DisplayPresets::get(key, kind)`, normalised through the sanitizer in a test) and use only
the competition's own data, so a fresh display works without any editing:

1. **Classic big timer** (screen): event name top, round badge, huge timer, status line, progress bar bottom.
   This is the existing projector page made editable.
2. **Timer with sponsor bar** (screen): the classic layout shifted up, a `box` strip at the bottom with a `rotator`
   and the competition logo left, and a QR to the event page right.
3. **Timer with live board** (screen): timer left 60 %, leaderboard top 10 right (`source: live`), finished count.
4. **OBS lower third** (overlay, transparent): a box bottom-left with the round name + timer + spotlight line.
5. **OBS corner timer** (overlay): a small rounded box top-right with the timer and a progress strip.
6. **OBS leaderboard sidebar** (overlay): a right column with leaderboard top 8 (`live`), latest finishers ticker,
   finished count.
7. **Blank** (either kind).

---

## 7. Runtime renderer

### 7.1 Page

`GET /display/{token}` (`CompetitionDisplayController`):
- Template `templates/competition_display/display.html.twig` is **standalone**: its own `<!doctype html>`, no
  `base.html.twig`, so no site chrome, no SW registration, no stale-document/asset self-heal scripts, no Turbo, no
  Bootstrap.
- Headers and meta:
  - `<meta name="robots" content="noindex,nofollow">` + `X-Robots-Tag`;
  - **`<meta name="referrer" content="no-referrer">`**, because the token is in the path and images come from the
    img host. The page has no forms, so the stateless-CSRF/no-referrer gotcha does not apply;
  - `Cache-Control: private, no-store`.
- Inline: the normalised design JSON, the first state (§7.2) and `serverNowMs` in `data-` attributes
  (`json_encode|e('html_attr')`).
- Overlay: inline `html,body{background:transparent;margin:0;overflow:hidden}`, set in the template like
  `watch.html.twig:28-31`, not in a stylesheet.
- Assets: a new Encore entry `display` (`assets/display.js` + `assets/styles/display.scss`) with the @font-face
  rules of the curated fonts (§10.4). Browsers download only the families an element uses.
- An unknown or regenerated token answers 404 with a tiny standalone page: "This display link is no longer valid"
  for a screen, a transparent empty page for an overlay.

### 7.2 State - `GET /display/{token}/state` (JSON, `private, no-store`)

The query is `GetCompetitionDisplayState::forToken(token, now)`, which assembles only what the design's elements need:

```jsonc
{
  "displayId": "...", "kind": "screen", "designVersion": 7, "rendererBuild": "<entrypoints hash>",
  "serverNowMs": 1763380000123,
  "texts": { "startsIn": "Starts in %time%", "timesUp": "Time's up", "stopped": "Paused", "notStarted": "Not started",
             "finished": "finished", "resultsSoon": "…" },
  "competition": { "name": "…", "logoUrl": "…|null", "seriesLogoUrl": null, "organizationLogoUrl": null, "location": "…", "date": "…" },
  "round": { "id": "…", "name": "…", "category": "solo", "badge": {"bg": "#…", "fg": "#…"}, "minutesLimit": 90,
             "startsAtMs": …, "timezone": "Europe/Prague", "resultsPublished": false },
  "stopwatch": { /* RoundStopwatchState exactly */ },
  "puzzles": [ { "name": "…", "brand": "…", "pieces": 500, "imageUrl": "…|null" } ],   // revealed only
  "refreshAtMs": 1763380600000,          // next moment the server-side picture changes by itself (a reveal, a pinned→current switch); null = none
  "results": { "published": false, "finished": 34, "total": 120,
               "top": [DisplayResultRow…], "latest": [DisplayResultRow…] },        // only when uses_results; rows only if source allows
  "spotlight": { DisplayResultRow-like } | null,
  "message": "…" | null,
  "qr": { "<elementId>": "data:image/svg+xml;base64,…" },
  "images": { "<path>": "https://img…/preset:display/plain/<path>" },
  "mercure": { "url": "…", "topics": [...], "token": "…", "expiresIn": 3600 } | null,
  "followedRoundIds": ["…"]
}
```

Details:
- `results.top` / `latest` are computed for the largest `rows` any element asks for, so the payload is bounded.
- `source` gating happens on the server: when no element may show live rows and the round is unpublished, the
  arrays are empty and `finished` / `total` are null unless a `finished_count` element is live.
- A round without results (an imported round with a sheet) gives `results: null`.
- Statement budget: about 6 queries (display + competition, rounds overview for follow, stopwatch, visible puzzles,
  result entries when needed, spotlight entry). This is pinned by a `QueryCountAssertions` test.

`GET /display/{token}/design` returns `{designVersion, design}` and is fetched when `designVersion` grows.

### 7.3 Live updates

**Topics** (subscriber JWT minted by a new `OfficialResultsSubscription::forDisplay(competitionId, roundIds)`, after
the token lookup, the same way as `forOnlineParticipant`):
- `/competition-display/{competitionId}`: **private**, signal-only. The payload is
  `{"type":"display.signal","what":"results|puzzles|round|design|control","roundId":?,"displayId":?}` and carries no
  data, so a leaked JWT learns only that something changed.
- `/round-stopwatch/{roundId}` for every round in the follow set: the existing public payload, applied directly
  (instant start, exactly like the projector page).

**Who publishes the signals.** `CompetitionDisplayLiveUpdates` is always called after the dispatch returned, never
from a handler, and catches and logs a failure at `warning`:
- `OfficialResultsLiveUpdates::publish()` → `resultsChanged(roundId)`. It is the one choke point for results writes
  and is already after the commit.
- `RevealRoundPuzzleNowController`, the round-puzzles reveal settings controller, add/remove round puzzle →
  `puzzlesChanged(roundId)`.
- Round edit (name, limit, start, badge) → `roundChanged(roundId)`.
- Design save, follow change, regenerate, delete → `designChanged(displayId)`.
- Spotlight and message → `controlChanged(displayId)`.

**Rule 1 guard.** The service first asks `CompetitionDisplaysPresence::forRound(roundId)` /
`forCompetition(competitionId)`: one indexed `EXISTS`, memoised per request (`ResetInterface`). A competition
without displays publishes nothing new and only pays that one query on writes.
- `resultsChanged` also requires a display with `uses_results`.
- Publishing of results (`roundChanged` in `OfficialResultsLiveUpdates`) is a results signal too: published-source
  elements appear at that moment.

**Client** (`assets/displays/runtime.js`, DOM-free core plus a thin DOM adapter):
- Mercure: `official_results_events.js` as is, giving fetch SSE with Bearer, backoff, silence detection and renewal.
  Every state answer carries `mercure`, and the module's `update()` renews the token.
- A signal → a debounced state fetch (1.5 s trailing, at most one in flight), so a burst of 40 finishes in 10 s
  costs about 7 fetches per display.
- A stopwatch message → `apply()` with the `isNewerState()` ordering. When the message is for a round other than the
  shown one (follow = current), a state fetch follows, because the server re-picks the round.
- Safety-net state fetch every 60 s, which re-syncs the clock offset (shortest round trip wins, as with
  `createServerClock`) and renews the token. Every 5 s while the stream is down. One at `refreshAtMs` (+250 ms
  jitter) and on `visibilitychange`.
- Before and after a scheduled start: fetches at −2 s and +1 s, the same as `round_stopwatch_controller.js:199-225`.

### 7.4 Long-running robustness

- **Rendering cost:**
  - the element tree is built once per `designVersion`;
  - each animation frame runs only while some timer/progress element is visible and the phase is
    scheduled/running, and touches only the text nodes whose string changed (the projector's `setText` cache);
  - leaderboard and ticker diff rows by `key` (reuse nodes, move, add, remove);
  - the rotator uses one `setTimeout` chain and preloads the next image before the swap.
- **No leaks:**
  - one `AbortController` per design build owns every listener, timer and observer, and is aborted on rebuild;
  - the harness pins this with a fake timer registry (§11.2);
  - no unbounded arrays: state history is not kept, and the "seen ids" set lives inside
    `official_results_events.js`.
- **Wake lock:** `navigator.wakeLock.request('screen')` for kind `screen`, re-acquired on `visibilitychange`.
  Unsupported (old TV browsers) = nothing happens; the doc tells organisers to turn the TV's sleep off.
- **Cursor:** hidden after 3 s without movement. A screen shows a small fullscreen button on mouse move only.
  An overlay never shows any UI.
- **Self-reload policy:**
  - `rendererBuild` differs (a deploy) → `location.reload()` only in phase `idle`, `stopped` or `times_up` and
    when the last state fetch succeeded (never reload into an offline page);
  - the same rule after 20 h of uptime, as a leak and drift backstop;
  - never reload while a round counts down or runs.
- **Errors:** a failed state fetch keeps the last picture; the clock keeps running from the last state. A 404 on state
  (token regenerated or display deleted) shows the "no longer valid" view (transparent for overlays), stops the
  stream and checks again every 5 minutes. Organisers regenerating a token by mistake can paste the new URL.
- **Old engines:** no `:has()`, container queries or `structuredClone`. Babel already transpiles to `esmodules`
  targets, which is enough for OBS 30+ (CEF Chromium 1xx). Smart-TV browsers older than about 2018 are not supported
  (documented). The renderer uses absolute positioning and `transform: scale`
  only - no grid or flex `gap`; flexbox without `gap` is used only inside the leaderboard.

---

## 8. Organiser pages and the editor

### 8.1 Routes (all organiser routes: `IS_AUTHENTICATED_REMEMBERED` + `COMPETITION_TOOLKIT_MANAGE` on the display's competition)

| Route name | Path (en; the cs/de/es/fr/ja variants follow the stopwatch pattern) | Method | What |
|---|---|---|---|
| `competition_displays` | `/en/manage-displays/{competitionId}` | GET | list: name, kind, followed round, copy URL, open, QR of the URL (to open on a TV), live control, edit, duplicate, delete, regenerate; "New display" (kind + preset); "Copy from another event" |
| `add_competition_display` | `…/{competitionId}/add` | POST | → 303 to the editor |
| `edit_competition_display` | `/en/display-editor/{displayId}` | GET | the editor |
| `save_competition_display` | `/en/display-editor/{displayId}/save` | POST (JSON) | `{design, baseVersion}` → `{designVersion, design}` / 409 `{error: "conflict", designVersion}` / 422 `{errors: [{path, message}]}` |
| `upload_competition_display_image` | `/en/display-editor/{displayId}/image` | POST multipart | JSON `{path, url}` / 422 / 429 |
| `competition_display_settings` | `/en/display-editor/{displayId}/settings` | POST form | name, locale, follow mode / pinned round / round subset → 303 or 422 |
| `duplicate_…`, `delete_…`, `regenerate_competition_display_token` | `…/{displayId}/duplicate` … | POST | 303 + flash |
| `copy_competition_displays` | `/en/manage-displays/{competitionId}/copy-from/{sourceCompetitionId}` | GET + POST | pick the displays to copy |
| `competition_display_control` | `/en/display-control/{displayId}` | GET | live control: spotlight picker + message |
| `set_competition_display_spotlight` / `…_message` | `…/{displayId}/spotlight` | POST (fetch, JSON) | |
| `competition_display` | `/display/{token}` | GET | public renderer |
| `competition_display_state` / `_design` | `/display/{token}/state`, `/display/{token}/design` | GET | JSON |

- Every POST carries a session CSRF token (organisers are signed in); fetch posts send it in the body.
- Forms: 303 on success, 422 with reasons, an explicit `action`; never a 200 to a full-page form.
- Links into the feature:
  - "Displays" on the competition's management page and in `_round_tool_links.html.twig` next to Stopwatch;
  - the stopwatch control page gets "Open a display" when the competition has any.
  
  All of these show only while `COMPETITION_TOOLKIT_MANAGE` is granted.

### 8.2 Editor UX

Layout (desktop/laptop. Below 900 px the editor shows "Open on a computer to edit", while the list page, links, QR
and live control work on a phone):

```
┌ toolbar: name · kind · Undo Redo · Preview phase [Idle|Countdown|Last 10 s|Running|Warning|Time's up|Paused] ·
│          Data [Sample|Live] · Grid/Snap · Zoom · Save (Ctrl+S) · Open display ↗
├ left: Add element (catalogue, grouped Clock / Text / Pictures / Results / Other) + Layers (SortableJS list: eye,
│       lock, name, drag = z-order)
├ centre: 16:9 stage (the real renderer at scale; overlay: checkerboard behind = transparency visible)
└ right: Properties of the selection: Position & size (x,y,w,h % + px hint), Visibility (phases, results, puzzle),
         Style (§4.1, Coloris pickers), Element props (§5); Canvas & theme when nothing is selected
```

- **Stimulus controllers:**
  - `display_editor_controller.js` (lazy) owns the document state: an immutable design object, an undo stack of 100
    snapshots, and a dirty flag with a `beforeunload` guard;
  - `display_stage_controller.js` handles the stage pointer work.
  
  Pure modules (no DOM, node-tested):
  - `assets/displays/geometry.js`: drag/resize maths, 8 handles, Shift = keep ratio, Alt = from centre, clamping to
    the canvas, snap to a 1 % grid, the canvas centre/edges and siblings' edges and centres (guides drawn while
    dragging), arrow keys 0.1 % / Shift 1 %;
  - `assets/displays/schema.js`;
  - `assets/displays/render.js`;
  - `assets/displays/timer_format.js`;
  - `assets/displays/sample_data.js`.
- **Pointer events:** `pointerdown` + `setPointerCapture`, with moves batched per animation frame. The stage DOM is
  rendered by `render.js` with an editor overlay layer (selection boxes, handles, guides) on top, so the preview is
  exactly the runtime.
- **Preview data:** `Sample` is a fake clock. The phase buttons set `stopwatch` and `nowMs` (e.g. Running at 47:12 of
  90; Last 10 s), plus 20 sample entries with long names and flags, 2 revealed sample puzzles, a sample spotlight and
  a message. `Live` loads the organiser-side state of the selected round through
  `/display-editor/{displayId}/preview-state`, which is the same query run as the organiser, without the token.
- **Live badges:** an element with `source: live` carries a red "LIVE before publishing" chip on the stage and in
  Layers.
- **Save:** explicit Save only. There is no autosave, because a half-edited design would appear on a live hall
  screen; the unsaved-changes guard covers lost work.
  - A 409 conflict offers "Load the saved version" (discarding local) or "Overwrite" (re-sends with the new
    `baseVersion`).
  - A 422 selects the offending element and shows the message.
  - After a successful save the open displays update within about 2 s, through the design signal (§7.3).
- **Images:**
  - drop a file on the stage or in the props "Upload";
  - the client shrinks large JPEGs (`image_compression.js`, as the page sections do) and leaves PNG/WebP/GIF as they
    are, which keeps transparency;
  - progress and errors are shown in place;
  - an uploaded picture is placed as an `image` element when dropped on the stage.
- **Duplicate element:** Ctrl+D. **Delete:** Del. **Multi-select:** Shift-click (move/delete together; alignment
  buttons left/centre/right/top/middle/bottom, distribute) - kept small.
- **Accessibility:** Layers and the property panel are the keyboard path, so every operation possible with the mouse
  is possible from the panel fields.

### 8.3 Live control (`competition_display_control`)

A phone-friendly page for the stream operator:
- The **spotlight picker** searches the followed round's entries by table number or name (the same Find logic as the
  live entry, through `GetRoundResultEntries` masked like referees - the operator may not be an organiser, but in v1
  only organisers open it). It has "Clear".
- **Message**: a text field + Set/Clear and 3 recent messages.
- A small live preview of the display.

Writes go through `SetCompetitionDisplaySpotlight` / `SetCompetitionDisplayMessage`, followed by a control signal.

---

## 9. Security

### 9.1 Token

- 128 random bits (`OnlineToken`), stored in plain text like the online personal links. It is a capability for a
  read-only view, so it is not hashed: the organiser must be able to copy it again.
- Lookup is by a unique index. Brute force is infeasible; the rate limiter `display_public` (per IP, 600/min)
  protects only against hammering.
- Regenerate = instant revoke. Deleting the competition cascades.
- Not in the Referer header (`no-referrer`). Organisers are told in the editor that the URL is "like a password -
  whoever has it sees what the display shows".

### 9.2 XSS and injection

- Server: a strict whitelist sanitizer (§4) with no raw HTML, CSS, classes or ids from users.
  - Text is plain: control characters removed, length capped.
  - URLs: `https:` only for the QR custom target; image paths must match `^competition-displays/<own cid>/[0-9a-f-]{36}\.(jpe?g|png|gif|webp)$`.
  - Images are uploaded by us; SVG is refused.
- Client: `render.js` uses `document.createElement`, `textContent`, `style.setProperty` with the re-validated value,
  and `img.src` only from the server's `images` map / `qr` data URIs (checked prefix
  `data:image/svg+xml;base64,` or the configured image host).
- The JSON islands in the HTML are `json_encode|e('html_attr')`.
- A harness test feeds `<img src=x onerror=…>`, `</script>`, `javascript:` and `expression(` strings into every
  string field and asserts that no element, attribute or handler appears. A test also greps `assets/displays/` for
  `innerHTML` / `insertAdjacentHTML` / `outerHTML`.

### 9.3 Secret puzzles (context rule 4 - an early reveal is a disaster)

- The puzzle data in the state comes **only** from a new query, `GetRoundPuzzlesVisibleNow::forRound(roundId, now)`.
  It is extracted from the masking block of `GetEditionRounds.php:77-150`, and `GetEditionRounds` is refactored to
  call it, so there is one rule:
  - round-level reveal (`RoundPuzzleReveal::revealAt`);
  - `PuzzleHideMode` (`image_only` = the name without the image);
  - the site-wide `hide_until` / `hide_image_until` embargo.
  
  The future "reveal when the timer starts" mode lands there once.
- The organiser's editor preview in `Live` mode uses the same query, so the editor never shows an organiser a puzzle
  that the hall would not see either. The organiser can still see their own puzzle on the round's puzzles page.
- Nothing about a hidden puzzle is sent: not its name, image, brand, pieces or **count**. `when.puzzle` is computed
  on the server as `round.anyPuzzleRevealed` and sent as a bool.
- `refreshAtMs` carries the reveal moment, so the display flips at the reveal without polling. This is not secret
  content, and the moment is public on the event page.
- Image URLs: a secret puzzle's image has a random file name until the reveal (`SecretPuzzleHides`); the URL is sent
  only after the reveal.
- Test matrix: §11.1.

### 9.4 Unpublished results and private players (rules 5 + the read-side guarantees)

- Results data only for displays that have results elements. Live rows only for elements with `source: live`.
  Otherwise rows appear only after `resultsPublishedAt`.
- Names: organiser participant/team names only. A private player is masked exactly as for a guest on the published
  results page: no profile name, `#code`, avatar or player id, ever. There are no ids at all (opaque keys).
  `PrivateProfileCanaryTest` gets the display page + state URL (needle "Jane Smith" = profile name; the fixture's
  participant name differs).
- Blocklist (`HiddenPlayers`): a display has no viewer, the same as the public projector and published results to a
  guest. `BlocklistCanaryTest` gets it as a documented exemption ("no viewer - an anonymous capability URL").
- Drafts: works by token (rehearsal). `DraftCanaryTest` / `DraftVisibilityCoverageTest` record the reader with that
  reason.
- Custom field values, suspicious and tracker-less times: not involved (official results only), recorded in
  `TrackerlessTimeCanaryTest` / `CustomValuesPrivacyCanaryTest` coverage lists if they enumerate readers.

### 9.5 Permissions and flag

- New `CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE` (subject = competition id) = `COMPETITION_EDIT` +
  `CompetitionToolkitFeature::isAvailableTo()` (admins always, organisers while
  `COMPETITION_TOOLKIT_FOR_ORGANISERS=1`). This copies `OnlineRoundsFeature` / `OnlineRoundsVoter`.
  **Shared with the other toolkit areas - whichever work package lands first creates it.** It is documented in
  `docs/features/feature_flags.md`.
- Every organiser action checks the voter on the competition **of the display loaded from the database**, never on an
  id from the request. Copy-from checks both events.
- Public display routes need no flag: a display exists only if an admin (or a flagged organiser) made it
  (context rule 2).
- Referees: no access to the editor in v1. Live control is organisers only (v2: let a referee run the spotlight).

---

## 10. Storage, images, fonts, translations

### 10.1 Upload

`UploadCompetitionDisplayImageController` mirrors `UploadPageSectionImageController`:
- JPEG/PNG/GIF/WebP ≤ 5 MB, `ImageOptimizer`, path `competition-displays/<cid>/<uuid7>.<ext>`;
- rate limiter `competition_display_image_upload` (60/h per player);
- the answer includes the delivery URL.

### 10.2 Delivery

- A new imgproxy preset **`display=rs:fit:1920:1920`** with no forced format: imgproxy keeps the PNG alpha and may
  serve WebP by `Accept`. It is added to `compose.yml:191` and to **lily's** `apps/myspeedpuzzling/compose.yaml`
  (an ops step in WP-D7; the memory rule is that lily changes are done by us).
- Until it is live, the URL builder falls back to `UploaderHelper::getPublicPath()` (`/original/`), behind a
  parameter `display_image_preset` (empty = original).
- The Cloudflare and images-cache TTL are fine, because file names are unique UUIDs.

### 10.3 Lifecycle

§3.2 (after-commit delete of unreferenced paths, `GetStoredFileReferences` extended, orphans → the existing prune TODO).

### 10.4 Fonts

- Self-hosted woff2, latin + latin-ext subsets, OFL fonts only, ≈ 30-60 KB each:
  - Rubik (already there);
  - Inter;
  - Oswald (condensed, sporty);
  - Bebas Neue (big numerals);
  - Roboto Mono (tabular timer);
  - Orbitron (digital look);
  - Courier Prime (already in `assets/fonts`).
- `font-display: block` on the display page: a flash of the fallback on a projector is worse than 100 ms of nothing.
- `font-variant-numeric: tabular-nums` is always on timers, so digits do not jitter.
- Japanese text falls back to system fonts (documented).

### 10.5 Translations

- English while building (`competition_display.*` for the organiser UI, `competition_display.runtime.*` for the
  texts the display shows itself).
- All 6 locales at the end (context rule 3).
- The runtime texts are rendered in the display's `locale`, not the visitor's, because a TV has no visitor.

---

## 11. Test plan

### 11.1 PHP (PHPUnit, `Project Test Suite`)

- `DisplayDesignSanitizerTest`:
  - driven by `tests/fixtures/display-design-cases.json`: clamping, unknown keys dropped, unknown type rejected with
    its path, foreign image prefix rejected, caps (60 elements, 30 images, 64 KB), colour/font fallbacks, an XSS
    string kept verbatim as text;
  - every preset normalises to itself (idempotence).
- Handlers:
  - add (cap 20 under the lock);
  - save (version conflict, derived `uses_live_results`, dropped images → delete message dispatched after commit);
  - duplicate, delete, regenerate (the old token → 404);
  - follow (a foreign round → 404);
  - spotlight/message (a foreign entry → 404);
  - `CopyCompetitionDisplays` (both voters, images copied to the target prefix, missing image left out);
  - `DuplicateCompetitionHandler` copies displays with remapped pinned rounds and new tokens.
- `GetCompetitionDisplayState`:
  - follow current = `LiveResultsCurrentRound` (running beats recent beats upcoming); a subset; a pinned deleted
    round falls back to current;
  - results absent for designs without results elements;
  - `published` source empty before publishing and rows after;
  - `live` rows before publishing; DNS left out;
  - team rows with members;
  - opaque keys (no participant ids in the JSON - asserted by regex over the whole payload);
  - a statement-count pin.
- **Secret-puzzle matrix** (`DisplaySecretPuzzleTest`), for every reveal mode (automatic before/after start+delay,
  scheduled before/after, manual before/after "Reveal now") × hide mode (entirely / image only) × the site-wide
  embargo, with a two-puzzle round where one is revealed. The state JSON must not contain the hidden puzzle's name,
  brand, image path or pieces (string search), and `anyPuzzleRevealed` / `refreshAtMs` must be right.
  `GetEditionRounds` keeps its current tests green after the extraction.
- Controllers:
  - organiser routes 403 for a non-maintainer, and for a maintainer while the flag is off; admin is OK;
  - public routes: `noindex`, `no-referrer` meta, `private, no-store`; 404 page for a bad token; overlay
    transparency CSS present.
- Live updates:
  - a hub spy sees a display signal after `RecordRoundResults` only when the competition has a display with results
    elements;
  - **nothing new is published for a competition without displays** (rule 1);
  - signals are never published from handlers: a rolled-back write publishes nothing.
- `GetStoredFileReferences` sees background, image and rotator paths. `DeleteCompetitionDisplayImages` keeps
  referenced files.
- Canaries: the display page + state in `PrivateProfileCanaryTest`; documented exemptions in `BlocklistCanaryTest` /
  draft coverage. The existing projector page tests stay unchanged (`round_stopwatch` byte-identity: no template diff
  at all).
- Translations: the `ClaudeInstructionsTest`-style key parity (6 locales) at the end.

### 11.2 Node harnesses (`tests/*.mjs`, run from `*ScriptTest.php` like `DatePickerScriptTest`)

- `display-schema-harness.mjs`: the JS normaliser over the same fixture file - parity with PHP.
- `display-render-harness.mjs` (jsdom):
  - a design + data produce the expected boxes (% frames, scale), visibility by phase/results/puzzle, timer texts per
    mode/format, `remaining` at 0 and with no limit, the big countdown;
  - leaderboard diffing reuses nodes (identity kept across updates), the ticker order, the rotator sequence;
  - the XSS corpus produces no elements or attributes;
  - no `innerHTML` anywhere in `assets/displays/` (source scan).
- `display-runtime-harness.mjs` (fake clock, fetch and timers):
  - signal debounce (40 signals → ≤ 8 fetches);
  - polling fallback while the stream is down;
  - a token renewal path through `official_results_events.js`;
  - a fetch at `refreshAtMs`; a stopwatch message for another round triggers a re-pick;
  - newer-state ordering;
  - a 404 shows the invalid view and slows the polling;
  - the reload policy (never during scheduled/running, never after a failed fetch);
  - **leak check**: 1,000 design swaps and 10,000 state updates leave the live timer and listener count constant.
- `display-editor-geometry-harness.mjs`: drag, resize with each handle, ratio lock, clamping, snapping to grid,
  centre and siblings, nudge, undo/redo stack bounds, multi-select move.

### 11.3 Browser walk (before merge, Chrome via the dev stack; memory: "client-side changes get a browser walk")

1. As an admin with the flag off: create each preset, edit (move, resize, style, upload a transparent PNG logo,
   rotator with 3 images), save, reload the editor (the same picture).
2. Open the display in a second window, full screen. Start in 10 s on the stopwatch control page: the countdown,
   the big last seconds, then running. The editor save changes the open display within about 2 s, without a touch.
3. Live entry: record 5 results. The `live` leaderboard and ticker update; the `published` one stays at "Results
   soon" until Publish. The private fixture player shows the participant name only.
4. A secret puzzle with a manual reveal: nothing on the display, then "Reveal now" → the puzzle appears. A
   two-puzzle round with an automatic reveal flips at the moment.
5. Regenerate the token: the old window shows "no longer valid"; the new URL works.
6. **OBS Studio 30+**: browser source 1920×1080, the overlay presets over a colour source (transparent edges, text
   outline readable), "Shutdown when not visible" off, a scene switch keeps state.
7. Soak: a screen display running a 3 h round with live results. Chrome Task Manager memory flat (±10 %), no
   console errors; and kill Wi-Fi for 2 minutes: the clock continues, it catches up after.
8. A phone at 375 px: the list, copy link, QR and live control work; the editor shows the "use a computer" note.

---

## 12. Risks

| Risk | Mitigation |
|---|---|
| Early secret puzzle reveal through a new surface | One extracted query shared with the event page; the string-search matrix test; no puzzle data in designs; the editor preview uses the same query |
| Unpublished results on a stream by accident | Default `published`; `live` needs an explicit, confirmed switch and is badged; no results data at all for designs without results elements |
| Token leaks (posted with a stream link) | Read-only, regenerate in one click, no Referer, no ids in the payload; the editor warns |
| OBS CEF / smart-TV engines lag behind Chrome | No modern CSS, transform-scale stage, transpiled JS; tested in OBS; TVs older than about 2018 unsupported (documented) |
| Hours-long pages leak or drift | One AbortController per build, node leak check, clock re-sync every 60 s, a calm-moment reload after a deploy or 20 h |
| PHP/JS schema drift | One fixture file run by both sides |
| Mercure load at big events | Signals carry no data; debounced fetch; a handful of displays per event; the state query is budgeted |
| imgproxy preset needs a lily change | Fallback to `/original/` behind a parameter until it is deployed |
| Editor scope creep (it is a mini design tool) | A fixed element set, no free CSS, multi-select limited to move/align; scenes and animations are v2 |
| Parallel halls pick the wrong "current" round | `follow_round_ids` subset, or pinned |

---

## 13. Open questions (defaults decided - Jan may overturn)

1. **Series-level library** - default: no. Per event + duplicate + "copy from another event". Revisit when weekly
   series organisers ask.
2. **Default results source** - default `published`. The live presets start as `live`, with the badge.
3. **Referees running the spotlight** - default: organisers only in v1.
4. **Drafts by token** - default: allowed (rehearsal). The alternative is to refuse like the OBS watch page; that
   would block rehearsing before publishing.
5. **Display limit** - 20 per event, 60 elements, 30 images. Large enough for any real hall plus a stream; caps keep
   the storage honest.

---

## 14. Work packages (each ≤ 2 days; D = this area)

| WP | Content | Depends on |
|---|---|---|
| **D1** (1.5 d) | `CompetitionDisplay` entity + generated migration, `DisplayKind`, token, messages/handlers add · rename · locale · follow · duplicate · delete · regenerate, `CompetitionToolkitVoter`/`Feature` (if not yet landed by another area), list page + settings form, feature_flags.md, handler + voter tests | shared toolkit flag (create it here if first) |
| **D2** (1.5 d) | Schema v1: `DisplayDesignSanitizer` (PHP) + `assets/displays/schema.js` + shared fixtures + both tests; `DisplayPresets` (7 presets) + idempotence test; save endpoint + `SaveCompetitionDisplayDesign` (conflict, derived flags) | D1 |
| **D3** (2 d) | Read side: extract `GetRoundPuzzlesVisibleNow` from `GetEditionRounds` (refactor, existing tests green); `GetCompetitionDisplayState` (follow, stopwatch, puzzles, results projection + source gating, spotlight, message, QR, images, texts), state + design endpoints, `forDisplay()` subscription, secret-puzzle matrix, canaries, statement pin | D1, D2; architect A for multi-puzzle selection semantics (the current several-puzzles-per-round model already works) |
| **D4** (2 d) | Renderer: standalone template + Encore entry `display`, `render.js`, `timer_format.js`, `runtime.js` (clock, `official_results_events.js`, debounce, refreshAt, wake lock, reload policy, invalid view), overlay transparency, render + runtime harnesses | D2, D3 |
| **D5** (1 d) | `CompetitionDisplayLiveUpdates` + `CompetitionDisplaysPresence` (memo, ResetInterface); hooks in `OfficialResultsLiveUpdates`, reveal/round-puzzle/round-edit controllers, display controllers; hub-spy tests incl. "no displays = nothing published" | D3 |
| **D6** (2 d) | Editor: `display_editor_controller.js` + `display_stage_controller.js`, `geometry.js` (+ harness), layers (SortableJS), property panel for all v1 types, preview phases + sample data, save/409/422 flow, undo/redo, keyboard | D2, D4 |
| **D7** (1 d) | Images: upload controller + limiter, `display` imgproxy preset (dev compose + lily ops), URL builder fallback, `GetStoredFileReferences` branches, `DeleteCompetitionDisplayImages` via the extracted `DeleteUnreferencedStoredFiles`, rotator preload | D1, D2 |
| **D8** (1.5 d) | Live control page (spotlight picker, message), "Copy from another event", `DuplicateCompetitionHandler::copyDisplays()`, links from the management page / round tools / stopwatch control page (flag-gated), the editor's "Live" preview-state endpoint | D1, D3, D5 |
| **D9** (1.5 d) | Fonts (5 new woff2 + @font-face), preset polish, docs (`docs/features/competitions-management/displays.md`, summary in `.claude/rules/features/events.md`, an obs-youtube.md section "Overlays" replacing the crop trick), English texts, browser walk + OBS + 3 h soak; then translations into 5 locales with the other areas | all |

Critical path: D1 → D2 → D3 → D4 → D6 → D9, about 10.5 days. D5, D7 and D8 run in parallel after D3 (or after D2
for D7).
