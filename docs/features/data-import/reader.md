# Data import - the spreadsheet reader (stage 3, reader)

`SpreadsheetReader` turns an **untrusted** uploaded `.xlsx` or `.csv`/`.tsv` file into sheets, regions and typed
cells, with constant memory. It is a library: no database, no routes, no messages, no screens. **Nothing calls it
yet** - the import's reading job (stage 3) will.

Design: [README.md](README.md) "Reading a file", "Large imports", "Security and privacy", decision D14. Where the
code differs from D14's wording: "[Rows are read by our own pass](#rows-are-read-by-our-own-pass-not-by-openspouts-row-iterator)".

## The API

```php
$workbook = $reader->open($path, SpreadsheetFormat::Xlsx, new SpreadsheetLimits());   // throws SpreadsheetUnreadable

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

    $workbook->mergedRanges($sheet);                    // list<CellRange>, one quick pass
}

$workbook->close();                                     // removes what reading left in the temporary folder
```

| Class | What |
|---|---|
| `Services\SpreadsheetReader\SpreadsheetReader` | the service; `open(path, format, limits)`. Its optional constructor argument is the temporary folder (default: the system's) |
| `Services\SpreadsheetReader\ReadWorkbook` | an opened file: `sheets()`, `rows(sheet)`, `mergedRanges(sheet)`, `close()` |
| `Value\Spreadsheet\ReadSheet`, `ReadTable`, `FrozenPane`, `CellRange` | what is known before any row |
| `Value\Spreadsheet\ReadRow`, `ReadCell`, `CellKind`, `DatePrecision` | what a row holds |
| `Value\Spreadsheet\SpreadsheetFormat`, `SpreadsheetLimits` | what to read it as, and how big it may be |
| `Exceptions\SpreadsheetUnreadable` + `Value\Spreadsheet\SpreadsheetUnreadableReason` | the only exception for a bad file |
| `Services\SpreadsheetReader\NumberFormatClassifier` | a number format code → date / time / duration / number |
| `Services\Export\SpreadsheetSafeValue::withoutFormulaGuard()` | takes off the `'` our own export puts in front of a text - **not** applied by the reader |

Rows: a row is handed out when it holds at least one value; its number is the spreadsheet's (gaps stay gaps, a stray
value in row 1,048,576 is row 1,048,576). A sheet can be streamed again - reading starts over. A generator that is
abandoned half-way lets go of the file by itself.

What the reader hands to the layer above ("Recognising columns and values") and does **not** decide itself: which
row is the header (the table's `headerRow`, the frozen pane and the used range are the hints), which rows are
puzzles, whether a row of only unticked checkboxes is empty (it is handed over as booleans), what a text like `0:56:4`
means.

## Cell kinds

An empty cell has no kind - it is simply not in the row. A text of length zero is empty; nothing else is trimmed.

| `kind` | `value` | `shown` | From |
|---|---|---|---|
| `text` | `string`, exactly as typed | the text | shared strings, text typed in place, rich text (plain), a formula's text result; every CSV cell |
| `number` | `int` or `float` (a whole number is an `int`: barcodes stay whole) | without float noise (`0.3`, 15 significant digits); a percentage format as `12.5%` | numeric cells with a General, number or text format; a "date" a spreadsheet shows as `#####` (negative, beyond the year 9999) |
| `date` | `DateTimeImmutable`, midnight UTC - always the full day the cell holds | by precision: `2024-03-05`, `2024-03`, `2024`; without a year `--03-05`, `--03`; with a time ` 13:30:00` appended | numeric cells with a date format; ISO dates of strict Open XML (`t="d"`) |
| `duration` | seconds (`int`, may be negative or over a day) | `25:30:00` | numeric cells with an elapsed-time format (`[h]:mm:ss`, `[mm]:ss`) |
| `time_of_day` | seconds since midnight (`int`) | `13:30:00`, `0:12:34` | numeric cells with a time-only format (`h:mm:ss`, `mm:ss`, `h:mm AM/PM`); a date format on a number below 1 |
| `boolean` | `bool` | `TRUE` / `FALSE` | checkboxes, `TRUE`/`FALSE` |
| `error` | the code, `#DIV/0!` | the code | error values, also a formula's saved error |

More on a cell:

- **`precision`** (dates): `day` / `month` / `year` - the smallest unit the cell's number format shows. `mmmm yyyy`
  on 5 March 2024 is `month`; the value is still 2024-03-05, the precision says how much of it the player ever saw.
- **`yearShown`** (dates): false when the format shows no year (`m/d`, `d.m.`, `d-mmm`). The year is in the value, the
  player never saw it - the layer above asks.
- **`timeSeconds`** (dates): the time of day when the format shows one next to the date (`m/d/yyyy h:mm`), else null.
- **`isFormula`**: the value is what the spreadsheet saved as the formula's result. **A formula is never calculated**
  (nothing here can calculate one). A formula without a saved value is an empty cell.
- **`hyperlink`**: the address a cell links to, when the sheet has one for it and it is not the cell's own text.
- `asText()`, `asNumber()`, `asDate()`, `asSeconds()`, `asBoolean()`: the value with its type, a `LogicException` for
  a cell of another kind.

Edge cases, decided here:

- A time-only format on a number of a day or more (a sum shown as `h:mm:ss`) is a `time_of_day` of the part after the
  day - what the player saw. A real duration over a day needs `[h]`.
- The 1900 date system has no day 0: a date or date-time format on a number below 1 is a `time_of_day`. Its phantom
  29 February 1900 is skipped the way spreadsheets do (serial 59 = 28 February, 61 = 1 March). The 1904 date system
  (`workbookPr date1904`) is honoured.
- A date never depends on the server's time zone (a spreadsheet has none).
- A text that starts with `=` is a text. A text cell stays text whatever its number format (`0:56:04` typed as text).
- In a text `_xHHHH_` is the character a spreadsheet could not write into XML (`_x000D_` = a carriage return,
  `_x005F_` = an underscore in front of such a sequence); control characters that are no text are dropped.
- Merged cells: the value sits in the range's first cell, the others are empty; `mergedRanges()` hands out the ranges.
- Rows hidden by a filter, hidden sheets (`hidden: true`, "very hidden" too): read like any other.
- A row number that repeats or goes backwards is passed over (the first one stands).

## Number formats

`NumberFormatClassifier::classify(code)` → `NumberFormat` (`kind`, `precision`, `yearShown`, `timeShown`,
`percent`). The rules:

1. Only the **first section** counts (`positive;negative;zero;text`).
2. Literals are taken out: `"quoted text"`, `\x`, `_x` (padding), `*x` (fill), and every `[…]` that is not elapsed
   time - colours `[Red]`, conditions `[>100]`, locale tags `[$-409]`, `[$Kč-405]`, `[DBNum1]`.
3. `[h]`, `[hh]`, `[m]`, `[mm]`, `[s]`, `[ss]` → **duration**.
4. `AM/PM`, `A/P` are taken out (they hold an `m`); `General` → general; the exponent of `0.00E+00` is not a year.
5. Tokens `y` / `e` (year), `m`, `d`, `h`, `s`: an `m` or `mm` **right after hours or right before seconds is
   minutes**, any other `m` - and every `mmm`, `mmmm` - a month.
6. Only time tokens → **time**. Any of year, month, day → **date**: precision `day` if it shows a day, else `month`,
   else `year`; `yearShown` if it shows a year; `timeShown` if it also shows a time.
7. No such token: `@` → text, anything else → number (`percent` when it holds `%`).

| Code | Kind | Precision | Year shown |
|---|---|---|---|
| `General`, empty, `;;;` | general | | |
| `0`, `#,##0.00`, `0.00E+00`, `# ?/?`, `[>100]0.0;0.00`, `"m"0`, `0.0" min"`, `#,##0.00\ [$Kč-405]` | number | | |
| `0%`, `0.00%` | number, percent | | |
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

The table above is `NumberFormatClassifierTest`'s data provider.

## How an .xlsx is read

1. **The archive is checked** (`XlsxPackage`) - "Safety" below.
2. **The structure is read** (`XlsxStructure`), a few small passes of our own with `XMLReader` straight out of the
   zip: `xl/workbook.xml` + its relationships (sheets in tab order, visibility, the date system; chart sheets are no
   sheets), `xl/styles.xml` (every cell style → its number format, classified once per format), per sheet its part up
   to the first row (`<dimension>`, the frozen `<pane>`) and its tables (`xl/tables/*.xml` through the sheet's
   relationships: `ref`, `headerRowCount`, `totalsRowCount`).
3. **OpenSpout opens the workbook** and reads the **shared strings** - every distinct text of every sheet - into
   `SharedStringsStore`, our own store behind OpenSpout's factory interface: a small table stays in memory (up to
   5,000 strings and 1 MiB), anything more goes to one file with an index beside it in the reader's temporary folder,
   and a lookup reads one string. OpenSpout's own stores are bounded by the *number* of strings, not their size.
4. **Rows** (`SheetCellStream` + `XlsxWorkbook`): one pass of our own over the sheet's XML, node by node. Per cell:
   the style index, the type, whether it holds a formula, and the saved value as stored - a number, a boolean, an
   error code, a shared string's index, or the text itself. The value is typed by the style's number format; a text is
   looked up in the store. One row in memory.
5. **After the rows** (`mergedRanges()`, and before streaming a sheet whose relationships name links): one pass in
   which libxml skips `<sheetData>` by itself, for `<mergeCells>` and `<hyperlinks>`.

### Rows are read by our own pass, not by OpenSpout's row iterator

D14 chose OpenSpout "with a small pass of our own for what a streaming reader does not give". That was built first,
exactly so: OpenSpout's row iterator for the values, our pass over the same sheet XML in step with it (meeting on the
row number) for the style of each cell. It worked - and was measured before it was replaced:

| 1 sheet, PHP 8.5.12 | OpenSpout's rows + our pass in step | Our pass + shared strings from the store |
|---|---|---|
| 20,000 rows × 10 columns | 2.0 s | 0.6 s |
| 50,000 rows × 20 columns (1,000,000 cells) | 8.9 s | 2.6 s |
| 20,000 × 10, the sheet says `<dimension ref="A1:IV20000"/>` (256 columns) | 3.0 s | 0.5 s |
| 20,000 × 10, the sheet says `<dimension ref="A1:XFD20000"/>` (16,384 columns) | **76 s** | 0.5 s |

OpenSpout sizes every row by the sheet's `<dimension>` (or the row's `spans`) and copies that row for every cell it
adds: **one attribute of an untrusted file decides what a cell costs** - a million cells of a sheet that calls itself
16,384 columns wide would take about six minutes. A stray formatted cell far to the right does the same to an honest
file. On top of that its iterator had to be corrected cell by cell anyway: it hands a date-styled number back as an
object built by its own rules in PHP's default time zone (the default style is never a date, a duration is one of
five exact codes), a formula's saved error as nothing, and a text that starts with `=` as a formula without a result.

So the rows are read by our pass alone, and OpenSpout does what a streaming reader is hard to replace for: opening
the workbook and extracting the shared strings. **`OpenSpoutParityTest` holds our rows against OpenSpout's row
iterator** on generated workbooks - the same rows, cells and values wherever OpenSpout has a value at all.

This is the one place the code departs from the letter of D14. If the owner prefers OpenSpout's iterator back, the
price is the table above plus a refusal of sheets that declare more columns than the limit.

## How a CSV/TSV file is read

`CsvWorkbook`: one sheet, title `''`, every cell a `text` exactly as written (typing is the next layer's job).

- **Never loaded whole:** one pass in 1 MiB chunks (is it text, is it valid UTF-8, does it end lines with a bare
  `\r`), then `fgetcsv` record by record.
- **Encoding** as `ParticipantFileReader` tells it (its byte tables are copied into `CsvEncoding`; that reader is
  untouched): a byte-order mark, UTF-16 without one, valid UTF-8, else Windows-1250 when the bytes look Central
  European, else Windows-1252. A UTF-8 mark in front of a quoted first header (`"ID",Name,…`) does not disturb.
- **Delimiter:** Excel's `sep=;` first line (also `sep=|`), else the one of `,` `;` tab that gives the first 20
  records the same number of fields, as long as half of them agree - never the most frequent character. A file of one
  column is read with a comma.
- **Quotes** as in RFC 4180: a cell may hold delimiters, doubled quotes and line breaks. Lines end with `\r\n`, `\n`
  or a bare `\r`.
- **Row numbers** are the record numbers a spreadsheet shows: the `sep=` line is no row, a record with line breaks in
  a cell is one row, an empty line is counted but not handed out.
- UTF-16 files and files with bare `\r` line ends are first rewritten as UTF-8 with `\n` into the temporary folder
  (through an iconv stream filter, in 64 KiB chunks); `close()` removes the copy.

## Safety

The file is untrusted. Before a byte is parsed, and while reading:

| Check | Where |
|---|---|
| File size | both formats |
| It is a zip; an old `.xls` and a password-protected workbook (both an OLE container) are told apart; `.xlsb`, `.ods`, `.numbers`, a `.docx`, any other zip get their own reason | `XlsxPackage` |
| Number of zip entries; no encrypted entry; no entry name that leaves the archive (`..`, absolute, a drive, a backslash) | `XlsxPackage` |
| What the archive says it unpacks to: all parts together, and the ratio to the packed size - of the archive and of any part over 1 MiB | `XlsxPackage` |
| **That the archive tells the truth:** libzip hands out whatever a compressed stream holds, whatever size its header declares (measured: a part declared as 100 bytes gave 2 MB). Every XML part of the workbook is unpacked once, counted, and refused when it is longer or shorter than declared (0.03 s for the million-cell file) | `XlsxPackage` |
| **No DOCTYPE in any workbook part** - without one no entity can be declared, so none can be expanded or fetched, by us or by OpenSpout. Every part is opened and read up to its root element | `XlsxPackage`, `XmlPart` |
| XML is read with `LIBXML_NONET`, never with entity substitution or DTD loading; a part that ends before its root element closes is damaged; a part with more nodes than its reader expects is too large | `XmlPart` |
| Cell styles (≤ 70,000 - Excel allows 64,000), number formats (≤ 10,000), relationships, tables (≤ 2,000): bounded before OpenSpout reads the same parts | `XlsxStructure` |
| Rows 1-1,048,576, columns A-XFD, cells of a row in order | `SheetCellStream` |
| Sheets, rows per sheet, rows in all, the right-most column with a value, cells in all | `XlsxStructure`, `XlsxWorkbook`, `CsvWorkbook` |
| Text: no NUL bytes, hardly any control characters; a workbook given as text is refused | `CsvWorkbook` |
| Nothing is unpacked to disk but the shared strings (and a rewritten UTF-16 CSV), into a folder of the reader's own (`<temporary folder>/msp-spreadsheet-<random>`, mode 0700), removed by `close()`, by the destructor and when opening fails. Pictures and other binary parts are never opened | `XlsxWorkbook`, `SharedStringsStore`, `CsvWorkbook` |
| No formula is ever calculated | everywhere |
| The exception's message never holds a cell's content (it goes to logs) | `SpreadsheetUnreadable` |

**Whatever a library throws is wrapped**: `SpreadsheetUnreadable` with a reason, the original as `previous`. Two
exceptions are the caller's own mistakes and stay what they are: `InvalidArgumentException` for a sheet of another
workbook or a closed workbook, and for a path with `#` in it (the zip stream wrapper can not address it).

### Limits and reasons

`SpreadsheetLimits` (defaults = the import's limits, README Q8):

| Limit | Default | Reason when reached |
|---|---|---|
| `maxFileBytes` | 25 MiB | `too_large` |
| `maxZipEntries` | 10,000 | `too_large` |
| `maxUncompressedBytes` | 512 MiB | `too_large` |
| `maxCompressionRatio` | 200 | `too_large` |
| `maxSheets` | 100 | `too_many_sheets` (at `open()`) |
| `maxRowsPerSheet` / `maxRows` (all sheets streamed from one opened workbook) | 50,000 / 50,000 | `too_many_rows` (while streaming) |
| `maxColumns` - the right-most column that may hold a value | 60 | `too_many_columns` (while streaming) |
| `maxCells` (all sheets) | 1,000,000 | `too_many_cells` (while streaming) |
| `maxHyperlinksPerSheet` | 10,000 | none - a sheet with more is read without its links |
| `maxMergedRangesPerSheet` | 5,000 | none - the first 5,000 are handed out |

Rows and cells count what holds a value. A sheet over a row, column or cell limit is refused **while it is
streamed** - the workbook's other sheets can still be read, so the layer above can say "this sheet is too wide" and
go on. An empty styled cell beyond the last allowed column is not a value and refuses nothing.

| Reason | When |
|---|---|
| `too_large` | the file, the archive's entries, what it unpacks to, its ratio (a zip bomb), too many cell styles or XML nodes |
| `too_many_sheets`, `too_many_rows`, `too_many_columns`, `too_many_cells` | the limits above |
| `not_a_spreadsheet` | not a zip (for `.xlsx`), a zip without a workbook (a `.docx`), an empty file, binary data or a picture given as text |
| `damaged` | a truncated or broken zip, a missing file, a part that is missing, not well-formed, cut short, longer than its header says, or holds a DOCTYPE; a sheet named twice; no sheet; rows or cells that make no sense; a shared string that is not there; anything a library threw |
| `password_protected` | an encrypted workbook (OLE container with an `EncryptedPackage`), an encrypted zip entry |
| `unsupported_format` | `.xls`, `.xlsb`, `.ods`, `.numbers`; a workbook given as CSV |

## Deliberately not done

- **No formula is calculated**, no external link followed, no macro looked at (`.xlsm` is read like `.xlsx`).
- **No typing of CSV values**, no header detection, no "is this row a puzzle" - the next layer.
- **The leading `'` of our own export** is left on the text; `SpreadsheetSafeValue::withoutFormulaGuard()` takes it
  off. (A text a player typed as an apostrophe followed by `=` looks the same and would lose its apostrophe.)
- **No names of a table's columns** - the header row's cells say them.
- **Links inside the workbook** (to another cell) are not handed out; only links to the outside.
- **`.xls`, `.xlsb`, `.ods`, `.numbers`** are not read ("save it as Excel or CSV").
- **Pictures** are never opened.
- **Sheet titles** are handed out as written in the workbook (no `_xHHHH_` decoding).
- **Number formats are not applied**: `shown` is a stable text (ISO-like dates, `h:mm:ss`), not Excel's rendering -
  no month names, no thousands separators, no currency.
- **A formula's text result is not trimmed** - OpenSpout's iterator trims it, we keep what was saved.

## Measurements

PHP 8.5.12 (aarch64, the project's base image in Docker on the development machine), 2026-11-14. Peak memory is the
growth of `memory_get_peak_usage()` while a sheet is opened and streamed, after a warm-up read (classes loaded).

| File | Time | Peak memory |
|---|---|---|
| Generated, 2,000 rows × 10 columns (20,000 cells, 10 kinds of cells, a text of its own per row) | 0.06 s | +0.3 MiB |
| Generated, 20,000 × 10 (200,000 cells) | 0.6 s | +0.6 MiB |
| Generated, 50,000 × 20 (1,000,000 cells, 4.7 MiB) - the largest allowed | 2.6 s (0.2 s to open: checks, structure, 100,050 shared strings to the store's file) | +0.6 MiB |

The design's budget for the largest file was 3 minutes and 64 MB (README "Performance").

The reader was also run over the players' real files - locally, outside the repository, counts only; nothing of
them is recorded here.

A sheet of many small tables side by side (README "What real spreadsheets look like" has one with 20) easily reaches
beyond column 60 of Q8: such a sheet is refused with `too_many_columns` while the workbook's other sheets are read.
Whether the limit moves or such a sheet is offered table by table is the next layer's call.

## Tests

`tests/Services/SpreadsheetReader/` - every fixture is **generated in the test** (PhpSpreadsheet's writer for a
realistic workbook; `RawXlsx` writes a workbook part by part for the shapes a writer can not produce: an error value,
a formula without a saved value, several tables, hostile parts). No real file is in the repository.

| Test | What |
|---|---|
| `NumberFormatClassifierTest` | the classification table, built-in formats |
| `XlsxReaderTest` | every cell kind, date precisions, durations against times, texts, formulas, tables, panes, hidden sheets, checkboxes, gaps and stray cells, merged ranges, links, 1904, edges of the calendar, time zones, a workbook written by PhpSpreadsheet |
| `XlsxSafetyTest` | one test per limit and reason: zip bomb, too many entries, a part longer than its header says, not a zip, truncated, other archives, `.xls`, encrypted, entity in each part, broken sheets, the temporary folder |
| `CsvReaderTest` | delimiters, line ends, `sep=`, mark, encodings, quotes, empty lines, one column, limits, not text |
| `OpenSpoutParityTest` | our rows against OpenSpout's row iterator |
| `SharedStringsStoreTest` | the store: memory, file, lookups, clean-up |
| `SpreadsheetReaderMemoryTest` | memory does not grow with the rows (20,000 × 10 against 2,000 × 10, under 4 MiB; a CSV of 40,000 rows). Opt-in, not in CI: `SPREADSHEET_READER_LARGE=1 vendor/bin/phpunit --group spreadsheet-reader-large tests/Services/SpreadsheetReader` reads 50,000 × 20 and prints time and memory |

Every test checks afterwards that the reader left nothing in the temporary folder it was given.
