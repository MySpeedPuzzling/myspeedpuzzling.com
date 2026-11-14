# Data import - the spreadsheet reader (stage 3, reader)

`SpreadsheetReader` turns an **untrusted** uploaded `.xlsx` or `.csv`/`.tsv` file into sheets, regions and typed
cells, with constant memory. It is a library: no database, no routes, no messages, no screens. **Nothing calls it
yet** - the import's reading job (stage 3) will.

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

    $workbook->rows($sheet, $sheet->tables[1]->range);  // only the cells inside a range - one table of several
    $workbook->mergedRanges($sheet);                    // list<CellRange>, one pass that skips the rows
}

$workbook->rowsRead(); $workbook->cellsRead();          // consumed so far - what the next job takes off its budget
$workbook->close();                                     // lets go of the file, removes its temporary folder; never throws

$reader->pruneLeftovers($clock->now()->modify('-1 day'));   // folders of readers that never closed (a killed worker)
```

| Class | What |
|---|---|
| `Services\SpreadsheetReader\SpreadsheetReader` | the service: `open(path, format, limits)`, `pruneLeftovers(lastWrittenBefore)`. Its optional constructor argument is the base of the temporary folders (default: the system's) |
| `Services\SpreadsheetReader\ReadWorkbook` | an opened file: `sheets()`, `rows(sheet, ?window)`, `mergedRanges(sheet)`, `rowsRead()`, `cellsRead()`, `close()` |
| `Value\Spreadsheet\ReadSheet`, `ReadTable`, `FrozenPane`, `CellRange` | what is known before any row |
| `Value\Spreadsheet\ReadRow`, `ReadCell`, `CellKind`, `DatePrecision` | what a row holds |
| `Value\Spreadsheet\SpreadsheetFormat`, `SpreadsheetLimits` | what to read it as, and how big it may be |
| `Exceptions\SpreadsheetUnreadable` + `Value\Spreadsheet\SpreadsheetUnreadableReason` | what a file is or holds: the only exception for a bad file |
| `Exceptions\SpreadsheetReaderFailed` | what is the server's fault: a temporary folder that can not be made or written (a full disk). Never shown as "your file is damaged" |
| `Services\SpreadsheetReader\NumberFormatClassifier` | a number format code → date / time / duration / number |
| `Services\Export\SpreadsheetSafeValue::withoutFormulaGuard()` | takes off the `'` our own export puts in front of a text - **not** applied by the reader |

**Rows.** A row is handed out when it holds at least one value; its number is the spreadsheet's (gaps stay gaps, a
stray value in row 1,048,576 is row 1,048,576). A sheet can be streamed again - reading starts over, and counts again.
A generator that is abandoned half-way lets go of the part by itself.

**A window.** `rows($sheet, $range)` hands out only the cells inside the range - the column numbers stay the sheet's.
Rows above it are skipped by the XML parser alone, the first row below it ends the pass, cells left and right of it
are neither handed out nor counted. That is how a sheet of twenty tables side by side is read table by table; **how
many columns a mapped region may have (the 60 of Q8) is the next layer's rule**, the reader only refuses a sheet
wider than 1,000 columns.

**Budgets across jobs.** The caller reads one sheet per job. `SpreadsheetLimits::$maxRows` and `$maxCells` are what
is still allowed to be read; after a job `rowsRead()` / `cellsRead()` say what it used, and
`$limits->withConsumed($rows, $cells)` are the limits of the next job. Opening the same workbook again costs the
structure only ("Measurements": under a millisecond for a 24 MiB file of one sheet, 7 ms for a hundred sheets) - no
row and no shared string is read until a job asks.

**Errors.** What a file is or holds → `SpreadsheetUnreadable` with a reason. What is the server's → 
`SpreadsheetReaderFailed`. A bug → the error it is (nothing is caught wholesale; the one thing that is turned into
"damaged" is a PHP warning raised while a part or a record is read, when an error handler makes an exception of it).
`InvalidArgumentException`: a sheet of another workbook, or a closed workbook.

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
   anything inside it**. One `ZipArchive`; `xml(entry)` opens a part's stream, puts a byte meter on it and hands it to
   `XMLReader::fromStream()`. No `zip://` wrapper, no second archive, nothing unpacked to disk.
2. **The structure** (`XlsxStructure`), when the workbook is opened: the package's relationships say where the
   workbook part is, its relationships where the sheets, the styles and the shared strings are - **every part is
   found through a relationship, never by the name it "should" have**. Read: the sheets in tab order with their
   visibility, the date system, every cell style → its number format (classified once per format), and per worksheet
   its part up to the first row (`<dimension>`, the frozen `<pane>`) and its tables (`ref`, `headerRowCount`,
   `totalsRowCount`). A tab that is no worksheet (a chart sheet) is counted and never opened.
3. **Rows** (`SheetCellStream` + `XlsxWorkbook`), when they are asked for: one pass over the sheet's XML, node by
   node, up to `</sheetData>`. Per cell: the style index, the type, whether it holds a formula, and the saved value as
   stored - a number, a boolean, an error code, a shared string's number, or the text itself. The value is typed by
   the style's number format. One row in memory.
4. **Shared strings** (`SharedStrings` + `SharedStringsStore`), when the first cell asks for one - a sheet of numbers
   never pays for them: one pass, node by node (no DOM - a string of two million runs is two million steps), into a
   bounded store: a small table stays in memory (up to 5,000 strings and 1 MiB), anything more goes to one file with
   an index beside it in the reader's temporary folder, and a lookup reads one string.
5. **Merged ranges** (`mergedRanges()`), when they are asked for: one pass in which the XML parser skips
   `<sheetData>` by itself and stops after `<mergeCells>`.

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
  or a bare `\r`.
- **Row numbers** are the record numbers a spreadsheet shows: the `sep=` line is no row, a record with line breaks in
  a cell is one row, an empty line is counted but not handed out.
- **Columns:** every field of a record counts, empty ones too - a line of nothing but delimiters is as wide as it is
  long.
- UTF-16 files and files with bare `\r` line ends are first rewritten as UTF-8 with `\n` into the reader's temporary
  folder (through an iconv stream filter, in 64 KiB chunks, every write checked); a UTF-16 file is looked through
  **after** it became UTF-8, so its control characters are counted and dropped like everybody's. `close()` removes
  the copy.

## Safety

The file is untrusted. **No string, row, part or pass over it is without a cap**, and every cap is checked where the
bytes arrive - not after something was built.

| What | How | Where |
|---|---|---|
| The archive, before a byte is unpacked | file size; it is a zip (an old `.xls` and a password-protected workbook - both an OLE container - are told apart; `.xlsb`, `.ods`, `.numbers`, a `.docx` get their own reason); number of entries; no encrypted entry; no entry name that leaves the archive (`..`, absolute, a drive, a backslash) **or stands twice**; what it says it unpacks to, and the ratio to its packed size | `XlsxPackage::open()` |
| **What is checked is what is opened** | every part is read through `XlsxPackage::xml()` - whatever its name (`strings.bin`), wherever a relationship finds it (`/customXml/s.xml`), whoever asks. Nothing else opens the zip | `XlsxPackage` |
| **The archive lies about sizes** (libzip hands out whatever a compressed stream holds: an entry declared as 1,000 bytes streamed 51 MB) | a meter on every part's stream counts the bytes that really arrive: more than the entry declares = `damaged`, at the first chunk | `PartMeter`, `PartMeterFilter` |
| **One budget for all the XML of a workbook** | the same meter counts every pass over every part against `maxReadBytes` (256 MiB): a hundred sheets that point to one part cost a hundred times its size; rows and cells that hold nothing are not free; over it = `too_large`. This is the bound on the **time** a hostile workbook can cost - about 11 s of parsing at worst ("Measurements"), seconds for a file within the limits | `ReadBudget` |
| XML entities | a part with a DOCTYPE, an entity or an entity reference is refused (without a DOCTYPE no entity can exist); `LIBXML_NONET`, no entity substitution, no DTD loading | `XmlPart` |
| A part cut short or not well-formed | `damaged`, at the node where it breaks | `XmlPart` |
| Work per tab | every `<sheet>` element counts against `maxSheets` (a chart sheet too) **before** anything is done for it | `XlsxStructure` |
| Cell styles (≤ 70,000 - Excel allows 64,000), number formats (≤ 10,000), relationships looked at (≤ 100,000) and kept (≤ 5,000, only the kinds asked for), tables (≤ 2,000), names (a tab ≤ 255 bytes, a format code ≤ 1,024) | bounded where they are read | `XlsxStructure` |
| Attributes that should be numbers (`s`, `r` of a row, `numFmtId`, `headerRowCount`, a pane's split) | digits or `damaged` - never cast and guessed | `XmlPart::number()` |
| **A cell's value** | refused the moment it grows over `maxCellBytes` (131,072 = 32,767 characters of 4 bytes), while its text nodes arrive (the XML parser itself gives up on a text node of 10 MB) | `SheetCellStream` |
| **A row** | a value beyond column `maxColumns` is refused when its cell ends; the values of a row together over `maxRowBytes` (1 MiB) when the cell that tips it ends - also when the weight comes from shared strings a row's cells point to. A row is never built first and measured after | `SheetCellStream`, `XlsxWorkbook` |
| **Shared strings** | a string over `maxCellBytes`, more strings than `maxCells`, more bytes than the read budget: refused while they are read; kept on disk beyond 1 MiB / 5,000 strings | `SharedStrings`, `SharedStringsStore` |
| **A line of a text file** | longer than `maxRowBytes`: refused by the look-through, before anything is parsed (a 25 MB line of commas would be 26 million fields) | `CsvWorkbook::lookThrough()` |
| **A record of a text file** | longer than `maxRowBytes` (a quote that never closes: refused after 1 MiB, not after the rest of the file); more fields than `maxColumns` (empty ones count); a cell over `maxCellBytes` | `CsvWorkbook` |
| Text that is no text | NUL and other control characters counted over the whole file, in every encoding: more than 1 in 100 bytes = `not_a_spreadsheet`; the few that remain never reach a cell | `CsvWorkbook`, `ReadCell::withoutControls()` |
| Rows, cells, sheet width | `maxRowsPerSheet`, `maxRows`, `maxCells`, `maxColumns` | `XlsxWorkbook`, `CsvWorkbook` |
| Temporary files | only the shared strings of a large workbook and a rewritten UTF-16 / old-Mac text file; in `<base>/msp-spreadsheet-<16 hex>` (mode 0700), made when first needed, files under random names created exclusively (`x`), every write checked (a full disk fails loudly, never a shorter copy); removed by `close()` and the destructor, which never throw; `pruneLeftovers()` for what a killed worker left | `TemporaryFolder` |
| Formulas | never calculated | everywhere |
| Logs | the exception's message never holds a cell's content | `SpreadsheetUnreadable` |

### What happens to each hostile file

| The file | What it gets |
|---|---|
| A zip bomb (a part that unpacks to 1,000× its size, declared honestly) | `too_large` at `open()`, nothing unpacked |
| An entry that declares 1,000 bytes and holds 51 MB, under any name, reached through any relationship | `damaged` at the first chunk read from it |
| A tab typed as a chart sheet that points at such a part | listed nowhere, never opened |
| 100 sheets that all point to one large part | `too_large` when the passes over it together exceed `maxReadBytes` |
| 50,000 `<sheet>` elements | `too_many_sheets` at the 101st |
| A cell of 5 MB / 20 MB | `cell_too_large` / `damaged` (the XML parser's own 10 MB limit) while it arrives |
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
| `maxReadBytes` - XML really unpacked and parsed from one opened workbook, all passes | 256 MiB | `too_large` | while any part is read |
| `maxSheets` - tabs of any kind | 100 | `too_many_sheets` | `open()` |
| `maxRowsPerSheet` - rows of one `rows()` call | 50,000 | `too_many_rows` | while streaming |
| `maxRows` - rows still allowed from this opened file | 50,000 | `too_many_rows` | while streaming |
| `maxCells` - cells still allowed from this opened file; also the number of shared strings | 1,000,000 | `too_many_cells` | while streaming |
| `maxColumns` - the right-most column a value may stand in; fields of a CSV record | 1,000 | `too_many_columns` | while streaming |
| `maxCellBytes` - one cell's text, one shared string | 131,072 | `cell_too_large` | while streaming |
| `maxRowBytes` - a row's values together; a line and a record of a text file | 1 MiB | `cell_too_large` | while streaming; a text file's line at `open()` |
| `maxMergedRangesPerSheet` | 5,000 | none - the first 5,000 are handed out | |

Rows and cells count what was handed out (read twice = counted twice; outside a window = not counted). A sheet over a
limit is refused **while it is streamed** - the workbook's other sheets can still be read. An empty styled cell
beyond the last allowed column is not a value and refuses nothing.

| Reason | When |
|---|---|
| `too_large` | the file, the archive's entries, what it says it unpacks to, its ratio (a zip bomb), the XML read from it, too many cell styles or number formats |
| `too_many_sheets`, `too_many_rows`, `too_many_columns`, `too_many_cells` | the limits above |
| `cell_too_large` | a cell, a shared string, a row's values together, a line or a record of a text file hold more than a spreadsheet's can |
| `not_a_spreadsheet` | not a zip (for `.xlsx`), a zip without a workbook (a `.docx`), an empty file, binary data or a picture given as text |
| `damaged` | a truncated or broken zip, a missing file, two entries of one name, a part that is missing, not well-formed, cut short, longer than its header says, or holds a DOCTYPE; a sheet named twice; no sheet; attributes, rows or cells that make no sense; a shared string that is not there; a PHP warning over what the file holds |
| `password_protected` | an encrypted workbook (OLE container with an `EncryptedPackage`), an encrypted zip entry |
| `unsupported_format` | `.xls`, `.xlsb`, `.ods`, `.numbers`; a workbook given as CSV |

## Deliberately not done

- **No formula is calculated**, no external link followed, no macro looked at (`.xlsm` is read like `.xlsx`).
- **No typing of CSV values**, no header detection, no "is this row a puzzle" - the next layer.
- **No links** (see "Cell kinds"), **no names of a table's columns** (the header row's cells say them).
- **The leading `'` of our own export** is left on the text; `SpreadsheetSafeValue::withoutFormulaGuard()` takes it
  off. (A text a player typed as an apostrophe followed by `=` looks the same and would lose its apostrophe.)
- **`.xls`, `.xlsb`, `.ods`, `.numbers`** are not read ("save it as Excel or CSV").
- **Pictures** are never opened.
- **Sheet titles** are handed out as written in the workbook (no `_xHHHH_` decoding).
- **Number formats are not applied**: `shown` is a stable text (ISO-like dates, `h:mm:ss`), not Excel's rendering -
  no month names, no thousands separators, no currency.
- **A formula's text result is not trimmed** - what the spreadsheet saved is kept.

## Measurements

PHP 8.5.12 (aarch64, the project's base image in Docker on the development machine), 2026-11-14, the best of 4-5
runs each (the machine is shared; a single run under load took up to seven times as long). Peak memory is the growth
of `memory_get_peak_usage()` while a workbook is opened and its first sheet streamed, after a warm-up read.

| File | `open()` | Streaming the sheet | Peak memory |
|---|---|---|---|
| Generated, 2,000 rows × 10 columns (20,000 cells, 10 kinds of cells, a text of its own per row) | < 1 ms | 0.06 s | +0.2 MiB |
| Generated, 20,000 × 10 (200,000 cells; 20,050 shared strings, kept in the store's file) | < 1 ms | 0.5 s | +0.5 MiB |
| Generated, 50,000 × 20 (1,000,000 cells; 100,050 shared strings) - the most cells allowed | < 1 ms | 2.5 s | +0.5 MiB |
| **The largest file allowed: 24.1 MiB** - the million cells plus 22 MiB of pictures that nobody opens | **< 1 ms** | 2.6 s | +0.5 MiB |
| 100 sheets of 500 rows with a table and a frozen pane each | 7 ms | 4 ms (one sheet) | +0.3 MiB |

`open()` reads the archive's directory, the workbook part, its relationships, the styles, and of every worksheet the
part up to its first row, its relationships and its tables' root elements - nothing that grows with the rows. So a
job per sheet that opens the workbook again pays milliseconds for it; the shared strings are read by the job whose
sheet needs them (0.03 s for 20,000 strings, 0.13 s for 100,000 - inside the streaming times above).

The design's budget for the largest file was 3 minutes and 64 MB (README "Performance").

**The time a hostile file can cost** is bounded by `maxReadBytes`. Measured on sheets of nothing but empty cells, a
megabyte on disk each: 64 MB of `<c s="1"/>` are walked in 1.4 s, 64 MB of bare `<c/>` (the most nodes a byte can
buy) in 2.6 s - so the whole budget of 256 MiB is about 11 s, at the end of which the file is refused with
`too_large`.

**The four real files** (research.md; kept outside the repository - read locally, counts only, default limits, the
best of 3 runs; "everything" = every sheet streamed, its merged ranges, and every table once more through its window):

| File | Sheets | Rows / cells handed out | `open()` | Everything | Peak memory |
|---|---|---|---|---|---|
| File 1 | 10 (a frozen pane on each) | 4,486 / 34,986 - 4,916 dates (723 month-only, 1,313 without a shown year), 618 times of day, 356 durations, 2 errors, 1,348 formula cells | 6 ms | 0.40 s | +0.5 MiB |
| File 2 | 1 | 793 / 4,996 - every cell a formula | 2 ms | 0.06 s | +0.1 MiB |
| File 3 | 20 (2 hidden; 32 tables on 13 sheets, headers on rows 1-8; 12 frozen panes) | 5,209 / 30,214 - 3,603 durations, 4,910 booleans, 2,461 dates; 29,474 cells again through the 32 windows | 9 ms | 0.82 s | +0.5 MiB |
| File 4 (CSV) | 1 | 460 / 7,282 in 23 columns | 2 ms | 0.02 s | +1.4 MiB |

File 3's sheet of twenty tables side by side reaches column 93: the first version refused it at 60 columns, now it
is read whole, and table by table through windows.

## Tests

`tests/Services/SpreadsheetReader/` - every fixture is **generated in the test** (PhpSpreadsheet's writer for a
realistic workbook; `RawXlsx` writes a workbook part by part for the shapes a writer can not produce, and makes an
archive lie about a part's size). Every hostile file is small on disk. No real file is in the repository.

| Test | What |
|---|---|
| `NumberFormatClassifierTest` | the classification table, built-in formats |
| `XlsxReaderTest` | every cell kind, date precisions, durations against times, texts and their white space, formulas, tables, panes, hidden sheets, checkboxes, gaps and stray cells, merged ranges, a window on two tables side by side, the budget handed from one open to the next, leading zeros, rounding at midnight, a relationship prefix other than `r:`, a workbook whose parts have other names, 1904, edges of the calendar, time zones, a workbook written by PhpSpreadsheet |
| `XlsxSafetyTest` | one test per limit and reason; the hostile files of the table above; garbage attributes; `<dxfs>`; an unwritable temporary folder is not `damaged` |
| `CsvReaderTest` | delimiters, line ends, `sep=`, mark, encodings, quotes, empty lines, one column, limits, a line of five million delimiters, a quote that never closes, control characters in every encoding, a window, not text, an unwritable temporary folder |
| `OpenSpoutParityTest` | our rows and shared strings against OpenSpout's reader (the development dependency's only use) |
| `SharedStringsStoreTest`, `TemporaryFolderTest` | the store: memory, file, lookups, clean-up; the folder: lazy, 0700, exclusive names, `pruneLeftovers()` |
| `SpreadsheetReaderMemoryTest` | memory does not grow with the rows (20,000 × 10 against 2,000 × 10, under 4 MiB; a CSV of 40,000 rows under 6 MiB - the parser's view). Opt-in, not in CI: `SPREADSHEET_READER_LARGE=1 vendor/bin/phpunit --group spreadsheet-reader-large tests/Services/SpreadsheetReader` reads 50,000 × 20 and prints time and memory |

Every test checks afterwards that the reader left nothing in the temporary folder it was given.
