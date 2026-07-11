# Generating puzzle illustrations with ChatGPT — what works

ChatGPT/DALL-E is poor at jigsaw piece anatomy by default (wavy blobs, multiple bumps per side). These rules, baked into every prompt in this folder, get it much closer:

1. **Anchor on the emoji**: "shaped exactly like the standard puzzle-piece icon 🧩" — the model knows that geometry far better than a verbal description.
2. **Few, large pieces** — one to three oversized pieces per scene. Never ask for a full cut grid or a pile of small pieces.
3. **Missing-piece trick** — an almost-assembled puzzle with ONE piece-shaped hole reads as "puzzle" instantly and hides anatomy errors.
4. **Boxes instead of pieces** where possible — puzzle boxes, stopwatches, podiums and hands are easy; use them to carry the scene and keep pieces as accents.
5. **Contained spot illustrations, not backgrounds** — a compact scene on white/transparent generates far more reliably than a wide backdrop with negative-space constraints. Readability gradients get added in CSS, never in the image.
6. **Iterate in the same chat**: after the first generation, correct specifically — "make each piece have exactly ONE round knob per side on a narrow neck", "remove the extra bumps", "thicker consistent navy outline". Two to three rounds usually lands it.
7. Ask for **"flat vector illustration style, clean SVG-like linework"** to keep outlines crisp and downscalable.

## The anatomy paragraph (already included in every prompt below)

> CRITICAL — jigsaw piece anatomy: every puzzle piece must be the classic die-cut shape, exactly like the standard puzzle-piece icon (🧩): a rounded square where each side is either flat, has ONE centered round knob protruding on a narrow neck, or ONE matching round socket cut inward. Maximum one knob or socket per side; knobs are mushroom-shaped (narrow neck, round head). Never wavy edges, never blobby outlines, never multiple bumps on a side. If pieces are shown assembled, seams are thin dark-navy cut lines following those knob-and-socket shapes.

## Slots and files

| Homepage slot | File | Ratio |
|---|---|---|
| Hero (right of headline) | `hero-spot-illustration.md` | 4:3 |
| Track Your Times section | `section-track-times.md` | 4:3 |
| Leaderboards section | `section-leaderboard.md` | 4:3 |
| Competitions section | `section-competitions.md` | 4:3 |
| Puzzle Database section | `section-puzzle-database.md` | 4:3 |
| Community band | `community-band.md` | 16:9 |
| Hero wide background (retired approach) | `homepage-hero-background.md` | 16:9 |

Generate PNG on white/transparent. Drop finished files anywhere and tell Claude — integration (WebP/AVIF conversion, dimensions, lazy-loading, CLS-safe slots) is handled in code.
