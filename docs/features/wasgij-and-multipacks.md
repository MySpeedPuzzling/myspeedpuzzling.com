# Wasgij and multipacks - two concepts the catalogue does not know yet

Status: **problem statement, nothing decided** (2026-10-22). Written after an accidental merge (below) showed that
the catalogue has no way to say "these are two different puzzles in one box", so they look like duplicates to
everybody - players, moderators, the duplicate signals and the merge review.

## The incident (2026-10-19, reverted 2026-10-21)

Merge request `01a15444-3ca2-715e-b232-75eb208336b3` merged the two catalogue entries of
**Wasgij Christmas 10: The Mystery Shopper** (Jumbo, 1000 pieces) into one:

| Puzzle | Image | What it is |
|---|---|---|
| `0191be5e-22ec-71d0-9a97-494c10875689` | `jumbo-wasgij-christmas-10-the-mystery-shopper-1000.jpg` | the **box image** - shoppers staring at something |
| `0191be5d-222b-72b9-8405-b6b5b0ca8de3` | `jumbo-wasgij-christmas-10-the-mystery-shopper-solution-1000.jpg` | the **Wasgij puzzle** - what they are staring at (not printed on the box) |

Both were added by the same player a minute apart on 2024-09-04, each with her own time (2:53:00 and 4:34:00). Same
name, same brand, same piece count, no codes - to the reporter and the moderator they were one puzzle added twice.
The merge deleted the solution entry, moved its time onto the box entry, and the player got two first tries on one
puzzle. She resolved that warning on the site herself by deleting a time on another such pair - it left no trace
(first-try conflicts are computed live, never stored), so we do not know which puzzle it was.

**Revert (by hand, there is no feature yet):** the puzzle row re-inserted from the 2026-10-19 04:30 UTC backup (the
merge audit snapshot does not hold every column), the moved solving time put back, the `puzzle_redirect` row deleted,
the request set back to pending and rejected through the internal API (the reporter got the "rejected" notification;
the "approved" one was deleted), then statistics, karma, XP and badges recalculated. The `puzzle_merge_audit` and the
`merge_request_approved` decision stay as history. Afterwards the four entries of Christmas 10 and Christmas 16 (the
same pattern, never merged) got name proposals "… - box image" / "… - non box image".

## Problem 1: Wasgij - the puzzle is not the picture on the box

A Wasgij box shows a scene of people reacting to something; the puzzle in the box is **what they see**, which is not
printed anywhere. Lines: Original, Mystery, Destiny, Retro, Christmas (also advent calendars). Consequences:

- **Which picture is "the puzzle's image"?** The box image is what a player recognises on the shelf and scans; the
  solution image is what was actually assembled. The catalogue holds both kinds today, chosen by whoever added it.
- **Spoiler.** Showing the solution image on the puzzle page, in search, in lists, in a friend's activity or a
  leaderboard spoils the puzzle for anybody who has not solved it yet - the whole point of a Wasgij is not knowing
  the picture. Today nothing can hide it except `hide_image_until`, which is meant for secret competition puzzles.
- **Box-image puzzles exist too.** Some Wasgij boxes (Christmas sets) contain a second puzzle of the box image -
  then the box image *is* a puzzle and both entries are real, different puzzles (see Problem 2).
- **Times are not comparable** across the two: the Wasgij puzzle is solved without a reference picture.

## Problem 2: multipacks - one box, several puzzles

One product (one EAN, one brand code, one name on the box) contains several puzzles: "2×500", "4×1000", advent
calendars (24 small puzzles), Wasgij Christmas sets (box image + Wasgij puzzle). Each puzzle is solved and timed on
its own, so each needs its own catalogue entry - but the entries then share **name, brand, EAN and brand code** and
differ only in the picture (and sometimes the piece count). Consequences:

- **They look like duplicates** to every duplicate check: the merge review, `duplicate_puzzle_signal`, the approval
  queue's "merge into the duplicate", players reporting duplicates. Nothing tells the reviewer "same box, different
  puzzle", so an honest review merges them - exactly what happened above.
- **Names are made up ad hoc** to tell them apart. Found in the catalogue (2026-10-22): "… Box image" /
  "… Non box image", "… - box image" / "… - non box image", "… (Box Image)", "… - Box image puzzle", day numbers for
  advent calendars (which do not even agree between entries - see the six "Look out Below!" merges of 2026-10-16,
  Day 1 → Day 19 etc., worth a second look).
- **Shared EANs are already tolerated** in places: multiscan lets a code several puzzles share be scanned once per
  puzzle (`docs/features/multiscan/README.md`), the add form accepts re-used EANs, `box_text` reads "2×500"
  (`docs/features/insights/image-taxonomy.md`). But nothing stores that the puzzles belong together.

## What needs deciding

1. **A multipack as a thing**: a "box" grouping several puzzles (shared EAN/brand code/name on the box), each puzzle
   with its own label within the box ("Box image", "Wasgij puzzle", "Day 7", "Puzzle 2 of 4") - or only a marker on
   each puzzle ("part of a multipack, label X")? Where does the shared EAN live?
2. **Duplicate checks aware of it**: puzzles of the same multipack are never suggested as duplicates, the merge review
   warns (or refuses) when the candidates are parts of one box, the approval queue does not offer merging them.
3. **Wasgij flag + spoiler handling**: mark a puzzle as a Wasgij (the image is not the puzzle); decide which picture is
   shown by default (box image) and how the solution picture is revealed (after the player's own result? a click?
   never in lists/feeds?). Ties into the image pipeline's `special` tag "Wasgij" (`image-taxonomy.md`).
4. **Naming convention meanwhile**: until 1. exists, one suffix everybody uses - proposed "… - box image" /
   "… - non box image" for Wasgij pairs; something analogous for numbered multipacks. Moderators' guide gets a line.
5. **Cleanup**: find existing pairs (same brand + name + pieces, different picture; Wasgij lines first) and label them;
   check the past Wasgij/advent merges for wrongly merged pairs.

Related: the admin-only "Revert merge" feature (`docs/TODO.md`) - this incident is why it is needed.
