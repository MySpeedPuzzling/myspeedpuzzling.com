# Event types ("What kind of event")

Organisers run more than competitions: Virginia JPA hosts speed puzzling competitions, workshops and puzzle socials
(swaps, casual puzzling), and a competition day often has a puzzle swap too (MSP #308/#334, 2026-10-19). Every event,
edition and series can say what kind of event it is.

## The kinds

`Value\EventType` - stored by value, never rename one:

| Value | English |
|---|---|
| `competition` | Competition |
| `marathon` | Puzzle marathon |
| `workshop` | Workshop |
| `casual_puzzling` | Casual puzzling |
| `puzzle_swap` | Puzzle swap |

Labels: `event_type.<value>` in all 6 locales. Add a kind at the end of the enum when organisers ask for one.

## Rules

- **Optional.** An event can be several kinds (a competition with a puzzle swap) or none. `Value\EventTypes` is the
  value: never empty, each kind once, in the enum's order; `null` = not set.
- **Stored** as a jsonb list `event_types` on `competition` and `competition_series`, `[]` = not set (every event before
  the field existed - no backfill). Written only through `Competition::changeEventTypes()` /
  `CompetitionSeries::changeEventTypes()`.
- **An edition without its own shows its series'** (like "Who can enter"): `Competition::eventTypes()`, in SQL
  `CASE WHEN c.event_types <> '[]'::jsonb THEN c.event_types ELSE cs.event_types END`. The edition forms show the
  series' kinds ticked; an edition saved with exactly its series' kinds stores `[]`, so it keeps following the series.
- **Shown** (`EventTypes::shownOf()`) only when they say something: nothing for a plain competition or when none is set
  (most events - they look as before); otherwise every kind, the competition too, so "competition + swap" is never
  mistaken for a swap only.

## Where

- Forms: the add/edit event form, the series form, "Add edition", "Add several dates" (`FormType\EventTypesType`,
  checkboxes, before "Who can enter").
- Events page: a tag per kind on the row (`RowTagType::EventType`, after "Recurring"; a grouped series row only when
  every date has the same kinds), and the kinds' names in the search text (`EventsIndexFactory`: typing "swap" finds
  the puzzle swaps).
- Byline of the event, edition and series pages (`event_parts/_event_types.html.twig`), organization page cards.
- Internal API: `eventTypes` on competition/edition/series create and `PATCH` (left out = unchanged; an edition created
  without them takes its series'), answered as the stored list (`[]` = not set). Public API v1 is unchanged.

## Not yet

- A filter by kind on the events page (chips next to the scope) - `docs/TODO.md`.
