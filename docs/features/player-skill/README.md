# Rank Icons — Design System

This document describes the player skill tier icon system for **My Speed Puzzling**. It covers the visual design, color palette, SVG implementation, and usage patterns.

Reference files:

- `rank-icons-sprite.svg` — production sprite with all 9 icons (7 tiers + locked + unknown) as `<symbol>` elements
- `rank-icons-sprite-demo.html` — live demo with plain icons, badges, and a 22-row ladder (uses inline sprite for file:// compatibility)


## Tiers & Colors

Seven tiers grouped by hue with gradually increasing contrast, plus two special states (locked and unknown). Colors are designed to work on white backgrounds.

### Color Groups

**Special States (Gray)**

| ID | Name | Hex | CSS Variable | Description |
|----|------|-----|--------------|-------------|
| `rank-locked` | Locked | `#999` | `--rank-locked` | Content not yet accessible |
| `rank-unknown` | Unknown | `#cfcfcf` | `--rank-unknown` | Rank not yet determined |

**Group A — Green (T1–T3)**

| Tier | ID | Name | Hex | CSS Variable |
|------|----|------|-----|--------------|
| T1 | `rank-casual` | Casual | `#a8d4b8` | `--rank-casual` |
| T2 | `rank-enthusiast` | Enthusiast | `#5db88e` | `--rank-enthusiast` |
| T3 | `rank-proficient` | Proficient | `#2e9468` | `--rank-proficient` |

**Group B — Blue (T4–T5)**

| Tier | ID | Name | Hex | CSS Variable |
|------|----|------|-----|--------------|
| T4 | `rank-advanced` | Advanced | `#5a8ec8` | `--rank-advanced` |
| T5 | `rank-expert` | Expert | `#4a53b0` | `--rank-expert` |

**Group C — Red (T6)**

| Tier | ID | Name | Hex | CSS Variable |
|------|----|------|-----|--------------|
| T6 | `rank-master` | Master | `#be3a30` | `--rank-master` |

**Group D — Navy (T7)**

| Tier | ID | Name | Hex | CSS Variable |
|------|----|------|-----|--------------|
| T7 | `rank-legend` | Legend | `#1a1a2e` | `--rank-legend` |

### CSS Variables

```css
:root {
  --rank-locked:     #999;
  --rank-unknown:    #cfcfcf;
  --rank-casual:     #a8d4b8;
  --rank-enthusiast: #5db88e;
  --rank-proficient: #2e9468;
  --rank-advanced:   #5a8ec8;
  --rank-expert:     #4a53b0;
  --rank-master:     #be3a30;
  --rank-legend:     #1a1a2e;
}
```


## Icon Design

The icons follow a military-rank-inspired progression across 4 phases, plus two special state icons:

**Locked:** Circle container with a padlock inside. Used when the rank is not yet accessible.

**Unknown:** Circle container with a question mark inside. Used when the rank has not yet been determined.

**Phase A — Circle (T1–T3):** Entry-level tiers. A circle container with 1, 2, or 3 upward-pointing chevrons inside.

**Phase B — Pentagon (T4–T5):** Officer-level tiers. A pentagon container with 1 or 2 chevrons inside.

**Phase C — Pentagon + Star (T6):** Master tier. A pentagon container with a filled 5-point star inside.

**Phase D — Shield + Crown (T7):** Legend tier. A shield container with a filled crown inside (scaled to 0.7 to keep clear of shield edges).

All icons share these properties:

- ViewBox: `0 0 24 24`
- Stroke style: `stroke-linecap="round"` `stroke-linejoin="round"`
- Default stroke-width: `1.8` (use `1.5` at 48px for visual balance)
- Color: uses `currentColor` so it can be set via CSS `color` property
- Filled elements (star in T6, crown in T7): also use `currentColor` for both `fill` and `stroke`


## SVG Implementation — Sprite Approach

For performance at scale (leaderboards with 500+ rows), icons are implemented as an **SVG sprite** using `<symbol>` + `<use>`.

### Why sprite, not inline SVG

Each icon definition is ~200–400 bytes. With inline SVG repeated 500 times, that's 100–200KB of duplicated DOM. With a sprite, each `<use>` reference is ~60 bytes, and the browser parses the path data only once. The sprite file itself is cached by the browser.

### File: `rank-icons-sprite.svg`

Contains 9 `<symbol>` elements with IDs: `rank-locked`, `rank-unknown`, `rank-casual`, `rank-enthusiast`, `rank-proficient`, `rank-advanced`, `rank-expert`, `rank-master`, `rank-legend`.

### Inline sprite for file:// usage

When opening HTML files directly from disk (no web server), external SVG sprite references fail due to cross-origin restrictions. Embed the sprite inline in a hidden `<svg style="display:none">` block at the top of `<body>`, then reference with `<use href="#rank-expert"/>` (no filename prefix).

### Usage — Plain icon

```html
<svg class="rank-icon" width="24" height="24" style="color: var(--rank-expert)">
  <use href="#rank-expert"/>
</svg>
```

The `width`/`height` controls the rendered size. The `color` CSS property controls the icon color via `currentColor`.

### Usage — Badge component

Badge = colored background + white icon + white label, with a white border and soft shadow for a 3D effect.

```html
<span class="rank-badge expert">
  <svg class="rank-icon" width="20" height="20">
    <use href="#rank-expert"/>
  </svg>
  Expert
</span>
```

```css
.rank-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: 8px;
  font-weight: 700;
  font-size: 14px;
  color: #fff;
  border: 1px solid #fff;
  box-shadow: 0px 0px 6px 0px rgba(0, 0, 0, 0.30);
}

.rank-badge .rank-icon { color: #fff; }

.rank-badge.locked     { background: var(--rank-locked); }
.rank-badge.unknown    { background: var(--rank-unknown); }
.rank-badge.casual     { background: var(--rank-casual); }
.rank-badge.enthusiast { background: var(--rank-enthusiast); }
.rank-badge.proficient { background: var(--rank-proficient); }
.rank-badge.advanced   { background: var(--rank-advanced); }
.rank-badge.expert     { background: var(--rank-expert); }
.rank-badge.master     { background: var(--rank-master); }
.rank-badge.legend     { background: var(--rank-legend); }
```

### Usage — Ladder row

```html
<div class="ladder-row">
  <span class="position">1</span>
  <svg class="rank-icon" width="24" height="24" style="color: var(--rank-legend)">
    <use href="#rank-legend"/>
  </svg>
  <span class="player-name">Sarah K.</span>
  <span class="time">12:34</span>
</div>
```

### Sizing recommendations

| Context | Size | Stroke-width note |
|---------|------|-------------------|
| Inline text / small UI | 16px | Consider `stroke-width: 2.2` for readability |
| Default / lists | 24px | Default `1.8` works well |
| Cards / badges | 20–32px | Default `1.8` works well |
| Large display / hero | 48px+ | Use `stroke-width: 1.5` to avoid heaviness |


## SVG Definitions (raw)

For reference, here are the raw SVG paths for each icon (without the `currentColor` abstraction):

### Locked
```svg
<circle cx="12" cy="12" r="10"/>
<rect x="8.5" y="11" width="7" height="5.5" rx="1"/>
<path d="M10 11V9a2 2 0 0 1 4 0v2"/>
```

### Unknown
```svg
<circle cx="12" cy="12" r="10"/>
<path d="M9.5 9.5a2.5 2.5 0 0 1 4.6 1.3c0 1.5-2.1 2-2.1 3.2"/>
<circle cx="12" cy="17" r="0.5" fill="currentColor" stroke="none"/>
```

### T1 Casual
```svg
<circle cx="12" cy="12" r="10"/>
<polyline points="8 13 12 9 16 13"/>
```

### T2 Enthusiast
```svg
<circle cx="12" cy="12" r="10"/>
<polyline points="8 11.5 12 7.5 16 11.5"/>
<polyline points="8 15.5 12 11.5 16 15.5"/>
```

### T3 Proficient
```svg
<circle cx="12" cy="12" r="10"/>
<polyline points="8 10 12 6 16 10"/>
<polyline points="8 13.5 12 9.5 16 13.5"/>
<polyline points="8 17 12 13 16 17"/>
```

### T4 Advanced
```svg
<polygon points="12 2, 22 9, 18.5 21, 5.5 21, 2 9"/>
<polyline points="8 13.5 12 9.5 16 13.5"/>
```

### T5 Expert
```svg
<polygon points="12 2, 22 9, 18.5 21, 5.5 21, 2 9"/>
<polyline points="8 12 12 8 16 12"/>
<polyline points="8 16 12 12 16 16"/>
```

### T6 Master
```svg
<polygon points="12 2, 22 9, 18.5 21, 5.5 21, 2 9"/>
<polygon points="12 6 13.8 9.8 18 10.2 15 12.7 15.8 16.8 12 14.5 8.2 16.8 9 12.7 6 10.2 10.2 9.8" fill="currentColor" stroke="currentColor" stroke-width="1.2"/>
```

### T7 Legend
```svg
<path d="M12 2l8.5 3.5v5.5c0 5.2-3.7 8.8-8.5 11.2-4.8-2.4-8.5-6-8.5-11.2V5.5L12 2z" stroke="currentColor" stroke-width="1.6"/>
<g transform="translate(3.6 3) scale(0.7)">
  <path d="m12 6 4 6 5-4-2 10H5L3 8l5 4z" fill="currentColor" stroke="currentColor" stroke-width="1.4"/>
</g>
```
