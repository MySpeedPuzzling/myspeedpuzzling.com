# Puzzle Insights — TODO

Items marked with ✅ are done.

## Puzzle Detail Page

### ✅ 1. Visual polish of intelligence section
### ✅ 2. Metric clarification — inline explanations, section icons
### ✅ 3. Confidence level — dots + readable text
### ✅ 4. Time prediction — removed timeline bar, added explanation
### ✅ 5. Personality metrics — scale bars, individual sections
### ✅ 6. Missing median time in PuzzleTimes component
- Collapsible "Puzzle Insights" card with methodology link
- Difficulty icon next to piece count (24px)
- Per-section icons matching methodology page
- Prediction moved after confidence, always shown

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

### ✅ 13. Visual improvement of the table
- Matches existing ladder style (custom-table-wrapper, striped, hover)
- Rank icons before player names, monospace numbers, proper avatar circles
- Qualification banner with primary styling, progress bars
- 100 per page, return URLs on player links

### 26. Add links to MSP-ELO ladder page
- Currently no navigation links to the ELO ladder from anywhere in the app
- Short term: add link from /en/ladder page
- Long term: find a better permanent home (nav, profile, hub?)

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

### ✅ 21. MSP-ELO Rating card
- Banner style matching ladder, trophy icon, primary border
- Ranked: #position · ELO number
- Not qualified: progress bars with qualification requirements
- Private players: explanation card

### ✅ 22. Skill Profile card
- Redesigned as "Player Insights" with badge + piece count
- Percentile bar, next tier, methodology link
- Private player explanation card
- 500pc only (configurable via constant)

### ✅ 23. Skill per piece count
- Simplified to 500pc only
- Side-by-side cards (skill + ELO) on tablet+
- Equal height with mt-auto alignment

---

## Cross-cutting

### ✅ 24. Feature flag — admin-only visibility
- Documented in `docs/features/feature_flags.md`

### ✅ 25. Locked icons for non-members
- Uses design system locked icons (`diff-locked` / `rank-locked`)
