# Puzzle Intelligence — TODO

Items marked with ✅ are done.

## Puzzle Detail Page

### ✅ 2. Metric clarification — make everything self-explanatory
### ✅ 3. Confidence level — readable text + dots
### ✅ 4. Time prediction — removed useless timeline bar
### ✅ 5. Personality metrics — scale bars for context
### ✅ 6. Missing median time in PuzzleTimes component

### 1. Visual polish of intelligence section
- The overall look of the intelligence card needs a design refresh
- Current layout feels dense and utilitarian — needs breathing room, better hierarchy, more visual appeal
- Consider: card structure, spacing, typography, iconography, color usage

---

## Puzzle List Page

### ✅ 9. Differentiate difficulty from regular badges
- Difficulty is now a 16px icon before the piece count, not a badge
- Difficulty dropdown filter with icons in the filter row
- Sorting by easiest/hardest first added

---

## Overall System

### ✅ 7. Revisit puzzle difficulty tiers
- 6 tiers: Very Easy, Easy, Average, Challenging, Hard, Very Hard
- Semaphore color gradient (green→amber→red) with WCAG AA contrast
- SVG icon system: rounded square + directional chevrons

### ✅ 8. Revisit player skill tiers
- 7 tiers: Casual, Enthusiast, Proficient, Advanced, Expert, Master, Legend
- Prestige color progression (green→blue→red→navy)
- SVG icon system: circle→pentagon→shield progression

---

## Methodology Page

### ✅ 11. Recalculation countdown
### ✅ 17. Link to methodology page (from ELO ladder)

### 10. Add illustrations / visual design
- The methodology page is text-heavy and feels like dry documentation
- Adding brand illustrations or diagrams would make it more inviting

### 12. More detailed formula explanations (do after #18)
- Add exact mathematical formulas in collapsible sections
- Target: data-curious members who want precise details

---

## MSP-ELO Ladder Page

### ✅ 14. Pagination (50 per page)
### ✅ 15. Fixed country flags
### ✅ 16. Personal banner (ranked state + qualification progress)
### ✅ 19. Scoped to 500pc only
### ✅ 20. Removed unexplained ELO tier labels (replaced with rank icons)

### 13. Visual improvement of the table
- The ladder table needs to match the visual quality of other pages
- Better styling, spacing, row hover states, rank highlighting

### 18. ELO algorithm review (do last — backend-only, only changes numbers)
- **Partial fix done:** Restricted ELO to first-attempt solves only
- **Open questions:**
  - Should ELO account for puzzle difficulty?
  - `getAveragePoolElo` uses previous-run data — stale pool average
  - Should ELO and skill percentile remain separate systems?
  - K-factor tuning (currently 60 for first 10, 30 after)
- **Safe to do last** — only affects calculated numbers, no frontend/UX impact

---

## Player Profile Page

### 21. MSP-ELO Rating card — visual tweaks
- The ranked state needs visual improvement — currently feels flat
- Ensure the card shows the player's actual rank position when ranked

### 22. Skill Profile card — clarification and visual improvement
- Confidence now shows dots + text (done globally via macro)
- Skill score tooltip removed (done globally)
- Still needs: overall visual polish, layout improvements

### 23. Skill level per piece count — visual improvement
- Needs visual polish: better card layout, clearer tier badges, progress visualization
- Should feel like a progression dashboard, not a data table

---

## Cross-cutting

### ✅ 24. Feature flag — admin-only visibility
- Documented in `docs/features/feature_flags.md`

### ✅ 25. Locked icons for non-members
- Uses design system locked icons (`diff-locked` / `rank-locked`)
