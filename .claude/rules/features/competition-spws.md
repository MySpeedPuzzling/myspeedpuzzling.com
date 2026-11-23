---
paths:
  - "src/**/*Unboxed*"
  - "src/**/CompetitionRoundPuzzle.php"
  - "src/**/RoundPuzzleReveal.php"
  - "src/**/RoundPuzzlesVisibleNow.php"
  - "src/**/SecretPuzzleHides.php"
  - "templates/manage_round_puzzles.html.twig"
  - "src/**/EventWizard/**"
  - "src/**/*EventWizard*"
  - "templates/event_wizard/**"
  - "docs/features/competitions-management/spws-*.md"
---

# Competition toolkit - SPWS 1:1 demo (2026-11-24)

The Speed Puzzle World Series (Toronto 2027) made configurable 1:1. The rest of the toolkit: `competition-toolkit.md`
(+ `-v2.md`); the delivery record: `docs/features/competitions-management/toolkit/STATUS.md` "SPWS 1:1 demo".

- **Unboxed per puzzle**: `spws-unboxed-per-puzzle.md` (Review amendments binding) — `competition_round_puzzle.unboxed`
  next to the round switch `competition_round.unboxed`: effective = round OR row (`CompetitionRoundPuzzle::isUnboxed()`,
  `CompetitionRound::hasUnboxedPuzzles()`; `keepsPicturesUnboxed()` checks the first publish first), the moment stays the
  round's first publish. The one rule reads `(cr.unboxed OR crp.unboxed)`: SQL `RoundPuzzleReveal::sqlImageHidden()`,
  PHP `RoundPuzzlesVisibleNow`, site-wide `SecretPuzzleHides::hideOf()`, safety net
  `ResyncUnboxedRoundPicturesWhenPublished` (in its one statement) - never a picture rule of your own. Write:
  `ChangeRoundUnboxed(competitionId, roundId, unboxed, ?unboxedRoundPuzzleIds)` (null = row flags untouched, a list =
  exactly these rows, a foreign id → `CompetitionRoundPuzzleNotFound`, nothing changed = nothing written); round puzzles
  page choice No / Every puzzle / Only some (`unboxed=0|1|some` + `unboxed_puzzles[]`, "some" shown from 2 puzzles or
  while a row is flagged, confirm only when a held picture would come out), per-card "Unboxed" badge, hub "· Unboxed: N
  puzzles". A puzzle merge keeps the flag on the kept row. "Add to my profile" of an unboxed challenge opens the add form
  with "Solved without looking at the box image" ticked (`IsRoundPuzzleUnboxed`, only while the form opens). Duplicate
  copies only the round switch. Tests `UnboxedRoundTest`, `SecretPuzzleHidesTest`, `PuzzleAddOfficialEntryTest`.
