# Multiscan — scan a pile of puzzles, apply one action

**Status:** designed and shipped 2026-09-22 (both stages of [`implementation-plan.md`](implementation-plan.md) in one go, straight to `main`, no feature flag).
**Members only.** All six locales. Web first; native apps work through the existing single-shot bridge (see §9).

## 1. Why

Scanning an EAN today is one puzzle at a time: open the scanner, scan, land on the puzzle, pick an
action, go back. With a pile of 20 boxes ("I lent these to Anna", "these came back", "I bought these")
that is 20 round trips. Multiscan turns it into: scan, scan, scan… one tap.

## 2. What we build on (measured on production 2026-09-22)

| | Count | Share |
|---|---|---|
| Puzzles in the catalogue | 41,043 | |
| … with an EAN | 23,762 | 58 % |
| Distinct puzzles in players' libraries | 18,558 | |
| … with an EAN | 12,655 | 68 % |
| EANs shared by more than one puzzle | 816 | |
| Puzzles carrying several comma-separated EANs | 266 | |
| Change requests that proposed an EAN | 1,228 (723 approved) | |

Consequences:

- **"Not found" is a main path, not an edge case** — roughly one box in three from a real pile. The
  resolution flow (§6) is part of version 1, not a follow-up.
- One EAN can resolve to several puzzles (duplicates pending merge, a brand reusing a code across
  piece counts). Every scan needs a disambiguation step, ideally silent.
- Members already fix EANs through change requests. Multiscan makes that instant and in context.

Existing pieces reused as-is: `barcode_scanner_controller.js` (the browser's detector where it reads EAN-13, zbar
elsewhere - see "Decoder" at the end, checksum + 10-frame confirmation), `SearchPuzzle::allByEan()` (substring match, zeros
tolerated, hidden puzzles excluded), `GetUserPuzzleStatuses` (one query → solved / in library /
wishlist / lent / borrowed / listed, with `lentPuzzleIds`), the single-puzzle handlers
(`AddPuzzleToCollection`, `AddPuzzleToWishList`, `LendPuzzleToPlayer`, `BorrowPuzzleFromPlayer`,
`ReturnLentPuzzle`, `AddPuzzle`), the `#code`-or-name person input, the `PuzzleChangeRequest`
moderation queue.

## 3. UX shape (decided)

**Scan into a tray, then choose one action** — the pattern inventory tools converge on. Rejected:
"choose the action first, every scan applies instantly": a mis-scan (boxes carry a second barcode)
would commit immediately, and lending sends a notification that cannot be unsent.

- One page (`/en/multiscan`, titled *Scanning*), camera running **continuously**; a typed /
  hardware-scanner EAN input is one tap away behind the keyboard button.
- Each scan = one server round trip that resolves the EAN *and* the player's status for it, and
  renders a tray row: cover, name, pieces, brand, status chip (*in library*, *lent to Anna*,
  *borrowed from Petr*, *not in your library*, *2 matches*, *unknown puzzle*).
- Feedback per scan: green flash on the viewfinder, short vibration, short beep, count badge.
  Duplicate = double buzz + low beep + "Already scanned" mini-toast; unknown = long buzz + two-tone.
- **Duplicates never enter the tray** and never cost a request: the browser knows the codes in the
  tray and answers a repeat with a pulse on the existing row and a short note; the server also
  refuses a different EAN resolving to a puzzle already there. The scanner ignores a code for
  ~2.5 s after accepting it.
- **Ambiguous EAN**: if exactly one candidate is already in the player's library / lent / borrowed
  lists, it is picked silently. Otherwise the row shows a chooser (image + pieces) and is excluded
  from actions until chosen.
- **A code several puzzles share (a multipack) can be scanned once per puzzle** (2026-10-04). A repeat scan of it
  is no duplicate while one of its puzzles is not in the tray: one left = added straight away, several = a new
  chooser offering only those. It is a duplicate once all of them are in, or while a row of the code still asks.
  The browser lets such codes through its own duplicate check (`data-multiscan-open-eans`).
  "Change" on a row offers only the puzzles no other row holds.
- **Action bar** pinned to the bottom (wrapping, never scrolling): every action shows how many rows
  it applies to, greys out at zero; ineligible rows say why (*already lent*, *not yours*). The
  person picker suggests the people you lend to / borrow from first, then favourites, searches
  any player, and takes a plain name; the collection picker lists yours and creates a typed one. Apply is **all-or-nothing** in one
  transaction. Eligibility was checked while scanning, so an apply-time failure is rare and gets a
  plain error.
- **After apply**: every row stays in the pile with its chip refreshed ("In your library", "Lent to
  Anna") - the everyday sequence is *add to library, then lend them* - a recap card says "6 puzzles
  lent to Anna" with the list, camera keeps running. "Clear all" empties the pile. No navigation,
  no reload.
- **Contextual entry points** preselect the action but still go through the tray: library header
  ("Multiscan"), lend/borrow page ("Scan returned puzzles"), collection page ("Scan puzzles into this
  collection").

## 4. Actions

Version 1 — only the puzzle varies, the shared input is natural:

| Action | Shared input | Eligible row | Skipped with reason |
|---|---|---|---|
| Add to my library | collection (system or named) | any resolved | already in that collection |
| Add to wishlist | — | any resolved | already on wishlist, already in library |
| Lend to … | person (`#code` or name), note | resolved, not lent by me | already lent to X |
| Borrowed from … | person, note | resolved, not held by me | already borrowed from X |
| Mark returned / Give back | — | I am owner or holder of an open lend | not lent / not borrowed |

"Mark returned" (I am the owner) and "Give back" (I am the holder) are one action on the server
(`ReturnLentPuzzles`) and one button whose label follows the rows. Rows can belong to different
borrowers; the recap groups them ("3 from Anna, 1 from Petr").

Later (per-puzzle inputs or wide side effects): mark solved without a time, list for swap/free (sell
needs a price per box), remove from library, mark sold to X (wipes collections + wishlist — never
batched blindly).

Batch **lend** re-uses the single handler, so today the borrower gets one notification per puzzle.
Aggregating into one ("Anna lent you 6 puzzles") needs a new notification type — follow-up.

## 5. Reliability rules

- **One normaliser** (`Value\Ean`): digits only, EAN-8 / UPC-A / EAN-13, GS1 checksum, leading
  zeros stripped exactly like `AddPuzzleHandler` stores them. Lookup, link and create all use it, so
  what is stored is what is later found.
- **Errors are not misses.** "Not found" is rendered only after a successful lookup with zero
  matches. A failed request keeps the EAN, shows "Couldn't check, retrying" and retries with backoff.
- **Every row shows the cover image** — a wrong match is caught by eye. Tapping a row reopens the
  chooser.
- **Membership is checked twice**: on the page (controller) and in every write LiveAction (each is
  its own HTTP request).
- **Validate, then mutate.** Batch handlers check every row first and throw before touching
  anything (rolled-back handlers leak through later flushes otherwise).
- **Hidden puzzles** (`hide_until` in the future) are invisible to the lookup on purpose and are
  checked on the write side too: nobody can link an EAN to them or create a duplicate of them.
- **A row's puzzle can change after the scan** (2026-10-08). Merged away: the row becomes the puzzle it
  was merged into (`GetCurrentPuzzleIds`, in a `PreReRender` hook and again before Apply - nothing
  queried while every puzzle exists), or goes when that puzzle is already in the tray. Deleted without
  a merge: the row becomes an unknown code. Turned secret and hidden from the player: Apply takes it
  out with "no longer available … nothing was changed" (`multiscan.error.unavailable`) - never why,
  and never a "try again" that would fail the same way. Its organisers get "still secret until …".
- Link and create are idempotent on the normalised code: a double tap or a retry never makes two
  puzzles or two change requests.

## 6. When the EAN is not found

Five different causes; the UI never lumps them:

1. **Puzzle exists, EAN missing** (most common) → link the code to the right puzzle.
2. **Puzzle not in the catalogue** → quick add.
3. **Wrong barcode** (price label, bundle, promo sticker). A brand-prefix hit ("looks like a
   Ravensburger code", `GetManufacturers::allByEanPrefix`) says it is the right barcode and the puzzle
   is just unregistered; no brand + odd length hints "try the other barcode".
4. **Hidden puzzle** → shown as case 2, but the write side refuses silently (generic message).
5. **Lookup failed** → retry, never "not found".

The person is holding the box at that moment — the only place name and pieces can be read — so the
**resolve sheet opens immediately** and the camera pauses. "Skip for now" is always there.

Paths, in order:

- **Find it in the catalogue** — search prefiltered by the detected brand, results with cover
  images. Picking one **links the EAN** to that puzzle (permanent: the next person scans clean).
- **Add new puzzle** — brand and EAN prefilled, name + pieces typed, and a **photo of the box, required**
  like on the add form (changed 2026-09-28: the optional photo let 16 image-less puzzles in within four
  days). `AddPuzzle::$puzzlePhoto` is non-nullable and `PuzzleBoxPhoto::constraint()` is the one rule for
  both places; quick-add answers "take a photo of the box first" / "not a usable photo" inline and keeps
  everything typed. See "Quick-add photo and never losing the tray" below. Lands unapproved but usable immediately, like the
  add form today. A "Open the full form" link leads to `puzzle_add?ean=…` in a new tab for the rare
  case someone wants everything (identification number, alternative name). The brand select lists every
  brand, approved or not; a brand typed into the text field instead goes to `AddPuzzle` as text and
  `ManufacturerResolver` makes it the existing brand of that name (case and spacing ignored) before it
  would create a new one (`docs/features/brand-duplicates.md`).
- **Skip for now** — the row moves to a separate **Unresolved** section under the actionable list,
  with its own count, tappable later. The main list therefore always equals "what the batch will
  touch", and the box is not lost. Unresolved rows survive an apply.

**Linking policy** (a wrong link poisons future scans for everyone):

| Puzzle's current EAN | What happens | Audit |
|---|---|---|
| empty | applied immediately | `PuzzleChangeRequest` saved already **approved** (reporter = reviewer = the member) so moderators see it in their list |
| different | the batch uses the chosen puzzle right away; the code is **not** written | `PuzzleChangeRequest` **pending** with `proposedEan = "<existing>, <new>"` (comma list, the merge convention) |
| already contains it | no-op | — |
| belongs to a hidden puzzle | refused, generic message | — |

## 7. Component design

`MultiscanTray` (Live Component) owns the tray; the scanner lives **outside** it so re-renders never
touch the `<video>`. A tiny `multiscan` Stimulus bridge listens for `barcode-scanner:scanned`, calls
`component.action('scan', {ean})`, reads `data-multiscan-*` attributes after `render:finished` to
fire feedback, pause/resume the camera and re-open the native scanner.

State is a `LiveProp` list of rows `{key, ean, puzzleId|null, state, candidateIds}`, addressed by `key` (one code can
have several rows). `candidateIds` holds every puzzle of a shared code, also on resolved rows, and stays empty for a code of
one puzzle or none. A tray rendered before keys existed gets the code as its key (`#[PostHydrate]`). Each render hydrates
rows with **one** `GetPuzzleOverview::byIds()` and the request-cached `GetUserPuzzleStatuses`, so a
scan costs the lookup + 2 queries. Chips, eligibility and skip reasons are computed in PHP by one
`MultiscanEligibility` service that the batch handlers use too — the counts the user sees are the
counts the handler enforces.

Writes are LiveActions that dispatch **one batch message** (`LendPuzzlesToPlayer`,
`BorrowPuzzlesFromPlayer`, `ReturnLentPuzzles`, `AddPuzzlesToCollection`, `AddPuzzlesToWishList`,
`LinkEanToPuzzle`, existing `AddPuzzle`) — never Turbo streams (Hotwire guide rule). Known
exceptions are caught and rendered as an inline error; the tray is never lost on an error.

## 8. Privacy / safety

The page shows only the viewer's own data (their rows, their lend counterparties) and catalogue
puzzles; the person input is free text / `#code` like the lend form. Register it in
`BlocklistCanaryTest` / `PrivateProfileCanaryTest` allowlists with that reason.

## 9. Native apps

The native scanner bridge (`window.onNativeScanResult`) is single-shot and closes after each code.
In continuous mode the bridge re-opens it after the tray acknowledges a scan. Works, feels choppier
than the web camera; a native "multi mode" is an app-side change (follow-up).

## 10. Open follow-ups

Tracked in [`implementation-plan.md`](implementation-plan.md) §"Follow-ups".

## Quick-add photo and never losing the tray

Requiring a camera step inside a scanning session must never cost the scanned pile (2026-09-28):

- **The photo lives in the `multiscan` controller**, not in the file input: picked → shrunk in the browser
  (`assets/image_compression.js`, shared with the add form: ≤ 2000 px JPEG, a 3 MB phone photo uploads as
  ~0.65 MB) → kept per barcode, with a thumbnail + "Retake". The Live Component empties the input it uploaded
  from after *every* answer, failed ones too, so each attempt uploads from a throw-away input
  (`component.files('photo', …)`). Skipping a code and reopening it keeps its photo.
- **"Add puzzle" without a photo opens the camera** instead of sending anything.
- **Failures never show the framework's error modal** (`response:error` → `displayError = false`) - a toast
  says the scans and the photo are kept, and the button works again (managed by the controller:
  `data-loading` never re-enables after a failed request).
- **A retry never creates a second puzzle**: `quickAddId` (LiveProp) is fixed when the resolve sheet opens and
  becomes the new puzzle's id; `createPuzzle` finds an existing puzzle with that id and just uses it.
- **Tray persistence**: after every render the controller mirrors the rows, action, collection and a half-filled
  quick-add (not the photo) to `sessionStorage` (`multiscan-tray:<playerId>`, 24 h); on page load an empty tray
  calls the `restore` LiveAction. `restore` trusts only the codes - every code is looked up again like a fresh
  scan, a remembered pick is kept only when it is still one of that code's candidates. On phones, opening the
  camera can make the browser drop the page; this brings everything back ("N scanned puzzles are back").


## Decoder: zbar on every platform (2026-10-04, reverted on Android 2026-10-06)

**2026-10-06:** the browser's own `BarcodeDetector` is back wherever it reads EAN-13 (Android Chrome, Samsung Internet,
Chrome on macOS); zbar decodes everywhere else.
- Why: zbar on Android cost about half of the Android scans. Add-form lookups from Android fell from 169 to 92 a day,
  while iOS stayed flat.
- Cause: Chrome opens 640×480, often from the wrong back lens, and zbar cannot read that at a distance the camera focuses
  on.
- The misread described below is still real. It is contained by `Value\EanList` for now.
- Research and the staged plan out of both problems: [`docs/features/barcode-scanner/README.md`](../barcode-scanner/README.md).

What follows is the 2026-10-04 reasoning, kept for the record.

`barcode_scanner_controller.js` (multiscan, the add-time form, the global search) decodes with our own zbar
(`@undecaf/zbar-wasm` + `@undecaf/barcode-detector-polyfill`) on every platform. The browser's own
`BarcodeDetector` is used only when zbar cannot be loaded.

Why: Android's built-in detector (Google's barcode engine behind Chrome and Samsung Internet) sometimes reads the
left half of an EAN-13 with the wrong parity. Every G-coded digit comes back as the L-coded digit one bar module
away, so the first digit becomes 0 and the result is a *different code that still passes the check digit*.
Ravensburger `4005555011897` came back as `0045555011897` (stored without leading zeros as `45555011897`), a Blitz
Puzzle `3770039925052` as `0778649925052`. Our 10-identical-reads rule does not help, because the misread is
stable on a given box and phone. In multiscan the wrong code matched nothing, the player picked the puzzle by name
and `LinkEanToPuzzle` proposed it as an extra code.

Evidence:
- Eight of the nine players who entered such codes scan with Android Chrome or Samsung Internet. Jan's iPhone
  (zbar) never reproduced it, and one player reproduced it at will on her box.
- Across every stored and proposed code (25k), only these two misread forms occur (16 cases, 15 of them
  Ravensburger `4005555`).
- A simulation with 301 catalogue codes × 30 random print and camera distortions:
  - zbar: 4,792 correct reads, 2 wrong, never this parity misread.
  - zxing-cpp (`zxing-wasm` 3.1.4): 5,475 correct, 37 wrong, 11 of them exactly this misread.
  - So zxing is not the alternative: it reads more, but also misreads more.
- Speed: zbar needs ~11 ms for a 640×480 frame and ~31 ms for a 720p frame on an M-series Mac. Android Chrome's
  default camera stream is 640×480.

Reading rules (`barcode_scanner_controller.js`):
- A code is accepted after 10 identical reads, and zbar's `quality > 8` (more than 8 scan lines agree within the frame).
  The native detector reports no quality, so on Android (native again since 2026-10-06) only the 10 reads apply.
- A frame that reads two different codes counts for nothing (two boxes in view, or a misread).
- One decode per camera frame (`requestVideoFrameCallback`, falling back to `requestAnimationFrame`, plus a 250 ms
  safety tick), so the 10 reads are 10 different frames.
- A scan-loop generation guard keeps a quick stop + start from running two loops.
- Vertical scanning stays on (Jan, 2026-10-04): zbar's `X_DENSITY = 0` would be ~2× faster but cannot read a box
  held sideways.
- Checked with Chrome's fake camera: a single code is accepted after exactly 10 frames (~0.47 s), two codes in view are
  never accepted, continuous mode decodes 29-30 times a second from a 30 fps camera, and still does after a stop +
  start.

Second line of defence: the add form and "Suggest a change" refuse `45555…` / `045555…` codes with the full code
as a suggestion (`Value\EanList`).
