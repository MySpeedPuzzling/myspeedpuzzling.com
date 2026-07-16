# Badge Generation via ComfyUI — Model Research & Bake-off Plan

_Researched 2026-07-16 (deep-research run: 23 sources fetched, 25 top claims adversarially verified 3-vote, 22 confirmed). Supersedes the ChatGPT pipeline in `prompts/badges.md` for **generation tooling**; the visual spec (socket→tab progression, brand colors, outline rules) in that file remains the source of truth for WHAT to generate._

## Context

- Task A — **5 tier frames**: one grid image, jigsaw-piece frames with exact socket/tab placement per tier, brand fills (#f6f9fc / #EC726F flat / coral→#69b3fe / coral→#4e54c8 gradients), uniform ~2-3px #2b3445 outline.
- Task B — **16+ center icons**: semi-flat line illustration, navy outline, white + coral fills, MSP brand style.
- Task C — transparent backgrounds + composition (icon onto frame).
- Hardware: **Apple M3 Max, 36 GB unified memory** (RAM is the binding constraint; disk is not — 300+ GB free). ComfyUI on MPS.

## Non-negotiable MPS rules (verified, mid-2026)

1. **Never use fp8 checkpoints** — PyTorch MPS has no fp8 (e4m3fn) support; weights convert on the fly to fp16/fp32 at 2-4× memory and force swap. **GGUF quants (Q6_K/Q8_0) or fp16 only.**
2. **Avoid bf16 on M3** — bf16 is software-emulated on M1–M3 (~2.1× slower than fp16; hardware bf16 starts with M4). ComfyUI defaulted macOS to bf16 in 2026 builds and caused a 7× slowdown regression; launch with `--fp16-vae --fp16-unet`.
3. **Do NOT use `--force-fp16`** — known black-image bug with MPS fp16 attention on macOS 14.5+. Use the two granular flags above instead.
4. `--use-pytorch-cross-attention` → 30–50 % faster on M-series.
5. `PYTORCH_ENABLE_MPS_FALLBACK=1` — torch.nonzero (mask nodes), some ControlNet scatter ops, and bicubic interpolate silently need CPU fallback.
6. Grid sheets ≤ 2K px on Z-Image (quality degrades ≥3K); plan 5-frame strips at ~2048×512.

## Candidate stacks (bake-off entrants)

### Stack 1 — FLUX.2 Klein 9B — "brand-precision" candidate
- **Why**: FLUX.2 is the only family with an *officially documented* exact-hex mechanism (BFL prompting guide: hex codes in prompt + JSON structured prompts with `color_match: "exact"`); a Jan-2026 head-to-head found Klein 9B leading prompt adherence in its class. Unified generation + editing → same model can do "recolor this frame to tier-2 fill" reference passes.
- **Files**: `black-forest-labs/FLUX.2-klein-9B` — fp16/GGUF Q8_0 (18.2 GB full; Q8 ~10 GB) + **Qwen3-8B text encoder** + VAE. 4-step distilled variant (CFG locked 1.0) for iteration, base variant for quality. `unsloth/FLUX.2-klein-4B-GGUF` (8 GB class, Qwen3-4B TE) as the light alternative.
- **Caveats**: as of late Jan 2026 Klein ran in ComfyUI portable (v0.9.2+) but **not ComfyUI Desktop** and wanted extra custom nodes — verify current status on the target install first. Hex prompting is documented for dev/pro, not klein explicitly. Vendor "precise color matching" ≠ bit-exact → keep the color-snap post-pass regardless.

### Stack 2 — Qwen-Image-Edit-2511 (+ Qwen-Image 2512 base) — "consistency editor"
- **Why**: verified strongest local edit model — region-preserving targeted recolors (demonstrated down to recoloring a single letter), style transfer from reference images. The 20B class has the best instruction-following for layout ("socket on left edge, tabs elsewhere").
- **Files**: `unsloth/Qwen-Image-Edit-2511-GGUF` Q6_K (~16 GB; Q8 ~21 GB is tight on 36 GB) + Qwen2.5-VL-7B text encoder **Q8 GGUF** (+ mmproj) + `qwen_image_vae.safetensors` + **Lightning 4-step LoRA** (mandatory for usable speed: ~3-4 min/edit with it on Apple Silicon, 6-8+ min without).
- **Caveats**: heaviest and slowest stack; one Mac test found Q8_0 output slightly blurry vs full precision — evaluate quant level in the bake-off.

### Stack 3 — Z-Image Turbo + Z-Image Base + Icons.Redmond LoRA — "fast iteration + LoRA ecosystem"
- **Why**: 6B, ~1-2 min/1024² on Apple Silicon at 8-9 steps, native ComfyUI support since v0.6.0 with **zero reported MPS workarounds** — the iteration workhorse. Has the only dedicated icon LoRA on a modern base: **Icons.Redmond (artificialguybr), Z-Image-Turbo port** (Civitai model 122827 / version 2705464, triggers `ICREDM, ICONS`). `alibaba-pai/Z-Image-Turbo-Fun-Controlnet-Union` enables control-image-guided frame geometry. Z-Image **Base** (released 2026-01-28, non-distilled, 30-50 steps CFG 3-5, Qwen3-4B TE, day-0 ComfyUI) is the designated **LoRA-training base** — the long-term option of training a small MSP-brand style LoRA on our existing illustrations.
- **Files**: `Comfy-Org/z_image_turbo` fp16 repack (or `jayn7`/`unsloth` GGUF Q8), Qwen3-4B TE, VAE; `Tongyi-MAI/Z-Image` base; ControlNet-Union; Icons.Redmond LoRA.
- **Caveats**: Z-Image-**Edit** was still unreleased as of late Jan 2026 (verify July status) — recolor passes would go through Stack 1/2 models or ControlNet re-runs.

### Stack 4 — SDXL + LoRAs + LayerDiffuse — "verified-everything control group"
- **Why**: every link verified: **only native-RGBA route** in ComfyUI (`huchenlei/ComfyUI-layerdiffuse`, SD1.5/SDXL only — true alpha from the sampler, no matting); **Vector Illustration XL** LoRA (Civitai 60132, SDXL version file, trigger `color icon`, 35.9k downloads 3028↑/0↓) for icons; most mature ControlNet/IP-Adapter ecosystem; fastest (~30 s/img).
- **Caveats**: weakest prompt/color adherence of the four — viable only with control images + LoRAs doing the heavy lifting. "Game Icon XL" (580146) was inspected and is glossy 3D game-UI style — **wrong for MSP brand**, skip it.

### Ruled out
- **FLUX.2-dev**: 32B + 24B Mistral TE; Q8 GGUF alone is 32 GB; >20 min/image even on a 16 GB CUDA card. Does not fit 36 GB unified memory in any useful form.
- **FLUX.1-dev/Kontext**: superseded by Klein (same family, better adherence, smaller, unified edit); fp16 ~105 s/img at 30 steps on this exact machine if ever needed as control.
- **SD3.5 / HiDream-I1 / Lumina 2**: no task-relevant evidence surfaced (absence of evidence, not proof of inferiority — but nothing argues for them over the four above).
- Flux flat-icon LoRAs: thin traction, monochrome-only, or white-background-baked — none beats Icons.Redmond/Vector-Illustration-XL.

## Cross-cutting techniques (apply to whichever stack wins)

1. **Geometry via control image, not prompt** — research verdict: *no* verified model reliably places sockets vs tabs on named sides from text. We render the 5 frame shapes programmatically (exact spec already in `prompts/badges.md` §SVG Template: 200 px body, 50 px knobs, socket/tab table) → rasterize → canny/lineart control input → the model paints brand style over deterministic geometry. Consistency by construction; ComfyUI-official 3×3 brand-grid template validates the grid+crop pattern (its cloud Gemini node is replaced by our local model).
2. **Exact hex via post-pass, not faith** — vendor "precise color matching" is not bit-exact anywhere. Pipeline: generate → measure (`analyze_color`) → snap flat fills to brand hexes (trivial on flat art: color quantize + palette map). Model adherence only minimizes correction.
3. **Transparency A/B** — flat art on a solid contrast background → compare InSPyReNet vs BiRefNet(-HR) matting on the 2-3 px outlines (no published evidence exists for this art style; must test ourselves) vs LayerDiffuse native alpha (SDXL stack only). Icons generated on pure white can also be flattened/keyed programmatically.
4. **Icon-set consistency** — single-grid generation (strip of 4-5 icons per sheet, ≤2K) + verbatim style prefix + reference-image conditioning where the model supports it (Qwen-Edit-2511 style transfer, Klein multi-reference).

## Bake-off protocol

Same test matrix per stack, outputs into one comparison sheet; Jan judges style, metrics judge fidelity:

| Test | Prompt/input | Scored on |
|---|---|---|
| F1 | 5-frame tier strip, prompt-only | geometry adherence (honesty check) |
| F2 | 5-frame tier strip, control-image-guided | outline uniformity, fill quality |
| I1-I3 | icons: stopwatch (easy), flame (medium), interlocking-puzzle-heart (hard) — style prefix from `prompts/badges.md` | brand-style match, 48 px readability |
| E1 | recolor pass: tier-3 frame → tier-4 fill (Stacks 1-2 only) | structure preservation, hex distance |
| T1 | best F/I outputs → InSPyReNet vs BiRefNet vs LayerDiffuse | edge integrity on 2-3 px outlines |
| — | all of the above | wall-clock per image on the M3 Max |

Hex fidelity measured with `analyze_color` against the 4 brand colors; expected outcome is a **split verdict** (icons ← Z-Image+LoRA or Klein; frames ← control-image route on Klein/Qwen-Edit), which is fine — production pipeline composes anyway.

## Open items before downloads

- [ ] Jan: pick target ComfyUI install (multiple exist; models dir + whether Desktop or portable — portable preferred if Klein-on-Desktop is still broken)
- [ ] Set `CIVITAI_API_TOKEN` for the MCP (Civitai downloads 401 without it)
- [ ] Verify July-2026 status: Klein in ComfyUI Desktop; Z-Image-Edit released?
- [ ] Approx download sizes: Stack 1 ~15 GB · Stack 2 ~28 GB · Stack 3 ~20 GB · Stack 4 ~10 GB (≈73 GB all-in, fine on 300+ GB)

## Key sources

- BFL FLUX.2 prompting guide (hex + JSON `color_match`): docs.bfl.ai/guides/prompting_guide_flux2
- ComfyUI official 3×3 brand-icon grid template: comfy.org/workflows/templates-3x3_grid_brand_icons-aeda3ae212cd
- Qwen-Image-Edit editing capabilities: qwenlm.github.io/blog/qwen-image-edit + docs.comfy.org/tutorials/image/qwen/qwen-image-edit
- LayerDiffuse native alpha (SDXL): github.com/huchenlei/ComfyUI-layerdiffuse
- MPS bf16 slowdown benchmark: lilting.ch/en/articles/comfyui-qwen-mps-bf16-slowdown; fp8-unsupported + GGUF route: soywiz.com/qwen_image_edit
- Z-Image-Base release: comfyui-wiki.com/en/news/2026-01-28-alibaba-z-image-base-release + github.com/Tongyi-MAI/Z-Image
- FLUX.2 Klein variants/sizes/TEs: medium.com/diffusion-doodles/flux-2-klein-shrinking-flux-2-dev
- Icons.Redmond Z-Image port: civitai.com/models/122827 (version 2705464) · Vector Illustration XL: civitai.com/models/60132
