# Puzzle Intelligence — TODO

## Puzzle Detail Page

### 1. Visual polish of intelligence section
- The overall look of the intelligence card needs a design refresh
- Current layout feels dense and utilitarian — needs breathing room, better hierarchy, more visual appeal
- Consider: card structure, spacing, typography, iconography, color usage

### 2. Metric clarification — make everything self-explanatory
- **Problem:** Personality metrics (Memorability, Skill Sensitivity, Predictability, Box Dependence) are only explained via tiny tooltip icons — first-time users have no idea what these mean
- Tooltips are not user-friendly: hard to discover, invisible on mobile, require hover
- **Goal:** A total newcomer must understand every metric without needing to hover/click anything
- **Ideas to explore:**
  - Inline one-sentence explanation below each metric name (e.g. "How much easier is this puzzle the second time?")
  - Contextual phrasing that embeds the value (e.g. "Skill matters a lot — big gap between fast and slow solvers")
  - Could the metric name itself be more descriptive?

### 3. Confidence level — unclear presentation
- **Problem:** Currently shows 3 colored dots + a number (e.g. "●●● 178") — first reaction is "what is this?"
- No label, no context — users don't know this represents data confidence / sample size
- **Ideas to explore:**
  - Add a visible label (e.g. "Based on 178 solvers" or "High confidence (178 solvers)")
  - Replace dots with something more intuitive (e.g. text badge, star rating metaphor, progress indicator)
  - Consider whether confidence even needs to be this prominent, or if it could be secondary info

### 4. Time prediction — range too wide, timeline bar useless
- **Problem:** Prediction range like "50-70 min" is too wide to be useful — users get no real insight
- The timeline/range bar always shows the dot in the middle (by definition: predicted = midpoint of range) — adds no information
- **Ideas to explore:**
  - Can we narrow the prediction range algorithmically? (tighter stddev, confidence-weighted, cap the spread)
  - Remove the timeline bar entirely — it adds visual noise but no value
  - Show just the predicted time prominently, with range as secondary small text
  - Consider whether the range should even be shown if it's too wide (e.g. > 30% spread)

### 5. Personality metrics — add ranges/scale context
- **Problem:** Seeing "Memorability: 1.3x" doesn't tell the user if that's high, low, or typical
- The colored badge helps but isn't enough — users want to know "compared to what?"
- **Ideas to explore:**
  - Show the typical range (e.g. "1.3x — typical range: 1.0x–2.0x")
  - Mini bar/scale showing where this puzzle falls relative to all puzzles
  - Percentile-style framing (e.g. "Higher than 72% of puzzles")
  - Verbal contextualization (e.g. "Most puzzles score 1.0–1.2x, this is above average")

### 6. Missing median time in PuzzleTimes component
- **Problem:** The component currently only shows "Average time" (`averageTime`) but should also display median
- Median is a more robust measure (less affected by outliers) and aligns with the intelligence system which is median-based
- **Action:** Add `medianTime` calculation to `PuzzleTimes` component and display it alongside or instead of average

---

## Puzzle List Page

### 9. Differentiate difficulty/skill badges from regular badges
- **Problem:** Puzzle list now shows difficulty badges alongside existing badges (piece count, manufacturer, tags, etc.) — too many badges competing for attention, all looking similar
- Difficulty/skill badges need a distinct visual language that separates them from standard metadata badges
- **Ideas to explore:**
  - Different shape or style (e.g. pill with icon prefix, outlined vs filled, subtle background gradient)
  - Dedicated placement area on the card (not mixed in with other badges)
  - Use the tier color system consistently so users learn to recognize intelligence badges at a glance
  - Consider a small icon prefix (e.g. bar-chart icon) to mark intelligence-related badges

---

## Overall System

### 7. Revisit puzzle difficulty tiers
- Review and potentially revise:
  - **Number of tiers** — are 7 tiers the right granularity, or would fewer be clearer?
  - **Tier names** — are they intuitive? (e.g. "Moderate" vs "Below Average" confusion)
  - **Tier colors** — do the current colors communicate the right feeling/hierarchy?
- Goal: tiers should be immediately intuitive and visually distinct

### 8. Revisit player skill tiers
- Same review as difficulty tiers:
  - **Number of tiers** — 7 tiers, right amount?
  - **Tier names** — Casual/Enthusiast/Proficient/Advanced/Expert/Master/Grandmaster — are these motivating and clear?
  - **Tier colors** — do they form a clear visual hierarchy?
- Goal: players should feel a clear sense of progression and understand where they stand

---

## Methodology Page

### 10. Add illustrations / visual design
- The methodology page is text-heavy and feels like dry documentation
- Adding brand illustrations or diagrams would make it more inviting and easier to digest
- Ideas: step-by-step flow diagrams, visual examples of tier badges, illustrated "how it works" sections

### 11. Recalculation countdown
- Currently shows static text: "All intelligence metrics are recalculated hourly."
- **Idea:** Replace with a live countdown — "Next recalculation in: 42 min"
- Since recalculation runs on a fixed hourly cron (`0 * * * *`), the countdown is trivially computed client-side from current time
- Makes the system feel alive and transparent

### 12. More detailed formula explanations
- Consider adding the exact mathematical formulas (weighted median, difficulty index, outperformance, ELO) directly on the page
- Target audience: data-curious members who want to understand precisely how their scores are computed
- Could be collapsible/expandable sections so casual readers aren't overwhelmed

---

## MSP-ELO Ladder Page

### 13. Visual improvement of the table
- The ladder table needs to match the visual quality of other pages
- Better styling, spacing, row hover states, rank highlighting, etc.

### 14. Load more / pagination
- Currently the ladder likely dumps all rows at once
- Add "load more" or pagination to keep the page fast and manageable

### 15. Broken country flags
- Flags next to player names are broken / not rendering — needs debugging

### 16. Personal banner at the top
- **Problem:** The "You need X first attempts and Y total solves" alert is just a generic warning box
- **Goal:** Replace with a personal status banner that always shows at the top:
  - If ranked: show your position, rating, and recent trend (e.g. "#42 — 1,180 ELO")
  - If not yet qualified: show progress toward qualification with clear progress indicators
- Should feel like "your spot" on the page, not an error message

### 17. Link to methodology page
- Users seeing the ladder will have questions about how ELO is calculated
- Add a visible link/button to the methodology page (e.g. "How is this calculated?" or "Learn more about MSP-ELO")

### 18. ELO algorithm review (do last — backend-only, only changes numbers)
- **Partial fix done:** Restricted ELO to first-attempt solves only (was counting repeats)
- **Open questions to brainstorm:**
  - ELO compares raw solve times (percentile among solvers of that puzzle) — doesn't account for puzzle difficulty. Should it?
  - `getAveragePoolElo` uses previous-run data during recalculation — stale pool average
  - Should ELO and skill percentile remain separate systems, or should ELO incorporate difficulty adjustment?
  - K-factor tuning (currently 60 for first 10 matches, 30 after)
- **Safe to do last** — only affects calculated numbers, no frontend/UX impact

### 19. Scope MSP-ELO to 500pc only
- **Decision:** MSP-ELO will be a single unified ranking based on 500-piece solo performance only
- Remove piece-count selector / multi-category support from the ladder
- This simplifies the system, increases sample sizes, and makes the ranking more meaningful
- 500pc is the most popular category and the competitive standard

### 20. Unexplained ELO tier labels ("Elite", "Strong", etc.)
- **Problem:** The ladder shows labels like "Elite", "Strong" next to players — these are not defined in the methodology page, not part of the skill tier system, and completely unexplained
- Users have no way to know what these mean or where the thresholds are
- **Action:** Either document these ELO-specific tiers in methodology and make them consistent with the rest of the system, or remove them entirely to avoid confusion

---

## Player Profile Page

### 21. MSP-ELO Rating card — visual tweaks
- The "Not ranked yet" state with qualification progress is good — keep the pattern
- The ranked state needs visual improvement — currently feels flat
- Ensure the card shows the player's actual rank position when ranked (may be missing)

### 22. Skill Profile card — clarification and visual improvement
- **Problem:** Same clarity issues as puzzle detail page but worse in profile context:
  - Confidence shows as "86" with 3 dots — no label, no explanation ("what is that?")
  - Raw score "0.980" shown without context — user has no idea what this number represents
  - Tooltips are the only explanation — bad pattern, not discoverable
- **Goal:** Make every number self-explanatory without requiring hover/tooltip
- **Ideas to explore:**
  - Replace raw "0.980" with contextual text (e.g. "Your skill score: 0.98 — performing at average level")
  - Replace confidence dots+number with readable text (e.g. "Based on 86 puzzles")
  - Inline descriptions under each section header
  - Consider whether raw scores even need to be shown to non-power-users

### 23. Skill level per piece count — visual improvement
- The per-category skill breakdown (500pc, 1000pc, etc.) is a great concept
- Needs visual polish: better card layout, clearer tier badges, progress visualization
- Should feel like a progression dashboard, not a data table

---

## Cross-cutting

### 24. Feature flag — admin-only visibility
- **Goal:** Deploy to production for testing on real data, but hide all intelligence UI from regular users
- Wrap all puzzle intelligence Twig blocks with `{% if is_granted('ADMIN_ACCESS') %}` — puzzle detail difficulty section, player profile skill/ELO cards, MSP-ELO ladder page, puzzle list difficulty badges, methodology page link
- Backend logic stays as-is (computation runs, data is stored) — only template rendering is gated
- This lets us iterate on visuals and verify calculations on production data before public launch
- **Remove the flag** once all TODO items are resolved and we're ready for public release

### 25. Locked icons for non-members / not signed in
- Anywhere a difficulty or skill tier is displayed, non-members and anonymous users should see the **locked icon** (`diff-locked` / `rank-locked`) instead of the actual tier icon
- This applies to: puzzle list badges, puzzle detail difficulty section, player profile skill/ELO cards, solve analysis recap
- Use the locked variant from the SVG sprite (padlock inside the same container shape)
- Should feel like a teaser — "there's something here, become a member to see it"
