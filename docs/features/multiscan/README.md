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

Existing pieces reused as-is: `barcode_scanner_controller.js` (native `BarcodeDetector`, zbar
polyfill, checksum + 10-frame confirmation), `SearchPuzzle::allByEan()` (substring match, zeros
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
- **Action bar** pinned to the bottom (wrapping, never scrolling): every action shows how many rows
  it applies to, greys out at zero; ineligible rows say why (*already lent*, *not yours*). The
  person picker suggests the people you lend to / borrow from first, then favourites, searches
  any player, and takes a plain name; the collection picker lists yours and creates a typed one. Apply is **all-or-nothing** in one
  transaction. Eligibility was checked while scanning, so an apply-time failure is rare and gets a
  plain error.
- **After apply**: tray keeps only unresolved rows, a recap card says "6 puzzles lent to Anna" with
  the list, camera keeps running. No navigation, no reload.
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
- **Add new puzzle** — brand and EAN prefilled, name + pieces typed, **optional photo** taken on the
  spot (file upload inside the Live Component). Lands unapproved but usable immediately, like the
  add form today. A "Open the full form" link leads to `puzzle_add?ean=…` in a new tab for the rare
  case someone wants everything (identification number, alternative name).
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

State is a `LiveProp` list of rows `{ean, puzzleId|null, state, candidateIds}`; each render hydrates
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
