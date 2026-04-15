# Stat Icons — Design System

Icons for representing **average** and **median** time values throughout the My Speed Puzzling app.

## Concept

**Ø / Õ** — a single circle base with two diacritic variants:

| Symbol | ID         | Meaning          | Visual                              |
|--------|------------|------------------|-------------------------------------|
| Ø      | `stat-avg` | Average (mean)   | Circle + diagonal slash             |
| Õ      | `stat-med` | Median           | Circle + horizontal tilde (centered)|

### Why this notation

- **Ø** is an established shorthand for "average" (*Durchschnitt*) in German-speaking and Northern European usage — invoices, engineering specs, newspaper stats. It leans on real convention rather than inventing one.
- **Õ (circle + tilde)** extends the universal statistical diacritic for median (`x̃`, where `~` denotes median) onto the same circular base, giving the pair a shared identity.
- Two simple closed shapes with distinct diacritics survive small sizes far better than bar charts or crossed-line notation.

## Specifications

- **ViewBox:** `0 0 24 24`
- **Fill:** `none`
- **Stroke:** `currentColor` (so color cascades from CSS text color)
- **Stroke width:** `1.8` (bump to `2.0 – 2.6` at ≤18 px to compensate for optical thinning)
- **Caps / joins:** `round`
- **Circle:** `cx=12 cy=12 r=6.84`  (≈5% smaller than a 7.2 reference; shrink creates clear overflow)
- **Slash (Ø):** line from `(19, 5)` to `(5, 19)` — top-right → bottom-left, classic Ø direction, overflows the circle on both ends
- **Tilde (Õ):** path `M 2 12 Q 7 10, 12 12 T 22 12` — centered on `y=12`, wave amplitude ±1, extends from `x=2` to `x=22` (bold overflow for first-sight recognition)

## Size guidance

| Display size | Stroke-width |
|--------------|--------------|
| 24 px +      | 1.8          |
| 18 – 20 px   | 2.0 – 2.2    |
| 14 – 16 px   | 2.2 – 2.4    |
| 12 px        | 2.4 – 2.6    |

## Usage

### External sprite

```html
<svg class="stat-icon" width="16" height="16">
  <use href="stat-icons-sprite.svg#stat-avg"/>
</svg>
```

### Inline sprite (for `file://` or fully offline use)

```html
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
  <symbol id="stat-avg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="6.84"/>
    <line x1="19" y1="5" x2="5" y2="19"/>
  </symbol>
  <symbol id="stat-med" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="6.84"/>
    <path d="M2 12 Q 7 10, 12 12 T 22 12"/>
  </symbol>
</svg>

<svg class="stat-icon" width="16" height="16"><use href="#stat-med"/></svg>
```

### CSS

```css
.stat-icon { display: inline-block; vertical-align: middle; color: var(--stat-accent); }
```

The icon inherits the text color of its parent, so any surrounding CSS color rule applies.

## Relationship to the rest of the icon system

| Family      | Shape language                 | Prefix | File                            |
|-------------|--------------------------------|--------|---------------------------------|
| Rank        | Circles / pentagons / shields  | `rank-`| `rank-icons-sprite.svg`         |
| Difficulty  | Rounded squares + chevron gauge| `diff-`| `difficulty-icons-sprite.svg`   |
| **Stat**    | **Circle + diacritic**         |`stat-`| `stat-icons-sprite.svg`         |

The three families share: 24×24 viewBox, `currentColor`, stroke width 1.8, round caps/joins. They read as visually related without overlapping.

## Files

- `stat-icons-sprite.svg` — production sprite (2 symbols)
- `stat-icons-sprite-demo.html` — usage demo (plain, pills, sizes, leaderboard, dark mode)
- `STAT-ICONS-DESIGN-SYSTEM.md` — this document
