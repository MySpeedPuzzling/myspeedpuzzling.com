# Difficulty Icons — Design System

This document describes the puzzle difficulty tier icon system for **My Speed Puzzling**. It covers the visual design, color palette, SVG implementation, and usage patterns.

Reference files:

- `difficulty-icons-sprite.svg` — production sprite with all 8 icons (6 tiers + locked + unknown) as `<symbol>` elements
- `difficulty-icons-sprite-demo.html` — live demo with plain icons, badges, size comparison, and a ladder


## Tiers & Colors

Six tiers using a semaphore color scheme (green = easy → red = hard) for instant visual recognition, plus a gray "Unknown" variant for puzzles without a difficulty rating. Chevron direction provides meaning without color (accessible for color-blind users).

| Tier | ID | Name | Hex | CSS Variable |
|------|----|------|-----|--------------|
| T1 | `diff-very-easy` | Very Easy | `#2d8a3e` | `--diff-very-easy` |
| T2 | `diff-easy` | Easy | `#5a9a18` | `--diff-easy` |
| T3 | `diff-average` | Average | `#d4b800` | `--diff-average` |
| T4 | `diff-challenging` | Challenging | `#d07020` | `--diff-challenging` |
| T5 | `diff-hard` | Hard | `#c83530` | `--diff-hard` |
| T6 | `diff-very-hard` | Very Hard | `#8a1820` | `--diff-very-hard` |
| — | `diff-unknown` | Unknown | `#cfcfcf` | `--diff-unknown` |
| — | `diff-locked` | Locked | `#999999` | `--diff-locked` |

CSS variables (copy into your root stylesheet):

```css
:root {
  --diff-very-easy:   #2d8a3e;
  --diff-easy:        #5a9a18;
  --diff-average:     #d4b800;
  --diff-challenging: #d07020;
  --diff-hard:        #c83530;
  --diff-very-hard:   #8a1820;
  --diff-unknown:     #cfcfcf;
  --diff-locked:      #999999;
}
```


## Icon Design

The icons use a **bidirectional chevron gauge** inside a **rounded square** container. The gauge reads like a meter: chevrons pointing down mean below average (easier), a filled dot marks neutral (average), and chevrons pointing up mean above average (harder). More chevrons = more extreme.

**Visually distinct from Rank icons:** Rank icons use circles, pentagons, shields, and stars. Difficulty icons use a consistent rounded square container with directional chevrons, ensuring no confusion between the two systems.

| Tier | Inner Element | Direction | Reading |
|------|---------------|-----------|---------|
| T1 Very Easy | 2× chevron | Down | Well below average |
| T2 Easy | 1× chevron | Down | Below average |
| T3 Average | Filled dot | Neutral | Average |
| T4 Challenging | 1× chevron | Up | Above average |
| T5 Hard | 2× chevron | Up | Well above average |
| T6 Very Hard | 3× chevron | Up | Far above average |
| — Unknown | Question mark | — | Not yet rated |
| — Locked | Padlock | — | Access restricted |

All icons share these properties:

- ViewBox: `0 0 24 24`
- Stroke style: `stroke-linecap="round"` `stroke-linejoin="round"`
- Default stroke-width: `1.8` (use `1.5` at 48px for visual balance)
- Color: uses `currentColor` so it can be set via CSS `color` property
- Container: `<rect x="3" y="3" width="18" height="18" rx="3"/>` (rounded square)
- Filled elements (dot in T3): uses `currentColor` for `fill` with `stroke="none"`


## SVG Implementation — Sprite Approach

For performance at scale (puzzle catalogs with 500+ entries), icons are implemented as an **SVG sprite** using `<symbol>` + `<use>`.

### Why sprite, not inline SVG

Each icon definition is ~200–300 bytes. With inline SVG repeated 500 times, that's 100–150KB of duplicated DOM. With a sprite, each `<use>` reference is ~60 bytes, and the browser parses the path data only once. The sprite file itself is cached by the browser.

### File: `difficulty-icons-sprite.svg`

Contains 8 `<symbol>` elements with IDs: `diff-very-easy`, `diff-easy`, `diff-average`, `diff-challenging`, `diff-hard`, `diff-very-hard`, `diff-unknown`, `diff-locked`.

### Usage — Plain icon

```html
<svg class="diff-icon" width="24" height="24" style="color: var(--diff-hard)">
  <use href="difficulty-icons-sprite.svg#diff-hard"/>
</svg>
```

The `width`/`height` controls the rendered size. The `color` CSS property controls the icon color via `currentColor`.

### Usage — Badge component

Badge = colored background + white icon + white label, with a white border and soft shadow for a 3D effect.

```html
<span class="diff-badge hard">
  <svg class="diff-icon" width="20" height="20">
    <use href="difficulty-icons-sprite.svg#diff-hard"/>
  </svg>
  Hard
</span>
```

```css
.diff-badge {
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

.diff-badge .diff-icon { color: #fff; }

.diff-badge.very-easy   { background: var(--diff-very-easy); }
.diff-badge.easy        { background: var(--diff-easy); }
.diff-badge.average     { background: var(--diff-average); }
.diff-badge.challenging { background: var(--diff-challenging); }
.diff-badge.hard        { background: var(--diff-hard); }
.diff-badge.very-hard   { background: var(--diff-very-hard); }
.diff-badge.unknown     { background: var(--diff-unknown); }
.diff-badge.locked      { background: var(--diff-locked); }
```

### Usage — Ladder / catalog row

```html
<div class="catalog-row">
  <svg class="diff-icon" width="24" height="24" style="color: var(--diff-challenging)">
    <use href="difficulty-icons-sprite.svg#diff-challenging"/>
  </svg>
  <span class="puzzle-name">Venice Canals</span>
  <span class="piece-count">1000 pcs</span>
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

For reference, here are the raw SVG elements for each icon (without the `currentColor` abstraction):

### T1 Very Easy
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<polyline points="8 8.5 12 12.5 16 8.5"/>
<polyline points="8 12.5 12 16.5 16 12.5"/>
```

### T2 Easy
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<polyline points="8 10 12 14 16 10"/>
```

### T3 Average
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>
```

### T4 Challenging
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<polyline points="8 14 12 10 16 14"/>
```

### T5 Hard
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<polyline points="8 15.5 12 11.5 16 15.5"/>
<polyline points="8 11.5 12 7.5 16 11.5"/>
```

### T6 Very Hard
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<polyline points="8 17 12 13 16 17"/>
<polyline points="8 13.5 12 9.5 16 13.5"/>
<polyline points="8 10 12 6 16 10"/>
```

### Unknown
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<path d="M9.5 9.5a2.5 2.5 0 0 1 4.9.5c0 1.5-2.4 2-2.4 3.5"/>
<circle cx="12" cy="16.5" r="0.01" stroke-width="2.5"/>
```

### Locked
```svg
<rect x="3" y="3" width="18" height="18" rx="3"/>
<rect x="8" y="11" width="8" height="6.5" rx="1.5"/>
<path d="M10 11V9a2 2 0 0 1 4 0v2"/>
```


## Accessibility

The icon system is designed to be accessible without color:

- **Direction encodes meaning:** Down chevrons = easier, up chevrons = harder, dot = neutral
- **Count encodes intensity:** More chevrons = more extreme difficulty
- **Color is supplementary:** The semaphore palette (green → red) reinforces the meaning but is not required to understand it
- **Always pair with text:** In badges and catalogs, always include the difficulty label alongside the icon
