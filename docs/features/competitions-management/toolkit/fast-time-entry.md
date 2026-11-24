# Fast time entry (judge page, results desk) - SPWS 1:1 demo, 2026-11-24

Jan (verbatim): "entering time should be extremely convenient and fast - typing `:` should not be required, i would
consider if typing manually 3 inputs h/m/s when typing 2 digits to the one it will automatically jump focus to another
for example (hours optional, minutes and seconds required) - for judges if not clicking "Stop" button when timestamp
timewatch is running, the entering time must be very fast"

Binding additions from Jan (via the coordinator, same day): digits typed in one go fill **from the right** -
`3025` = 30:25, `13025` = 1:30:25, `5812` = 58:12, `45` = 0:45, `123456` = 12:34:56, `013025` and `0013025` = 1:30:25
(leading zeros never shift anything). A judge typing digits without looking must always get exactly this. Correcting
one part ("tap minutes, fix it") must stay possible.

## Today

- Judge card (`/en/live-results/<roundId>`): one `<input inputmode="numeric">` "Time", placeholder `h:mm:ss`, hint
  "Digits only work too: 12345 = 1:23:45, 5812 = 0:58:12", a line "= 1:23:45" under it, then **Review** → confirm step
  → **Save**. The numeric keypad opens (iPhone: a digit pad **without** `:` and without Return; Android: digits + a few
  signs). So the placeholder asks for a colon the iPhone pad cannot type; the digits path works but nothing shows it.
- Per-challenge times of a block (`renderSplits()`, pencil → typed split): the same kind of field, placeholder `h:mm:ss`.
- Results desk: the result editor's one input (time / pieces / missing modes), the per-puzzle editor's "finished at"
  rows (`data-row-at`) - `inputmode="numeric"`, placeholder `1:23:45`.
- Time proposals: confirmed on the judge card - a "Confirm" button, or the proposal's time prefilled into the same Time
  field (no field of its own). "Didn't finish" asks pieces only (no time).
- One parser for all: `parseResultTime()` in `assets/official_results_time.js` (digits right-aligned as h:mm:ss, at
  most 6 characters - so `0013025` is refused today).

Taps from a typed table to a saved time (stopwatch running, table opens without Enter): tap the Time field, the digits,
Review, Save. Keystrokes for the time itself:

| time    | today, as the placeholder asks | today, digits (hidden hint) | (a) three boxes H/MM/SS | chosen: right-filling field |
|---------|--------------------------------|-----------------------------|-------------------------|-----------------------------|
| 58:12   | 5 (`58:12` - iPhone: impossible) | 4                         | 4 (focus starts in MM)  | 4                           |
| 1:23:45 | 7 (`1:23:45` - iPhone: impossible) | 5                       | 7 (tap H, 1, tap/next, 2345) | 5                      |

SPWS times are mostly **over an hour** (blocks of 90-150 min), so the hours case is the common one.

## Options

**(a) Three boxes H / MM / SS, focus jumps after 2 digits (Jan's idea).** Clear structure, a box per unit.
- Where does focus start? In H: a blind `3025` becomes 30 h 25 min - refused by Jan's own binding rule. In MM: every
  time over an hour needs a tap on H first and a jump back (+2 actions on the common SPWS case), and a judge who reads
  "1:47:23" off a clock and types `14723` gets 14:72 → error.
- Three real inputs = focus moves on every 2nd digit. On iPhone a `focus()` outside the user's own event drops the
  keyboard; inside the `input` event it works, but Android keyboards differ (composition, no cancelable
  `beforeinput`). The split rows and the desk rows are re-rendered with `innerHTML` - three inputs per row multiply that.
- Correcting one part is natural (tap the box). Backspace in an empty box → previous box is extra logic.

**(b) One numeric field, digits fill from the right ("microwave"), formatted as you type.** `3` → 0:03, `30` → 0:30,
`302` → 3:02, `3025` → 30:25, `13025` → 1:30:25. Colons appear by themselves.
- Blind typing is exactly Jan's rule; leading zeros vanish; whatever a judge reads off a clock (`1:47:23`, `0:58:12`,
  `58:12`) typed as its digits gives the right time. No focus moves at all.
- Intermediate states can be invalid (`1472` = 14:72 on the way to 1:47:23) → those are not shouted at: the line under
  the field stays calm while typing, the error turns red only on Review.
- One input = one `<label>`, one value for screen readers, works with the existing `innerHTML` rows.

**(c) (b) + tap a part to fix it.** Tapping the field when it holds a time selects the part under the finger (hours,
minutes or seconds); typed digits replace just that part, after 2 digits the selection moves to the next part (Jan's
jump, inside one field), a third digit never spills into the neighbour. Hours missing (58:12 that should be 1:58:12):
retype the whole time - 5 digits.

**(d) Considered and dropped:** a custom on-screen keypad (no OS keyboard - big, but a second keyboard to learn and
more bundle), `type="time"` (wall clock, AM/PM, locale UI - not a duration), a duration picker wheel (slow).

## Decision: (c) - one right-filling field with part correction

It is the only option that gives Jan's blind-typing results by construction, it is fastest on the SPWS case (5
keystrokes for 1:23:45 vs 7), it needs no focus moves (the iPhone keyboard never drops), and it keeps the "tap a part,
fix it" of the three boxes. The three-box look is replaced by the live formatted value + a reading in words under it
("1 h 23 min 45 s"), which removes the "is 58:12 hours or minutes?" doubt the boxes were meant to remove.

## Behaviour (one pure module `assets/time_entry.js`)

- **Blind path** (field empty, or everything selected, or typing at the end after a blind start): every digit of the
  field is taken, leading zeros dropped, and re-formatted from the right: ≤ 2 digits `0:ss`, 3-4 `m:ss`/`mm:ss`, 5-6
  `h:mm:ss`/`hh:mm:ss`. Anything else typed (`:` `.` `,` space on a desktop keyboard) is ignored - typing `1:23:45`
  with colons still gives 1:23:45. More than 6 significant digits are kept and refused ("At most 6 digits"), never
  shifted.
- **Paste** (`insertFromPaste`): a text with separators is read by `parseResultTime()` as is (`83:45` = 1:23:45 keeps
  working), else the digits path.
- **Part path**: the **first** tap/click into a field holding a time selects everything (typing starts over, blind -
  a prefilled proposal or a refused value is replaced, never edited by accident); a tap on the field **being typed in**
  selects the part under the caret and switches the field to part mode (until it is emptied or left). Typed digits
  change only that part; 2 digits → the next part is selected; the last part stays selected (a third digit starts it
  over); a part never holds more than 2 digits (never spills). Leaving the field (blur) re-formats a readable time
  canonically (`1:5:25` → `1:05:25`); empty or unreadable text stays as typed.
- **Reading** `readTimeEntry(text)` → `{empty}` | `{seconds}` | `{error: 'minutes' | 'seconds' | 'too_long' |
  'out_of_range' | 'invalid'}`. For blind digits it equals `parseResultTime(digits)` (pinned by the harness over every
  digit string up to 6 + leading zeros); seconds and minutes 0-59 (each with its own message), hours ≤ 23, 1 s to
  23:59:59 (`RoundEntryResult::MAX_SECONDS`).
- **Shown value** of a time set by code (proposal prefill, refused change, restored draft, the split's own time):
  `formatTimeEntry(seconds)` = `58:12`, `1:23:45`, `0:45` - the same format the blind path produces; it is focused with
  everything selected, so typing replaces it (as today). A draft stored before this release (`1:23:45`) restores.
- `parseResultTime()` gains one widening: leading zeros of a digits-only text are dropped before the 6-digit check
  (`0013025` = 1:30:25). Nothing valid today changes; the participants sheet benefits too.
- A delegated `bindTimeEntry(root)` (input listener in the **capture** phase, so the controller's own `input` action
  sees the formatted value; click → part select; focusout → canonical) - no per-element state, survives `innerHTML`.
  Fields opt in with `data-time-entry`.

## Surfaces

| surface | change |
|---|---|
| Judge card Time field `#lr-time` (incl. a proposal's prefilled time, the refused-change restore, the draft) | `data-time-entry`, placeholder = digits example, hint "Digits only - 3025 = 30:25, 13025 = 1:30:25. Tap a part to fix it.", line under it = reading in words; errors red on Review only |
| Judge per-challenge typed time of a block (`renderSplits()` form) | `data-time-entry`, same placeholder, `readTimeEntry()`, specific error |
| Results desk result editor (time mode only; pieces/missing modes keep a plain number field) | `data-time-entry` toggled with the mode, `readTimeEntry()`, specific errors |
| Results desk per-puzzle "finished at" rows | `data-time-entry`, `readTimeEntry()` |
| "Didn't finish" | unchanged - pieces only |
| Time proposals | no field of their own - the card's Time field above |
| Participants sheet (`sheet_results.js`, a spreadsheet grid cell that also takes pieces / DNS / text) | out: a cell is a free text grid with paste of whole columns; it keeps `parseResultTime()` (gains the leading-zero widening). |
| Player's "add result" form, My data | out (must not change) |

Server side unchanged (the browser sends seconds).

## Offline / service worker

The judge page's offline copy is the same template (`LiveResultsShellController` renders `live_results.html.twig`)
and the bundle is content-hashed: a stored shell + its listed assets stay a consistent pair. No `CACHE_VERSION` bump.

## Bundle

Baseline 239.3 KB / 65.8 KB gzip (budget 240.5 / 66). The module is small; the budget is raised on purpose in
`LiveEntryBundleTest` with the measured numbers.

## Tests

- `tests/time-entry-harness.mjs` + a PHPUnit wrapper: Jan's cases, blind parity with `parseResultTime()` over every
  digit string, typing step by step (`1`,`13`,`130`,…), backspace, paste, part edits (minutes fixed, seconds fixed,
  hours added, third digit), errors.
- Existing harnesses (`live-results-harness.mjs`, desk) stay green.
- Browser walk: judge card 375 px + desktop (type, correct a part, block split, offline), desk editor.

## Not covered here

- The real iPhone/Android keyboards (numeric pad without Return: Review must stay visible) - `docs/TODO.md`.
- Review + Save stay two steps (the confirm step is the safety net: what it replaces, conflicts).

## Review amendments (independent review 2026-11-24, binding)

1. A tap on a field that is not focused yet selects everything (blind path); only a tap on the focused field selects a
   part - a prefilled time typed over blind never lands in part mode.
2. Typed separators are ignored (blind path) and every reader of a `data-time-entry` field uses `readTimeEntry()`
   (`reviewTime`, `typedSplit`, the desk result editor, the desk per-puzzle rows) - so `83:45` typed on a desk keyboard
   is the error "Minutes go from 00 to 59", while a **pasted** `83:45` keeps reading as 1:23:45.
3. A backspace that removed only a colon also removes the digit before it (no dead key); after every rewrite the
   selection is set again.
4. Selections after a click are set in `requestAnimationFrame` (iOS places its caret after the click).
5. Nothing is rewritten while a keyboard composes (`isComposing`); `compositionend` applies the mask.
6. One rule for a long part: never more than 2 digits (the hours-split rule is dropped).
7. Desk result editor: crossing between time mode and a count mode (pieces / missing) clears the field and toggles
   `data-time-entry`; pieces ↔ missing keep their conversion.
8. Every prefill of a time field uses `formatTimeEntry()` (judge proposal + refused restore, split edit, desk editor and
   per-puzzle rows); display-only times (chips, split rows) keep `formatResultTime()`.
9. Blur normalisation leaves empty and unreadable text untouched; `''` stays `''` (focus decisions,
   `proposalChoiceUntouched`); a bare `:` becomes `''`.
10. `parseResultTime()` strips leading zeros but keeps one digit (`0`/`00` stay out of range, never "empty").
11. Live reading: errors of a time on its way are muted while typing, red on Review; too many digits are red at once.
12. `bindTimeEntry()` is bound once per controller in `connect()` and unbound in `disconnect()`.
13. The reading in words carries no plural forms (unit abbreviations: h / min / s, Std. / Min. / Sek., 時間 / 分 / 秒).
    Part editing is pointer-first; keyboard users move the caret with ←/→ and type, or retype the whole time.

## As built (2026-11-24)

- `assets/time_entry.js` (pure: `formatDigits`, `formatTimeEntry`, `readTimeEntry`, `partAt`, `maskTimeEntry`,
  `timeEntryText`, `timeInWords`; DOM: `bindTimeEntry`, `setTimeEntry`), harness `tests/time-entry-harness.mjs` +
  `tests/TimeEntryScriptsTest.php` (Jan's cases, parity with `parseResultTime()` over 2,400 digit strings, part edits,
  paste, backspace).
- Judge card `#lr-time` + split form (`data-time-entry`), `showTimeReading()` / `timeErrorText()` in
  `live_results_controller.js`; desk result editor + per-puzzle rows in `results_desk_controller.js`.
- Texts (6 locales): `live_results.entry.time_placeholder` / `time_hint` / `time_invalid` changed, `time_parsed` gone,
  new `time_words`, `time_words_hours`, `time_minutes_invalid`, `time_seconds_invalid`, `time_too_long`;
  `results_desk.editor.placeholder_time` / `error_time`, `competition_toolkit.desk_puzzles.error_split` changed.
- Judge bundle 239.3 → 244.8 KB / 65.8 → 67.6 KB gzip, budget 245.5 KB / 68 KB.
- Walked in Selenium Chrome at 375 px (mobile emulation) and 1280 px: blind 13025 / 3025 / 5812 / 12345, minutes fixed
  by a tap, a fresh tap replacing a value, an invalid time on Review, a block's per-challenge typed time, the offline
  copy (network off, reload, type), the desk result editor (time, mode switch to pieces and back) and per-puzzle rows.
- Keystrokes for the time itself: 58:12 = 4 (`5812`), 1:23:45 = 5 (`12345`), no colon, no field to move to; then
  Review + Save as before.

## PR review fixes (second independent review, 2026-11-24)

- "Being typed in" = the user typed or tapped in this field since it got the focus (`timeTouched`, cleared on
  `focusin` and by `setTimeEntry()`); a focus by code (proposal prefill, refused restore, split edit) never counts, so
  the judge's first tap on a prefilled time selects all of it.
- The split edit and the refused-change restore select the value after focusing it (typed over, never appended to).
- `compositionend` only re-sends `input` (the capture listener masks it once).
- Part mode: a backspace over a colon takes the digit before it, the parts stay.
- Desk: the time typed comes back when the organiser switches pieces → time again.
- The blur tidy-up sends `input`, so drafts and previews follow it.
