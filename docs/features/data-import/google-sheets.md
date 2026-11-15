# Data import: bring a Google Sheets link (stage 3c)

What stage 3c built of [the design](README.md): a signed-in player pastes the link of a Google sheet shared with
"Anyone with the link" on "Bring the file"; the import is made at once, the `imports` worker fetches the sheet as an
`.xlsx` file, stores it like an upload ([upload.md](upload.md)) and asks for the reading ([reading.md](reading.md)).
From there on nothing differs from an uploaded file. No Google account, no key, no OAuth; private sheets are not
read.

## The page

"Bring the file" (`data_import_new`) has **the link first**, the file picker below it - two forms of one page, both
posted to the page itself:

- the link's form is a plain form (`google_link`, the page's CSRF token, no script): POST, then the import's page
  (PRG); a link that is not taken re-renders the page with 422 and the reason **under the link's field**, which keeps
  what was typed;
- the form carries a hidden `import_id` (a fresh UUIDv7 per rendering): the same form sent twice - a double click -
  answers the first import and costs one request of the limit; an id that is not the player's own import of that
  sheet is not used;
- the hint under the field says how to share the sheet: "Share → Anyone with the link → Viewer" (6 locales; the same
  hint stands next to the "not shared" failure).

Limits: the rate limiter `data_import_google` - **10 an hour per player** (README "Security and privacy"), counted
for every link that is taken **and for every "Try again"** of a failed fetch (each is a request of ours to Google);
a link that is no sheet's costs nothing - and the 3 imports holding a place (`DataImportStatus::holdingAPlace()`,
under the player's lock, like an upload's start). An import that is being fetched, or whose fetch failed, holds its
place until it is read or discarded.

## The link

`Value\GoogleSheetLink::parse()` cuts **the document's id** out of what was pasted
(`^[A-Za-z0-9_-]{20,100}$`) - and nothing else of the link is ever used or stored: the address asked for is built by
us from the id (`GoogleSheetFile::exportUrl()`), and `data_import.google_spreadsheet_id` keeps the id only (what
"Try again" fetches, and a later "Look for new rows" would).

| Taken | Refused |
|---|---|
| `docs.google.com/spreadsheets/d/<id>` with whatever follows: `/edit`, `/edit?usp=sharing`, the phone app's `?usp=drivesdk`, `/htmlview`, `/view`, `/preview`, `/pubhtml`, `/copy`, `/export?…`, `#gid=…` | Google documents, slides, forms; Drive folders and files (`drive.google.com/…`); every other host - also one that only starts or ends like Google's |
| with the account part: `/spreadsheets/u/1/d/<id>/…` | a link with a user, a password or another port; another scheme; a line break inside; longer than 2,000 characters |
| without `https://`, with `http://`, spaces around | **"published to the web"** links (`/spreadsheets/d/e/2PACX-…`): they carry another id the export does not take - a sentence of their own ("paste the link from Share → Copy link instead") |

A refused link is the player's own news: nothing is logged, no import is made, the rate limit is not touched.

## States

`fetching` → `uploaded` (then stage 3b: `reading` → `setting_up` / `read_failed`) or `fetch_failed`; `discarded` from
anywhere.

| State | Means | The page |
|---|---|---|
| `fetching` | the import waits for the worker, or the worker is asking Google - no file is stored yet | "Fetching the sheet from Google…" on stage 3b's live line (the state line only, no numbers), which then goes on to "Waiting to be read…" and "Reading the file…" without a reload |
| `fetch_failed` | Google did not hand the sheet over; `read_error` says why | the reason in plain words, **Try again** (fetches again), "Upload a file instead", Discard |

The import is named `''` until Google names the download (the pages say "Google sheet" then); afterwards it carries
the document's title with `.xlsx` (from `Content-Disposition`, cleaned like an uploaded file's name -
`Value\DataImportFileName`). `file_kind` is `xlsx`; `file_size` is what arrived. `file_key` is known from the start
(the same server-chosen key as an upload's), so a discard - and the prune after it - removes whatever a fetch stored
behind it; the pages treat the file as kept only once it was fetched.

Once read, the page of a Google import adds "you can switch its sharing off again" (README "Bring the file").

## The fetch, step by step

`CreateImportFromGoogleSheet` (handled at once, in the request): the import in `fetching`, and
`StartImportFromGoogleSheet(importId)` queued on the transport `imports` in the same transaction.

`StartImportFromGoogleSheetHandler` runs **outside a transaction** (`HandledOutsideTransaction`): Google is waited
for up to a minute. Its writes are small messages of their own, each in its own transaction and on fresh state
(`RequiresFreshEntityManagerState`), under the import's advisory lock:

| # | Message | What |
|---|---|---|
| 1 | `BeginImportFetch` | the import is taken: `fetch_attempt` + 1, `fetch_started_at`, committed **before Google is asked**. Nothing to fetch (taken already, fetched, failed, discarded) = the handler ends - a second copy of the message asks Google nothing |
| 2 | - | `GoogleSheetFile::fetchTo()` streams the export into a temporary file (below) |
| 3 | - | the file is stored under the import's key through `ImportFileStorage::store()` - the fallback form's way |
| 4 | `RecordImportFetched` | `fetching` → `uploaded`, the name and the size. Refused when the import is not being fetched by that very fetch any more (discarded meanwhile, given up, started again): the handler then deletes what it stored |
| 5 | `RequestImportRead` | stage 3b's request: stamps `read_requested_at` and queues `ReadImportFile`. Should the worker die between 4 and 5, the import waits in `uploaded` without a stamp and the safety net asks for it after two minutes |
| - | `RecordImportFetchFailed` | instead of 3-5: `fetch_failed` + the reason |

After every end the page is told (`ImportProgress::publish()` - the player's private topic; `moving` is true while
`fetching`). The temporary file (in the folder of the files being read) is removed in a `finally`.

**"Try again"** (`POST /{_locale}/import/{importId}/fetch-again` → `FetchImportAgain`): `fetch_failed` → `fetching`,
queued again; pressed twice it queues once.

## How Google is asked

`Services\DataImport\GoogleSheetFile` - **what comes back is hostile input over a hostile path**:

- **The address is ours**: `https://docs.google.com/spreadsheets/d/<id>/export?format=xlsx`.
- **Redirects are followed by hand** (the client is told `max_redirects: 0`), at most 5, and every hop is checked
  before it is asked: `https` only, no port, no user, and a host of the list.
- **Hosts allowed** - two, both seen on a real fetch (2026-11-15, Google's own public sample sheet:
  `docs.google.com` → 307 → `doc-08-4o-sheets.googleusercontent.com` → 200, `Content-Type:
  application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment;
  filename="…"; filename*=UTF-8''…`):
  - `docs.google.com` - where the export is asked for;
  - `doc-<x>-<y>-sheets.googleusercontent.com` (`GoogleSheetFile::DOWNLOAD_HOST_PATTERN`) - where it is downloaded
    from. **Not all of `*.googleusercontent.com`**: that domain also names Google Cloud customers' machines
    (`<ip>.bc.googleusercontent.com`). Should Google ever download from another host, the fetch fails as "refused"
    with a **warning** naming the host - then the pattern is widened here, knowingly.
  - A redirect to `accounts.google.com` is never followed: it is Google asking to sign in - "not shared".
- **No private address**: every host is resolved first (`HostAddresses`, IPv4); loopback, private, link-local and
  other non-public addresses are refused (`IpUtils::isPrivateIp()`), and the request is **pinned to the address that
  was checked** (`resolve`) - no second lookup that could answer differently.
- **Caps**: 25 MB (`DataImportLimits::MAX_FILE_BYTES`), streamed straight into the file and **counted while it
  arrives** - `Content-Length` is only ever a reason to stop sooner; over the cap the transfer is cut. 5 s to
  connect, 20 s without a byte, 60 s for the whole fetch over all hops together.
- **It must be a zip**: the first four bytes are `PK\x03\x04` and the content type is not HTML - else nothing is
  kept. Of an answer that is no zip at most 64 KiB are read, to tell Google's sign-in page from anything else.

The event results import reads its organizer's sheet by another road (`GoogleSheetReader`: the tabs' page and one
CSV per tab, small answers in memory, redirects left to the client). The two share the id's pattern and Symfony's
HTTP client, nothing else - this one takes any player's link and a file of megabytes.

## Outcomes

| What Google did | Reason (`read_error`) | The player reads | Log |
|---|---|---|---|
| 200 and a zip within the cap | - | the import goes on to be read | - |
| a redirect to `accounts.google.com`, a 401 or 403, or a 200 with its sign-in page (HTML naming the sign-in) | `not_shared` | "Google asks to sign in … Share it and try again" + the sharing hint | info |
| 404, 410 | `not_found` | "Google does not know a spreadsheet with this link …" | info |
| more than 25 MB announced or arriving | `too_large` | "As an Excel file the sheet is larger than 25 MB …" | info |
| 5xx, 429, any other status, no answer, no bytes for 20 s, over 60 s, a host that does not resolve | `refused` | "Google did not hand the sheet over this time. Try again later, or download it (File → Download → Microsoft Excel) and upload the file." | info |
| a redirect to a host that is not on the list, to a private address, without https, without an address, or the sixth redirect | `refused` | the same | **warning** - Google changed, or somebody is in the path |
| a 200 that is no zip and no sign-in page (an error page, CSV, nothing) | `not_a_spreadsheet` | "What Google sent is not a spreadsheet. Download the sheet … and upload the file." | info |
| object storage, the disk, a bug | `failed` | "Something went wrong on our side … Please try again." | error |
| stuck in `fetching` for 15 minutes | `stalled` | the same | warning |

Nothing is thrown on: the queue has no retries, a failed fetch is recorded on the import and started again by the
player.

## The safety net

`myspeedpuzzling:read-waiting-imports` (stage 3b's cron, every 5 minutes) also ends a fetch that never ended: an
import in `fetching` without any news for 15 minutes (`last_activity_at`: its message was lost, or its worker died -
a fetch itself never takes more than a minute) becomes `fetch_failed` with the "our side" wording, its page is told,
the player can try again. A fetch that was given up can not write into the next one (`fetch_attempt`).

## Routes, messages, columns

| | |
|---|---|
| Routes | `data_import_new` (POST also takes `google_link`), `data_import_fetch_again` (POST, CSRF `data_import`); `data_import_detail` and `data_import_progress` know the two new states |
| Messages | `CreateImportFromGoogleSheet`, `StartImportFromGoogleSheet` (→ `imports`), `BeginImportFetch`, `RecordImportFetched`, `RecordImportFetchFailed`, `FetchImportAgain` |
| Rate limiter | `data_import_google` |
| Service | `data_import.google_http_client` (no redirects followed; tests swap it for recorded answers) |
| Migration (generated) | `data_import`: `fetch_started_at`, `fetch_attempt`. The states are values of the existing `status` column; `google_spreadsheet_id` and `source` exist since stage 3a |

The consumer needs outbound HTTPS to `docs.google.com` and `*.googleusercontent.com` and DNS - nothing else new.

## Tests

No test reaches the network: Google is `GoogleSheetHttpMock` (answers queued by the test, every request recorded),
DNS is `FixedHostAddresses`. Every id is made up.

- `tests/Value/GoogleSheetLinkTest.php` - every form of a link, everything that is refused.
- `tests/Services/DataImport/GoogleSheetFileTest.php` - a public sheet (the address, the hops, the pinned
  addresses, the title); not shared (sign-in page with 200 - also under a lying content type -, 401, 403, the
  redirect to the sign-in); 404; over the cap mid-stream without a `Content-Length`, and announced; redirects to
  another host, to a look-alike, to another machine of googleusercontent.com, to a private address, to the metadata
  address, without https, with a port or a user; a host of the list resolving to a private address; six redirects
  (and five that are followed); pages, CSV and nothing instead of a zip; bytes arriving one by one.
- `tests/Controller/DataImport/ImportGoogleSheetTest.php` - the page (link first); public sheet → fetched, stored,
  read requested, read; every failure's sentence; "Try again"; a fault of ours; a Docs link and its kin refused at
  the form; the fourth import; the rate limit (links and tries); another player's import untouched (404s); a
  discard while fetching; a given-up fetch's late write; the stuck-state rescue; 6 locales.

## Not built here

- **"The same Google sheet again"** (README: "You imported this on …" / "Look for new rows"): needs versions (rows
  matched by `source_key`, stage 4). Until then the same link pasted again is a new import; when its bytes equal an
  import that was read, stage 3b's same-file check closes it in favour of that one.
- "Published to the web" links, private sheets (OAuth), a scheduled re-read, `.csv` export of one tab.
- Never run against the real Google by the handler in this stage's tests - the real chain was observed by hand
  (above) and the shapes recorded.
