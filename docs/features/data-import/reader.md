# Data import - the spreadsheet reader (stage 3, reader)

`SpreadsheetReader` turns an **untrusted** uploaded `.xlsx` or `.csv`/`.tsv` file into sheets, regions and typed
cells, with constant memory. It is a library: no database, no routes, no messages, no screens. Its caller is the
import's reading job - [reading.md](reading.md).

Design: [README.md](README.md) "Reading a file", "Large imports", "Security and privacy", decision D14. **No library
parses a file at runtime** - where and why that differs from D14's wording: "[No library at runtime](#no-library-at-runtime)".

## The API

```php
$workbook = $reader->open($path, SpreadsheetFormat::Xlsx, new SpreadsheetLimits());

foreach ($workbook->sheets() as $sheet) {              // no row is read for this
    $sheet->position; $sheet->title; $sheet->hidden;    // 1 = the first tab
    $sheet->tables;                                     // list<ReadTable>: name, range, headerRow, totalsRows
    $sheet->frozenPane;                                 // firstUnfrozenRow / firstUnfrozenColumn, or null
    $sheet->usedRange;                                  // what the file says about itself - a hint, or null

    foreach ($workbook->rows($sheet) as $row) {         // a generator: one row in memory
        $row->number;                                   // the row number a spreadsheet shows
        foreach ($row->cells as $column => $cell) {     // 1 = A; empty cells are not there
            $cell->kind; $cell->value; $cell->shown; $cell->isFormula;
        }
    }

    foreach ($workbook->rows($sheet, ...array_column($sheet->tables, 'range')) as $window => $row) {
        // only the cells inside the windows, all of them in ONE pass; $window = which one (0, 1, …)
    }

    $workbook->mergedRanges($sheet);                    // list<CellRange>, one pass that skips the rows
}

$workbook->rowsRead(); $workbook->cellsRead();          // consumed so far …
$workbook->bytesRead(); $workbook->secondsRead();       // … what the next job takes off its budgets (CPU seconds)
$workbook->close();                                     // lets go of the file, removes its temporary folder; never throws

$reader->pruneLeftovers($clock->now()->modify('-1 day'));   // folders of readers that never closed (a killed worker)
```

| Class | What |
|---|---|
| `Services\SpreadsheetReader\SpreadsheetReader` | the service: `open(path, format, limits)`, `pruneLeftovers(lastWrittenBefore)`. Its optional constructor argument is the base of the temporary folders (default: `msp-spreadsheet-reader` under the system's - a folder of the reader's own, so nothing else is ever pruned) |
| `Services\SpreadsheetReader\ReadWorkbook` | an opened file: `sheets()`, `rows(sheet, ...windows)`, `mergedRanges(sheet)`, `rowsRead()`, `cellsRead()`, `bytesRead()`, `secondsRead()`, `close()` |
| `Value\Spreadsheet\ReadSheet`, `ReadTable`, `FrozenPane`, `CellRange` | what is known before any row |
| `Value\Spreadsheet\ReadRow`, `ReadCell`, `CellKind`; `Value\DatePrecision` (the import's one precision enum) | what a row holds |
| `Value\Spreadsheet\SpreadsheetFormat`, `SpreadsheetLimits` | what to read it as, and how big it may be |
| `Exceptions\SpreadsheetUnreadable` + `Value\Spreadsheet\SpreadsheetUnreadableReason` | what a file is or holds: the only exception for a bad file |
| `Exceptions\SpreadsheetReaderFailed` | what is the server's fault: the uploaded file is not there or can not be opened, a temporary folder can not be made or written (a full disk). Never shown as "your file is damaged" |
| `Services\SpreadsheetReader\NumberFormatClassifier` | a number format code → date / time / duration / number |
| `Services\Export\SpreadsheetSafeValue::withoutFormulaGuard()` | takes off the `'` our own export puts in front of a text - **not** applied by the reader |

**Rows.** A row is handed out when it holds at least one value; its number is the spreadsheet's (gaps stay gaps, a
stray value in row 1,048,576 is row 1,048,576). A sheet can be streamed again - reading starts over, and counts again.
A generator that is abandoned half-way lets go of the part by itself; a workbook that is closed while a sheet is
streamed hands out no further row.

**Windows.** `rows($sheet, $range, $range, …)` hands out only the cells inside the ranges, **all of them in one pass
over the sheet**: for every row one `ReadRow` per window it has cells in, the generator's key being the window's index
(the column numbers stay the sheet's). Rows above the first window are skipped by the XML parser alone, the first row
below the last ends the pass, cells outside every window are neither handed out nor counted. That is how a sheet of
twenty tables side by side is read - in one pass, not twenty (each pass over a sheet costs its bytes against the
budget). **How many columns a mapped region may have (the 60 of Q8) is the next layer's rule**; the reader only
refuses a sheet wider than 1,000 columns.

**Budgets across jobs.** (The import's reading job reads a whole file in one job, each sheet in one pass -
[reading.md](reading.md); what follows is for a caller that splits.) Four limits are budgets **of the import, not of
one opened file**: `maxRows`, `maxCells`, `maxReadBytes`, `maxReadSeconds` (CPU time; the wall-clock ceiling
`maxWallSeconds` is per opened file). After a job, `rowsRead()`, `cellsRead()`,
`bytesRead()` and `secondsRead()` say what it used, and
`$limits->withConsumed($rows, $cells, $bytes, $seconds)` are the limits of the next job - so a hostile file costs its
budget once, not once per job. Opening the same workbook again costs the structure only ("Measurements": under a
millisecond for a 24 MiB file of one sheet, 8 ms for a hundred sheets); but **every job whose sheet has texts reads
the workbook's shared strings again**, and all of that counts: the defaults (512 MiB, 60 s) leave room for a hundred
jobs on an ordinary workbook, a caller with many sheets and a large string table reads several sheets per job.

**Errors.** What a file is or holds → `SpreadsheetUnreadable` with a reason. What is the server's →
`SpreadsheetReaderFailed`. A bug → the error it is (nothing is caught wholesale). Nothing depends on whether PHP
warnings are exceptions: every call of the XML parser is checked by its result, and while the reader works its
warnings are kept from the application's error handler ("Safety"). `InvalidArgumentException`: a sheet of another
workbook, or a closed workbook.

### What the job must still do

The reader bounds what a file can cost **inside PHP**. Two things are the caller's - the import's reading job:

1. **A hard timeout around a read, at the level of the process** (the Messenger worker's time limit, a
   `pcntl_alarm`, the container's): the reader's seconds are counted by the reader itself, in PHP - a defence against
   every shape we know and measured, not against a bug of the parser we do not know. A read that is still running long
   after its budget is killed from outside.
2. **Mark the import as "reading attempted" before the read starts**, in a transaction of its own. A job that was
   killed (the timeout above, memory, a crash of the parser) is then seen on the retry and the import is failed with
   "we could not read this file" - never read again blindly, time after time.

And: pass the budgets on (`withConsumed()`), call `pruneLeftovers()` when the worker starts and once a day, and keep a
**cap of its own on the text it stores** - a row may hold 1 MiB and there may be 50,000 of them; the reader bounds a
cell and a row, not what an import adds up to.

What the reader hands to the layer above ("Recognising columns and values") and does **not** decide itself: which
row is the header (the table's `headerRow`, the frozen pane and the used range are the hints), which rows are
puzzles, whether a row of only unticked checkboxes is empty (it is handed over as booleans), what a text like `0:56:4`
means.

## Cell kinds

An empty cell has no kind - it is simply not in the row. A text of length zero is empty.

| `kind` | `value` | `shown` | From |
|---|---|---|---|
| `text` | `string` | the text | shared strings, text typed in place, rich text (plain), a formula's text result; every CSV cell |
| `number` | `int` or `float` (a whole number is an `int`: barcodes stay whole) | without float noise (`0.3`, 15 significant digits); a percentage format as `12.5%`; a format of nothing but zeros with its leading zeros (`0045555123456`) | numeric cells with a General, number or text format; a "date" a spreadsheet shows as `#####` (negative, beyond the year 9999) |
| `date` | `DateTimeImmutable`, midnight UTC - always the full day the cell holds | by precision: `2024-03-05`, `2024-03`, `2024`; without a year `--03-05`, `--03`; with a time ` 13:30:00` appended | numeric cells with a date format; ISO dates of strict Open XML (`t="d"`) |
| `duration` | seconds (`int`, may be negative or over a day) | `25:30:00` | numeric cells with an elapsed-time format (`[h]:mm:ss`, `[mm]:ss`) |
| `time_of_day` | seconds since midnight (`int`) | `13:30:00`, `0:12:34` | numeric cells with a time-only format (`h:mm:ss`, `mm:ss`, `h:mm AM/PM`); a date format on a number below 1 |
| `boolean` | `bool` | `TRUE` / `FALSE` | checkboxes, `TRUE`/`FALSE` |
| `error` | the code, `#DIV/0!` | the code | error values, also a formula's saved error; a `t="d"` cell that holds no date (`#VALUE!`) |

More on a cell:

- **`precision`** (dates): `day` / `month` / `year` - the smallest unit the cell's number format shows. `mmmm yyyy`
  on 5 March 2024 is `month`; the value is still 2024-03-05, the precision says how much of it the player ever saw.
- **`yearShown`** (dates): false when the format shows no year (`m/d`, `d.m.`, `d-mmm`). The year is in the value, the
  player never saw it - the layer above asks.
- **`timeSeconds`** (dates): the time of day when the format shows one next to the date (`m/d/yyyy h:mm`), else null.
- **`paddedDigits`** (numbers): the digits a format of nothing but zeros pads to (`0000000000000` = 13), else null.
  Such a number is a **code** - a barcode typed as a number keeps its leading zeros in `shown`.
- **`isFormula`**: the value is what the spreadsheet saved as the formula's result. **A formula is never calculated**
  (nothing here can calculate one). A formula without a saved value is an empty cell.
- `asText()`, `asNumber()`, `asDate()`, `asSeconds()`, `asBoolean()`: the value with its type, a `LogicException` for
  a cell of another kind.

**Text - what is and is not changed:**

- **White space:** a text whose `<t>` element has `xml:space="preserve"` is kept to the character. Without it the
  white space around the text is the file's layout, not the player's, and goes - **as Excel shows it** (a writer that
  wants spaces kept must say `preserve`, and every spreadsheet does). The same rule for shared strings and for text
  typed in place; a formula's text result (`<v>`) and a CSV cell are kept as they are.
- **Control characters never reach a cell**, from a workbook or a text file in any encoding: the C0 controls except
  tab, line feed and carriage return are dropped (`ReadCell::withoutControls()` - a NUL can not even be stored). A
  text file that is mostly control characters is no spreadsheet.
- In a workbook `_xHHHH_` is the character a spreadsheet could not write into XML (`_x000D_` = a carriage return,
  `_x005F_` = an underscore in front of such a sequence).
- The reading aid of East Asian text (`<rPh>`) is not part of a text.
- A text that starts with `=` is a text. A text cell stays text whatever its number format (`0:56:04` typed as text).

**Numbers, dates, times - the edges:**

- A time-only format on a number of a day or more (a sum shown as `h:mm:ss`) is a `time_of_day` of the part after the
  day - what the player saw. A real duration over a day needs `[h]`.
- **Rounding:** a cell that shows a time is rounded to the second first and split into day and time after
  (`23:59:59.7` is midnight of the next day, as a spreadsheet shows it). A cell that shows **only its day is that
  day** - however close to midnight its number is.
- The 1900 date system has no day 0: a date or date-time format on a number below 1 is a `time_of_day`. Its phantom
  29 February 1900 is skipped the way spreadsheets do (serial 59 = 28 February, 61 = 1 March). The 1904 date system
  (`workbookPr date1904`) is honoured. A date never depends on the server's time zone (a spreadsheet has none).
- A `t="d"` cell holds an ISO date; anything else there - no date, 31 February, `T99:99` - is the error `#VALUE!`.
- Merged cells: the value sits in the range's first cell, the others are empty; `mergedRanges()` hands out the ranges.
- Rows hidden by a filter, hidden sheets (`hidden: true`, "very hidden" too): read like any other.
- A row number that repeats or goes backwards is passed over (the first one stands).
- **Links:** where a cell links to is **not read** (links stand after the rows - a second pass over the sheet for
  something optional). An address typed into a cell is its text. Whatever the next layer takes for a link is
  untrusted text like any other cell: a `javascript:` or `file:` address must never become a link on a page.

## Number formats

`NumberFormatClassifier::classify(code)` → `NumberFormat` (`kind`, `precision`, `yearShown`, `timeShown`,
`percent`, `zeroPadded`). The rules:

1. Only the **first section** counts (`positive;negative;zero;text`).
2. Literals are taken out: `"quoted text"`, `\x`, `_x` (padding), `*x` (fill), and every `[…]` that is not elapsed
   time - colours `[Red]`, conditions `[>100]`, locale tags `[$-409]`, `[$Kč-405]`, `[DBNum1]`.
3. `[h]`, `[hh]`, `[m]`, `[mm]`, `[s]`, `[ss]` → **duration**.
4. `AM/PM`, `A/P` are taken out (they hold an `m`); `General` → general; the exponent of `0.00E+00` is not a year.
5. Tokens `y` / `e` (year), `m`, `d`, `h`, `s`: an `m` or `mm` **right after hours or right before seconds is
   minutes**, any other `m` - and every `mmm`, `mmmm` - a month.
6. Only time tokens → **time**. Any of year, month, day → **date**: precision `day` if it shows a day, else `month`,
   else `year`; `yearShown` if it shows a year; `timeShown` if it also shows a time.
7. No such token: `@` → text, two or more zeros and nothing else → a number padded to that many digits, anything
   else → number (`percent` when it holds `%`).

| Code | Kind | Precision | Year shown |
|---|---|---|---|
| `General`, empty, `;;;` | general | | |
| `0`, `#,##0.00`, `0.00E+00`, `# ?/?`, `[>100]0.0;0.00`, `"m"0`, `0.0" min"`, `#,##0.00\ [$Kč-405]` | number | | |
| `0%`, `0.00%` | number, percent | | |
| `00000`, `0000000000000` | number, zero-padded to 5 / 13 digits | | |
| `@` | text | | |
| `m/d/yyyy`, `mm-dd-yy`, `d.m.yyyy`, `yyyy-mm-dd`, `[$-409]d-mmm-yy;@`, `[$-F800]dddd\,\ mmmm\ dd\,\ yyyy`, `yyyy"年"m"月"d"日"`, `[$-404]e/m/d` | date | day | yes |
| `m/d`, `d.m.`, `d-mmm`, `dddd` | date | day | **no** |
| `mmmm\ yyyy`, `mmm\ yyyy`, `mmm-yy`, `mm/yyyy` | date | month | yes |
| `mmmm` | date | month | no |
| `yyyy`, `yy` | date | year | yes |
| `m/d/yyyy h:mm`, `yyyy-mm-dd hh:mm:ss` | date with a time | day | yes |
| `h:mm`, `h:mm:ss`, `hh:mm:ss`, `mm:ss`, `mm:ss.0`, `h:mm AM/PM`, `[$-F400]h:mm:ss\ AM/PM`, `ss.00` | time | | |
| `[h]:mm:ss`, `[hh]:mm:ss`, `[h]:mm`, `[mm]:ss`, `[m]:ss`, `[s]` | duration | | |

Built-in format numbers (a cell style names a format by number; 0-49 need no code in the file): 14 is the reader's
"short date" - a full date with its year; 15 `d-mmm-yy`, 16 `d-mmm`, 17 `mmm-yy`, 18-21 times, 22 date and time,
45 `mm:ss`, 46 `[h]:mm:ss`, 47 `mm:ss.0`, 49 `@`. 27-36 and 50-58 mean different things in different East Asian
versions (a date in one, a month or a time in another): they are read as a full date, the reading that loses nothing -
and a "date" below 1 is a time of day anyway.

Only the workbook's own `<numFmts>` and its `<cellXfs>` count: the styles part is read up to `</cellXfs>` - the
formats of conditional formatting (`<dxfs>`) reuse the same numbers for other codes.

The table above is `NumberFormatClassifierTest`'s data provider.

## How an .xlsx is read

1. **The archive** (`XlsxPackage`): checked when it is opened - "Safety" - and from then on **the only door to
   anything inside it**. One `ZipArchive`; `xml(entry)` opens a part's stream, puts the meter on it (`PartMeter`,
   through a stream filter: bytes, clock, parser complaints, and `XmlFloodScanner` in front of the parser) and hands
   it to `XMLReader::fromStream()` as UTF-8. No `zip://` wrapper, no second archive, nothing unpacked to disk.
2. **The structure** (`XlsxStructure`), when the workbook is opened: the package's relationships say where the
   workbook part is, its relationships where the sheets, the styles and the shared strings are - **every part is
   found through a relationship, never by the name it "should" have**. Read: the sheets in tab order with their
   visibility, the date system, every cell style → its number format (classified once per format), and per worksheet
   its part up to the first row (`<dimension>`, the frozen `<pane>`) and its tables (`ref`, `headerRowCount`,
   `totalsRowCount`). A tab that is no worksheet (a chart sheet) is counted and never opened. The workbook part is
   read up to the end of its list of sheets, the styles up to `</cellXfs>`.
3. **Rows** (`SheetCellStream` + `XlsxWorkbook`), when they are asked for: one pass over the sheet's XML, node by
   node, up to `</sheetData>`. Per cell: the style index, the type, whether it holds a formula, and the saved value as
   stored - a number, a boolean, an error code, a shared string's number, or the text itself. The value is typed by
   the style's number format. One row in memory.
4. **Shared strings** (`SharedStrings` + `SharedStringsStore`), when the first cell asks for one - a sheet of numbers
   never pays for them: one pass, node by node (no DOM - a string of two million runs is two million steps), into a
   bounded store: a small table stays in memory (up to 5,000 strings and 1 MiB), anything more goes to one file with
   an index beside it in the reader's temporary folder, and a lookup reads one string.
5. **Merged ranges** (`mergedRanges()`), when they are asked for: one pass in which the XML parser skips
   `<sheetData>` by itself and which stops at the first element that stands after `<mergeCells>` in a sheet
   (conditional formats, links and extensions are never read).

Where the parser skips by itself (`XMLReader::next()`: the rows under `mergedRanges()`, the rows above a window) no
loop in PHP runs - but every 8 KiB chunk of what it skips still passes the meter on its way to the parser, with the
clock, the byte count and the scanner. That hook can not be forgotten in a loop, which is why the checks live there.

### No library at runtime

D14 chose OpenSpout "with a small pass of our own for what a streaming reader does not give". That was built first,
exactly so - OpenSpout's row iterator for the values, our pass in step with it for the styles - and measured:

| 1 sheet, PHP 8.5.12 | OpenSpout's rows + our pass in step | Our pass alone |
|---|---|---|
| 50,000 rows × 20 columns (1,000,000 cells) | 8.9 s | 2.6 s |
| 20,000 × 10, the sheet says `<dimension ref="A1:XFD20000"/>` (16,384 columns) | **76 s** | 0.5 s |

OpenSpout sizes every row by the sheet's `<dimension>` (or the row's `spans`): one attribute of an untrusted file
decides what a cell costs. The security review of the first version then found three more ways in, all through the
library: it opens parts by rules of its own (`/xl/` + target, whatever the relationship's type and the part's name),
so what we had checked was not what it opened; it builds each shared string as a DOM; and it does work per tab
before any cap. **Decision (2026-11-14): OpenSpout leaves the runtime entirely** and is a development dependency for
one thing - `OpenSpoutParityTest` holds our rows and shared strings against its reader on generated workbooks, the
same rows, cells and values wherever it has a value at all.

This is where the code departs from the letter of D14 (and of README "Reading a file").

## How a CSV/TSV file is read

`CsvWorkbook`: one sheet, title `''`, every cell a `text` exactly as written (typing is the next layer's job).

- **Never loaded whole:** one pass in chunks (is it text, is no line longer than a row may be, is it valid UTF-8,
  does it end lines with a bare `\r`), then `fgetcsv` record by record - and because the pass has seen that no line
  is longer than `maxRowBytes`, the parser is handed that as its line length, never an endless line. The parser does
  not read the file itself but a **view of it in memory that is moved along** (three times a row's size at most): a
  record runs over as many lines as a quote that never closes takes it, and the view is where that ends.
- **Encoding** as `ParticipantFileReader` tells it (its byte tables are copied into `CsvEncoding`; that reader is
  untouched): a byte-order mark, UTF-16 without one, valid UTF-8, else Windows-1250 when the bytes look Central
  European, else Windows-1252. A UTF-8 mark in front of a quoted first header (`"ID",Name,…`) does not disturb.
- **Delimiter:** Excel's `sep=;` first line (also `sep=|`), else the one of `,` `;` tab that gives the first 20
  records the same number of fields, as long as half of them agree - never the most frequent character. A file of one
  column is read with a comma.
- **Quotes** as in RFC 4180: a cell may hold delimiters, doubled quotes and line breaks. Lines end with `\r\n`, `\n`
  or a bare `\r` - which of them, the file's majority says: more bare `\r` than `\n` is an old Mac's file (one `\n`
  inside a cell does not make it a Unix file), anything else is read by its `\n` (a bare `\r` inside a cell stays in
  the cell).
- **Row numbers** are the record numbers a spreadsheet shows: the `sep=` line is no row, a record with line breaks in
  a cell is one row, an empty line is counted but not handed out.
- **Columns:** every field of a record counts, empty ones too - a line of nothing but delimiters is as wide as it is
  long.
- Bytes and seconds are counted like a workbook's (`bytesRead()`: the look-through and the parse; `maxReadBytes`,
  `maxReadSeconds`).
- UTF-16 files and files with bare `\r` line ends are first rewritten as UTF-8 with `\n` into the reader's temporary
  folder (through an iconv stream filter, in 64 KiB chunks, every write checked); a UTF-16 file is looked through
  **after** it became UTF-8, so its control characters are counted and dropped like everybody's. `close()` removes
  the copy.

## Safety

The file is untrusted. **No string, row, part or pass over it is without a cap, and neither is the time**: every cap
is checked where the bytes arrive - not after something was built, and before the XML parser gets them where the
parser itself would be slow.

| What | How | Where |
|---|---|---|
| The archive, before a byte is unpacked | file size; it is a zip (an old `.xls` and a password-protected workbook - both an OLE container - are told apart; `.xlsb`, `.ods`, `.numbers`, a `.docx` get their own reason); number of entries; no encrypted entry; **only stored and deflated entries** (bzip2, LZMA, XZ, Zstandard, deflate64: `unsupported_format` - no other decoder ever runs on an upload); no entry name that leaves the archive (`..`, absolute, a drive, a backslash) **or stands twice**; what it says it unpacks to, and the ratio to its packed size | `XlsxPackage::open()` |
| **What is checked is what is opened** | every part is read through `XlsxPackage::xml()` - whatever its name (`strings.bin`), wherever a relationship finds it (`/customXml/s.xml`), whoever asks. Nothing else opens the zip | `XlsxPackage` |
| **The archive lies about sizes** (libzip hands out whatever a compressed stream holds: an entry declared as 1,000 bytes streamed 51 MB) | a meter on every part's stream counts the bytes that really arrive: more than the entry declares = `damaged`, at the first chunk | `PartMeter`, `PartMeterFilter` |
| **One budget of bytes for all the XML of an import** | the same meter counts every pass over every part against `maxReadBytes` (512 MiB, carried from job to job): a hundred sheets that point to one part cost a hundred times its size; rows and cells that hold nothing are not free; over it = `too_large` | `ReadBudget` |
| **One budget of seconds** | the reader's own **CPU time** (the process's user + system time, `getrusage`; what the caller does between two rows is not counted) against `maxReadSeconds` (60 s, carried from job to job), and its wall-clock time against `maxWallSeconds` (300 s per opened file - the last resort), both asked on every 8 KiB chunk on its way to the parser - in every loop, and where the parser skips by itself; over either = `too_slow` | `CpuClock`, `ReadBudget`, `PartMeter` |
| **Shapes the XML parser is slow on** (libxml 2.9, which production runs: superlinear inside one call, where no check in PHP sees it) | `XmlFloodScanner` reads every chunk **before the parser does** and refuses, as `damaged`: a tag of more than 64 attributes - counted while the tag arrives, at its 65th value (a start tag of 300,000 attributes: 90 s unguarded); more than 64 namespace declarations in a part (150,000 in scope: 11 s for every megabyte after them); more than 2,000 distinct element and attribute names in a part (3.3 million: 117 s and 156 MB of the parser's own memory); more than 1,000 **distinct** runs of white space between two tags in a part (1.5 million distinct blank texts: stopped only by the clock before); a tag longer than 1 MiB; a comment longer than 64 KiB; **any processing instruction** but the XML declaration at the very start; a document type; a tag that is not one by the parser's own grammar. "The scanner" below | `XmlFloodScanner` |
| Encoding | a part starts with `<` as ASCII has it (after an optional UTF-8 byte-order mark) and is parsed as UTF-8 whatever it says - so the scanner reads what the parser reads; `encoding="UTF-8"` or `US-ASCII` may be declared; UTF-16, UTF-32, EBCDIC, any other declared encoding: `damaged` | `XmlFloodScanner`, `XmlPart` |
| The parser's complaints | kept in the parser's list instead of raised as a PHP warning each, counted on every chunk: more than 100 in a workbook (elements with a prefix nobody declared, by the hundred thousand) = `damaged`. Only the reader's own: what the application had waiting in that list is neither counted nor cleared | `ReadBudget`, `PartMeter` |
| **PHP warnings** | none is relied on and none escapes: every call of the parser is checked by its result (a compressed part that is corrupt = `damaged`), and while the reader works, warnings raised from inside the XML reader are taken by the reader's own handler - the same in production (warnings are logged) and in development (warnings are exceptions) | `ReadBudget`, `XmlPart` |
| XML entities | a part with a DOCTYPE, an entity or an entity reference is refused (without a DOCTYPE no entity can exist); `LIBXML_NONET`, no entity substitution, no DTD loading | `XmlPart` |
| A part cut short or not well-formed | `damaged`, at the node where it breaks | `XmlPart` |
| Work per tab | every `<sheet>` element counts against `maxSheets` (a chart sheet too) **before** anything is done for it | `XlsxStructure` |
| Cell styles (≤ 70,000 - Excel allows 64,000), number formats (≤ 10,000), relationships looked at (≤ 100,000) and kept (≤ 5,000, only the kinds asked for), tables (≤ 2,000), names (a tab ≤ 255 bytes, a format code ≤ 1,024) | bounded where they are read | `XlsxStructure` |
| Attributes that should be numbers (`s`, `r` of a row, `numFmtId`, `headerRowCount`, a pane's split) | digits or `damaged` - never cast and guessed | `XmlPart::number()` |
| **A cell's value** | by the scanner as the text arrives, before the parser has buffered it: more than 6 × `maxCellBytes` raw bytes between two tags (raw: a cell of 32,767 `&amp;` is 164 KiB as a workbook writes it and one cell) - so the parser's own 10 MB limit on a text is never reached; and decoded, where the cell is put together: refused the moment it grows over `maxCellBytes` (131,072 = 32,767 characters of 4 bytes; runs of rich text in a buffer of their own - no copying per run) | `XmlFloodScanner`, `SheetCellStream` |
| **A row** | a value beyond column `maxColumns` is refused when its cell ends; the values of a row together over `maxRowBytes` (1 MiB) when the cell that tips it ends - also when the weight comes from shared strings a row's cells point to. A row is never built first and measured after | `SheetCellStream`, `XlsxWorkbook` |
| **Shared strings** | a string over `maxCellBytes` (`cell_too_large`); more than `maxSharedStrings` (1,000,000) or `maxSharedStringBytes` (128 MiB) of them (`too_large`) - fixed caps, not what is left of the import's cell budget: every job that reads a sheet with texts reads them all; kept on disk beyond 1 MiB / 5,000 strings | `SharedStrings`, `SharedStringsStore` |
| **A line of a text file** | longer than `maxRowBytes`: refused by the look-through, before anything is parsed (a 25 MB line of commas would be 26 million fields) | `CsvWorkbook::lookThrough()` |
| **A record of a text file** | longer than `maxRowBytes` (a quote that never closes: refused after 1 MiB, not after the rest of the file); more fields than `maxColumns` (empty ones count); a cell over `maxCellBytes` | `CsvWorkbook` |
| Text that is no text | NUL and other control characters counted over the whole file, in every encoding: more than 1 in 100 bytes = `not_a_spreadsheet`; the few that remain never reach a cell | `CsvWorkbook`, `ReadCell::withoutControls()` |
| Rows, cells, sheet width | `maxRowsPerSheet`, `maxRows`, `maxCells`, `maxColumns` | `XlsxWorkbook`, `CsvWorkbook` |
| Temporary files | only the shared strings of a large workbook and a rewritten UTF-16 / old-Mac text file; in `<base>/msp-spreadsheet-<16 hex>` (mode 0700; the base, by default `msp-spreadsheet-reader` under the system's temporary folder, is the reader's own), made when first needed, files under random names created exclusively (`x`), every write checked (a full disk fails loudly, never a shorter copy); removed by `close()` and the destructor, which never throw; a folder in use is touched once a minute, so `pruneLeftovers()` - by last-written time - takes only what a killed worker left | `TemporaryFolder` |
| Formulas | never calculated | everywhere |
| Logs | the exception's message never holds a cell's content | `SpreadsheetUnreadable` |

### The scanner

`XmlFloodScanner` is a tokenizer that runs **strictly in sequence**: it stands in one of five states and enters a
construct only from the text state, at the `<` that really starts it. It never looks for a marker further on in the
buffer - so what it takes for a comment, a CDATA section or an attribute value is what the parser takes for one, and
nothing hides in between. (Its first version searched the buffer for `<!` and could be led past a flood by a
processing instruction: `<?a <!-- ?>…<?b --> ?>` is two instructions with real markup between them for the parser.)

| State | What it reads | Leaves it |
|---|---|---|
| declaration | the start of the part: an optional byte-order mark, then `<`; an XML declaration to its end (at most 1 KiB, nothing in it but `name="value"` pairs, UTF-8 or no encoding). Waits for the bytes it needs, however the stream is cut | → text |
| text | up to the next `<` | `<!--` → comment · `<![CDATA[` → CDATA · `</name>` and `<name attribute="value" …>` by the parser's grammar → text again · cut by the chunk's end → tag · `<?…`, `<!DOCTYPE`, anything else → refused |
| tag | a tag the chunk's end cuts short, carried: followed from quote to quote for its `>` (every byte once), its attribute values counted as they close | whole → text; a `<` in it, a 65th value, more than 1 MiB → refused |
| comment | up to `-->` (at most 64 KiB) | → text |
| CDATA | up to `]]>` (counts as text against the text cap) | → text |

| Cap | Value | Why this one |
|---|---|---|
| Attributes on a tag | 64 | what the parser is quadratic in; a workbook's widest tags have about 25 |
| Namespace declarations in a part | 64 | every one in scope is looked through for every element; real parts declare 5-15, on their root |
| Distinct element and attribute names in a part | 2,000 (not counting the ~110 a sheet is made of by the million, which are a fixed list) | the parser's dictionary; a workbook's whole vocabulary is a few hundred |
| Length of a tag | 1 MiB | not what the parser is slow on (a 9 MB tag of 60 attributes parses in no measurable time) - a `sqref` over thousands of ranges is one attribute of 100 KiB and more |
| Raw text between two tags | 6 × `maxCellBytes` (786 KiB) | the longest character reference is six bytes for one character |
| A comment | 64 KiB | no writer of workbooks writes comments at all |
| Processing instructions | none | a workbook's parts have none; a parser ends one at the first `?>` whatever stands in it |
| **Distinct** runs of white space between two tags in a part | 1,000 (runs of 1-64 bytes are told apart) | the parser keeps every distinct blank text of under 60 bytes in its dictionary, for good. See below |

A run of text, end tags and the start tags a sheet is made of (`<c>`, `<v>`, `<row>` … with the attributes they are
known to have, a prefix allowed) is consumed by one anchored regular expression; only where that stops is a tag looked
at by itself. `XmlFloodScannerTest` holds the scanner against the parser: for 3,000 generated documents that try to
hide markup (in attribute values of both quote kinds, comments, CDATA sections, processing instructions, behind
character references, with broken tags), fed in chunks of 8,192 bytes, of 1 to 300, and byte by byte - **whatever the
scanner lets through, the parser finds nothing in over the caps**, and the verdict does not depend on where the
stream is cut.

**Blank texts.** libxml interns a text node that is nothing but white space, shorter than 60 bytes and followed by a
tag - one dictionary entry per *distinct* text. A sheet of 1.5 million different 21-byte blank texts between its
cells therefore cost the parser's dictionary what millions of distinct names cost it, and nothing but the clock
stopped it. The measure is **distinct runs, not runs**: a pretty-printed sheet has a run before every element - a
50,000-row sheet of four columns has 450,000 of them - but only as many different ones as its nesting is deep (a line
end plus the indentation of that depth). Measured (2026-11-15), the most distinct runs in any part: every `RawXlsx`
fixture and the generated 50,000 × 20 workbook 0; a workbook written by PhpSpreadsheet 1; the generated sheet,
shared strings, styles and workbook part pretty-printed by libxml's own formatter (what `xmllint --format` writes) 6,
3, 4 and 3 - of 340,004 runs in the sheet. The cap of 1,000 is two orders of magnitude above that and costs the
parser nothing. Counted on the raw bytes of every chunk - a `>`, 1-64 bytes of space, tab, CR or LF, a `<` - whatever
the tokenizer's state (inside a comment or a CDATA section too): a superset of what the parser can take for a blank
text, so nothing is missed, and a run the stream cuts in two is put together. Count only: no byte is changed. The
attack shape is refused as `damaged` at its 1,001st distinct run (`XlsxTimeBoundTest`: a whole workbook opened and
refused in under 1 s, in the head of a sheet, among its cells, in the shared strings).

### What happens to each hostile file

| The file | What it gets |
|---|---|
| A zip bomb (a part that unpacks to 1,000× its size, declared honestly) | `too_large` at `open()`, nothing unpacked |
| An entry that declares 1,000 bytes and holds 51 MB, under any name, reached through any relationship | `damaged` at the first chunk read from it |
| A tab typed as a chart sheet that points at such a part | listed nowhere, never opened |
| 100 sheets that all point to one large part; one sheet read again by every job | `too_large` when the passes over it together exceed `maxReadBytes` - of the import, not of one job |
| A start tag of 300,000 attributes (2.6 MB) - on a cell, in a sheet's head, in the workbook part, the styles, the shared strings | `damaged` in 0.1 ms: refused at its 65th attribute, long before the tag is all there (unguarded: 90 s in one call) |
| The same tag, 150,000 namespace declarations or a document type between two processing instructions that seem to open and close a comment or a CDATA section (`<?a <!-- ?>…<?b --> ?>`) | `damaged` in 0.1 ms, at the first `<?` (hidden from a scanner that searches for markers: 15.5 s in one call) |
| Millions of distinct element or attribute names | `damaged` in 1 ms, at the 2,001st name (unguarded: 117 s for 3.3 million) |
| 1.5 million distinct blank texts of 21 bytes between tags | `damaged` at the 1,001st distinct run (before: `too_slow` after the whole budget) |
| 150,000 namespace declarations in scope, 600 or 60 on a tag | `damaged` in 0.1 ms: the tag's 65th attribute, or the part's 65th declaration (unguarded: 11 s per MiB after them) |
| 100,000 elements with a prefix nobody declared | `damaged` after the first hundred complaints of the parser; not one PHP warning |
| A sheet that parses slowly for a reason nobody thought of | `too_slow` when the import's CPU seconds are used up - also while the parser skips rows by itself |
| A compressed part that is corrupt | `damaged`, without a PHP warning, in production and development alike |
| A part packed with bzip2, LZMA, XZ, Zstandard | `unsupported_format` at `open()` |
| A part in UTF-16, UTF-32 or EBCDIC, or one that says `encoding="ISO-8859-1"` | `damaged` |
| Elements nested deeper than the parser goes (256) | `damaged` |
| A date in a workbook that counts from 1904 beyond the year 9999 (serial 2,957,004 and up) | a number cell - no date, no error |
| 50,000 `<sheet>` elements | `too_many_sheets` at the 101st |
| A cell of 5 MB / 20 MB | `cell_too_large` after its first 786 KiB of raw text, before the parser has buffered it |
| A cell typed in place of 120,000 runs of text | read in one straight pass (0.1 s) |
| A row of 20 cells of 100 KB each; a row of cells that all point to one 100 KB shared string | `cell_too_large` when the row passes 1 MiB |
| A shared string of 2,000,000 runs | read in one straight pass; `cell_too_large` if its text passes 131,072 bytes |
| A sheet that says it is 16,384 columns wide; a million empty rows | no effect on the cost of a cell; the bytes count against `maxReadBytes` |
| A part with a DOCTYPE and an external entity | `damaged`; the entity is never resolved |
| Two zip entries of one name | `damaged` at `open()` |
| A CSV line of 5,000,000 delimiters | `cell_too_large` at `open()`, before a field is made |
| A CSV record of 2,000 empty fields | `too_many_columns` |
| A CSV quote that never closes, 25 MB of text behind it | `cell_too_large` after one row's worth of it (measured: 0.06 s, +6 MiB) |
| UTF-16 text with U+0000 in it | the NULs are dropped from the cells; `not_a_spreadsheet` if the file is mostly such characters |

### Limits and reasons

`SpreadsheetLimits` (defaults = the import's limits of README Q8, plus the caps above):

| Limit | Default | Reason when reached | When |
|---|---|---|---|
| `maxFileBytes` | 25 MiB | `too_large` | `open()` |
| `maxZipEntries` | 10,000 | `too_large` | `open()` |
| `maxUncompressedBytes` - what the archive says it unpacks to, pictures included | 512 MiB | `too_large` | `open()` |
| `maxCompressionRatio` - of the archive, and of any part over 1 MiB | 200 | `too_large` | `open()` |
| `maxReadBytes` - **budget of the import**: bytes still allowed to be unpacked and parsed (a text file: read), all passes | 512 MiB | `too_large` | while any part is read |
| `maxReadSeconds` - **budget of the import**: seconds of **CPU time** the reader may still use | 60 s | `too_slow` | on every chunk |
| `maxWallSeconds` - wall-clock seconds of the reader's own time, per opened file: the last resort | 300 s | `too_slow` | on every chunk |
| `maxSheets` - tabs of any kind | 100 | `too_many_sheets` | `open()` |
| `maxRowsPerSheet` - rows of one `rows()` call | 50,000 | `too_many_rows` | while streaming |
| `maxRows` - **budget of the import**: rows still allowed | 50,000 | `too_many_rows` | while streaming |
| `maxCells` - **budget of the import**: cells still allowed | 1,000,000 | `too_many_cells` | while streaming |
| `maxSharedStrings`, `maxSharedStringBytes` - the distinct texts of a workbook; not budgets | 1,000,000 / 128 MiB | `too_large` | when a sheet's first text is read |
| `maxColumns` - the right-most column a value may stand in; fields of a CSV record | 1,000 | `too_many_columns` | while streaming |
| `maxCellBytes` - one cell's text, one shared string | 131,072 | `cell_too_large` | while streaming |
| `maxRowBytes` - a row's values together; a line and a record of a text file | 1 MiB | `cell_too_large` | while streaming; a text file's line at `open()` |
| `maxMergedRangesPerSheet` | 5,000 | none - the first 5,000 are handed out | |

Rows and cells count what was handed out (read twice = counted twice; outside a window = not counted). A sheet over a
limit is refused **while it is streamed** - the workbook's other sheets can still be read. An empty styled cell
beyond the last allowed column is not a value and refuses nothing.

| Reason | When |
|---|---|
| `too_large` | the file, the archive's entries, what it says it unpacks to, its ratio (a zip bomb), the bytes read from it over all jobs, too many cell styles, number formats or distinct texts |
| `too_slow` | the reader's CPU seconds on the file, over all jobs, are used up - or its wall-clock ceiling. Never the file's fault alone: the player is told "please try again" |
| `too_many_sheets`, `too_many_rows`, `too_many_columns`, `too_many_cells` | the limits above |
| `cell_too_large` | a cell, a shared string, a row's values together, a line or a record of a text file hold more than a spreadsheet's can |
| `not_a_spreadsheet` | not a zip (for `.xlsx`), a zip without a workbook (a `.docx`), an empty file, binary data or a picture given as text |
| `damaged` | a truncated or broken zip, two entries of one name, a part that is missing, can not be unpacked, is not well-formed, cut short, longer than its header says, not UTF-8, holds a DOCTYPE or one of the shapes the parser is slow on; a sheet named twice; no sheet; attributes, rows or cells that make no sense; a shared string that is not there |
| `password_protected` | an encrypted workbook (OLE container with an `EncryptedPackage`), an encrypted zip entry |
| `unsupported_format` | `.xls`, `.xlsb`, `.ods`, `.numbers`; a part packed by a method other than deflate; a workbook given as CSV |

A file that is **not there** or can not be opened is no reason of the file's: `SpreadsheetReaderFailed`.

**Why 60 s of CPU and 300 s on the wall** (changed in stage 3b; the first version counted wall-clock seconds). A
file at every limit at once (a million cells, 100,000 texts) is 2.4 s of reading and 31 MiB here. Wall-clock seconds
are seconds of a server that may be busy: the same read took 18-41 s on a host at load 11, so a wall-clock budget
either refuses honest files on a bad day or is so wide that it bounds nothing. **CPU time of the process** (user +
system, `getrusage` - `CpuClock`) is what the reader itself burns, the same on a quiet and on a loaded host: 60 s is
twenty-five times the measured need and still a hard bound on what a hostile file can make the parser compute. Time
that is no CPU time - a disk that does not answer, a host so loaded that the process hardly runs - must end too:
`maxWallSeconds` (300 s of the reader's own time per opened file) is that last resort, generous on purpose. Both end
in `too_slow`, which the import's page words as "please try again", never as a broken file.

`getrusage` counts the **whole process**: in a process of several threads (FrankenPHP's workers) the other threads'
CPU time would be counted too and a read could be refused early - never late. The reader runs in the Messenger
consumer, a process of one thread; do not read files in a web request. And these are budgets counted **in PHP**: the
job still wraps a read in PHP's own time limit (`set_time_limit`, 420 s - [reading.md](reading.md)).

## Deliberately not done

- **No formula is calculated**, no external link followed, no macro looked at (`.xlsm` is read like `.xlsx`).
- **No typing of CSV values**, no header detection, no "is this row a puzzle" - the next layer.
- **No links** (see "Cell kinds"), **no names of a table's columns** (the header row's cells say them).
- **The leading `'` of our own export** is left on the text; `SpreadsheetSafeValue::withoutFormulaGuard()` takes it
  off. (A text a player typed as an apostrophe followed by `=` looks the same and would lose its apostrophe.)
- **`.xls`, `.xlsb`, `.ods`, `.numbers`** are not read ("save it as Excel or CSV").
- **Workbook parts in UTF-16** (or any encoding but UTF-8) are not read - no spreadsheet writes them; a text file in
  UTF-16 is.
- **Workbook parts with a processing instruction** (other than the XML declaration), a document type or a comment of
  more than 64 KiB are not read - no spreadsheet writes them either.
- **Parts packed by another method than deflate** are not unpacked.
- **Pictures** are never opened.
- **Sheet titles** are handed out as written in the workbook (no `_xHHHH_` decoding).
- **Number formats are not applied**: `shown` is a stable text (ISO-like dates, `h:mm:ss`), not Excel's rendering -
  no month names, no thousands separators, no currency.
- **A formula's text result is not trimmed** - what the spreadsheet saved is kept.

## Measurements

PHP 8.5.12, libxml 2.9.14 (aarch64, the project's base image in Docker on the development machine; production runs
the same libxml), 2026-11-14, the best of 3-5 runs each (the machine is shared; a single run under load took up to
seven times as long). Peak memory is the growth of `memory_get_peak_usage()` while a workbook is opened and its first
sheet streamed, after a warm-up read.

| File | `open()` | Streaming the sheet | XML read | Peak memory |
|---|---|---|---|---|
| Generated, 2,000 rows × 10 columns (20,000 cells, 10 kinds of cells, a text of its own per row) | < 1 ms | 0.05 s | 0.6 MiB | +0.3 MiB |
| Generated, 20,000 × 10 (200,000 cells; 20,050 shared strings, kept in the store's file) | < 1 ms | 0.48 s | 6.4 MiB | +0.5 MiB |
| Generated, 50,000 × 20 (1,000,000 cells; 100,050 shared strings) - the most cells allowed | < 1 ms | 2.3 s | 31.4 MiB | +0.5 MiB |
| **The largest file allowed: 24.1 MiB** - the million cells plus 22 MiB of pictures that nobody opens | **< 1 ms** | 2.3 s | 31.4 MiB | +0.5 MiB |
| 100 sheets of 500 rows with a table and a frozen pane each | 8 ms | 4 ms (one sheet) | 0.9 MiB | +0.3 MiB |

`open()` reads the archive's directory, the workbook part up to its sheets, its relationships, the styles, and of
every worksheet the part up to its first row, its relationships and its tables' root elements - nothing that grows
with the rows. So a job per sheet that opens the workbook again pays milliseconds for it; the shared strings are read
by the job whose sheet needs them (0.03 s for 20,000 strings, 0.13 s for 100,000 - inside the streaming times above).

The design's budget for the largest file was 3 minutes and 64 MB (README "Performance").

**What the scanner costs.** Reading is 73 ms for a MiB of sheet XML in all (2.3 s for 31.4 MiB). Of that:

| | per MiB of XML |
|---|---|
| The XML parser's bare loop over a sheet (no scanner, nothing done with the nodes) | 12.2 ms |
| `XmlFloodScanner` on a sheet | 1.0 ms |
| `XmlFloodScanner` on shared strings | 0.8 ms |
| `XmlFloodScanner` on a sheet written with a prefix on every tag | 0.9 ms |
| `XmlFloodScanner` on a sheet whose cells are tags it does not know by heart (`<cell>`, `<value>`: each looked at by itself) | 26.7 ms |

So the scanner is 0.03 s of the 2.3 s a million cells take - a little over 1 % (the sequential tokenizer is four
times as fast as the first version, which searched the buffer) - and a third more on a part made of tags outside a
workbook's vocabulary, which no workbook is.

**The time a hostile file can cost** is bounded three times over: the scanner refuses the shapes the parser is slow
on within a millisecond (measured, a whole workbook opened and refused: 0.1 ms for a start tag of 300,000
attributes, in the open or behind `<?a <!-- ?>` or `<?a <![CDATA[ ?>`; 1.2 ms for 3.3 million distinct names; 0.1 ms
for 150,000 and for 15,000 namespace declarations and for a hidden document type - `XlsxTimeBoundTest` asserts under
5 s each; a legitimate tag of 900 KiB with 60 attributes is read in 8 ms); what passes the scanner is read in a
time that grows with its bytes - 64 MB of `<c s="1"/>` in 1.4 s, 64 MB of bare `<c/>` (the most nodes a byte can buy)
in 2.6 s - and those are a budget (512 MiB: about 20 s at worst); and the reader's seconds are a budget themselves
(60 s). Each over all jobs of an import.

**The four real files** (research.md; kept outside the repository - read locally, counts only, default limits, the
best of 3 runs; "everything" = every sheet streamed, its merged ranges, and all its tables once more as windows of one
pass):

| File | Sheets | Rows / cells of the sheets | `open()` | Everything | XML read | Peak memory |
|---|---|---|---|---|---|---|
| File 1 | 10 | 4,486 / 34,986 - 4,916 dates, 618 times of day, 356 durations, 2 errors, 1,348 formula cells | 2 ms | 0.21 s | 4.8 MiB | +0.5 MiB |
| File 2 | 1 | 793 / 4,996 - every cell a formula | < 1 ms | 0.02 s | 1.6 MiB | +0.1 MiB |
| File 3 | 20 (2 hidden; 32 tables on 13 sheets) | 5,209 / 30,214 - 3,603 durations, 4,910 booleans, 2,461 dates; 29,474 cells again through the 32 windows | 4 ms | 0.32 s | 11.0 MiB | +0.5 MiB |
| File 4 (CSV) | 1 | 460 / 7,282 in 23 columns | 1 ms | 0.01 s | 0.4 MiB | +1.4 MiB |

All four pass the scanner as they are. File 3's sheet of twenty tables side by side reaches column 93: the first
version refused it at 60 columns, now it is read whole, and its tables as twenty windows of one pass.

## Tests

`tests/Services/SpreadsheetReader/` - every fixture is **generated in the test** (PhpSpreadsheet's writer for a
realistic workbook; `RawXlsx` writes a workbook part by part for the shapes a writer can not produce, and makes an
archive lie about a part's size or packing method and breaks a part's packed bytes). Every hostile file is small on
disk. No real file is in the repository.

| Test | What |
|---|---|
| `NumberFormatClassifierTest` | the classification table, built-in formats |
| `XlsxReaderTest` | every cell kind, date precisions, durations against times, texts and their white space, formulas, tables, panes, hidden sheets, checkboxes, gaps and stray cells, merged ranges, a window on two tables side by side, the budget handed from one open to the next, leading zeros, rounding at midnight, a relationship prefix other than `r:`, a workbook whose parts have other names, 1904, edges of the calendar, time zones, a workbook written by PhpSpreadsheet |
| `XmlFloodScannerTest` | the scanner against the parser: 3,000 generated documents in chunks of every size (what passes holds nothing over the caps for the parser; the verdict does not depend on the cut), 31 hiding places that are refused, 11 honest documents that pass |
| `ReadBudgetTest` | the seconds are CPU time: waiting costs nothing of the budget, work does; the wall clock as the last resort |
| `XlsxTimeBoundTest` | 3,000 distinct blank texts (three times the cap, streamed into the part) refused in under a second, in three parts; a pretty-printed sheet of 50,000 rows read; a blank run cut by the stream; the shapes the parser is slow on (300,000 attributes on a tag, in five places; hundreds of thousands of distinct names; thousands of namespace declarations; undeclared prefixes; a flood, namespaces and a document type hidden behind processing instructions, in three parts) - the typed refusal **and** under 5 s each; what the scanner must let through (comments and CDATA with markup in them, made-up elements cut by every chunk edge; a `sqref` of 100 KiB; a cell of 32,767 ampersands); nesting deeper than the parser goes; the application's own parser errors left alone; parts that are not UTF-8; the budget of seconds - while rows are streamed, while the parser skips by itself, not the caller's time; bytes and seconds carried over three jobs; a corrupt packed part in each of four parts, without a warning, with warnings as exceptions; other packing methods; a cell of 120,000 runs; every extreme number in both date systems; several windows in one pass; `close()` while a sheet is streamed |
| `XlsxSafetyTest` | one test per limit and reason; the hostile files of the table above; garbage attributes; `<dxfs>`; an unwritable temporary folder is not `damaged` |
| `CsvReaderTest` | delimiters, line ends, `sep=`, mark, encodings, quotes, empty lines, one column, limits, a line of five million delimiters, a quote that never closes, control characters in every encoding, windows, an old Mac's file with a `\n` in a cell and a Windows file with a `\r` in one, bytes and seconds as budgets, `close()` while streaming, not text, a missing file and an unwritable temporary folder as the server's faults |
| `OpenSpoutParityTest` | our rows and shared strings against OpenSpout's reader (the development dependency's only use) |
| `SharedStringsStoreTest`, `TemporaryFolderTest` | the store: memory, file, lookups, clean-up; the folder: lazy, 0700, exclusive names, kept alive while in use, `pruneLeftovers()`, the default base |
| `SpreadsheetReaderMemoryTest` | memory does not grow with the rows (20,000 × 10 against 2,000 × 10, under 4 MiB; a CSV of 40,000 rows under 6 MiB - the parser's view). Opt-in, not in CI: `SPREADSHEET_READER_LARGE=1 vendor/bin/phpunit --group spreadsheet-reader-large tests/Services/SpreadsheetReader` reads 50,000 × 20 and prints time and memory |

Every test checks afterwards that the reader left nothing in the temporary folder it was given.
