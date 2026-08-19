# Badge Generation Pipeline (ComfyUI, local)

Validated end-to-end 2026-07-17 on the Speed Demon set (5 tiers). Model research and stack
rationale: `../badge-generation-comfyui.md`. Visual spec (socket/tab table, fills, outline):
`../prompts/badges.md`.

## The validated production recipe

```
1. render frames    — deterministic PIL renderer, exact spec geometry per tier   (badge_pipeline.py: render_frame)
2. generate icon    — FLUX.2 Klein 9B Q8 GGUF, brand style + hex prompt          (bakeoff_icons.py: g_klein graph)
3. cut out icon     — border-connected FLOOD FILL, *not* neural matting          (badge_pipeline2.py: floodfill_cutout)
4. compose          — icon alpha-pasted onto the 5 frames                        (badge_pipeline.py: compose_set)
5. polish (optional)— Klein 9B edit pass per badge, PER-TIER fill prompt         (badge_pipeline3.py: COMMON + TIER_FILL)
6. cut out result   — flood fill again -> final transparent PNG
```

Two shippable styles come out of this:
- **Row B ("pure flat")** — stop after step 4: 100 % deterministic fills/gradients, AI only draws the icon.
- **Row C ("hand-illustrated")** — after step 5: organic linework/gradients, geometry still exact.
Evidence: `results-speed-demon-v3.jpg` (top row = B, bottom row = C).

## Key experimental findings (don't relearn these)

1. **Prompt-only geometry fails** — the one-shot 5-badge grid (`results-pure-ai-grid.jpg`) gives
   beautiful icon/style consistency but ignores the socket/tab table and draws "sockets" as circles
   inside the body. Geometry must come from the deterministic renderer (or Jan's art).
2. **Neural matting destroys flat art** — Inspyrenet removed the icon's *interior* white fills
   (transparent clock face). Border-connected flood fill is exact on flat art with closed outlines,
   and free. Use `floodfill_cutout` for both icon extraction and final badge cutout.
3. **Klein edit passes preserve geometry 1:1** — 11/11 runs kept every tab/socket where the input
   had it. Safe to polish deterministic geometry.
4. **Text beats reference in Klein edit mode** — whatever fill the prompt describes overrides the
   input image's fill (round-2 regression: one shared prompt repainted all five tiers coral→sky).
   Therefore each tier's polish prompt must state that tier's fill exactly (`TIER_FILL` dict).
5. **MPS practicalities** — bf16 z-image beat its Q8 GGUF on speed (98 s vs 152 s); never use fp8
   files; icons ~70–150 s each, polish passes ~4–6.5 min each on the M3 Max.

## Model verdict (icon round 1, `results-icon-round1.jpg`)

| Model | Result |
|---|---|
| **FLUX.2 Klein 9B Q8** | Winner — full subject adherence (only one to draw the speed-lines), outline `#252b3f` (Δ12 from brand navy), MSP-like line style |
| FLUX.2 Klein 4B Q8 | Same language, simpler, 2× faster — good for iteration |
| Qwen-Image-Edit-2511 + Lightning | Strong, bolder stroke; reserve for reference-based edits |
| Z-Image Turbo (± Icons.Redmond LoRA) | Different flavor: bold filled app-icon style — alternative direction, not the default |
| SDXL + Vector Illustration XL | Out — subject drift (drew a wall clock), no coral |

## Running it

Scripts expect ComfyUI at `127.0.0.1:8000` (headless launch command in
`../badge-generation-comfyui.md` §Setup) and write to `badge-set/` next to the script.

```bash
python3 badge_pipeline.py frames   # render + verify the 5 tier frames only
python3 badge_pipeline.py run      # matting/grid/compose/polish experiment (round 1 layout)
python3 badge_pipeline2.py         # flood-fill icon, compose, polish all tiers (one prompt — kept for history)
python3 badge_pipeline3.py         # per-tier polish prompts — the validated final round
```

## Open items before production run

- [ ] Jan picks the style: Row B (pure flat) vs Row C (hand-illustrated)
- [ ] Scale test: 2–3 more achievement icons (flame, trophy, puzzle-heart) through the full recipe
      to verify cross-achievement coherence
- [ ] Color-snap post-pass for Row C (gradients drift slightly warm) — flat-art palette quantize
- [ ] If Row C: decide polish granularity — per composed badge (80 runs ≈ 6–9 h unattended) vs
      per part (5 frames + 16 icons = 21 runs ≈ 2 h, then compose)
- [ ] Icon zone/knob shape tuning if Jan supplies his own frame art later (renderer is a placeholder
      with exact spec geometry)
