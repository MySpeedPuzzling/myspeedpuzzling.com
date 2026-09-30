# First-try integrity

GitHub #217 · feature request `019d4e56-2fec-7164-bb6a-cf3738ae9bea` ("First Try" Tag Checks).

A result's `first_attempt` tag ("1st try") feeds statistics and every insight (difficulty, baseline, skill, MSP
rating), so it must mean what it says. Before this feature nothing checked it: on 2026-09-30 production had
**3,170 (player, puzzle) pairs with more than one first try** (1,084 players; 2,040 of them a solo + a pair/team
mix) and 1,268 first tries logged a day after an earlier solve of the same player.

## The rules

- **One first try per person per puzzle** - across their solo results and every pair/team result they are a member
  of. "Person" = registered player: guests are a name, not someone we can tell apart, and are left out of every rule.
- **A pair/team result is a first try only if it is everybody's first try** - so a result is refused the tag when
  *anybody* in it already holds a first try of the puzzle.
- **Hard block**: someone in the result the player may see (see Privacy) already has another result of the puzzle
  marked as a first try.
  - The player is on every such result (their own solo, or a pair/team they are a member of) → they may
    **"Make this result my first try"**: the tag moves - the new result gets it, the old ones lose it, in the same save.
  - Any such result belongs only to a teammate → the only way on is saving without the tag. Nobody changes another
    player's own result from here.
- **Warning** (never blocks): someone in the result solved the puzzle on an **earlier day** without the tag.
  Compared by day (`COALESCE(finished_at, tracked_at)`) - dates are often typed in without a time, and old dates are
  sometimes wrong, so the player can always save as it is.
- **Tolerated legacy on edit**: an edit that neither ticks the tag newly nor adds a registered person is never
  blocked, even with duplicates - otherwise the 3,170 old pairs would stop people fixing a typo. The notice points to
  the conflicts page instead (and offers the move when the player may make it).

## Where it is enforced

One read model and one decision, used everywhere:

- `Query\GetFirstTryTimes` - who took part in which result of a puzzle (the tracker of a solo result, every
  `puzzling_team_member` of a pair/team one - the tracker is always a member, verified on prod 2026-09-30), plus
  the conflicts page's lists and the banner count.
- `Services\FirstTry\FirstTryAssessor` → `Value\FirstTryAssessment` (holds, warnings, `viewerCanMove`, `tolerated`,
  lines already cut down to what the viewer may be told). `FirstTryFormCheck` feeds it the form's co-puzzler inputs,
  read like `PuzzlersGrouping` reads them (`PuzzlersGrouping::registeredPlayerIds()`, nothing created).

Surfaces:

- **Add/edit time forms** (`PuzzleAddController`, `EditTimeController`): checked **before anything is dispatched**
  - a refused first try must not leave a newly added puzzle behind (a new puzzle has no history, so it is never
  checked). The choice travels as `first_try_resolution` (`''` | `move`) next to the Symfony form, like
  `group_players[]`. A block = root form error + 422 + the notice below the checkbox.
- **Live check**: `first_try_check_controller.js` asks `GET /{_locale}/first-try-check` (`FirstTryCheckController`)
  whenever the puzzle, date, co-puzzlers or the tag change, debounced and aborted on newer input. The endpoint
  renders the same partial the 422 renders (`templates/first_try/_notice.html.twig`), so both always say the same.
  The controller never submits, re-renders or navigates the form.
- **Handlers** (`AddPuzzleSolvingTimeHandler`, `EditPuzzleSolvingTimeHandler`): the authoritative guard, before any
  write (photo upload included), throwing `FirstTryAlreadyTaken`. Catches races and the API. With
  `firstTryResolution: MoveHere` they untick the old results after persisting the new one.
- **API v1** (`POST`/`PUT /api/v1/me/solving-times`): the processors turn `FirstTryAlreadyTaken` into a 422
  problem+json (`FirstTryConflictResponse`). The message names result ids only when they are the caller's own;
  otherwise "already recorded for a co-puzzler". Warnings do not exist in the API. Note: `PUT` still defaults
  `first_attempt` to `false` when omitted - unchanged.

Not enforced: Relax trackings (never carry the tag) and races between two simultaneous saves (both pass the check,
the conflicts page cleans it up - no unique index is possible while legacy duplicates exist).

## Notice variants

Rendered by `_notice.html.twig` from the assessment and the chosen resolution:

| | When | Actions |
|---|---|---|
| A | block, the player is on every held result | Make this result my first try · Remove first try from this result |
| B | block, a teammate's own result holds it | Remove first try from this result |
| C | the player chose to move it here | Undo |
| D | warning: somebody solved it on an earlier day | Remove first try from this result (saving as is stays possible) |
| E | edit of a tolerated old duplicate | Make this result my first try (if allowed) · link to the conflicts page |

## Privacy

**Hidden people are left out completely.** A teammate hidden from the player - private without the player on their
allow list (`PrivateProfileAccess::sqlIsPrivate()`, a block in either direction outranks the list) or blocked by
the player (`HiddenPlayers`) - is ignored by the assessment: their first tries and earlier solves neither block nor
warn, and nothing about them reaches the player - no line, no refusal, no API message (a refusal alone would tell
one bit). The rule still holds for them: if the save gives them a second first try, it shows up on **their own**
conflicts page and banner, where only they resolve it. Visible teammates are named with dates - their results are
public anyway.

In the player's *own* results (the notice's "Your pair result with …", the conflicts page) a hidden co-puzzler shows
as "a puzzler" - neither name nor code. A result the player took part in always counts, whoever else is in it.

Without a signed-in viewer (kernel tests, cron) nobody is hidden and everybody counts.

## Conflicts page + banner

- `/{_locale}/first-try-conflicts` (`FirstTryConflictsController`, English only, `noindex`):
  - **Conflicts**: every puzzle where the player holds 2+ first tries; each result with its "1st try" badge (so it is
    clear what was marked), the **oldest marked one preselected**, plus "None of these was my first try". An unmarked
    solve from a day before all of them is pointed out. One POST per puzzle → `ResolveFirstTryConflict` (re-reads the
    player's marked results; anything else is refused with `FirstTryConflictChanged` → "This has changed in the
    meantime").
  - **First try logged after an earlier solve** (collapsed, never drives the banner): the player's first tries with
    an earlier solve of **their own** - a teammate's history is theirs to review. "Remove first try"
    (`UnmarkFirstAttempt`) or "It's fine, hide this" (`DismissFirstTryReview` → `first_try_review_dismissal`, the only
    stored state of the feature). Puzzles with a conflict are left out until the conflict is resolved.
- **Banner** on the player's **own profile page only** when they have conflicts. Other viewers pay no query for it
  (guarded by `FirstTryPagesTest`).

Unticking goes through `PuzzleSolvingTime::unmarkFirstAttempt()`: `PuzzleSolvingTimeModified` (puzzle statistics +
incremental insights; MSP rating and derived metrics follow on the 15-minute cron) and, for a pair/team result,
`GroupSolvingTimeEdited` - the other members get the usual "… edited your time" notification. The stored prediction
of the result stays: it records what was predicted back then, and the result's own tag is not one of its inputs.

## Photos survive a refused form

Any refused add/edit submit (not only this feature's) used to lose the photos - browsers never re-send a file
input. Now (`Services\PhotoStash\PhotoStash` + `FormPhotoStash`):

1. Every photo that passed its own validation is kept in object storage under `tmp-uploads/<playerId>/<token>`
   (+ `.json` meta, + `.jpg` preview made with Imagick right away, so a HEIC shows in every browser). The token is
   128 random bits; a token only works under the prefix of the player it was made for.
2. The form comes back with the photo shown as itself in its drop area, "Your photo is still here" and **Remove**
   (`kept_photo_controller.js`); the token travels as `photo_stash[<field>]`.
3. On the next submit `FormPhotoStash::restore()` puts it back into the empty file input **before** `handleRequest()`,
   so the form validates it like a fresh upload. A newly chosen file always wins. A token that stopped working
   (expired, somebody else's) is said out loud as a field error, never saved without the photo silently.
4. After a successful save the kept files are deleted; `myspeedpuzzling:prune-photo-stash` (daily cron, lily.srv)
   removes whatever nobody came back for after `PhotoStash::KEEP_HOURS` (24 h). At most 6 kept photos per player.

The preview route `GET /{_locale}/photo-stash/{token}` answers only the owner (404 for anyone else), `private,
no-store`, `nosniff`.
