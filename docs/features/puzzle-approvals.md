# Puzzle approvals

Newly added puzzles (`puzzle.approved = false`, set by `AddPuzzleHandler`) are visible only to the player who
added them until someone approves them. Until 2026-09-25 there was no UI for that - approval was SQL on
production, and backlogs went unnoticed for months. The approval queue is the UI, for admins and community
moderators.

## Where

| Route | What |
|-------|------|
| `GET /admin/puzzle-approvals` (`admin_puzzle_approvals`) | Queue of pending puzzles (all unapproved, newest first), 50 per page. No "approved" tab by design - who approved what lives in `puzzle_moderation_decision` |
| `GET/POST /admin/puzzle-approvals/{puzzleId}` (`admin_puzzle_approval_detail`) | One puzzle: its data, similar puzzles in the catalogue, the approve form (posts back here - a refused form comes back with what was typed and the dropped photo, 422) |
| `POST …/{puzzleId}/merge` (`admin_merge_unapproved_puzzle`) | Files a merge request and opens the merge review (CSRF `merge-puzzle-{id}`) |
| `GET/POST /admin/puzzles/{puzzleId}/edit` (`admin_edit_puzzle`) | A moderator's direct edit of any puzzle (below) |
| `GET /admin/puzzles/{puzzleId}/history` (`admin_puzzle_history`) | The puzzle's history: every decision about it, read only (below) |

Access: `PUZZLE_MODERATION_ACCESS` (admins + moderators), the same capability as change and merge requests -
approving new puzzles is the same job of looking after the catalogue. The `access_control` rule
`^/admin/puzzle(s/|-((change|merge)-requests|approvals))` must stay above `^/admin`. Key menu → "Puzzle Approvals".

## A moderator has two choices - there is no reject

A newly added puzzle almost always carries somebody's solving time, so it can never simply be deleted.

1. **Approve**, correcting the record on the way - the moderators' record form (`ApprovePuzzleFormType`, sharing
   `PuzzleRecordFormType::addNamesEditor()` / `addRecordFields()` and the names card / field / image / note partials):
   every name in the names editor (main title, its language, other names with theirs - docs/features/puzzle-names/),
   pieces, EAN, brand code, **a new photo of the box** (drop area + crop, `FormPhotoStash`) and a note for the history;
   every changed field is marked *Your edit*, the summary lists the corrections. Saved by `PuzzleRecordUpdater` inside
   `ApprovePuzzleHandler` (the file gets the SEO name of the *final* brand). EAN and brand code may hold several
   comma-separated codes - never reduce such a list.
2. **Merge** into the puzzle it duplicates. "Is it already in the catalogue?" shows what a search found - **similar
   puzzles, not duplicates**: any shared EAN (the `e:` lines of the search keys, leading zeros aside -
   `custom_puzzle_search_codes_trgm`), or the same piece count and a similar name - every name of the new puzzle
   against every name of the other, main titles and other names alike (trigram; the main titles through
   `custom_puzzle_name_trgm`), ~20 ms on production data. One title is printed by many brands (prod 2026-10-04:
   Pintoo's "Tropical Paradise" 500 matched five other brands' "Tropical Paradise" 500), so each candidate says how
   likely it is (`PuzzleDuplicateCandidate::likelihood()`): shared EAN = very likely, same brand (or a brand the new
   brand probably duplicates, `brandSuggestions()`) = compare the box, another brand = usually a different puzzle -
   those are folded away in a `<details>`. Each has "It's the same puzzle - merge", plus a field to paste any
   puzzle's address or id. Merging files a
   `SubmitPuzzleMergeRequest` with the moderator as reporter and redirects to the **existing merge review**
   (`?return=` back to the queue), where survivor, name, codes and image are settled and every solving time,
   collection item, listing, etc. moves over (`ApprovePuzzleMergeRequestHandler`, audited in `puzzle_merge_audit`).
   - The survivor **inherits approval**: if any merged puzzle was approved, the merged result is approved too -
     an unapproved duplicate with more times can survive without pulling the approved puzzle out of the catalogue.
   - A moderator is not notified about their own merge request.

## Brands

`AddPuzzleHandler` creates a new, unapproved `Manufacturer` whenever the brand field is not an existing id, and
other players' unapproved brands are hidden in the picker - so players create the same brand again and again
("Lluneta Puzzles" ×7 in September 2026). For a puzzle whose brand is new, the approve form asks what it is
(`PuzzleApprovalBrandChoice`):

| Choice | Effect |
|--------|--------|
| `merge_into` | The new brand duplicates an approved one: **all** its puzzles and the change requests proposing it move to the picked brand, what only the duplicate had (logo, EAN prefix) is kept, the duplicate is deleted |
| `use_existing` | Only this puzzle moves to the picked brand; the new brand stays (unapproved) |
| `approve` | It is a genuine new brand - approve it |

For an already approved brand, changing the brand select moves the puzzle (`use_existing`), otherwise `keep`.
Suggestions (`GetPuzzleApprovals::brandSuggestions`) = approved brands with a similar name or an EAN company
prefix (`manufacturer.ean_prefix`) matching the puzzle's EAN - the stronger signal. Only an **unapproved** brand
can be merged away here, and only into an **approved** one. The merge itself is `ManufacturerMerger`, shared with the
internal API's brand merge (any brand into any brand) - it also keeps the merged slug as a 301 redirect. A puzzle and a
change-request proposal are the only references to a brand; a new foreign key to `manufacturer` must be moved in
`ManufacturerMerger` too. Why brands get duplicated and how they are cleaned up: [`brand-duplicates.md`](brand-duplicates.md).

## Change requests - the review form

A player's "Suggest a change" (`PuzzleChangeRequest`) is reviewed at `/admin/puzzle-change-requests/{id}`
(`PuzzleChangeRequestDetailController`, GET + POST - a refused form comes back with what the reviewer typed, 422).
A pending request is approved through a form holding **the whole puzzle**: name, alternative name, brand, pieces,
EAN, brand code and image - every field editable, whether the player proposed it or not. The form is the puzzle's
record form (`PuzzleRecordFormType` + `PuzzleRecordFormData`; partials `admin/_puzzle_record_fields` / `_image` (carries
`image-editor` itself) / `_note`),
shared with the direct edit below.

- **Prefilled**: the proposed value where the player proposed a change, the puzzle as it is now everywhere else
  (`PuzzleRecordFormData::fromChangeRequest()`). "Current" is the live puzzle; when it changed since the proposal,
  the value at the time is shown too.
- **The proposal never mixes with the reviewer's edits**: a proposed field shows *Current* and *Proposed by the player*
  next to the input, and `puzzle_record_controller.js` marks every field live - *Proposed* (orange, the
  proposal goes in), *Your edit* (indigo `accent` - the theme's primary is too close to orange), *Keeping current*
  (the proposal is struck through) - with "Use proposed" / "Keep current" / "Undo my edit" links. Above the approve
  button a summary lists what approving saves and which proposals are not applied.
- **Image**: keep current / the proposed image (radios only when an image was proposed; thumbnails open in the
  lightbox), and a drop area (`file-drop-area`, cropping via `image-editor` like the add form). A dropped photo is used
  automatically (`PuzzleImageChoice::Upload`, `PuzzleRecordFormData::toValues()`) - picking keep / proposed again
  drops it. The field is `puzzlePhoto` so `FormPhotoStash` keeps it on a refused submit. The file gets the SEO name
  built from the *final* brand, name and pieces.
- The EAN goes through `EanList` like the add form (codes the puzzle already carries pass).
- An optional **note for the history** goes into the decision log.

`ApprovePuzzleChangeRequest` carries the reviewer's values as `PuzzleRecordValues`; the internal API still sends
`selectedFields` (those fields as proposed, the rest unchanged), which the handler turns into the same values. Both
are saved by `PuzzleRecordUpdater` - **the one place that writes a puzzle's record** for an approval and a direct edit:
first the record version the form was loaded with is compared with the puzzle (`PuzzleRecordVersion` - a hidden field
on the edit and approval forms; a save over a record somebody changed meanwhile is refused with
`PuzzleChangedMeanwhile`, 422, and the form comes back with what was typed), then everything is validated
(`InvalidPuzzleValues`, 422), then applied, and it returns the `before` / `after` snapshots the decision log keeps (+ the
image choice, + `selectedFields` from the internal API). Every message changing a puzzle's record is
`SerializedByLock` on `puzzle-<id>` (`PuzzleRecordVersion::lockKey()`), so two saves never check at the same moment.

## Editing a puzzle directly

Admins and moderators change any puzzle without the change request round: "Edit puzzle" on the puzzle page (a button
row under the actions + the ⋯ menu, `is_granted('PUZZLE_MODERATION_ACCESS')`; labels in all 6 locales) opens
`/admin/puzzles/{id}/edit` (`EditPuzzleController`). The same record form as the review, without a proposal, with every
name in the names editor (`PuzzleRecordFormType::addNamesEditor()`, `templates/puzzle/_names_editor.html.twig`) - every
changed field is marked *Your edit*,
the summary lists what saving changes, an optional note explains why. Pending
proposals for the puzzle are listed above the form (a fix someone proposed is better approved - it tells them).
`EditPuzzle` → `EditPuzzleHandler` → `PuzzleRecordUpdater`, logged as `puzzle_edited` with before/after; an edit that
changes nothing records nothing. Success redirects to `?return=` (the puzzle page). Works for unapproved and hidden
puzzles too (`GetPuzzleRecord` hides nothing).

## Merge requests - the review

`/admin/puzzle-merge-requests/{id}` shows the reported puzzles side by side (lightbox images, what differs between
them highlighted, solving times, a History link each) and lets the moderator pick **which one keeps its address**
("Keep this one", default = most solving times). The names are the names editor (docs/features/puzzle-names/),
started from every name of all the puzzles (`PuzzleMergeNames`: the survivor's main title, each other main title in
the language the reporter gave it); brand / pieces come with one-click choices from the reported puzzles where they
differ (`merge_review_controller.js`); EAN and brand code are the union (never reduce the list). The summary above the
button says what approving keeps, deletes and moves. Brand picker = every brand (`allIncludingUnapproved()`,
autocomplete). Image: one of the reported puzzles' images (a choice when more of them have one), or a **new photo**
dropped in the same card - used instead of all of them (`ApprovePuzzleMergeRequest::$uploadedImage`, stored by
`PuzzleImageStorage` like the record form's upload, named after the merged brand/name/pieces; cropped with
image-editor). Optional note → `ApprovePuzzleMergeRequest::$decisionNote`. The form (`PuzzleMergeReviewFormType`)
posts back to the page (422 keeps what was typed, the dropped photo too - `FormPhotoStash`) and carries every puzzle's
record version - a puzzle saved in between refuses the merge. The approval queue's "merge" can say which language the new puzzle's name is in.

## A puzzle's history

`/admin/puzzles/{id}/history` (`PuzzleHistoryController`, `GetPuzzleHistory`) - who changed, approved or merged what,
and when, newest first, **read only** (there is no write path to the log). Built from:

- every `puzzle_moderation_decision` row of the puzzle, plus the merge that folded it into another puzzle
  (`details.mergedPuzzleIds`) - so a merged-away puzzle keeps a history page, named as the log last saw it;
- before/after tables from `details.before/after` (edits, change requests approved since 2026-10-04, approvals),
  a merge's survivor before/after + what moved (`puzzle_merge_audit`), the proposal of older change requests
  (`appliedFields` when the internal API recorded them, else "not recorded back then");
- change requests decided without a log row - EANs written straight from a barcode scan (`LinkEanToPuzzleHandler`
  approves its own change request as the record);
- "Added by" from the puzzle row.

Linked from the puzzle page, the edit page, the change request page and every puzzle of a merge review.

## Who decided - `puzzle_moderation_decision`

Jan: "we must always know who approved/rejected merge request and change request and same for the approvals".
`reviewed_by_id` on the requests is not enough: it is `SET NULL` when the reviewer's player is deleted, and a
change request is deleted outright with its reporter (`ON DELETE CASCADE`).

`PuzzleModerationDecision` is an append-only log, one row per decision, written by
`PuzzleModerationDecisionRecorder` inside the deciding handler (commits or rolls back with it):

| Action (`PuzzleModerationAction`) | Written by |
|--------|-----------|
| `change_request_approved` / `change_request_rejected` | `ApprovePuzzleChangeRequestHandler` / `RejectPuzzleChangeRequestHandler` |
| `merge_request_approved` / `merge_request_rejected` | `ApprovePuzzleMergeRequestHandler` / `RejectPuzzleMergeRequestHandler` |
| `puzzle_approved`, `brand_approved`, `brand_merged` | `ApprovePuzzleHandler` |
| `puzzle_edited` | `EditPuzzleHandler` (a moderator's direct edit) |

- **No foreign keys**, on purpose: requests, puzzles and brands get deleted (merges, cascades) and so do players.
  The decider's id, name and code are copied in; the puzzle's name too.
- `source` = `admin_ui` / `internal_api` (`MergeDecisionSource`; the internal API sets it for merge approve + reject).
- `note` = rejection reason / the optional note of the approve and edit forms; `details` (JSON) = what changed (before/after, image choice, selected fields,
  merged ids, brand merge counts).
- Backfilled by migration `Version20260925165131` from every change / merge request decided before it existed
  (`details.backfilled = true`; a merge rejected via the internal API before the log existed shows `admin_ui`,
  since nothing recorded it).
- `puzzle.approved_at` / `puzzle.approved_by_id` also exist for convenience on the puzzle itself (`SET NULL` on
  player deletion) - the log is the durable record. Puzzles approved by SQL before the queue have both null.

## Tests

`tests/MessageHandler/ApprovePuzzleHandlerTest.php` (corrections, the three brand choices, refusals change nothing),
`tests/MessageHandler/PuzzleModerationDecisionLogTest.php`, the survivor-approval case in
`ApprovePuzzleMergeRequestHandlerTest`, `tests/Query/GetPuzzleApprovalsTest.php`, and the approve + merge flows as a
moderator in `tests/Controller/Admin/ModeratorAccessTest.php`. Direct edit + history: `EditPuzzleHandlerTest`,
`tests/Query/GetPuzzleHistoryTest.php`, `tests/Controller/Admin/EditPuzzleControllerTest.php`; merge review page:
`PuzzleMergeRequestControllerTest`.
