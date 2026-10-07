# Participant import: upload → columns → preview → confirm

Status: **plan, revised after review** (2026-10-07). Extends [participants.md](participants.md) §Excel Import, which
documents the rules the import already follows (matching, round names, teams). Those rules stay; this adds CSV, a sheet
chooser, a column mapping step, a preview of exactly what will happen, and a second, opt-in mode in which **the file is
the truth**.

## Why

An organiser (~230 participants, rounds Solo / Pair / Team / Team Relay) keeps her registration in a spreadsheet with
many other columns (member numbers, addresses…) on several sheets. What went wrong for her:

1. **CSV was refused** ("CSV is not supported yet"). She has to export one sheet, so CSV is the natural format.
2. **The import never removes a round entry.** A person she took out of a round in her sheet stayed in it (two such
   entries on production). Nothing said what it had kept.
3. **Names written in two ways** became two participants: a straight `'` and a curly `’` apostrophe, and a corrected
   typo typed as a new row while the old spelling stayed on the site (she removed the old ones by hand).
4. **Different groups share a team name inside a round.** Two different 4-person teams are both called the same in the
   Team round, and so are two pairs in the Pair round. On production they are separate teams only because they already
   existed. Imported into an empty event, the same file puts **8 people into one team** and 4 into one pair (verified
   locally with a copy of the event).
5. **An older file brings removed people back.** Importing a file made before she removed somebody silently restored
   them (reported as "updated").
6. Every message arrived as a flash after the fact, one per row (an export imported into another event gives 230
   identical warnings).

## Decisions

| # | Decision |
|---|----------|
| D1 | **Formats:** `.xlsx` (every sheet), `.csv`, `.tsv`, `.txt`. CSV: delimiter detected among `,` `;` TAB by a field count that stays the same over the first 20 records (not by counting characters - `;` files often hold `Solo, Pair` in a cell), an Excel `sep=;` first line honoured and skipped, quoted fields (RFC 4180), lone `\r` line ends. Encoding: BOM first (UTF-8, UTF-16LE/BE - Excel's "Unicode text" is UTF-16 + TAB), then valid UTF-8, then Windows-1250 when the bytes look Central European (0x8A 0x8D 0x8E 0x9A 0x9D 0x9E 0xE8 0xEC 0xF8 …), otherwise Windows-1252. The preview has **Encoding** and **Separator** selects ("Automatic" first) to override. `.xls` / `.ods` are not added (TODO). |
| D2 | **Nothing is written before the organiser confirms.** The upload is kept in object storage (D9), the page shows columns + preview, and only "Confirm" writes. |
| D3 | **Sheet chooser** for an `.xlsx` with more than one non-empty sheet. Hidden sheets are listed with "(hidden)" and never preselected. Switching sheets resets the mapping. **Header row** = the first row with at least 2 non-empty cells (title rows above it are skipped). Merged cells take the top-left value in every cell of the range (a team name merged over 4 rows). Formulas give their cached value, never a recalculation. At most 5,000 rows × 100 columns are read. |
| D4 | **Column mapping.** Every column is shown with its header and up to 3 sample values, plus a select: *Ignore* / Name / First name / Last name / Country / Rounds (list) / Round (one) / Team (every pair/team round of the row) / Team in round *X* (one option per pair/team round) / MySpeedPuzzling player id / Participant id / External id / Status. Known headers are detected (D5), anything else defaults to *Ignore*: extra columns are not an error (the preview lists them as "Not imported"). Each field except *Team in round X* (one per round) can be mapped once; a second column for the same field is an error shown next to the select. |
| D5 | **Header detection** (case-, space-, `_`/`-`-insensitive): everything the importer reads today plus a few aliases (full name, first/last name, surname, country code, rounds, division(s), team, `team: <round>`, `<round> team`, msp id). More aliases (localized headers) → `docs/TODO.md`. |
| D6 | **First + Last name** mapped → name = `first + ' ' + last`. Name **or** First+Last is required; without it the preview shows only "Choose the column with the participants' names". |
| D7 | **Two modes, chosen explicitly on the preview (Jan, 2026-10-07):** **Update only** (default, today's behaviour: add new people, update existing ones, nothing is removed) and **Full sync – the file is the truth** (additionally removes what is on the site but not in the file: participants, their round entries, and pairs/teams the import empties). The preview always lists exactly what full sync would remove (people, round entries, team changes, pairs/teams per round): in *Update only* as "On the site but not in the file – kept", in *Full sync* as "Will be removed". Confirming a sync that removes anything needs a checkbox; when it removes more than 25 % of the active participants (and at least 10), the organiser types the number of people removed instead. |
| D7b | **Full sync is refused (radio disabled with the reason, and refused again on confirm) when the plan cannot vouch for every row:** a row without a name, a row skipped as ambiguous, an unknown round name in a mapped rounds column, rounds of the event whose names differ only in case, more than 3 `participant_id`s of another event, or no row matching any existing participant while the event has active participants (a wrong Name column or the wrong file). |
| D8 | **Confirm re-checks.** Fingerprint = sha256 of (the mapped rows, the mode, the **event state version**). The state version is one query hashing, in id order, the event's participants (id, name, country, external id, player, deleted, source), round entries (id, participant, round, team), teams (id, round, name) and rounds (id, name, category). The handler computes it again inside its transaction and under a per-event lock; a different fingerprint → nothing is written, back to the preview with "Something changed on the site since the preview – please check it again." Results are not in the hash (they arrive all day on event day); the handler re-runs the results guard (D11) for every planned removal and treats a new result like a stale preview. One message, one handler, one transaction. A second click on Confirm after a successful import answers "Already imported" (the stash records `appliedAt`). |
| D9 | **Temporary storage = object storage**, not local `/tmp`: blue-green deploys run two containers and FrankenPHP workers are long-lived, so the confirm may hit another container. Same pattern as `PhotoStash`: `ParticipantImportStash` keeps `tmp-imports/<competitionId>/<token>` (the file), `<token>.json` (original name, format, uploader player id, stored at, applied at) and the parsed sheet as JSON per sheet/encoding/separator, so mapping changes do not download and parse the xlsx again. The token is 32 random hex chars in the URL; every request checks `CompetitionEditVoter` and that the token belongs to this event (any maintainer of the event may use it). At most 3 stashed imports per event (oldest dropped). Max 5 MB. The file holds personal data (addresses…): kept at most 24 hours, removed after a successful confirm, by "Start over" and by the existing prune cron (`myspeedpuzzling:prune-photo-stash` prunes `tmp-imports/` too). The preview sends `Referrer-Policy: same-origin`. |
| D10 | **Removal = the existing soft delete**, never a hard delete; a removed person can be restored on the participants page. A **self-joined** participant is first made the organiser's (`markAsImported()`), then soft-deleted: otherwise the row would be the player's own "I left" record, which the import never matches and "I'm going" silently restores. A round entry the file no longer lists is deleted (as the edit form does). A pair/team the import **empties** (at least one active member before, none after) is deleted the PR #244 way (members incl. hidden removed ones unassigned first, same transaction). Teams that were already empty (created in advance) are never removed. |
| D11 | **Guards on removal.** Never removed, listed under "Kept – has results": a participant whose linked player has a result in a round of this event, and a round entry whose player has a result in that round. Nothing else references `competition_participant` (only `competition_participant_round`; table spots reference players). |
| D12 | **Self-joined participants** not in the file are listed in their own group: "Joined on MySpeedPuzzling by themselves – you may not have them in your sheet". |
| D13 | **Removed participants matched by the file are restored** (today's rule, now explicit): action "Restore (removed on <date>)", with the round entries and teams that come back with them. In sync, those entries are compared with the file like everybody else's. A removed *self-joined* row is never matched (today's rule) → "New". Round entries of removed participants are otherwise out of scope (never listed, never removed). |
| D14 | **Sync scope follows the mapping, never guesses.** Round entries are only removed when a Rounds/Round column is mapped; an **empty** rounds cell keeps the person's rounds (grouped warning). Teams are only synced in a round that has its own *Team in round X* column; the generic Team column only adds teams (today's rule). Field values are never cleared by empty cells, in both modes. |
| D15 | **Team changes in full sync** (rounds with their own team column): the file names another team → the person moves (to the one team of that name; none → a new team; two or more of that name → not guessed, stays, warning); an empty cell while in a **named** team → unassigned (listed); an empty cell while in an **unnamed** team → stays (a file cannot name an unnamed team, and the export writes an empty cell for it). In *Update only* nobody is moved (today's "team kept" message). |
| D16 | **Team identity = round + team name** (case-insensitive, whitespace collapsed). (a) A person already in a team whose name equals the file's keeps exactly that team, even when two teams of the round share the name. (b) A new member for a name two or more teams of the round share is not guessed: unassigned + warning. (c) More people under one name than the round's teams usually have (pair: > 2; team: > the most common team size of that round – from the file, falling back to the site's teams, at least 2) → warning "8 people are in "<name>" in Team – teams in this round usually have 4. If these are different teams, give them different names in the file". Applying still puts them into one team: the organiser saw the warning and chose to confirm. Removed participants never count for sizes. |
| D17 | **Names probably of the same person** (warnings, never merged by themselves): a *name key* folds case, whitespace, diacritics, `’ ‘ ʼ ´ \`` → `'` and `‐ – —` → `-`. (a) Two rows of the file with the same key and a different spelling → warning. (b) A row that matches nobody by its exact name but exactly one active participant by key (same rules as today's name match) is **matched** to them, name updated to the file's spelling, shown as a change. (c) A new row and a participant on the site but not in the file whose keys are 1–2 edits apart (keys ≥ 6 characters) → warning "Daniel Walters (new, row 12) looks like Daniel Waters, who is on the site but not in the file". |
| D18 | **Messages stay per row** in the plan (each with its kind and row number - today's texts, so the console command prints what it printed); the preview groups them per kind (rows listed, first 10 + "…"). Unknown columns are not a warning on the preview (they are visibly "Not imported"); the console command keeps reporting them. |
| D19 | **Plain controllers, not a Live Component.** Upload = POST → 303 to the preview. Mapping, sheet, encoding, separator and mode = a GET form inside a `<turbo-frame data-turbo-action="advance">` (state in the URL, back button works, scroll kept). Confirm = a separate POST form carrying the previewed state in hidden fields → 303 to the participants page with the summary, or 303 back to the preview when stale. No POST answers 200 (CLAUDE.md Turbo rule). |
| D20 | **The console command and the round trip keep working.** `myspeedpuzzling:import-competition-participants <id> <file>` plans with the detected mapping in *Update only* and applies – same code path. An export imported back unchanged changes nothing in either mode (tests). The web flow now goes through the message bus (today's controller called the importer directly). |

## Design

### Pieces

Values (`src/Value`): `ParticipantImportMode`, `ParticipantImportField`, `ParticipantImportRound`, `ParticipantSheet`,
`ColumnMapping` (detect / fromQuery / toQuery / errors / **toRows(ParticipantSheet): ParticipantImportRows**),
`ParticipantImportRowData` (one mapped row: row number, name, country, external id, player id, participant id, status,
rounds cells - null when not mapped - generic team, team per round - key present = mapped), `ParticipantImportRows`
(rows + unmapped headers + `hash()`), `ParticipantFileFormat`, `ParticipantFileOptions` (encoding, separator),
`StashedParticipantImport`, `ParticipantImportRowAction`. Results (`src/Results`): `ParticipantImportPlan`,
`ParticipantImportRow`, `ParticipantImportRemovals`, `ParticipantImportResult` (existing, gains `restored`, `removed…`).

Services (`src/Services/ParticipantImport`):
- `ParticipantFileReader` – `sheets(path, format): list<{name, hidden, rows}>`, `read(path, format, sheet, options): ParticipantSheet`.
- `ParticipantImportStash` – file + meta + cached sheet JSON (D9), `markApplied()`, `prune()`, `ResetInterface`.
- `ParticipantImportPlanner` – `rounds(competitionId)`, `plan(competitionId, ParticipantImportRows, mode): ParticipantImportPlan`
  (read only, deterministic; holds today's matching rules, moved from `CompetitionParticipantImporter`).
- `ParticipantImportApplier` – executes the plan's operations through entities, persist only (handlers only).
- Query `GetParticipantImportStateVersion` (D8).

Message `ApplyParticipantImport(competitionId, ParticipantImportRows, mode, expectedFingerprint)` (SerializedByLock per
event) + handler: plan → compare fingerprint (throw `ParticipantImportPreviewStale` before any change) → re-check results
for removals → apply → result. The controller marks the stash applied and discards it after a successful dispatch, so the
handler never touches object storage. `CompetitionParticipantImporter::import()` stays as the façade for the console
command.

### Pages
- Upload (existing route `import_competition_participants`, POST): validates, stashes, 303 → preview.
- `participant_import_preview` GET `/en/import-event-participants/{competitionId}/{token}`: file + sheet chooser +
  encoding/separator, mapping table, mode cards, summary, grouped warnings, rows (unchanged collapsed), "on the site but
  not in the file", confirm form. `noindex`, `Cache-Control: private, no-store`, `Referrer-Policy: same-origin`.
- `participant_import_confirm` POST `…/confirm`, `participant_import_discard` POST `…/discard`.

## Tests
- Reader: delimiters (incl. `;` with commas in cells), `sep=`, quotes, BOM, UTF-16, Windows-1250 and -1252, merged
  cells, header row below a title, hidden and empty sheets, formulas, junk files.
- ColumnMapping: detection, aliases, duplicates, First+Last, query round trip, toRows.
- Planner (synthetic data reproducing every point of "Why"): both modes, removal lists, self-joined group, kept with
  results, restore with entries, apostrophe variant matched, typo pair warned, shared team names, 8 under one name,
  D7b blockers, D14/D15 scope, export → no operations in both modes, fingerprint stable.
- Handler: applies exactly the plan, stale → nothing written, sync removes people (soft, self-joined made the
  organiser's), entries, emptied teams; results guard.
- Existing `CompetitionParticipantImporterTest` stays green (façade). Functional: the whole web flow.

## Out of scope / TODO
- `.xls` / `.ods`, localized header aliases, a header row chosen by hand, remembering a mapping per event.
- A team renamed in the file (all its members under a new name) is planned as move + delete, not as a rename.
- Clearing field values from empty cells in full sync (D14).
