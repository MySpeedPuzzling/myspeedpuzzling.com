# puzzle-vision

The MySpeedPuzzling image pipeline: analyses every puzzle box image on a Mac with self-hosted open models (Apache-2.0 /
MIT) and pushes the results through the internal API (`/internal-api/puzzle-image-*`). Production is the master copy;
this tool keeps only a temporary work state.

**Runbook (setup, the M3 Max / M1 Air split, daily mode, versions, troubleshooting):
[`docs/features/insights/image-pipeline.md`](../../docs/features/insights/image-pipeline.md).**
Claude Code skills: `/image-pipeline-setup`, `/image-pipeline-run`, `/image-pipeline-status`, `/image-pipeline-derive`,
`/image-pipeline-add-analyzer`, `/image-crop-review`.

```sh
cd tools/puzzle-vision
uv sync --extra ml                                  # the model stack (mlx, mlx-vlm, torch, transformers, pyobjc)
uv run --extra ml puzzle-vision setup-check --profile m3-max
uv run --extra ml puzzle-vision run --profile m3-max  # backfill until done; --hours N, --follow, --daily
uv run puzzle-vision status --profile m3-max
uv run pytest                                       # pure-python tests, light dependencies only, no models
```

Layout: `puzzle_vision/` (cli, api, config, workstate, lock, download, `stages/`, `derive/`, `proposals/`),
`profiles/` (one TOML per machine: phases, analyzers, versions, requires), `launchd/` (job template), `tests/`.
