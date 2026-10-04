# Puzzle approvals

Newly added puzzles (`puzzle.approved = false`, set by `AddPuzzleHandler`) are visible only to the player who
added them until someone approves them. Until 2026-09-25 there was no UI for that - approval was SQL on
production, and backlogs went unnoticed for months. The approval queue is the UI, for admins and community
moderators.

## Where

| Route | What |
|-------|------|
| `GET /admin/puzzle-approvals` (`admin_puzzle_approvals`) | Queue of pending puzzles (all unapproved, newest first), 50 per page. No "approved" tab by design - who approved what lives in `puzzle_moderation_decision` |
| `GET /admin/puzzle-approvals/{puzzleId}` (`admin_puzzle_approval_detail`) | One puzzle: its data, likely duplicates, the approve form |
| `POST …/{puzzleId}/approve` (`admin_approve_puzzle`) | `ApprovePuzzle` (CSRF `approve-puzzle-{id}`) |
| `POST …/{puzzleId}/merge` (`admin_merge_unapproved_puzzle`) | Files a merge request and opens the merge review (CSRF `merge-puzzle-{id}`) |

Access: `PUZZLE_MODERATION_ACCESS` (admins + moderators), the same capability as change and merge requests -
approving new puzzles is the same job of looking after the catalogue. The `access_control` rule
`^/admin/puzzle-((change|merge)-requests|approvals)` must stay above `^/admin`. Key menu → "Puzzle Approvals".

## A moderator has two choices - there is no reject

A newly added puzzle almost always carries somebody's solving time, so it can never simply be deleted.

1. **Approve**, correcting name, pieces, EAN and brand code on the way (like a change request). EAN and brand
   code may hold several comma-separated codes - never reduce such a list.
2. **Merge** into the puzzle it duplicates. The detail page lists likely duplicates (any shared EAN, or the same
   piece count and a similar name - trigram, `custom_puzzle_name_trgm`, ~30 ms on production data) with a
   "Merge with this" button, plus a field to paste any puzzle's address or id. Merging files a
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
EAN, brand code and image - every field editable, whether the player proposed it or not
(`ReviewPuzzleChangeRequestFormType`).

- **Prefilled**: the proposed value where the player proposed a change, the puzzle as it is now everywhere else
  (`ReviewPuzzleChangeRequestFormData::prefilled()`). "Current" is the live puzzle; when it changed since the proposal,
  the value at the time is shown too.
- **The proposal never mixes with the reviewer's edits**: a proposed field shows *Current* and *Proposed by the player*
  next to the input, and `change_request_review_controller.js` marks every field live - *Proposed* (orange, the
  proposal goes in), *Your edit* (indigo `accent` - the theme's primary is too close to orange), *Keeping current*
  (the proposal is struck through) - with "Use proposed" / "Keep current" / "Undo my edit" links. Above the approve
  button a summary lists what approving saves and which proposals are not applied.
- **Image**: keep current / the proposed image (radios only when an image was proposed; thumbnails open in the
  lightbox), and a drop area (`file-drop-area`, cropping via `image-editor` like the add form). A dropped photo is used
  automatically (`PuzzleChangeRequestImageChoice::Upload`, `ReviewPuzzleChangeRequestFormData::imageChoice()`) - picking
  keep / proposed again drops it. The field is `puzzlePhoto` so `FormPhotoStash` keeps it on a refused submit. The file
  gets the SEO name built from the *final* brand, name and pieces.
- The EAN goes through `EanList` like the add form (codes the puzzle already carries pass).

`ApprovePuzzleChangeRequest` carries the reviewer's values as `ReviewedPuzzleValues`; the internal API still sends
`selectedFields` (those fields as proposed, the rest unchanged), which the handler turns into the same values - one
apply path. The decision log keeps `before` / `after` of every field and the image choice (+ `selectedFields` from
the internal API).

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

- **No foreign keys**, on purpose: requests, puzzles and brands get deleted (merges, cascades) and so do players.
  The decider's id, name and code are copied in; the puzzle's name too.
- `source` = `admin_ui` / `internal_api` (`MergeDecisionSource`; the internal API sets it for merge approve + reject).
- `note` = rejection reason / merge decision note; `details` (JSON) = what changed (before/after, image choice, selected fields,
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
moderator in `tests/Controller/Admin/ModeratorAccessTest.php`.
