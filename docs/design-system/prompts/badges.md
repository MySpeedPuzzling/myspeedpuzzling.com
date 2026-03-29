# Achievement Badge System

Prompt system for generating consistent gamification badges in the MySpeedPuzzling illustration style.

## Concept

The badge itself IS a jigsaw puzzle piece. Every badge at every level is unmistakably a puzzle piece — connectors (tabs and sockets) are present on all 4 sides at every level.

Level progression is expressed through two visual dimensions:

1. **Connector direction** — sockets (concave/indented) evolve into tabs (convex/protruding). Level 1 has all sockets, Level 5 has all tabs. The piece "grows outward" as you level up.
2. **Fill richness** — gradient intensity using brand colors, from flat/muted at Level 1 to rich gradient with effects at Level 5.

The achievement type is communicated by a small icon centered on the puzzle piece.

## Level Progression

| Level | Connectors | Shape Description | Fill Treatment |
|-------|-----------|-------------------|----------------|
| 1 | 4 sockets | All 4 sides have inward concave cutouts — the piece looks "hollow", receiving | Flat single color — gray-100 (#f6f9fc), no gradient |
| 2 | 3 sockets + 1 tab (top) | Top edge protrudes outward, other 3 sides indent inward | Flat solid coral (#EC726F), no gradient |
| 3 | 2 sockets + 2 tabs (top, right) | Top and right protrude, bottom and left indent | Subtle diagonal gradient — coral (#EC726F) to sky blue (#69b3fe) |
| 4 | 1 socket + 3 tabs (top, right, bottom) | Only left side indents, rest protrude outward | Visible diagonal gradient — coral (#EC726F) to lavender-purple (#4e54c8) |
| 5 | 4 tabs | All 4 sides protrude outward — the complete, "giving" piece | Rich diagonal gradient — coral (#EC726F) to deep indigo (#4e54c8), plus sparkle accents |

### Connector Specification

- **Tab (protruding)**: A smooth rounded knob that extends outward from the center of an edge. Classic jigsaw connector shape. Width ~25% of the edge length, protrusion depth ~20% of the body width.
- **Socket (indented)**: The inverse — a smooth rounded cutout that goes inward from the center of an edge. Same proportions as the tab, just concave instead of convex.
- All connectors (tabs and sockets) are the same size across all levels. Only the direction (in vs out) changes.

### Gradient Direction

All gradients run diagonally from top-left to bottom-right. Consistent across every level and every category.

### Outline

Every level has the same dark navy (#2b3445) outline at ~2-3px stroke weight with rounded line caps. The outline weight does NOT change between levels.

## Production Approach: Hybrid (SVG Frames + AI Icons)

After testing, pure AI generation cannot produce pixel-perfect consistent badge frames across 40+ categories. ChatGPT interprets reference images loosely — connector positions shift, proportions drift, outlines vary.

The solution is to split the work:

### What to build deterministically (SVG / Figma)

The 5 puzzle piece frames — these are geometric, must be pixel-perfect, and are reused across all 40+ categories:

- 5 SVG templates (one per level) with the correct socket/tab configuration
- Dark navy (#2b3445) outlines at consistent stroke weight
- Brand color fills and gradients per level
- Transparent background
- A defined center zone (circular or rounded square mask/area) where the icon goes

These are created once in Figma or as SVG code and never change.

### What to generate with ChatGPT

The ~40 center icons only — small, simple illustrations on transparent background:

- Stopwatch, puzzle stack, trophy, flame, heart, etc.
- All in the MSP illustration style (dark navy outlines, soft pastel fills)
- Each icon generated independently — minor cross-generation style variation is acceptable because they share the same style prefix
- Generated at a size that fits the center zone of the puzzle piece template

### Assembly

Overlay each AI-generated icon onto the 5 SVG puzzle piece templates:

- Can be done in Figma (manual), or programmatically (SVG composition, ImageMagick, CSS)
- 40 icons x 5 levels = 200 badges, all with perfectly identical frames
- Adding a new category = generate 1 icon, compose onto 5 templates

### Why this works

| Concern | Pure AI approach | Hybrid approach |
|---------|-----------------|-----------------|
| Frame consistency | Drifts between generations | Pixel-perfect (SVG) |
| Scalability (40+ categories) | Each generation risks drift | Generate 1 icon, reuse 5 frames |
| Center icon style | Consistent within strip | Consistent enough (same style prefix) |
| Effort per new category | Full prompt + review 5 badges | Generate 1 small icon |
| Transparent background | AI sometimes adds white | SVG is natively transparent |

## Icon Generation Prompt

### Icon Style Prefix

Copy this **verbatim** for every icon generation. Upload your brand logo alongside as a style reference.

```
I'm uploading my brand logo as a style reference. Match its illustration style exactly.

Style: Semi-flat line illustration icon — a small, compact, centered symbol. Every
shape has a consistent dark navy blue outline (~2px stroke, color #2b3445) with rounded
line caps and joins. Inside the outlines, fills are soft and pastel-toned — like a
gentle watercolor wash contained within crisp outlines. Not fully flat, but far from
3D. All geometry is rounded and friendly — no sharp corners, no aggressive angles.

Color rules: Dark navy (#2b3445) for ALL outlines and linework. Fills use white
(#ffffff) as the primary base and coral-salmon pink (#EC726F) as the accent color —
used sparingly on 1-2 elements for a pop of warmth. No other colors. Fills are soft
and muted — never neon, never glossy, never photorealistic. No 3D rendering, no
metallic textures, no drop shadows, no background elements.

Output: A single small icon, centered, on a completely transparent background. PNG
format. The icon should be simple with minimal detail — it will be displayed at
48-96px inside a badge. No text, no labels, no frame around the icon.

Aspect ratio: 1:1 square.
```

### Icon Prompts

Append one of these subject lines after the style prefix:

**Puzzles Solved:**
```
Subject: A small stack of 3 jigsaw puzzle pieces, slightly overlapping, viewed from
a three-quarter angle.
```

**Speed Achievement (time under threshold):**
```
Subject: A small stopwatch with a round clock face, two clock hands, a small press
button on top, and 2 tiny horizontal speed-line dashes to the right suggesting motion.
```

**Collection Size:**
```
Subject: A small open box with 3 miniature puzzle boxes stacked neatly inside, viewed
from a slight angle.
```

**Competition Wins:**
```
Subject: A small trophy cup with a jigsaw puzzle tab shape on the front of the cup.
```

**Streak (consecutive days):**
```
Subject: A small flame shape — stylized, rounded, friendly — with clean outlines.
```

**Social (favorites received):**
```
Subject: A small heart shape formed by 2 interlocking jigsaw puzzle piece connectors
fitting together.
```

**First Attempt:**
```
Subject: A single small star with a checkmark in the center.
```

**Lending (puzzles lent):**
```
Subject: Two small hands exchanging a jigsaw puzzle piece between them.
```

**Diversity (different manufacturers):**
```
Subject: A small grid of 4 tiny puzzle boxes arranged 2x2, each slightly different in
shape, suggesting variety.
```

**Group Solving (team puzzles):**
```
Subject: Three small simplified person silhouettes standing together, with a tiny
puzzle piece above their heads.
```

### Batch Generation

For efficiency, generate multiple icons in one image:

```
[ICON STYLE PREFIX]

Subject: A horizontal row of 5 small icons on a transparent background, evenly spaced.
Each icon is independent (not connected). From left to right:

1. A small stack of 3 jigsaw puzzle pieces, slightly overlapping
2. A small stopwatch with clock hands and speed-line dashes
3. A small trophy cup with a puzzle tab on the front
4. A small flame shape, rounded and friendly
5. A small heart formed by 2 interlocking puzzle connectors

Each icon uses the same style: dark navy outlines, white and coral fills only.

Aspect ratio: 16:9 landscape.
```

After generation, crop each icon individually.

---

## SVG Template Specification

For creating the 5 puzzle piece frames in Figma or SVG:

### Dimensions

- Body (rounded square, ignoring connectors): 200x200px with 16px corner radius
- Tab knob: 50px wide (25% of edge), 40px protrusion depth (20% of body), fully rounded
- Socket cutout: same dimensions as tab, but inward
- Outline: 3px stroke, color #2b3445, round line caps, round line joins
- Icon zone: centered circle, 80px diameter (40% of body), defines where the AI icon is placed

### Level Templates

| Template | Top | Right | Bottom | Left | Fill |
|----------|-----|-------|--------|------|------|
| level-1.svg | socket | socket | socket | socket | Flat #f6f9fc |
| level-2.svg | tab | socket | socket | socket | Flat #EC726F |
| level-3.svg | tab | tab | socket | socket | Gradient #EC726F → #69b3fe (135deg) |
| level-4.svg | tab | tab | tab | socket | Gradient #EC726F → #4e54c8 (135deg) |
| level-5.svg | tab | tab | tab | tab | Gradient #EC726F → #4e54c8 (135deg) + sparkle dots |

### Export

- Format: SVG (scalable) or PNG at 2x (512x512px including connectors)
- Background: transparent
- Each level is a separate file

## Evaluation Checklist

After generating icons and composing badges, verify:

**Icons (AI-generated):**
- [ ] Dark navy outlines on every element
- [ ] Fills use only white + coral (no extra colors)
- [ ] Icon is simple enough to read at 48px
- [ ] Background is truly transparent
- [ ] Style feels consistent with the brand logo

**Composed badges (SVG + icon):**
- [ ] All 5 levels of a category look unified
- [ ] Connector progression is clear: all sockets → mixed → all tabs
- [ ] Fill progression is visible: flat gray → flat coral → gradient → rich gradient
- [ ] Icon is centered and doesn't overlap with connectors
- [ ] Icon reads clearly against both light (Level 1) and gradient (Level 5) fills
- [ ] Across categories: frames are identical, only center icon differs
