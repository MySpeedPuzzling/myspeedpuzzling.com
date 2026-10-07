# Participant import: upload → columns → preview → confirm

Status: **plan** (2026-10-07). Extends [participants.md](participants.md) §Excel Import, which documents the rules the
import already follows (matching, round names, teams). Those rules stay; this adds CSV, a sheet chooser, a column
mapping step, a preview of exactly what will happen, and a second, opt-in mode in which **the file is the truth**.

## Why

An organiser (~230 participants, rounds Solo / Pair / Team / Team Relay) keeps her registration in a spreadsheet with
many other columns (member numbers, addresses…) on several sheets. What went wrong for her:

1. **CSV was refused** ("CSV is not supported yet"). She has to export one sheet, so CSV is the natural format.
2. **The import never removes anything.** A person she took out of a round in her sheet stayed in it. The import
   reported "updated" or "unchanged", and nothing said what it had kept.
3. **Names written in two ways** became two participants: a straight `'` and a curly `’` apostrophe, and a corrected
   typo typed as a new row while the old spelling stayed on the site.
4. **Different groups share a team name inside a round.** Two different 4-person teams are both called the same in the
   Team round, and so are two pairs in the Pair round. On production they are separate teams only because they already
   existed. Imported into an empty event, the same file puts **8 people into one team** and 4 into one pair (verified
   locally with a copy of the event).
5. Every message arrived as a flash after the fact, one per row (an export imported into another event gives 230
   identical warnings).

## Decisions

| # | Decision |
|---|----------|
| D1 | **Formats:** `.xlsx` (every sheet), `.csv`, `.tsv`, `.txt`. CSV: delimiter detected from `,` `;` tab, quoted fields (RFC 4180, `""` inside quotes), UTF-8 with or without BOM, otherwise read as Windows-1252. `.xls` / `.ods` are not added (TODO). |
| D2 | **Nothing is written before the organiser confirms.** The upload is kept in object storage (D9), the page shows columns + preview, and only "Confirm" writes. |
| D3 | **Sheet chooser** for an `.xlsx` with more than one non-empty sheet. The first non-empty sheet is preselected; switching sheets re-detects the columns. |
| D4 | **Column mapping.** Every column is shown with its header and up to 3 sample values, plus a select: *Ignore* / Name / First name / Last name / Country / Rounds (list) / Round (one) / Team (every pair/team round of the row) / Team in round *X* (one option per pair/team round) / MySpeedPuzzling player id / Participant id / External id / Status. Known headers are detected (D5), anything else defaults to *Ignore*: extra columns are not an error and are not reported. Each field except *Team in round X* (one per round) can be mapped once; a second column for the same field is an error shown next to the select. |
| D5 | **Header detection** (case-, space-, `_`/`-`-insensitive): everything the importer reads today (`name`, `country`, `external_id`, `msp_player_id`, `status`, `round_names`, `round_name`, `team_name`, `team_name: <round>`, `participant_id`) plus a small set of aliases: `full name` / `participant` / `player` → Name; `first name` / `firstname` / `given name` → First name; `last name` / `lastname` / `surname` / `family name` → Last name; `country code` / `nation` → Country; `rounds` / `round` / `divisions` / `division` → Rounds; `team` → Team; `team: <round>` / `<round> team` → Team in round. More aliases (e.g. localized headers) go to `docs/TODO.md`. |
| D6 | **First + Last name** mapped → name = `first + ' ' + last` (whitespace collapsed). Name **or** First+Last is required; without it the preview shows only "Choose the column with the participants' names". |
| D7 | **Two modes, chosen explicitly on the preview (Jan, 2026-10-07):** **Update only** (default, today's behaviour: add new people, update existing ones, nothing is removed) and **Full sync – the file is the truth** (additionally removes what is on the site but not in the file: participants, their round entries, and pairs/teams that end up empty or no longer appear in the file). The preview always lists exactly what full sync would remove (people, round entries, pairs/teams per round). In *Update only* the list is shown as "On the site but not in the file – kept", in *Full sync* as "Will be removed". Confirming a full sync that removes anything needs an extra checkbox ("I have checked the list of removals"). Nothing is ever removed without this explicit choice. |
| D8 | **Confirm re-checks the preview.** The confirm form carries a fingerprint of the previewed plan. The handler plans again from the stored file + mapping + mode against the current data; a different fingerprint → nothing is written, the organiser is sent back to the preview with "Something changed on the site since the preview – please check it again." Applying is **one message, one handler, one transaction**, serialized per event with a lock. |
| D9 | **Temporary storage = object storage**, not local `/tmp`: blue-green deploys run two containers and FrankenPHP workers are long-lived, so the confirm may hit another container. Same pattern as `PhotoStash` (`src/Services/PhotoStash/PhotoStash.php`): `ParticipantImportStash` keeps `tmp-imports/<competitionId>/<token>` + `<token>.json` (original file name, format, the uploader's player id, stored at). The token is 32 random hex chars, carried in the URL; every request also checks `CompetitionEditVoter` and that the token belongs to this event. Max 5 MB. Kept 24 hours; removed after a successful confirm, by "Start over", and by the existing prune cron (`myspeedpuzzling:prune-photo-stash` also prunes `tmp-imports/`, so no new cron row is needed). |
| D10 | **Removal = the existing soft delete** (`CompetitionParticipant::softDelete()`), never a hard delete. A removed person can be restored on the participants page. A round entry the file no longer lists is deleted (as the edit form does). A pair/team whose (active) members are all gone is deleted the PR #244 way (members incl. hidden removed ones unassigned first, same transaction). |
| D11 | **Guards on removal.** Never removed, listed under "Kept – has results": a participant whose linked player has a result in a round of this event (`puzzle_solving_time.competition_round_id` of one of the event's rounds, as tracker or member of the result's pair/team), and a round entry whose player has a result in that round. Nothing else references `competition_participant` (checked: only `competition_participant_round`; table spots reference players, not participants). |
| D12 | **Self-joined participants** (players who clicked "I'm going" on MySpeedPuzzling) that are not in the file are listed in their own group in the removal list: "Joined on MySpeedPuzzling by themselves – you may not have them in your sheet". They are removed in full sync like everybody else; the organiser sees them before confirming. |
| D13 | **Removed participants matched by the file are restored** (today's rule, now explicit): the row's action is "Restore (removed on <date>)". A removed *self-joined* row is the player's own "I left" record and is never matched (today's rule), so such a row becomes "New". |
| D14 | **Sync scope follows the mapping, never guesses.** Round entries are only removed when a Rounds column is mapped. Teams are only synced for a round that has its own *Team in round X* column. The generic Team column only adds teams (today's rule) and never removes. Field values are never cleared: an empty country / external id / player id cell keeps what the site has, in both modes. |
| D15 | **Unnamed teams** cannot be written in a file. In full sync, a person whose team cell is empty and who is in an **unnamed** team stays in it. Unnamed teams are only removed when they end up with no active members. (The export writes an empty cell for unnamed teams, so export → full sync changes nothing.) |
| D16 | **Team identity = round + team name** (case-insensitive, whitespace collapsed), as today. In addition: (a) a person already in a team whose name equals the file's keeps exactly that team, even when the round has two teams of that name; (b) a new member for a name that two or more teams of the round share is not guessed: they stay unassigned, with a warning; (c) more people under one name than the round's teams usually have (pair: more than 2; team: more than the most common team size in the file for that round, at least 2) → warning "8 people are in "<name>" in Team – teams in this round usually have 4. If these are different teams, give them different names in the file (you can rename them on the Teams page later)". Applying still puts them into one team: the organiser saw the warning and chose to confirm. |
| D17 | **Names probably of the same person** (warnings, never merged automatically): a *name key* folds case, whitespace, diacritics, `’ ‘ ʼ ´ \`` → `'`, and `‐ – —` → `-`. (a) Two rows of the file with the same key but a different spelling. (b) A new row whose key equals an existing participant's who is not matched otherwise: **matched** to that participant (name updated to the file's spelling, shown as "Update: name O’Hara → O'Hara"), only when exactly one active candidate (same rules as today's name match). (c) A new row and a participant that is on the site but not in the file whose names are 1–2 edits apart (Levenshtein on the key, names ≥ 6 characters): warning "Daniel Walters (new, row 12) looks like Daniel Waters, who is on the site but not in the file". |
| D18 | **Warnings are grouped** in the preview: one line per kind with the rows (first 10 + "…"), not one per row. Rows show their own badge. Unknown round names, unknown countries, invalid player ids, ambiguous names, unknown participant ids keep today's meaning. |
| D19 | **Plain controllers, not a Live Component.** The participants page is a Live Component, but the import is a separate flow with a file upload and a big preview. Upload = POST → 303 to the preview. Mapping, sheet and mode changes = a GET form (the state lives in the URL: `?sheet=&map[3]=name&mode=sync`, bookmarkable, back button works). Confirm = POST → 303 to the participants page with the summary flash, or 303 back to the preview when stale. No POST answers 200 (CLAUDE.md Turbo rule). |
| D20 | **The console command and the round trip keep working.** `myspeedpuzzling:import-competition-participants <id> <file>` plans with the detected mapping in *Update only* and applies – same code path. An export imported back unchanged changes nothing in either mode (guarded by tests). |

## Design

### Pieces (all in `src/Services/ParticipantImport/` unless noted)

- `ParticipantFileReader` – `sheets(string $path, string $format): list<string>` and
  `read(string $path, string $format, int $sheet): ParticipantSheet` (`headers: list<string>`, `rows: list<list<string>>`
  with row numbers as the organiser sees them, trimmed strings, trailing empty rows dropped).
  XLSX via PhpSpreadsheet (`listWorksheetNames`, `setLoadSheetsOnly`, formatted values so `123` stays `123`), CSV via
  `fgetcsv` with the detected delimiter and `escape: ''`.
- `ParticipantImportStash` (D9) – `keep(UploadedFile, competitionId, playerId): string token`, `describe()`,
  `localCopy(token, competitionId): string path` (temp file, removed on `reset()` – `ResetInterface`, worker mode),
  `discard()`, `prune()`.
- `Value\ParticipantImportField` (enum) + `Value\ColumnMapping` (column index → field, plus round id for *Team in
  round*) with `detect(headers, rounds)`, `fromQuery(array)`, `toQuery()`, `errors()`.
- `Value\ParticipantImportMode` (enum `update`, `sync`).
- `ParticipantImportPlanner::plan(competitionId, ParticipantSheet, ColumnMapping, mode): ParticipantImportPlan` –
  **read only** (DBAL reads, no entity changes, no generated ids). Holds all of today's matching rules (moved here
  from `CompetitionParticipantImporter`).
- `Results\ParticipantImportPlan` – row results (row number, name, action: new / update / restore / unchanged /
  skipped, field changes before → after, rounds added, team per round, per-row messages), grouped warnings, errors,
  the removal lists (participants: normal / self-joined / kept-has-results; round entries: removed / kept-has-results;
  teams: removed per round), counts per action, and `fingerprint()` (sha256 over the operations and the site values
  they depend on; new participants identified by row number, never by id).
- `ParticipantImportApplier::apply(plan)` – writes the plan through entities and repositories (persist only; uuid7 for
  new rows); used only from handlers.
- Message `ApplyParticipantImport(competitionId, stashToken, sheet, mapping, mode, expectedFingerprint)` +
  handler: read the stash, plan, compare the fingerprint (throw `ParticipantImportPreviewStale` – 409 – **before**
  any change, see `project_rolled_back_handler_leaks`), apply, return `ParticipantImportResult`. Locked per event
  (`SerializedByLock` / lock middleware, key `participant-import-<competitionId>`).
- `CompetitionParticipantImporter::import(competitionId, path)` stays as the façade for the console command
  (`ImportCompetitionParticipants` message): read → detect mapping → plan (update) → apply. Its existing tests stay.

### Controllers / pages

- `ImportCompetitionParticipantsController` (existing route `import_competition_participants`, POST): validates the
  upload (`.xlsx .csv .tsv .txt`, ≤ 5 MB), stashes it, 303 → preview. Invalid → flash + 303 to the participants page.
- `ParticipantImportPreviewController` GET `/en/import-event-participants/{competitionId}/{token}` (6 locales):
  sheet chooser, mapping table, mode radio, preview; `noindex`, `Cache-Control: private`.
- `ConfirmParticipantImportController` POST same path + `/confirm`: CSRF, voter, dispatch; 303 to the participants
  page with the summary, or 303 back to the preview (with the same query) on stale / mapping errors.
- `DiscardParticipantImportController` POST `/discard`: removes the stash, 303 back.
- Template `templates/competition/participant_import_preview.html.twig`; the upload form on
  `manage_competition_participants.html.twig` accepts the new formats, its help text changes ("you will see what
  happens before anything is saved").

### Preview page, top to bottom

1. File name + "Start over" (discard). Sheet chooser (only for a multi-sheet xlsx).
2. **Columns:** a table (header · samples · select). "Update preview" submits the GET form (no JS needed; a small
   `onchange` auto-submit is fine).
3. **Mode:** two radio cards – *Update only* (default) / *Full sync – the file is the truth*, each with one line of
   explanation and the number of removals it would make.
4. **Summary:** N new · N updated · N restored · N unchanged · N skipped · (sync) N removed.
5. **Warnings** (grouped, D18) and errors.
6. **Rows:** a table with the action badge, name, what changes, rounds, teams. Unchanged rows collapsed in a
   `<details>`.
7. **On the site but not in the file** (D7, D11, D12): people (self-joined in their own group), round entries,
   pairs/teams per round, plus "Kept – has results".
8. **Confirm** form (POST, CSRF, hidden: sheet, mapping, mode, fingerprint; checkbox in sync mode when removals > 0).

## Tests

- `ParticipantFileReaderTest`: `,` `;` tab, quotes with commas/newlines/`""`, BOM, Windows-1252 (`O’Hara` as `0x92`),
  empty trailing rows, a 2-sheet xlsx.
- `ColumnMappingTest`: detection of every known header and alias, duplicates, First+Last, query round trip.
- `ParticipantImportPlannerTest` with **synthetic** fixtures (made-up names) reproducing every fact above: removal
  lists per mode, self-joined group, kept-has-results, restore, apostrophe variants matched and warned, typo pair
  warned, same team name of two groups (existing teams kept, new member not guessed), 8 people under one team name
  warned, sizes, unknown round/country, scope rules D14/D15, export → plan = nothing in both modes.
- `ApplyParticipantImportHandlerTest`: applies exactly the plan; stale fingerprint → nothing written; sync removes
  people (soft), entries, emptied teams (PR #244 way); results guard.
- Existing `CompetitionParticipantImporterTest` stays green (façade).
- Functional: upload CSV → preview → change mapping → sync → confirm; xlsx with 2 sheets; stale confirm; another
  event's token → 404; non-maintainer → 403; translations in 6 locales.

## Out of scope / TODO
- `.xls` / `.ods`, encodings beyond UTF-8 / Windows-1252, localized header aliases, remembering a mapping per event.
- Clearing field values from empty cells in full sync (D14).
