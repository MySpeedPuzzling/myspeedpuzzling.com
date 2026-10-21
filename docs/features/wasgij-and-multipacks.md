# Wasgij and multipacks - two concepts the catalogue does not know yet

Status: **problem statement, nothing decided** (2026-10-22). Written after an accidental merge (below).

These are **two separate concepts**, each with its own problem, and they can **combine in one box**:

- **Wasgij** is about **one puzzle**: its picture is not printed on the box (spoiler).
- **Multipack** is about **one box**: it holds several puzzles that share the box's name, EAN and brand code.

A box can be a multipack without any Wasgij (2×500, advent calendars), a Wasgij without being a multipack (most
Wasgij boxes: one puzzle), or **both** - one box with a normal puzzle of the box image and a Wasgij puzzle. That
combined case is what got merged by accident. Whatever we build must keep them independent: "part of a multipack"
and "is a Wasgij" are two separate facts about a puzzle.

## The incident (2026-10-19, reverted 2026-10-21)

Merge request `01a15444-3ca2-715e-b232-75eb208336b3` merged the two catalogue entries of the box
**Wasgij Christmas 10: The Mystery Shopper** (Jumbo, 1000 pieces) into one. The box contains **two puzzles**
(multipack), and **one of them is a Wasgij**:

| Puzzle | Image | Multipack | Wasgij |
|---|---|---|---|
| `0191be5e-22ec-71d0-9a97-494c10875689` | `jumbo-wasgij-christmas-10-the-mystery-shopper-1000.jpg` | puzzle 1 of the box: the **box image** (shoppers staring at something) | no - an ordinary puzzle, its picture is on the box |
| `0191be5d-222b-72b9-8405-b6b5b0ca8de3` | `jumbo-wasgij-christmas-10-the-mystery-shopper-solution-1000.jpg` | puzzle 2 of the box | **yes** - what the shoppers see, not printed on the box |

Both were added by the same player a minute apart on 2024-09-04, each with her own time (2:53:00 and 4:34:00). Same
name, same brand, same piece count, no codes - to the reporter and the moderator they were one puzzle added twice.
The merge deleted the Wasgij entry, moved its time onto the box-image entry, and the player got two first tries on
one puzzle. She resolved that warning on the site herself by deleting a time on another such pair - it left no trace
(first-try conflicts are computed live, never stored), so we do not know which puzzle it was.

**Revert (by hand, there is no feature yet):** the puzzle row re-inserted from the 2026-10-19 04:30 UTC backup (the
merge audit snapshot does not hold every column), the moved solving time put back, the `puzzle_redirect` row deleted,
the request set back to pending and rejected through the internal API (the reporter got the "rejected" notification;
the "approved" one was deleted), then statistics, karma, XP and badges recalculated. The `puzzle_merge_audit` and the
`merge_request_approved` decision stay as history. Afterwards the four entries of Christmas 10 and Christmas 16 (the
same combined box, never merged) got name proposals "… - box image" / "… - non box image".

## Concept 1: Wasgij - the puzzle is not the picture on the box

A Wasgij box shows a scene of people reacting to something; the puzzle is **what they see**, which is not printed
anywhere. Lines: Original, Mystery, Destiny, Retro, Christmas (also advent calendars). This is a property of **a
puzzle**, whatever box it comes in. Consequences:

- **Which picture is "the puzzle's image"?** The box image is what a player recognises on the shelf; the solution
  image is what was actually assembled. The catalogue holds both kinds today, chosen by whoever added it.
- **Spoiler.** Showing the solution image on the puzzle page, in search, in lists, in a friend's activity or a
  leaderboard spoils the puzzle for anybody who has not solved it yet - the whole point of a Wasgij is not knowing
  the picture. Today nothing can hide it except `hide_image_until`, which is meant for secret competition puzzles.
- **Times are not comparable** with a puzzle solved from its picture - a Wasgij is solved without a reference.

## Concept 2: multipack - one box, several puzzles

One product (one EAN, one brand code, one name on the box) contains several puzzles: "2×500", "4×1000", advent
calendars (24 small puzzles), a box image + Wasgij set. This is a property of **a box**. Each puzzle is solved and
timed on its own, so each needs its own catalogue entry - but the entries then share **name, brand, EAN and brand
code** and differ only in the picture (and sometimes the piece count). Consequences:

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

## When both meet in one box

The combined box makes each problem worse: the two entries share everything a duplicate check looks at (multipack),
and the one difference - the picture - is exactly what the Wasgij entry may want to hide (spoiler). If the Wasgij
entry shows the box image to avoid the spoiler, the two entries become indistinguishable even by picture. So the
multipack grouping (with a label per puzzle) is what has to tell them apart, not the image.

## What needs deciding

1. **Multipack** (box level): a "box" grouping several puzzles (shared EAN/brand code/name on the box), each puzzle
   with its own label within the box ("Box image", "Day 7", "Puzzle 2 of 4") - or only a marker on each puzzle
   ("part of a multipack, label X")? Where does the shared EAN live?
2. **Duplicate checks aware of multipacks**: puzzles of the same box are never suggested as duplicates, the merge
   review warns (or refuses) when the candidates are parts of one box, the approval queue does not offer merging them.
3. **Wasgij** (puzzle level, independent of 1.): mark a puzzle as a Wasgij (its picture is not on the box); decide
   which picture is shown by default and how the solution picture is revealed (after the player's own result? a
   click? never in lists/feeds?). Ties into the image pipeline's `special` tag "Wasgij" (`image-taxonomy.md`).
4. **Naming convention meanwhile**: until 1. exists, one suffix everybody uses - proposed "… - box image" /
   "… - non box image" for box image + Wasgij sets; something analogous for numbered multipacks. Moderators' guide
   gets a line.
5. **Cleanup**: find existing multipack entries (same brand + name + pieces, different picture; Wasgij Christmas sets
   first) and label them; check the past Wasgij/advent merges for wrongly merged puzzles of one box.

Related: the admin-only "Revert merge" feature (`docs/TODO.md`) - this incident is why it is needed.
