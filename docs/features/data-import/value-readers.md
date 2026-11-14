# Data import - value readers (stage 4a)

**Status:** 2026-11-14 - built, nothing calls it yet. Pure logic: no database, no routes, no templates, no Messenger,
no new dependency. The design it implements is [README.md](README.md) "Recognising columns and values" and "Keys,
exactly"; this page says what each class accepts, how sure it is, and the rules decided where the design was silent.

Code: `src/Services/DataImport/Reading/` (the readers - stateless services) and `src/Value/DataImport/` (what they
take and hand out). Tests: `tests/Services/DataImport/Reading/`, `tests/Value/DataImport/`.

## The principle

A reader **never throws and never guesses**. Every value comes back in one of these states:

| State | Meaning | What the layer above does |
|---|---|---|
| nothing | the cell is empty | nothing - not a problem |
| certain | one reading | takes it |
| read from text | one reading, but not written the plain way | takes it, shows once what was read |
| needs the column | a shape with several meanings | one decision per column and shape: resolved from the rows, or one question |
| needs a yes | a reading plus other text | proposes it, the player confirms |
| a problem | no reading, or a reading that cannot be right | the row waits in "To fix" with choices |

What is "ask the player" on purpose - never decided silently:

- what `17:36` means when the rows' paces fit both readings (or there are fewer than three rows with a piece count);
- what a bare number in a time column means (`237`), and whether `8.10` is 8.1 hours or 8 h 10 min;
- the day/month order when no value settles it, and the year-first order of two-digit dates (`23/12/30`);
- the year of a date written without one (`1/3`, `20-Sep`, a cell showing `m/d`);
- a time with words around it (`1:40ish`, `17:36 for both`);
- a multipack (`13x99`), an unknown count (`?`, `~1000`);
- a word in a yes/no column that is neither (`Back to the library`);
- a barcode a spreadsheet spoiled (`4.00556E+12`).

## Input - `CellInput`

The readers know nothing about the spreadsheet reader (stage 3, built in parallel). They take a `CellInput`:

| Factory | Holds |
|---|---|
| `CellInput::blank()` | no value |
| `CellInput::text(string)` | a text as typed (every CSV cell) |
| `CellInput::number(int\|float)` | a number cell |
| `CellInput::duration(int $seconds)` | an elapsed-time cell (`[h]:mm:ss`), may be over a day |
| `CellInput::timeOfDay(int $seconds)` | a time-only cell (`h:mm:ss`, `h:mm`) |
| `CellInput::date(DateTimeImmutable, DatePrecision, bool $yearKnown)` | a date cell: the precision its format shows, and whether the format shows the year |
| `CellInput::boolean(bool)` | a checkbox |

The stage that reads rows adapts its cells to this (the reader's `ReadCell` has the same seven kinds; an error cell
is a text).

### Text first - `ImportText`

Before a text is read, what is not meant is taken away (`ImportText::clean()`): broken bytes, the full-width forms of
digits and marks (`１２：３４` = `12:34`, NFKC), non-breaking and other odd spaces, control and direction marks, zero-width
characters, the emoji variation mark. A text of nothing but whitespace is "nothing". A text longer than 200 characters
is no value of any kind (it is a note) - checked before any pattern runs, so a megabyte in a cell costs nothing.

## Durations - `DurationReader`

`read(CellInput): ReadDuration { certainty, ?seconds, readings, alternatives, ?shape, ?leftover }`

`seconds` is what the import takes when nobody says otherwise; `readings` holds every possible time; `shape` names an
ambiguous shape, so one answer covers the column. A reading above **1,000 hours** (`MAX_SECONDS`) is never offered.

| Certainty | Examples | Notes |
|---|---|---|
| `no_time` | empty, spaces, `0`, `0:00`, `0:00:00`, `0 min`, a zero cell | a result without a time. **Decided:** a zero is "no time", not a problem (formulas over empty cells give `0:00:00`) |
| `certain` | `1:09:17`, `0:55:41`, `0:56:4`, `1:5:9`, `01:02:03`, `25:10:05`; a duration cell under 24 h; a time-of-day cell **with seconds** | three parts are hours:minutes:seconds; minutes and seconds must be below 60 |
| `read_from_text` | `8min`, `8 min`, `25min`, `1h 23m`, `1 h 23 min 5 s`, `1h23`, `18hr36`, `1.5 h`, `1,5 h`, `90 minutes`, `45m30s`, `8'30"`, `1:02 h`, `17:36 min`, `17:36.5`, `(1:02:03)` | unit words below; a number after the last unit is the next smaller one (`1h23` = 1 h 23 min); under a larger unit a smaller one stays below 60; a fraction can only be one of seconds, so `17:36.5` is minutes:seconds |
| `read_with_leftovers` | `0:55:41 (slower than last time)`, `pb 1:02:03`, `about 25 min`, `8 min 2 attempts`; with a two-part time also `1:40ish`, `casual but 1:11`, `17:36 for both` | exactly one time inside other text. `leftover` is the rest as typed (case and accents kept, separators and a pair of brackets around it dropped) - for the private notes. A two-part time keeps its `shape` too: it needs the column's answer **and** the yes |
| `two_readings` | see "Shapes" | `seconds` is null until the column is settled |
| `unreadable` | `1:13:??`, `6:07, 6:23, 5:05`, `tube`, `timed`, `gold foil`, `?`, `-`, `$$$`, `1:75:00`, `1:02:03:04`, `-5`, `1e5`, `NaN`, `1,000`, `3 days`, a date or yes/no cell | several times in one value, a piece of a time, words |

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
| `two_part_text` | `17:36`, `83:45`, `1:02` | minutes:seconds · hours:minutes. **Decided:** always both - `83:45` cannot be a time of day, but it can be 83 hours of a 9,000-piece puzzle; the column decides |
| `time_of_day_cell` | a time-only cell without seconds (`1:02` typed, stored as 1 h 2 min) | as stored · stored hours taken as minutes. **Decided:** a time-only cell *with* seconds is certain - `1:09:17` typed into a plain cell is stored exactly so, and it is how most real time cells arrive |
| `long_duration_cell` | a duration cell of 24 h or more (`45:30` typed, stored as 45 h 30 min) | as stored · stored hours taken as minutes (only without seconds) |
| `zero_seconds_duration_cell` | a duration cell under 24 h with `:00` seconds | **taken as stored** (`certain`) - the shape is only a flag for the column |
| `bare_number` | `237`, `2`, `8.10`, `8.75`, a number cell | whole: minutes · seconds · hours. Up to two decimals: decimal minutes · decimal hours · "hours.minutes" (`8.10` = 8 h 10 min) · "minutes.seconds" - the last two only when the decimals are below 60. More decimals: decimal minutes · decimal hours · below 1 also the part of a day a spreadsheet stores a time as (`0.048113` = 1:09:17) |
| `decimal_with_unit` | `1.30 h`, `12.30 min` | decimal (`1.3 h`) · "hours.minutes" (1 h 30 min). **Decided:** one decimal (`1.5 h`) and decimals of 60 and more (`2.75 h`) are plain decimals - read from text |

A number cell `8.1` is read like the text `8.10`: under the "hours.minutes" habit the decimals are hundredths
(`8.1` = 10 min, `8.05` = 5 min, `8.5` = 50 min) - a spreadsheet drops the last zero.

### The column decides - `DurationColumnResolver`

`resolve(list<DurationColumnCell{read, ?pieces}>): DurationColumnResolution` - one `DurationShapeDecision` per shape
the column holds: **resolved** (`rule`) or a **question** (`rule` null) with `options` (each reading with how many
rows it can read and how many it fits), `suggested` (the reading fitting most rows, offered first, never applied) and
`exampleRows` (rows with a piece count first). `DurationColumnResolution::apply(ReadDuration)` gives a value as the
resolved shapes read it.

The evidence is the **pace**: pieces per minute of a row under a reading must be between
`SLOWEST_PIECES_PER_MINUTE` = 0.5 (1,000 pieces in 33 hours) and `FASTEST_PIECES_PER_MINUTE` = 60 (the add form
refuses from 100).

A shape is resolved to a reading only when **all** of this holds:

1. the reading can read **every** value of the shape (a `8.75` in the column rules "hours.minutes" out - like a 13
   in a date);
2. at least `ROWS_TO_DECIDE` = 3 rows of the shape have a piece count;
3. it fits at least `NEARLY_ALL` = 95 % of them;
4. for every other reading that can read every value: **no** row fits the other and not this one, and at least
   `TELLS_APART` = 50 % of the rows fit this one and not the other.

Readings that give the same time for every value of the column are one reading (for whole numbers "hours" and
"hours.minutes" are the same; `sameAs` names the twin). Everything else is a question - a column that mixes
minutes:seconds for small puzzles with hours:minutes for a large one, 300 pieces in `8:10` (36 a minute or 0.6 a
minute - both possible), `8.10` against 8.1 hours (six minutes apart).

`zero_seconds_duration_cell` alone has a default: when the rows do not single out a reading, it stays **as stored**
unless there is a reason to doubt it - its pace does not fit, a duration cell of 24 h or more stands in the same
column (the same typing habit), or the column's other times have seconds and such cells are two or more and over
`MANY_ZERO_SECONDS` = 10 % of the column's times.

## Dates - `ImportDateReader`

`read(CellInput): ReadDate { certainty, ?date, precision, yearKnown, possibleOrders, problems, centuryAssumed }`

`date` is midnight UTC of the day - of the **first day** of the month or year when only that much is known. Today
comes from the injected clock.

| Input | Reading |
|---|---|
| a date cell | passes through: precision and `yearKnown` as handed over; a month cell is cut to its first day |
| `2023-12-30`, `2023/12/30`, `2023.12.30`, `20231230`, with a time after it (`…T10:00:00Z`, `… 14:05`) | certain - a four-digit year in front has one order |
| `30/12/2023`, `30.12.2023`, `30. 12. 2023`, `30-12-2023` | certain, `possibleOrders = [dmy]` (the 30 settles it) |
| `12/30/2023` | certain, `[mdy]` |
| `3/4/2023` | `order_open`: `[dmy, mdy]`, `date` null; `inOrder()` gives each reading |
| `30-12-23`, `03/04/05`, `23/12/30` | two-digit years: day-first, month-first **and year-first** are tried (`23/12/30` is how Japan writes it) |
| `1/3`, `1.3.` | a day and a month without a year (`yearKnown` false), orders as above. **Decided:** two numbers are never a month and a two-digit year |
| `12/2023`, `3.2024`, `2023-12` | a month |
| `2020` (text or number) | a year - only 1900-2100; any other bare number is unreadable (`1000` is a piece count, `45000` a spreadsheet's day number - never guessed) |
| `3 March 2024`, `March 3, 2024`, `3rd March 2024`, `Sunday, 3 March 2024`, `03-Mar-2024`, `3-Mar-24`, `3. března 2024`, `3. März 2024`, `3 de marzo de 2024`, `1er mars 2024`, `2024年3月3日(日)` | certain, `possibleOrders = []` - a month's name is no question of order |
| `Sept 2020`, `September 2020`, `září 2020`, `marzo de 2020`, `2024年3月` | a month |
| `Sep-20`, `Sep '20`, `Sep/20` | a month of a two-digit year - how a spreadsheet shows `mmm-yy`. **Decided** |
| `Sep 20`, `20-Sep`, `3 March`, `3. března`, `3月3日` | a day without a year. **Decided:** a month's name and a number after a space is a day (`Sep 20` = 20 September) unless the number is no day of that month |
| `March`, `3月` | a month without a year |
| empty | `no_date` |
| `garbage`, `31/02/2023`, `3/4/5`, `30/12-2023`, a time, a range, two dates, a date with words around it, `令和6年…` | `unreadable` |

Month names: English, Czech (the month's own name, the form after a day - `března` - and the short forms), German,
Spanish, French - full and short, any case, with or without accents (`ImportCalendarWords`); Japanese `年月日`.
Weekday names in front and the small words in between (`de`, `of`, `the`, `le`, `dne` …) are skipped.

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

### The order of a column - `DateOrderResolver`

`resolve(list<ReadDate>): DateOrderResolution { state, orders, suggested, rowsByOrder, exampleRows, rows }` over
every date of the column whose order is a question (`possibleOrders` not empty):

| State | When |
|---|---|
| `not_needed` | no such date |
| `resolved` | exactly one order makes every value a real date - or several do and read every value the same (`3/13` month-first and year-first) |
| `ambiguous` | several orders fit and read some value differently: one question, with rows the orders read differently |
| `mixed` | no order fits every value (`30/12/2023` next to `12/30/2023`): a problem, with the rows that do not follow the order most rows do |

Only a contradiction removes an order. A date in the future does not (people mistype years - the README's "2028
among 2024"); it only makes the order without such dates the `suggested` one. Consequence, on purpose: a column of
two-digit day-first dates (`30/12/23`, `15/01/24`) stays a question between day-first and year-first, with day-first
offered first; it is settled by the first four-digit year or by a first part that is no day.

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
| `problem` `not_whole` | `731.78` (an average), `1,5` |
| `problem` `zero` / `negative` / `too_many` | `0` · `-5` · `60001`, `500.000` |
| `problem` `unreadable` | `large`, `1e3`, `500-1000`, `70 x 50 cm`, a date / duration / yes-no cell |

**Decided:** in a pieces column a dot (or comma, space, apostrophe) before **exactly three digits** is a thousands
mark - `1.000` is 1,000 and `2.000` is 2,000, never one or two pieces; a piece count is whole. Decimals that are
all zero are dropped (`1000.0`, `500.00`), any other decimals are `not_whole`.

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
| marks | 1, ✓, ✔, ☑, ✅, ○ | 0, ☐, ✗, ✘, ✕, ✖, ❌, × |

The letter `x` is a tick (the README's "Got rid of: yes / x"); the signs `×` `✗` `❌` are a cross. Anything else is
`unknown` and comes back as typed (cut to 100 characters) - the layer above asks once per distinct word.

### A flag with a date - `FlagWithDateReader`

`read(CellInput): ReadFlagWithDate { flag: ReadYesNo, date: ReadDate, ?leftover }` for a column that feeds two
fields: `Y - 30/12/2023`, `Yes (Jan 2023)`, `y 2022`, `30/12/2023 - Y`. The date part goes through `ImportDateReader`
with all its rules - `Y - 3/4/2023` keeps its open order for `DateOrderResolver`. A flag alone has no date; a date
alone has no flag (**decided:** what a date without a flag means - "completed" - is the column's business); a known
flag with something that is no date keeps it as `leftover`; an unknown word with a date (`maybe 2022`) is a question
about the word.

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
("MySpeedPuzzling works this out": ppm, pieces per minute, diff, percentage, standing, place, rank, average …) and
`ready_made_field` (one of `StandardCustomField` - private notes, started on, the ratings, price, artist …).

The words are data: `ImportField::headerWords()` per language (en, cs, de, es, fr, ja) and, for the ready-made
fields, their own `StandardCustomField::headerWords()` plus a few more in `HeaderMatcher` (`comment` → private
notes: D11, `place of purchase` → bought from, `puzzle type` → condition).

`HeaderMatcher::guess(string $header): list<FieldGuess{field, readyMadeField, score}>`, the best first:

- a header is compared folded (`SearchText::fold()`), `%` and `#` as the words "percent" and "number", every run of
  anything but letters and digits one space - and also with the spaces taken out: `No. of Pieces`, `no_of_pieces`
  and `NoOfPieces` are one header. A part in brackets is a note, not the name: `Time (h:mm:ss)` is `Time`;
- **100** = the header is one of the field's words;
- **50-95** = the header holds such a word as a whole word among others (in Japanese: anywhere) - higher the larger
  the word's share of the header; a computed-looking word gets 20 more, so `Diff between previous best attempt` is
  "computed" before "attempt number";
- no guess for a blank header or one without a known word (`ID`, `Picture URL`).

Equal guesses come in the order of `ImportField`, the ready-made fields last: `stav` / `estado` / `状態` is the
status before the condition, `place` is "computed" before "where it is". The values decide between them - that is
the recogniser's second signal, not this class's.

## Keys - `ImportPuzzleKey`, `ImportSourceKey`

Both are the sha256 (64 hex characters) of a canonical text of lines joined by `\n`; a folded text never holds a
newline, so no part can run into the next. `ImportKeysTest` pins exact outputs - the keys are stored for ever.

`ImportPuzzleKey::of(name, ?pieces, ?resolvedBrandId, ?brandText)`:

```
puzzle-key:1
<the name through SearchText::fold() - a "(…)" part stays>
<the piece count, or nothing>
<"id:" + the catalogue brand's id in lower case - else "text:" + the brand's letters and digits>
```

`ImportBrandText::lettersAndDigits()` is the brand reduced to what two spellings share (`Master Pieces` =
`MasterPieces` = `master-pieces` = `masterpieces`).

`ImportSourceKey::of(kind, puzzleKey, ?date, precision, ?seconds, ?type, occurrence = 1)`:

```
source-key:1
<result | owned | both | wish_list>
<the puzzle_key>
<2023-12-30 | 2023-12 | 2023 - the date at its precision - or "no date">
<the seconds, or "no time">
<solo | duo | team, or "no type">
<the occurrence, from 1>
```

No attempt number, comment, people, codes or custom values. The occurrence counts rows of one sheet that are equal
in everything else (README "Never twice", step 3) - the caller numbers them.

## For the next stages

- The column recogniser (the rest of 4a) asks each reader how many of a column's values it reads; a bare year, a
  piece count and a rating all read as numbers - the header guess and the other columns decide.
- A decision of `DurationColumnResolver` / `DateOrderResolver` that is a question becomes one of "the questions
  only this layout needs"; the player's answer is applied with `ReadDuration::settledBy()` / `ReadDate::inOrder()`.
- `unusual` times (too fast, far from the player's own) are not this stage: they need the puzzle (stage 6b).
- New shapes people's files show are one more row in a reader's table and in its test.
