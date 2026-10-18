# Puzzle tags - picture tags on the server

Status: **built 2026-10-18 (Insights stream 5a)**, stored, not shown anywhere public (README §3 "Stored, not shown").
Plan: [`implementation-plan.md`](./implementation-plan.md) §5.5. The taxonomy itself, the analyzers and the `tags`
payload: [`image-taxonomy.md`](./image-taxonomy.md) §10, §11.2, §14, Appendix A. The pipeline that pushes `tags`:
[`image-pipeline.md`](./image-pipeline.md) §7.

## What it is

The image pipeline on Jan's Macs pushes one `tags` analysis per picture (the fusion of every raw analysis, rules in
`tools/puzzle-vision/puzzle_vision/derive/tags.py`):

```json
{"taxonomy": "1.0.0",
 "assigned": {"trait.large_uniform_areas": {"s": 0.91, "src": ["traits"]}, "style.photo": {"s": 0.6, "src": ["describe"], "uncertain": true}, …},
 "candidates": {…}, "skills": {"skill.shape_sorting": 0.62, …}, "traits": {"trait.large_uniform_areas": 0.34, …}, "entities": {…}}
```

The app turns the `assigned` map into rows a page can query: **`puzzle_tag`** (puzzle, `tag_key`, `score`,
`uncertain`, `source` = `analysis` / `correction`, `analysis_version`, `taxonomy_version`, `updated_at`; primary key
puzzle + key, index on `tag_key` for later filters). `candidates`, `skills`, the numeric `traits` and `entities` stay in
the analysis (`GetPuzzleImageAnalyses`) - Insights reads numbers from there.

## The registry (`PuzzleTaxonomy`)

`src/Value/PuzzleTaxonomy.php` knows the taxonomy version (`VERSION`, the pipeline's `TAXONOMY_VERSION`), what a
well-formed key is (`<facet>.<key>` of a `PuzzleTagFacet`, ≤ 100 characters - `isWellFormedKey()`, the same check in
SQL for the writer) and the keys a page shows with their labels (`definitions()`, `inGroup()`). Every assigned key is
stored, known to the registry or not; the registry only decides what gets a label.

Only one group is labelled so far - **`PuzzleTagGroup::ImageTraits`**, what Insights shows first:

| Key | Label (`messages`, all 6 locales) |
|---|---|
| `trait.large_uniform_areas` | `puzzle_tags.trait.large_uniform_areas` - Large single-colour areas |
| `trait.smooth_gradients` | `puzzle_tags.trait.smooth_gradients` - Smooth colour gradients |
| `trait.busy_detail` | `puzzle_tags.trait.busy_detail` - Busy, lots of detail |
| `trait.collage_grid` | `puzzle_tags.trait.collage_grid` - Collage of many small pictures |
| `trait.text_heavy` | `puzzle_tags.trait.text_heavy` - Lots of text |
| `trait.dark` | `puzzle_tags.trait.dark` - Dark picture |
| `style.photo`, `style.photo_aerial`, `style.photo_macro`, `style.photomosaic` | `puzzle_tags.look.photo` - Photo |
| the other 12 `style.*` keys | `puzzle_tags.look.drawn` - Illustration or painting |

"Photo vs illustration" is the `style` facet read through `PuzzleTaxonomy::isPhoto()`; a style's own label comes when a
page shows styles. Another group = a `PuzzleTagGroup` case, its definitions and their labels in all 6 locales
(`PuzzleTaxonomyTest` checks every label key in every locale). Keys are never renamed; a taxonomy bump on the Mac makes
every `tags` row stale and the pipeline re-derives them - the writer picks them up with its next run.

## The writer (`PuzzleTagWriter`)

`myspeedpuzzling:refresh-puzzle-tags` → `RefreshPuzzleTags` → `PuzzleTagWriter` (set-based SQL on purpose: ~41k
puzzles × ~15 tags is a bulk derivation, not something to load row by row).

- A puzzle's tags = the keys **`assigned`** by the `tags` analysis of its **current** picture (the stored
  `image_fingerprint` equals `PuzzleImageFingerprint` of `puzzle.image`), **minus** the keys a correction removes,
  **plus** the keys a correction adds. An analysis of an older picture counts for nothing. Keys that are not
  well-formed are skipped; an `assigned` that is no JSON object (`null`, or `[]` - PHP stores an empty map as a list)
  is no tags.
- **Watermark per puzzle**: `puzzle_tag_state` holds the signatures the rows were last written from - md5 of the
  analysis (version, taxonomy, assigned map) and md5 of the corrections (key + verdict). A run rewrites only the
  puzzles whose current signatures differ: a new push, a new picture, new or removed corrections, a puzzle turning
  secret or revealed. Content signatures, not timestamps: the stored timestamps have whole seconds, a content change
  within a second would slip through. A second run in a row changes nothing (idempotent); rows are updated only where
  a value differs, so `updated_at` says when a tag really changed.
- One statement per 1,000 puzzles writes the tag rows (delete what is no longer wanted, upsert the rest) and the
  watermark together.
- `--full` rewrites every puzzle with an analysis, corrections or tags - after a change of the writer's rules.
- **Secret puzzles** (`hide_until` / `hide_image_until` ahead) get no rows; a puzzle turning secret loses its rows with
  the next run, a revealed one gets them back. Readers still check both columns (`GetPuzzleTags` does): between the
  moment a puzzle turns secret and the next run, its rows exist. The pipeline never analyses a hidden picture anyway
  (the image API refuses them), so this covers only a puzzle hidden after its analysis.
- A merge deletes the merged puzzle's rows (cascade); the survivor keeps its own (`PuzzleMergeForeignKeyCoverageTest`).

**Cron (lily.srv)**, daily after the M3's nightly pass (01:30 Prague, `--hours 6`, derive at its end):

```
40 7 * * * docker compose exec web php bin/console myspeedpuzzling:refresh-puzzle-tags
```

Cheap when nothing changed (one statement comparing signatures); safe to run more often.

## Corrections (`puzzle_tag_correction`)

An admin's or a moderator's word on one tag of one puzzle (README §3 "Corrections": puzzle-moderation access only):
`verdict` `add` (the puzzle has the tag - on an assigned key a confirmation; the analysis' score is kept) or `remove`
(it does not), `note`, `decided_by` (null once that account is deleted), `decided_at`, `taxonomy_version`. One per
puzzle and key; a new word replaces it, removing it gives the key back to the analysis. Its own data, never an edit of
an analysis - so it survives every re-run.

- **Internal API first** (no web UI yet): `GET /internal-api/puzzles/{id}/tags`, `PUT` / `DELETE
  /internal-api/puzzles/{id}/tag-corrections/{tagKey}` - [`internal-api.md`](../internal-api.md#puzzle-tags). A write
  rewrites that puzzle's tags at once.
- Messages `CorrectPuzzleTag` / `RemovePuzzleTagCorrection` (a secret puzzle is a 404, a key of no facet a 422).
- Not a catalogue decision: no `puzzle_moderation_decision` row and no karma (yet - see "Later").

## Reading

`GetPuzzleTags::forPuzzles($ids, PuzzleTagGroup::ImageTraits)` - one statement for many puzzles, hidden pictures left
out; `ofPuzzle()` / `correctionsOf()` for one. Labels: `PuzzleTaxonomy::definition($key)?->labelKey`.

## Later

- A moderator UI for corrections (puzzle page / admin) and karma for them (taxonomy §14: corrections are
  contributions), bulk corrections from a spot-check sheet.
- Corrections feeding the per-key threshold calibration (taxonomy §14).
- Public surfaces (filters, theme pages) one at a time - each needs its keys' labels and moderated precision first.
