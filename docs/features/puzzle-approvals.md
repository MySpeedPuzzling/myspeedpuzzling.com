# Puzzle approvals

Newly added puzzles (`puzzle.approved = false`, set by `AddPuzzleHandler`) are visible only to the player who
added them until someone approves them. Until 2026-09-25 there was no UI for that - approval was SQL on
production, and backlogs went unnoticed for months. The approval queue is the UI, for admins and community
moderators.

## Where

| Route | What |
|-------|------|
| `GET /admin/puzzle-approvals` (`admin_puzzle_approvals`) | Queue. Tabs *Pending* (all unapproved puzzles, newest first) and *Approved* (from the moderation log), 50 per page |
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
can be merged away, and only into an **approved** one. A puzzle and a change-request proposal are the only
references to a brand; a new foreign key to `manufacturer` must be moved in `ApprovePuzzleHandler::mergeBrand()` too.

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
- `note` = rejection reason / merge decision note; `details` (JSON) = what changed (selected fields, before/after,
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
