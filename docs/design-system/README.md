# MySpeedPuzzling Design System

Visual identity and style reference for MySpeedPuzzling.com.

## Color Palette

### Brand Colors

| Name | Hex | CSS Variable | Usage |
|------|-----|-------------|-------|
| **Primary (Coral-salmon)** | `#fe696a` | `$primary` | Links, buttons, badges, table headers, active navigation, CTA elements |
| **Accent (Indigo)** | `#4e54c8` | `$accent` | List markers, secondary interactive elements |
| **Info (Sky blue)** | `#69b3fe` | `$info` | Informational badges, secondary accents |
| **Success (Soft green)** | `#42d697` | `$success` | Success states, positive indicators |
| **Warning (Soft orange)** | `#fea569` | `$warning` | Warning states |
| **Danger (Pink-red)** | `#f34770` | `$danger` | Error states, destructive actions |

The user-facing brand color used in marketing and social is `#EC726F`, which is very close to the CSS primary `#fe696a`. Both are coral-salmon tones.

### Neutrals

| Name | Hex | CSS Variable | Usage |
|------|-----|-------------|-------|
| White | `#ffffff` | `$white` | Page background, card backgrounds |
| Gray 100 | `#f6f9fc` | `$gray-100` | Subtle background tints |
| Gray 200 | `#f3f5f9` | `$gray-200` / `$secondary` | Secondary backgrounds, section fills |
| Gray 300 | `#e3e9ef` | `$gray-300` / `$border-color` | Borders, dividers |
| Gray 400 | `#dae1e7` | `$gray-400` | Disabled borders |
| Gray 500 | `#aeb4be` | `$gray-500` | Placeholder text |
| Gray 600 | `#7d879c` | `$gray-600` | Secondary text, captions |
| Gray 700 | `#4b566b` | `$gray-700` / `$body-color` | Body text |
| Gray 800 | `#373f50` | `$gray-800` / `$dark` / `$headings-color` | Headings, emphasis text |
| Gray 900 | `#2b3445` | `$gray-900` | Strongest contrast, near-black |

### Color Personality

The palette is **warm and approachable**. The coral-salmon primary avoids the aggressiveness of pure red while maintaining energy and sportiness. The blue-gray neutrals keep things professional without being cold. Overall the colors say: "competitive but friendly community".

## Typography

### Font

**Rubik** (Google Fonts) with weights 300, 400, 500, 700. Rubik is a rounded, geometric sans-serif that reinforces the friendly, approachable brand personality.

```
font-family: 'Rubik', sans-serif;
font-display: optional; // No FOUT - invisible text until font loads, falls back if slow
```

### Scale

| Element | Size | Weight |
|---------|------|--------|
| H1 | 2.5rem (40px) | 500 |
| H2 | 2rem (32px) | 500 |
| H3 | 1.75rem (28px) | 500 |
| H4 | 1.5rem (24px) | 500 |
| H5 | 1.25rem (20px) | 500 |
| H6 | 1.0625rem (17px) | 500 |
| Body | 1rem (16px) | 400 |
| Body medium | 0.9375rem (15px) | 400 |
| Small | 0.875rem (14px) | 400 |
| Extra small | 0.75rem (12px) | 400 |

### Text Colors

- **Headings**: `#373f50` (gray-800) - dark blue-gray, strong but not black
- **Body text**: `#4b566b` (gray-700) - softer blue-gray for comfortable reading
- **Links**: `#fe696a` (primary coral), no underline, darken 10% on hover
- **Captions/secondary**: `#7d879c` (gray-600)

## Spacing & Layout

### Grid

- Bootstrap 5 grid with custom breakpoints
- Max container width: 1260px at xl breakpoint
- Gutter: 1.875rem (30px)
- Base spacer: 1rem with multipliers (0.25, 0.5, 1, 1.5, 3)

### Breakpoints

| Name | Width |
|------|-------|
| xs | 0 |
| sm | 500px |
| md | 768px |
| lg | 992px |
| xl | 1280px |
| xxl | 1400px |

## Components

### Navbar

- Fixed to top of page
- **Frosted glass effect**: `backdrop-filter: blur(10px)` on semi-transparent white `rgba(255,255,255,.66)`
- Content padding accounts for ~101px navbar height
- Navigation icons are thin-line style with labels below
- Active nav item highlighted in coral primary color
- Right side: Search, Sign in, Add, notification bell with badge count

### Cards

- White background
- Subtle rounded corners: `0.3125rem` (~5px)
- Soft box shadow: layered subtle shadows for depth without heaviness
- No border by default (shadow provides the edge definition)
- Content uses consistent internal padding

### Buttons

- **Primary (filled)**: Coral-salmon `#fe696a` background, white text, rounded corners
- **Outline**: Coral border with coral text on white background (used for secondary actions like "Recent times", "Show more")
- No heavy shadows on buttons
- Rounded corners matching card radius

### Badges & Pills

- **Competition badges**: Coral-salmon background, white text, rounded pill shape, small diamond/heart icon prefix (e.g., "WJPC 2024", "Australia National 2024")
- **Status badges**: Small rounded pills - green for "1st try", coral for counts
- **"New" badge**: Coral pill on navigation items (e.g., Marketplace)
- **Notification count**: Small circular coral badge on bell icon

### Tables

- **Header row**: Solid coral-salmon `#fe696a` background with white text
- Clean rows with subtle alternating background (`#fdfdfd`)
- Horizontal scrolling on mobile via `.table-responsive`
- Ranking numbers in left column
- Generous row padding for readability

### Tabs

- Clean underline style tabs
- Active tab: coral text with coral underline
- Inactive: gray text, no underline
- Icons above tab text (e.g., solo/duo/team player icons)

### Filter/Piece Count Chips

- Outlined rounded rectangles for piece count filters (e.g., "All", "500", "1000")
- Active chip: coral border and text
- Inactive: gray border and text

### Dropdown/Sort Controls

- Outline style with coral text and small dropdown arrow
- Consistent with the outline button aesthetic

## Logo

### Description

A **stopwatch merged with a jigsaw puzzle piece** - the two core concepts of speed puzzling unified into one mark.

### Visual Characteristics

- **Style**: Semi-flat line illustration with soft color fills
- **Outlines**: Consistent ~2px dark navy blue strokes (`#2b3445`)
- **Fills**: Soft gradient washes - coral-salmon, sky blue, lavender-purple, teal-aqua
- **Clock face**: White to light blue subtle gradient
- **Speed lines**: Horizontal dashes on the left side suggesting motion
- **Geometry**: Rounded and friendly, no sharp or aggressive angles
- **Background**: Transparent (PNG)

### Color Breakdown in Logo

- Coral-salmon pink (`#EC726F`) - warm accent shapes
- Sky blue - cool accent shapes
- Lavender-purple - depth accents on puzzle piece
- Dark navy (`#2b3445`) - all outlines and clock hands
- White/light blue - clock face
- Teal-aqua - small decorative accents

## Overall Aesthetic

### Mood

**Competitive but welcoming.** The site serves a community of speed puzzling enthusiasts - from casual hobbyists to world championship competitors. The design balances:

- **Sporty energy** (coral color, speed lines, timers, leaderboards) with **warmth** (rounded fonts, soft colors, no harsh contrasts)
- **Data-rich content** (times, rankings, statistics, PPM scores) with **clean readability** (generous spacing, clear hierarchy, subtle cards)
- **Community feel** (player names with country flags, puzzle images, completion counts) with **professional polish** (consistent components, frosted glass navbar, soft shadows)

### Design Principles

1. **Light and airy** - White-dominant with coral accents, never dark or heavy
2. **Rounded and friendly** - Rubik font, rounded corners, soft shadows, no sharp edges
3. **Information-dense but not cluttered** - Good use of hierarchy, spacing, and progressive disclosure
4. **Consistent coral accent** - The coral-salmon color ties everything together as the single brand color that appears in buttons, links, badges, table headers, and the logo
5. **Photography as content** - Puzzle box images are the main visual content; UI chrome stays minimal and lets the colorful puzzle images shine

### What the Design is NOT

- Not dark/moody (no dark mode, no heavy backgrounds)
- Not minimalist to the point of being sparse (it's information-rich)
- Not corporate/enterprise (it's a community hobby platform)
- Not childish despite the playful subject (clean, professional execution)
- Not gradient-heavy or neon (colors are soft and muted)
