# Data import - value readers (stage 4a)

**Status:** 2026-11-14 - built and reviewed once (the review's changes are in; "What the review changed" at the end),
nothing calls it yet. Pure logic: no database, no routes, no templates, no Messenger, no new dependency. The design it
implements is [README.md](README.md) "Recognising columns and values" and "Keys, exactly"; this page says what each
class accepts, how sure it is, and the rules decided where the design was silent.

Code: `src/Services/DataImport/Reading/` (the readers - stateless services) and `src/Value/DataImport/` (what they
take and hand out; the precision enum is `src/Value/DatePrecision.php`). Tests: `tests/Services/DataImport/Reading/`,
`tests/Value/DataImport/`.

## The principle

A reader **never throws and never guesses**. Every value comes back in one of these states:

| State | Meaning | What the layer above does |
|---|---|---|
| nothing | the cell is empty | nothing - not a problem |
| certain | one reading | takes it |
| read from text | one reading, but not written the plain way | takes it, shows once what was read |
| needs the column | a shape with several meanings - **it carries no value of its own** | one decision per column and shape: resolved from the rows, or one question |
| needs a yes | a reading plus other text | proposes it, the player confirms |
| a problem | no reading, or a reading that cannot be right | the row waits in "To fix" with choices |

The columns decide only when nothing else can be meant. A wrong reading of a time is a result sixty times too fast on
a public leaderboard; a wrong reading of a date turns "January 2023" into an exact day. Asked on purpose - never
decided silently:

- what `17:36` (or `45:30:00`, or a time cell `1:02`) means unless the other reading is far outside what anybody
  puzzles at;
- what a bare number in a time column means (`237`, `40`), and whether `8.10` is 8.1 hours or 8 h 10 min - unless
  the header names the unit;
- whether `Jan 23` / `10/23` is a day or a month of a year, when every value of the column can be both;
- the day/month order when no value settles it;
- whether typed dates beside day-first text dates were turned around by the spreadsheet;
- the year of a date written without one (`1/3`, `20-Sep`, a cell showing `m/d`);
- a time with words around it (`1:40ish`, `17:36 for both`);
- a multipack (`13x99`), an unknown count (`?`, `~1000`), a lone `1`-`9` in a pieces column;
- a word in a yes/no column that is neither (`Back to the library`);
- a barcode a spreadsheet spoiled (`4.00556E+12`);
- a header that means several things (`Name`, `Count`, `Completed`, `Year` …).

## Input - `CellInput`

The readers know nothing about the spreadsheet reader (stage 3, built in parallel). They take a `CellInput`:

| Factory | Holds |
|---|---|
| `CellInput::blank()` | no value |
| `CellInput::text(string)` | a text as typed (every CSV cell) |
| `CellInput::number(int\|float)` | a number cell |
| `CellInput::duration(int $seconds)` | an elapsed-time cell (`[h]:mm:ss`), may be over a day |
| `CellInput::timeOfDay(int $seconds, bool $clockFormat = false)` | a time-only cell (`h:mm:ss`, `h:mm`). `$clockFormat` = the format is a clock's (`h:mm AM/PM`, a date with a time): a moment, never a duration |
| `CellInput::date(DateTimeImmutable, DatePrecision, bool $yearKnown)` | a date cell: the precision its format shows, and whether the format shows the year |
| `CellInput::boolean(bool)` | a checkbox |

`SpeedPuzzling\Web\Value\DatePrecision` (day / month / year) is the one precision enum: the readers use it, the
spreadsheet reader will hand its cells over in it, and it is what `finished_at_precision` will store.

### Text first - `ImportText`

Before a text is read, what is not meant is taken away (`ImportText::clean()`): broken bytes, the full-width forms of
digits and marks (`１２：３４` = `12:34`, NFKC), non-breaking and other odd spaces, control and direction marks, zero-width
characters, the emoji variation mark. A text of nothing but whitespace is "nothing". A text longer than 200 characters
is no value of any kind (it is a note) - checked before any pattern runs, so a megabyte in a cell costs nothing.
Numbers are ASCII digits only: digits of other scripts (`٣ h`, `१२:३४`) are not read as numbers.

## Durations - `DurationReader`

`read(CellInput): ReadDuration { certainty, ?seconds, readings, alternatives, ?shape, ?leftover }`

`seconds` is what the import takes when nobody says otherwise; `readings` holds every possible time; `shape` names an
ambiguous shape, so one answer covers the column. **A value with a shape never has `seconds`** - it is taken only
through `ReadDuration::settledBy()` (`DurationColumnResolution::apply()`). A reading above **1,000 hours**
(`MAX_SECONDS`) is never offered.

**A text reads like the cell of the same value** (`45:30:00` like a duration cell of 45 h 30 min), so a sheet saved
as CSV reads like its workbook.

| Certainty | Examples | Notes |
|---|---|---|
| `no_time` | empty, spaces, `0`, `0:00`, `0:00:00`, `0 min`, a zero cell | a result without a time. A zero is "no time", not a problem (formulas over empty cells give `0:00:00`) |
| `certain` | `1:09:17`, `0:55:41`, `0:56:4`, `1:5:9`, `01:02:03`; a duration cell or a time-only cell **with seconds, under 24 h** | three parts are hours:minutes:seconds; minutes and seconds must be below 60 |
| `read_from_text` | `8min`, `8 min`, `25min`, `1h 23m`, `1 h 23 min 5 s`, `1h23`, `18hr36`, `1.5 h`, `1,5 h`, `90 minutes`, `45m30s`, `8'30"`, `1:02 h`, `17:36 min`, `17:36.5`, `(1:02:03)` | unit words below; a number after the last unit is the next smaller one (`1h23` = 1 h 23 min); under a larger unit a smaller one stays below 60 |
| `read_with_leftovers` | `0:55:41 (slower than last time)`, `pb 1:02:03`, `about 25 min`, `8 min 2 attempts`; with a shape also `1:40ish`, `casual but 1:11`, `17:36 for both` | exactly one time inside other text. `leftover` is the rest as typed (case and accents kept, separators and a pair of brackets around it dropped) - for the private notes. With a shape it needs the column's answer **and** the yes |
| `two_readings` | see "Shapes" | `seconds` is null until the column is settled |
| `unreadable` | `1:13:??`, `6:07, 6:23, 5:05`, `tube`, `timed`, `gold foil`, `?`, `-`, `$$$`, `1:75:00`, `1:02:03:04`, `-5`, `1e5`, `NaN`, `1,000`, `3 days`, **`-1:02:03`**, **`2 days 3 h`**, a clock-format time cell, a date or yes/no cell | several times in one value, a piece of a time, words - and the two below |

- **A sign makes a time unreadable.** `-1:02:03` is how a spreadsheet exports a negative difference; any `+`, `-`,
  `−`, `–` touching the time (before or after) means it is not a time. A dash with a space is a separator
  (`1:02:03 - with a break`).
- **Days next to a time make it unreadable** (`2 days 3 h`, `1d 2:30:00`, `2 dny 3 hod`, `2日 3時間`): the time is
  only a part of what is meant - never "3 h" with "2 days" as a note.

Unit words (any case, a full stop after a short form allowed; a Latin word must end there - `5 stars` is no `5 s`):

| | Hours | Minutes | Seconds |
|---|---|---|---|
| en | h, hr, hrs, hour, hours | m, min, mins, minute, minutes | s, sec, secs, second, seconds |
| cs | hod, hodin, hodina, hodiny, hodinu | minut, minuta, minuty, minutu | sek, sekund, sekunda, sekundy |
| de | Std, Stunde, Stunden | Min, Minute, Minuten | Sek, Sekunde, Sekunden |
| es | hora, horas | minuto, minutos | seg, segs, segundo, segundos |
| fr | heure, heures | mn, minute, minutes | seconde, secondes |
| ja | 時間, 時 | 分, 分間 | 秒, 秒間 |
| marks | | `'` `′` | `"` `″` `''` |

Between two parts: nothing, a comma, or `and` / `a` / `und` / `y` / `et`.

### Shapes with more than one meaning

| Shape (`DurationShape`) | Example | Readings (`DurationReadingRule`) |
|---|---|---|
| `two_part_text` | `17:36`, `83:45`, `1:02` | minutes:seconds · hours:minutes - always both (`83:45` can be 83 hours of a 9,000-piece puzzle) |
| `two_part_text_with_hundredths` | `1:02.03`, `17:36.50` | minutes:seconds with hundredths (62 s) · the "h:mm.ss" habit (1 h 2 min 3 s). Only two decimals below 60; `17:36.5`, `1:02.75`, `1:02.035` can only be fractions of a second - read from text |
| `time_of_day_cell` | a time-only cell without seconds (`1:02` typed, stored as 1 h 2 min) | as stored · stored hours taken as minutes. With seconds it is certain - `1:09:17` typed into a plain cell is stored exactly so |
| `long_duration_cell` | a duration of 24 h or more - a cell, or the text `45:30:00` (`45:30` typed, stored as 45 h 30 min) | as stored · stored hours taken as minutes (only without seconds; with seconds it has one reading and the column lets it stand unless its pace says otherwise) |
| `zero_seconds_duration_cell` | a duration under 24 h with `:00` seconds - a cell, or the text `1:30:00` | as stored · stored hours taken as minutes |
| `bare_number` | `237`, `2`, `8.10`, `8.75`, a number cell | whole: minutes · seconds · hours. With decimals: decimal minutes · seconds (rounded) · decimal hours; with at most two decimals below 60 also "hours.minutes" (`8.10` = 8 h 10 min) and "minutes.seconds"; below 1 also the part of a day a spreadsheet stores a time as (`0.05` = 1:12:00, `0.048113` = 1:09:17) |
| `decimal_with_unit` | `1.30 h`, `12.30 min` | decimal (`1.3 h`) · "hours.minutes" (1 h 30 min). One decimal (`1.5 h`) and decimals of 60 and more (`2.75 h`) are plain decimals - read from text |

A number cell `8.1` is read like the text `8.10`: under the "hours.minutes" habit the decimals are hundredths
(`8.1` = 10 min, `8.05` = 5 min, `8.5` = 50 min) - a spreadsheet drops the last zero.

### The column decides - `DurationColumnResolver`

`resolve(list<DurationColumnCell{read, ?pieces}>, ?DurationUnit $unitHint): DurationColumnResolution` - one
`DurationShapeDecision` per shape the column holds: **resolved** (`rule`) or a **question** (`rule` null) with
`options` (each reading with how many rows it can read and for how many its pace is ordinary / plausible),
`suggested` (the reading whose pace is ordinary for most rows, offered first, never applied) and `exampleRows` (rows
with a piece count first). `DurationColumnResolution::apply(ReadDuration)` gives a value as the resolved shapes read
it.

The evidence is the **pace** (pieces per minute of a row under a reading), in three bands:

| Band | Pieces per minute | Constants |
|---|---|---|
| ordinary | 0.2 - 15 (1,000 pieces in 83 hours … in 67 minutes) | `SLOWEST_…`, `ORDINARY_FASTEST_…` |
| plausible | 0.2 - 60 | `SLOWEST_…`, `FASTEST_…` |
| far outside | below 0.05 or above 100 (the add form's own limit) | `FAR_TOO_SLOW_…`, `FAR_TOO_FAST_…` |

The two readings of `40:00` are sixty times apart and a "possible" band is wider than that, so "it fits" proves
nothing: 1,000 pieces in `40:00`, `35:30`, `52:10` fit minutes:seconds at 19-28 pieces a minute - and were a slow
puzzler's 40 hours. A shape is resolved to a reading only when **all** of this holds:

1. the reading can read **every** value of the shape (a `8.75` in the column rules "hours.minutes" out - like a 13
   in a date);
2. at least `ROWS_TO_DECIDE` = 3 rows of the shape have a piece count, **and** they are at least
   `SHARE_OF_ROWS_TO_DECIDE` = 30 % of the shape's rows;
3. its pace is **ordinary for every one** of them - a pace faster than 15 pieces a minute is never chosen for the
   player;
4. every other reading that can read every value is **far outside** for at least `NEARLY_ALL` = 95 % of them.

So hours:minutes settles for 1,000 pieces in `3:30` (as minutes:seconds it would be 286 a minute); minutes:seconds
settles for 100 pieces in `45:10` (as hours 0.04 a minute); and 500 pieces in `45:10`, 1,000 pieces in `40:00`, a
bare `40`, a speed run of 100 pieces in `237`, `8.10` against 8.1 hours are all questions - with the ordinary reading
offered first.

Readings that give the same time for every value of the column are one reading (`sameAs` names the twin).

**A unit in the header answers the bare numbers.** `Time (min)`, `Minutes`, `Zeit (Std)` (`FieldGuess::$unitHint`,
below) passed as `$unitHint` resolves `bare_number` to that unit (`byHeaderUnit`), whatever the pace says. It says
nothing about the other shapes.

**When the rows do not settle it, a value stands as stored in two cases only** (`storedValueStands()`):

- it has no other reading at all - a duration of a day and more **with seconds** - and no row's pace speaks against
  it (such a value does not put the column's other times in doubt either);
- it is a time without seconds (`zero_seconds_duration_cell` or `time_of_day_cell` - a cell, or text like `1:30:00`)
  **in a column of real h:mm:ss times**: the column's other times have seconds, such values are one or at most
  `MANY_ZERO_SECONDS` = 10 % of the column's times (a time ends in `:00` once in sixty), no row's pace speaks against
  it, and no duration of a day and more *without* seconds stands in the column (the mark of `45:30` typed for
  minutes and seconds).

A column whose times all end in `:00` - an `[h]:mm` column, an `h:mm` column, their CSV - is **always asked**, never
silently hours and minutes.

## Dates - `ImportDateReader`

`read(CellInput): ReadDate { certainty, ?date, precision, yearKnown, possibleOrders, possibleShortMeanings, problems,
centuryAssumed, typed }`

`date` is midnight UTC of the day - of the **first day** of the month or year when only that much is known. Today
comes from the injected clock.

| Input | Reading |
|---|---|
| a date cell | passes through (`typed` true): precision and `yearKnown` as handed over; a month cell is cut to its first day |
| `2023-12-30`, `2023/12/30`, `2023.12.30`, `20231230`, with a time after it (`…T10:00:00Z`, `… 14:05`) | certain - a four-digit year in front has one order |
| `30/12/2023`, `30.12.2023`, `30. 12. 2023`, `30-12-2023` | certain, `possibleOrders = [dmy]` (the 30 settles it) |
| `12/30/2023` | certain, `[mdy]` |
| `3/4/2023` | `order_open`: `[dmy, mdy]`, `date` null; `inOrder()` gives each reading |
| `30-12-23`, `03/04/05`, `23/12/30` | two-digit years: day-first, month-first **and year-first** are tried (`23/12/30` is how Japan writes it) |
| `12/2023`, `3.2024`, `2023-12` | a month |
| `2020` (text or number) | a year - only 1900-2100; any other bare number is unreadable (`1000` is a piece count, `45000` a spreadsheet's day number - never guessed) |
| `3 March 2024`, `March 3, 2024`, `3rd March 2024`, `Sunday, 3 March 2024`, `03-Mar-2024`, `3-Mar-24`, `3. března 2024`, `3. März 2024`, `3 de marzo de 2024`, `1er mars 2024`, `2024年3月3日(日)` | certain, `possibleOrders = []` - a month's name is no question of order |
| `Sept 2020`, `September 2020`, `září 2020`, `marzo de 2020`, `2024年3月` | a month |
| **short dates** - `Jan 23`, `Sep 20`, `Sep/20`, `10/23`, `01/24`, `3.13` | `day_or_year_open` - see below |
| `Sep-20`, `Sep '20` | a month of a two-digit year, certain: `mmm-yy` is how a spreadsheet shows one, an apostrophe how people write one |
| `20-Sep`, `20 Sep`, `3 March`, `3. března`, `3月3日` | a day without a year, certain (`d-mmm` is how a spreadsheet shows one) |
| `March`, `3月` | a month without a year |
| empty | `no_date` |
| `garbage`, `31/02/2023`, `13/13`, `3/4/5`, `30/12-2023`, a time, a range, two dates, a date with words around it, `令和6年…`, `٣ March 2024` | `unreadable` |

Month names: English, Czech (the month's own name, the form after a day - `března` - and the short forms), German,
Spanish, French - full and short, any case, with or without accents (`ImportCalendarWords`); Japanese `年月日`.
Weekday names in front and the small words in between (`de`, `of`, `the`, `le`, `dne` …) are skipped.

### Short dates: a day or a year

`<month name> <1-2 digits>` and `<1-2 digits>/<1-2 digits>` are a **shape**: the last number is a day of the month
(the year not written) or a two-digit year (the day not written). `Jan 23, Feb 23, Mar 23` are the months of 2023 as
often as three 23rds - reading them as days would turn month-only data into exact days and only ask "which year?".

- With both meanings possible the value is `day_or_year_open` (`date` null, `possibleShortMeanings = [day_of_month,
  month_of_year]`); `asShort()` / `shortReading()` give each: the day reading has `yearKnown` false and, for the
  numeric form, its own open order (`10/11` = 10 November or 11 October); the month reading has month precision.
- A value with one meaning is read at once and still says which it is, so the column can settle the others:
  `Feb 30`, `2/30` can only be a month of 2030 (30 is no day of February); `Jan 5`, `25/3`, `25/03` can only be a
  day (one digit is no year, 25 no month).
- A year needs two digits; `23/10` is never October 2023 (a year in front has four digits).

### The column decides - `DateOrderResolver`

`resolve(list<ReadDate>, ?ShortDateMeaning $shortDatesAnswer): DateOrderResolution`, two questions in this order:

**1. The short dates** (`DateOrderResolution::$shortDates`, a `ShortDateDecision { state, meaning, suggested,
exampleRows, rows }`):

| State | When |
|---|---|
| `not_needed` | the column has no short date |
| `resolved` | some value has only one meaning and no value contradicts it (`Feb 30` → years; `Jan 5` or `25/03` → days) - or the player answered (`$shortDatesAnswer`) |
| `ambiguous` | every value can be both: **one question**. `suggested`: months of a year when the values march month by month (`Jan 23, Feb 23, Mar 23`; `10/23, 11/23, 12/23, 01/24`; three values at least); days when a value would be a month still to come; else nothing |
| `mixed` | some can only be days, some only years: a problem, with the rows that do not go the way most go |

**2. The order** of day and month, over every date written in numbers (`possibleOrders` not empty; short dates count
once they are settled as days, and not at all while their own question is open):

| State | When |
|---|---|
| `not_needed` | no such date |
| `resolved` | exactly one order makes every value a real date - or several do and read every value the same |
| `ambiguous` | several orders fit and read some value differently: one question, with rows the orders read differently |
| `mixed` | no order fits every value (`30/12/2023` next to `12/30/2023`): a problem, with the rows that do not follow the order most rows do |
| `typed_dates_in_doubt` | the text dates all follow one order and none could be read the other way (every one has a day above 12), **and** every typed date of the column has a day of 12 or less: what a spreadsheet leaves after reading day-first text as month-first (3 April became 4 March, 13 April stayed text). A question - "were these turned around?" - with the typed rows; on yes, `ImportDateReader::withDayAndMonthSwapped()` turns each back. Never two silent orders in one column |

Only a contradiction removes an order. A date in the future does not (people mistype years - the README's "2028
among 2024"); it only makes the order without such dates the `suggested` one. **One exception:** the year-first
reading of two-digit dates (`30-12-23` as 23 December 2030) is dropped when it puts a row into the future and another
order that is left does not - so a column of `30/12/23, 15/01/24` is day-first without a question, and it is asked
only when all orders survive (`23/12/30, 24/01/15`: day-first is 2030, year-first 2023 - both stay, year-first
offered first).

`DateOrderResolution::apply(ReadDate)` reads a date by what is settled; `isSettled()` says whether anything waits.

Rules decided here:

- **Two-digit years: 00-69 are 2000-2069, 70-99 are 1970-1999** (`centuryAssumed` true). The window is fixed, not
  relative to today: a row's key holds its date, and the same text must be the same date when the file is read
  again in three years. A year read into the future is flagged like any other.
- **A year nobody saw is never used.** With `yearKnown` false, a date cell carries the year its format hides and a
  text the placeholder year 2000 (a leap year, so `29/2` stays a date); `ImportDateReader::withYear()` puts in the
  year of the sheet's name or the player's answer (29 February of a year without one becomes unreadable).
- **Problems are flags, never corrections** (`DateProblem`): `in_the_future` - a day after tomorrow (somewhere it is
  already tomorrow), a month after this one, a year after this one; `before_1950`. A date without a known year has
  no problems.

## Pieces - `PiecesReader`

`read(CellInput): ReadPieces { certainty, ?pieces, ?problem, ?note, numbers }` - a count is a whole number from 1
to `MOST_PIECES` = 60,000.

| Certainty | Examples |
|---|---|
| `no_pieces` | empty |
| `certain` | `1000`, the number 1000, `1000.0` as a number cell, `１０００` |
| `read_from_text` | `1,000`, `1 000`, `1.000`, `12'000`; `1000.0`, `500.00` as text; `1000 pcs`, `500 pieces`, `500 dílků`, `1000 ks`, `500 Teile`, `500 Stück`, `500 piezas`, `500 pièces`, `500ピース` |
| `problem` `multipack` | `13x99`, `12 x 99`, `500*4`, `2×500`, `500 + 1000` - `numbers` holds them as written, `note` the value; no count is chosen and one row never becomes several puzzles |
| `problem` `unknown` | `?`, `??`, `mini?`, `1000?`, `~1000`, `ca. 500`, `500+` - `numbers` holds what was found |
| `problem` `possibly_thousands` | a lone `1` - `9` (a number cell, or a text of nothing else): `1.000` typed where a dot is the decimal mark is stored as 1. `numbers` holds both (`[1, 1000]`). A word or a thousands mark makes a small count meant: `1 piece`, `4 dílky` |
| `problem` `not_whole` | `731.78` (an average), `1,5` |
| `problem` `zero` / `negative` / `too_many` | `0` · `-5` · `60001`, `500.000`, a float beyond the integers (compared with the cap before any cast) |
| `problem` `unreadable` | `large`, `1e3`, `500-1000`, `70 x 50 cm`, `٣٠٠`, a date / duration / yes-no cell |

In a pieces column a dot (or comma, space, apostrophe) before **exactly three digits** is a thousands mark - `1.000`
is 1,000, never one piece; a piece count is whole. Decimals that are all zero are dropped (`1000.0`, `500.00`), any
other decimals are `not_whole`.

## Yes / no - `YesNoReader`

`read(CellInput): ReadYesNo { certainty: blank | known | unknown, ?value, ?word }`

A checkbox passes through; the numbers 1 and 0 are yes and no. Words and marks, in any case, with or without
accents, a full stop or brackets around them:

| | Yes | No |
|---|---|---|
| en | y, yes, yep, yeah, true, t, x, ok, done | n, no, nope, false, f |
| cs | ano, jo, pravda | ne, nepravda |
| de | ja, j, wahr | nein, falsch |
| es | sí / si, s, verdadero | no, falso |
| fr | oui, o, vrai | non, faux |
| ja | はい, 済, 済み | いいえ |
| marks | 1, ✓, ✔, ☑, ✅, ○ | 0, ☐, ✗, ✘, ✕, ✖, ❌, ×, the full-width `ｘ` |

The letter `x` is a tick (the README's "Got rid of: yes / x"); the signs `×` `✗` `❌` are a cross - and so is the
full-width `ｘ`, which is how a cross is typed on a Japanese keyboard. Anything else is `unknown` and comes back as
typed (cut to 100 characters) - the layer above asks once per distinct word.

### A flag with a date - `FlagWithDateReader`

`read(CellInput): ReadFlagWithDate { flag: ReadYesNo, date: ReadDate, ?leftover }` for a column that feeds two
fields: `Y - 30/12/2023`, `Yes (Jan 2023)`, `y 2022`, `30/12/2023 - Y`. The date part goes through `ImportDateReader`
with all its rules - `Y - 3/4/2023` keeps its open order, `Y - Jan 23` its open meaning, for `DateOrderResolver`. A
flag alone has no date; a date alone has no flag (what a date without a flag means - "completed" - is the column's
business); a known flag with something that is no date keeps it as `leftover`; an unknown word with a date
(`maybe 2022`) is a question about the word.

## Barcodes - `BarcodeCellReader`

`read(CellInput): ReadBarcodes { certainty, codes, other, ?damage }` - a thin layer over `EanList`, the one parser.

| Certainty | Examples |
|---|---|
| `no_code` | empty |
| `barcodes` | `4005556169320`, the number 4005556169320 or 4005556169320.0, `4005556169320.0` as text, `4 005556 169320`, several codes in one cell (`,` `;` `/` a line break) |
| `damaged` | `scientific_notation`: `4.00556E+12`, `4,00556e12` (a number of eight digits and more) · `digits_lost`: a number of 12 digits and more ending in five or more zeros whose check digit is wrong (`4005560000000` - the same cell saved and opened again). Never repaired: the digits are gone |
| `not_a_barcode` | `n/a`, `-`, a wrong check digit, a catalogue number, a code with letters |

**What is handed over:** `codes` are the barcodes the way the catalogue stores and compares them - digits without
leading zeros. A UPC-A whose leading zero a number cell dropped (`36000291452`) and the same code as printed
(`036000291452`) are the same `36000291452`; `printed()` gives the form under the bars. Only what
`EanList::isAcceptedNewCode()` accepts counts (a right check digit, a length a box carries; an Android misread of a
Ravensburger code is refused there). Everything else is in `other`, as typed - never compared with the catalogue as
a barcode. Brand codes need no reader of their own: `BrandCodeList::fromInputs()` takes the cell's text.

## Headers - `ImportField` + `HeaderMatcher`

`ImportField` is the "This is" list of the README's step 2: puzzle name, brand, pieces, barcode, brand code, solved
on, time, solved with, solo/pair/team, first try, attempt number, solved without the box, already on
MySpeedPuzzling, owned, not owned, status, public comment, collection - plus two special ones: `computed`
("MySpeedPuzzling works this out": ppm, pieces/min, pieces per minute, diff, percentage, standing, place, rank,
average …) and `ready_made_field` (one of `StandardCustomField` - private notes, started on, the ratings, price,
artist …).

The words are data: `ImportField::headerWords()` per language (en, cs, de, es, fr, ja) and, for the ready-made
fields, their own `StandardCustomField::headerWords()` plus a few more in `HeaderMatcher` (`comment` → private
notes: D11, `place of purchase` → bought from). German words are also indexed without their umlauts (`Groesse`,
`Kuenstler`).

`HeaderMatcher::guess(string $header): list<FieldGuess{field, readyMadeField, score, sure, unitHint}>`, the best
first. A header is compared folded (`SearchText::fold()`), `%` and `#` as the words "percent" and "number", every run
of anything but letters and digits one space - and also with the spaces taken out: `No. of Pieces`, `no_of_pieces`
and `NoOfPieces` are one header. A part in brackets is a note, not the name: `Time (h:mm:ss)` is `Time`.

- **Score 100** = the header is one of the field's words.
- **`sure`** = the one guess a column may be mapped by from its header alone: it is exact, no other field has the
  word, and the word does not mean several things. Never sure - the values or the player decide:
  - words two fields share (ties, both at 100): `Count` / `Anzahl` (pieces or copies), `Name` (the puzzle or a
    person), `Completed` / `Finished` / `Solved` / `dokončeno` / `beendet` … (the date solved **or** a status - a
    "Completed" column is a date in one real file and a yes/no in another), `Place` (a rank or where it is kept),
    `stav` / `estado` / `状態` (status or condition), `note` (a remark, or in French a mark out of five);
  - words with one field that still mean several things (`AMBIGUOUS_HEADERS`): `Type`, `Status`, `Size` / `Größe`,
    `Year` / `rok` / `Jahr` (the year of release - **never** "solved on"), `jméno` (a person's name, not the
    puzzle's).
- **Score 50-95** = the header only holds one of the field's words as a whole word among others (in Japanese:
  anywhere) - higher the larger the word's share of the header, never `sure`. Two limits:
  - **generic words count only as the whole header** (`WHOLE_HEADER_ONLY`): `time`, `date`, `datum`, `fecha`, `name`,
    `with`, `mit`, `con`, `avec`, `who`, `list`, `price`, `code`, `count`, `type`, `place`, `box`, `puzzle`, `year`
    and their like in the other languages. `Start time`, `End time`, `Date added`, `Release date`, `Creation date`,
    `With box`, `Who gave it`, `mit Rahmen`, `con marco`, `Sell price`, `Wish list`, `Puzzle type` give **no guess**;
  - **a qualifier stops every weaker guess** (`QUALIFIERS`: start, end, goal, best, previous, added, release, sold,
    sell, wish, frame … and their like): `Goal time`, `Best time`, `Date sold`. Where a specific field exists
    (`Start date` → started on) it is matched on its exact words only. The words of a formula are the exception:
    `Diff between previous best attempt` is still "computed" (and no longer an attempt number).
- Not guessed any more: `Image` / `Picture` (no puzzle name), `Jan` (no barcode - the Japanese `JANコード` stays),
  `Puzzle type` (it belongs to a known export's layout, later).
- **A unit in the header is handed out** (`unitHint`: seconds / minutes / hours) with a Time guess: in brackets
  (`Time (min)`, `Čas (h)`, `Zeit (Std)`, `Time [s]`), after "in" (`Time in minutes`), or as the whole header
  (`Minutes`, `Hours`, `Stunden` - only the long words; never `Min`, `M`, `H`). `DurationColumnResolver` takes it as
  the answer to the bare-number question.
- No guess for a blank header or one without a known word (`ID`, `Picture URL`).

Equal guesses come in the order of `ImportField`, the ready-made fields last.

## Keys - `ImportPuzzleKey`, `ImportSourceKey`, `ImportKeyFold`

Every key value is `1:` + a sha256 (64 hex characters) of a canonical text of lines joined by `\n`. The `1` is the
key's version; a change of the fold or of a canonical text is version 2, **computed side by side** - a stored key is
never rewritten (an import whose content was wiped keeps only its hashes, so it could not be).

**The fold is frozen.** `ImportKeyFold::v1()` is a copy of what `SearchText::fold()` was on 2026-11-14, not a call:
the search fold changes when search needs it (`SearchText::VERSION` is 3 already), and the keys must not change with
it. It is never edited; a new rule set is `v2()`. It runs on the ICU of the image, so `ImportKeysTest` pins exotic
characters with literal keys (an accent in NFC and NFD, an en dash, full-width forms, ß, Ł, a Japanese title,
half-width katakana, an emoji, a ligature) - an ICU that folds differently turns a test red before a key is stored.

### `ImportPuzzleKey`

`of(name, ?pieces, ?brandText): ?string` is a **pure function of what the row says** - nothing of the catalogue is in
it. Whether "MB" is a catalogue brand today, what the player answered, a brand created or merged later: the key does
not move, so `import_puzzle_decision`, the unlisted puzzle and every source key of the puzzle stay found.

```
puzzle-key:1
<the name through ImportKeyFold::v1() - a "(…)" part stays>
<the piece count - nothing when unknown: null, 0 and a negative number are all "unknown">
<the brand text reduced to letters and digits (ImportBrandText) - nothing without a brand>
```

A row without a name has no key (`null`).

`resolved(name, ?pieces, ?brandId): ?string` is a **second, lookup key** for "the same puzzle once its brand is
known" (`puzzle-key-by-brand:1`, the same name and pieces, the brand's id): `Ravensburger` and `Ravensburger
Spieleverlag` are two text keys and one resolved key. The layer above looks a puzzle up by its resolved key first
and falls back to the text key. It is never part of another key and is stored only next to its inputs (name, pieces,
brand id), so a brand answer or a brand merge computes it again.

`ImportBrandText::lettersAndDigits()` is the brand reduced to what two spellings share (`Master Pieces` =
`MasterPieces` = `master-pieces` = `masterpieces`).

### `ImportSourceKey`

**Per created thing, not per row** - and what kind of row it came from is not in it. A row that is both a result and
an owned puzzle has two keys; an owned puzzle is found again whether its row also carried a result or not. Each
takes the **text** puzzle key, never the resolved one.

```
result(puzzleKey, ?date, precision, ?seconds, solvingType, occurrence = 1)

    source-key:1
    result
    <the puzzle key>
    <2023-12-30 | 2023-12 | 2023 - the date at its precision - or "no date">
    <the seconds, or "no time">
    <solo | duo | team>
    <the occurrence, from 1>

owned(puzzleKey, ?collectionLabel)            wishList(puzzleKey)

    source-key:1                                  source-key:1
    owned                                         wish-list
    <the puzzle key>                              <the puzzle key>
    <"main", or "label:" + the folded label>
```

- **A key gives exact equality and nothing else.** `2020-09` (a month) and `2020-09-14` are unrelated keys. The
  pairing of "Never twice" - exact days first, then months, then years - compares parsed values and created results,
  never keys.
- **The occurrence** tells apart rows of **one sheet** that are equal in the whole rest of the key. They are numbered
  by a stable secondary order - the attempt number when the rows carry one, then a hash of the row's remaining
  content - never by their order on the sheet, so sorting the sheet or inserting a row changes no key. The caller
  numbers them (stage 4b).
- The answer to "one result or two?" does not change keys: it decides how many of the keyed rows are added.
- No attempt number, comment, people, codes or custom values in a key.

## What the review changed (2026-11-14)

| | Before | Now |
|---|---|---|
| Time columns | resolved when one reading's pace was merely possible (0.5-60) | resolved only when the winner is ordinary (0.2-15) for every row and the loser far outside (< 0.05, > 100); ≥ 3 rows **and** ≥ 30 % of the shape's rows |
| `:00` durations | taken as stored unless the column objected | never taken by themselves; stand only in a column of real h:mm:ss times; an all-`:00` column is asked |
| `45:30:00` as text | certain | the same shapes as the cell of that value |
| `-1:02:03`, `2 days 3 h` | a time (the sign and the days dropped) | unreadable |
| `1:02.03` | 62 s | a shape: 62 s or 1 h 2 min 3 s |
| clock-format time cells | a duration | unreadable (`CellInput::timeOfDay(…, clockFormat: true)`) |
| `Jan 23`, `10/23` | a certain day, the year asked | a shape: a day or a month of a year, settled per column |
| `30-12-23` year-first | kept, asked | dropped per column when it puts a row into the future and another order does not |
| typed dates beside day-first text | read as they were | `typed_dates_in_doubt` - a question |
| puzzle key | held the catalogue brand's id when resolved | a pure function of the row's text; a second "resolved" lookup key |
| source key | one per row, with the row's kind | one per created thing: result / owned / wish-list |
| key fold | the live `SearchText::fold()` | the frozen `ImportKeyFold::v1()`; every key value starts with its version |
| headers | any known word inside a header was a guess | generic words only as the whole header; qualifiers; `sure`; ties; `unitHint` |
| lone `1`-`9` pieces | a count | "is this 1,000?" |

## For the next stages

- The column recogniser (the rest of 4a) asks each reader how many of a column's values it reads; a bare year, a
  piece count and a rating all read as numbers - the header guess and the other columns decide. It maps a column by
  its header alone only for a `sure` guess.
- A decision that is a question becomes one of "the questions only this layout needs"; the player's answer is applied
  with `ReadDuration::settledBy()`, `DateOrderResolver::resolve($dates, $answer)`, `ReadDate::inOrder()`,
  `ImportDateReader::withDayAndMonthSwapped()`.
- `unusual` times (too fast, far from the player's own) are not this stage: they need the puzzle (stage 6b).
- A known export's layout (Puzzle Tracker) maps its own headers (`Puzzle type`, `Name`, `Status`) - they do not need
  the matcher.
- New shapes people's files show are one more row in a reader's table and in its test.
