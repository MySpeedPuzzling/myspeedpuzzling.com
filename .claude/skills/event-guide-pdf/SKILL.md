---
name: event-guide-pdf
description: Make (or refresh) a friendly, illustrated A4 PDF guide to the MySpeedPuzzling competition toolkit for one real event's organisers - a chapter per role (organiser, results desk, seating/printing, judges, competitors, stream, commentators, their website), data in/out, checklists, glossary - with annotated screenshots of their event on the sandbox or production. Use when Jan says "make a guide/PDF/manual for <event>'s organisers", "refresh the <event> guide", "re-shoot the screenshots of the guide", or the UI changed / the demo event was rebuilt and the guide must follow.
argument-hint: "<event slug or event folder> [what changed]"
allowed-tools: Bash, Read, Write, Edit, Agent
---

# Event guide PDF

One guide per real event, built from the event itself: the facts (ids, URLs, rounds, standings, displays) are read
from its pages, the screenshots are taken from the live site, and the PDF is printed by headless Chrome. The
content is hand-written HTML per event; everything else is one re-runnable command.

## Where things live

| What | Where |
|---|---|
| Engine (generic, in the repo) | `.claude/skills/event-guide-pdf/scripts/build.py` |
| Look (MSP coral/indigo, Rubik) | `templates/guide.css` - copied into each build, fonts + logo come from `public/` |
| Starting points | `templates/event.example.json`, `templates/shots.example.json`, `templates/guide-skeleton.html` |
| **The event folder** | **outside the repo or in a gitignored folder** (the repo is public: never commit real event names, people, e-mails, logos or the guide). Jan names it; e.g. a `guide/` folder next to the event's other assets |

The event folder holds `event.json`, `shots.json`, `guide.html`, the event logo, and after a build `facts.json`,
`shots/*.jpg` + `shots/*.json` (pin boxes), `build/` (the filled HTML) and `<output>.pdf`.

## The one command (re-shoot + re-render)

```bash
python3 .claude/skills/event-guide-pdf/scripts/build.py <event-folder>
```

It (1) discovers the facts read-only as the organiser account, (2) starts its **own** Selenium container
(`selenium/standalone-chromium`, `--rm`, a free port, removed on exit - never the shared dev `chrome`), signs in
per account and takes every shot in `shots.json`, (3) fills `guide.html`'s `{{placeholders}}` from `facts.json`,
places the numbered pins and prints A4 with page numbers. At the end it lists everything to check: a mark or clip
whose element is gone, a mark outside its shot, an unresolved placeholder, a missing shot. Exit code 1 = look at the
list; the PDF is still written.

Partial runs: `--only discover|shoot|render`, `--shots name1,name2` (retake some). After a UI change or a rebuilt
event (new ids/URLs) just run the full command - URLs, ids and round lines come from the event again.

## Safety: read-only

- Discovery only GETs pages. In the browser, the sandbox gate and the sign-in are the only POSTs; after sign-in
  every page gets a guard (`READ_ONLY_GUARD`) that refuses non-GET fetch/XHR/beacons and form submits. A shot may
  type a table into the judge's search or open a tab/menu/row, never press Save/Start/Publish/Stop/Confirm, and
  **never click a link** (a few state-changing actions are plain GET links, e.g. "I'm going!" - the guard can't
  stop those).
- Use made-up accounts only (sandbox: `test-user+1` organiser, `+2` judge, a made-up competitor; password
  `sandbox`). Never the real organisers' accounts. On production use accounts Jan gives you, and remember the
  bot-blocker stops headless Chrome there - shoot the sandbox copy of the event instead.
- Docker is shared: the script runs one container at a time and removes it; never stop other containers.

## Steps for a new event

1. **Brief.** Ask Jan (or read the brief) for: the event slug and host (sandbox / production), the folder, the
   organisers' names, which championships/categories apply, the accounts, the logo file, and which optional
   features the event uses (blocks/challenges, table names, unboxed, secret reveal, divisions, shared puzzles,
   standings rules, displays, competitor pages + proposals, registration on MSP). Read the event's real format
   (their rules, schedule) - the guide maps *their* words onto MSP.
2. **Read the as-built docs** before writing: `.claude/rules/features/competition-toolkit.md`,
   `competition-toolkit-v2.md`, `events.md`, `docs/features/competitions-management/README.md` + the docs of every
   feature the event uses. Check every UI label in `translations/messages.en.yml` / the templates. Describe only
   what exists; a doc claim you cannot find in a template is left out.
3. **Set up the folder.** Copy `templates/event.example.json` → `event.json` (host, `sandbox_gate`, slug, output
   name, logo, footer, accounts, global CSS - e.g. hide the sandbox banner), copy the logo next to it.
   Run `--only discover` and read `facts.json`: rounds are keyed by their slug, standings by their public slug,
   displays by their slugified name. Those keys are what `shots.json` and `guide.html` use - they survive a rebuild
   of the event as long as the names stay.
4. **Shots.** Start from `templates/shots.example.json`. Shoot first, look at every picture (shrink with
   `sips -Z 1000` and Read them), then write the text. Per shot:
   - `url` with placeholders; `account`: `organiser` (default) / `judge` / `competitor` / `guest`;
   - `viewport`: `desktop` 1280, `wide` 1440, `phone` 390 (emulated, 2×), `display` 1920×1080;
   - framing: `clip` (an element), `clip_from` (from an element's top down, `max_height` tall), `full` + `max_height`,
     else the viewport; `pad`;
   - `marks`: numbered pins on elements - `{"selector": …, "contains": "text", "index": n}`; with a text, the
     innermost element containing it wins. Prefer visible texts (UI labels) over CSS classes. The pin sits just left
     of the element (6 mm on paper, whatever the shot's scale); `"pin": "r"` puts it right of the element, `"tl"` on
     its top-left corner - use those when the element starts at the shot's left edge (a clamped pin covers its text);
   - `actions` (look-only): `{"type": "a6", "into": ".lr-find-input"}`, `{"click": {…}}`, `{"wait": ms}`;
     `settle` ms before the shot; `css` for the shot (e.g. a grey backdrop behind a transparent OBS overlay).
   Scrolling a page is unreliable - use `clip` / `clip_from` instead.
5. **Write `guide.html`** from `templates/guide-skeleton.html`. Structure: cover (MSP logo × event logo), welcome +
   how to use + try it yourself + contents + who does what; ch. 1 the event at a glance (their schedule, how it is
   set up - the `{{rounds.<slug>.format}}` lines -, their rules → MSP settings table); one chapter per role with
   Purpose / Open it / Sign in, setting up, on the day, options, 2-4 **real situations from their format**, and
   annotated screenshots (`<figure data-shot="name">` + `<ol class="callouts"><li data-n="1">…`); data in / data out;
   checklists (weeks before / week before / each round / after); glossary; help (Jan). Drop a role the event does not
   have; one line for features the event does not use.
   **Tone**: friendly plain English, explain why not just how, short sentences, UI labels exactly as on screen in
   `<span class="ui">`. Never internal words (round_puzzle, pick round, entry, value object, split → say "the moment
   … was recorded", reveal mode, as_zero_pieces…). Say clearly what the software does and what judges decide.
   Layout helpers: `.side` (text + phone shot), `.phones` (3 phone shots), `.cols`, `figure.w-80/.w-70/.w-60`,
   boxes `.box.tip/.why/.warn`, `.situation` (h4 + p), `.steps`, `.checklist`, `dl.glossary`.
6. **Render and read it yourself**: run the command, then Read the PDF in page ranges. Fix half-empty pages
   (a figure too tall → lower `max_height` or use `w-80`), pins outside shots, cut-off marks. Keep it under ~10 MB
   (e-mail attachment): shots are JPEG 82, the event logo is shrunk with `sips` when large.
7. **Content review**: spawn ONE independent general-purpose reviewer with only the PDF, the built HTML and the
   event's format (copied into its own file) - friendly? jargon? wrong for their format? missing real-life moments?
   pins vs captions? Apply its findings, re-render.
8. **Report** to Jan: PDF path + page count, the command, what the reviewer changed, and every feature you found
   missing or confusing while writing (that goes to the UX work, not into the guide as a promise).

## Surviving a rebuilt event or a changed UI

- Find elements by **UI labels** ("Publish results", "Challenge 2", "Table ") or stable attributes
  (`[data-time-boxes]`, `[data-toolkit-card=…]`), never by made-up data (a team name, a puzzle stand-in, a time).
  Open a table the event always has (`a1`), not one whose state you liked today. A typed table name opens the card
  by itself after a short pause; the example still taps the first row after typing, which is harmless when the card
  is already open (see `shots.example.json`).
- Anything a caption says about the data ("3 pieces missing", "none of the displays has X", "Table A6") goes stale
  silently. Prefer `{{…}}` values from `facts.json`; otherwise keep the wording generic. After every refresh,
  re-read the captions next to the shots that changed, and the "How it is set up" table.
- Keep the event folder the one source of truth: edit `guide.html` / `shots.json` there and run the full command
  there (not only `--only render`).

## Gotchas

- The judge page keeps tab drafts in sessionStorage - the script clears it before each shot, otherwise a card
  opened by the previous shot hides the search field.
- "Finished now" and the competitor's "Done - propose my time" show only while the round's stopwatch runs. Never
  start a stopwatch for a shot: caption it honestly ("in the screenshots the stopwatch isn't running").
- Sandboxes are shared: other people rehearse on the same demo event, so results move between runs. Re-read the
  facts and the shots before trusting a number in the text; prefer templated values (`{{…}}`) to hand-typed ones.
- The sign-in needs an `Origin`/`Referer` header (stateless CSRF) - the script sends them.
- `/execute/sync` awaits promises; CDP calls go through `/goog/cdp/execute` (chromedriver), so `Page.printToPDF`,
  `Page.captureScreenshot` (clip + `captureBeyondViewport`) and device-metrics emulation need no extra library.
