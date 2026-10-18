---
name: image-pipeline-add-analyzer
description: Checklist for adding a new image analyzer to the MySpeedPuzzling pipeline or bumping an analyzer's version (model, prompt, formula or taxonomy change) - enum case, payload schema docs, profiles, requires, what re-runs, what derives. Use when the user says "/image-pipeline-add-analyzer", "add an analyzer", "bump the describe version", "new model for embeddings", "change the taxonomy".
argument-hint: "<analyzer name or the change, e.g. 'bump artwork to 1.1.0'>"
allowed-tools: Bash, Read, Edit, Write, Grep, Glob
---

# Image pipeline - add an analyzer / bump a version

A change to application code goes through a branch + PR (CLAUDE.md rules). Runbook §4 explains versions.

## A new analyzer

1. **App**: a case in `src/Value/ImageAnalyzer.php` (+ its own `maxResultBytes()` if payloads exceed 64 KB) and the
   enum list in `docs/features/internal-api.openapi.yaml`; `tests/Value/ImageAnalyzerTest.php`. Deploy before any Mac
   pushes it (the API answers `422` for an unknown analyzer).
2. **Docs**: payload schema in `docs/features/insights/image-taxonomy.md` §11 (raw §11.1 / derived §11.2), the
   analyzer list in `docs/features/internal-api.md` § "Puzzle image analysis", the dependency table in the runbook §1
   and plan §1.2.
3. **Pipeline** (`tools/puzzle-vision/`):
   - `puzzle_vision/analyzers.py`: `RAW` or `DERIVED`, `INFO` (requires, unit gpu/ane/cpu, full/crop, model repos) or
     `DERIVED_INPUTS`, `M3_SECONDS_PER_IMAGE` once measured.
   - Raw: a `Stage` in `puzzle_vision/stages/` (heavy imports inside `load()` / the functions, never at module level;
     a pure payload builder function next to it) and its entry in `stages/__init__.py` `STAGES`. Derived: a module in
     `puzzle_vision/derive/` + `Deriver` + `ORDER`.
   - Profiles: `[analyzers.<name>]` with `version`, `requires` (must equal `analyzers.REQUIRES`), `claim_limit`,
     `seconds_per_image` (`workers` for CPU) in the profile of the machine that runs it, the same version in the other
     profile if it declares it; put it in a phase (Apple Vision never as a sidecar or next to a GPU stage; CPU-only in
     `sidecar`). Derived: `[derived.versions]` in both profiles.
   - Tests: a payload-shape test in `tests/test_payloads.py` (the coverage test fails until there is one) and
     `tests/test_profiles.py` if the split changes. `uv run pytest` must be green.
4. `setup-check` lists new model repos automatically (from `INFO`); add their sizes to `checks.MODEL_SIZES_GB` and
   the runbook §2 table. Licence: Apache-2.0 / MIT only (README §3 "Image intelligence").

## A version bump

- Version string `<major>.<minor>.<patch>+<model short id>` (derived: `@tax<TAXONOMY_VERSION>`); ≤ 50 characters.
  Major = meaning/schema changed, minor = new optional fields, patch = a fix changing values; a new model = a new
  `+suffix`.
- Change it in **both** profiles in one commit (`test_one_version_per_analyzer_across_machines` pins it), merge, then
  `git pull` on **both** Macs before either runs again - an older version on one machine would overwrite newer rows.
- What re-runs: every row of that analyzer becomes stale and is re-claimed. **`artwork` moves crops**: bump every
  crop-based analyzer with it (`colors`, `traits`, `segmentation`, `embed_dinov2`, `embed_siglip2`, `vision_labels`,
  `describe`) when crops change; `ocr`, `barcode`, `embed_siglip2_full` do not depend on it.
- What derives: a raw bump that feeds derived analyzers (`ocr`/`barcode` → `box_text`; embeddings → `zeroshot`,
  `neighbours`; most → `tags`) - run `/image-pipeline-derive` after the re-run. A taxonomy / prompt bank change =
  bump `TAXONOMY_VERSION` / `PROMPT_BANK_VERSION` in `derive/taxonomy.py` and the `zeroshot` / `tags` versions.
- `describe` (5 days of M3 time): decide with Jan first; after a bump the M1 must pass `verify --describe-parity` again.
