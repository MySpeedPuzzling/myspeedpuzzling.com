# Progression touch points: mobile-first UX/UI review

Branch `badges-rebase-2026-10-09` (PR #128, XP / levels / achievements), worktree
the PR #128 branch. Reviewed 2026-10-10.
This is a code-only review: the worktree stack was not running and `localhost:8080` is the `main` checkout, so I took no
screenshots. Heights at 375 px are estimates from the markup and SCSS (Bootstrap defaults, 15 px container padding).
Paths below are relative to the worktree.

Legend for "who": G = guest, F = free signed-in, M = member, O = owner of the page, A = admin.
Flag: `xp` = `XpFeatureGate` / `xp_system_visible()` (`xp-system`, admin-only while it is active), `-` = not gated
(already live on `main`).

---

## 0. What the five concepts are in code today

| # | Owner's concept | What exists | Code names | en UI names | Visibility |
|---|---|---|---|---|---|
| 1 | XP + Levels (activity) | Ledger `xp_entry` with denormalised `player.xp_total` / `level`, levels 1-50 (`src/Value/LevelTable.php`, Level 50 = 3,160 XP) | `XpLedger`, `XpCalculator`, `XpReason`, `XpRing`, `XpSolveReceipt`, `XpRecapCelebration`, `GetXpLeaderboard::xp()` | "XP", "Lv %level%", "Level", "XP Leaderboard", "XP ladder", "My XP history", "How XP & Levels work" | Everyone (`xp`); opt-out `experienceSystemOptedOut` |
| 2 | Achievement Points + achievements (completion) | `badge` rows (16 tiered types + `supporter` = "Early Adopter"), tiers Bronze to Diamond, AP = 5/10/25/50/100 per tier, `player.achievement_points` | `BadgeType`, `BadgeTier::points()`, `BadgeEvaluator`, `BadgesProfileSection`, `badge_art()`, `GetXpLeaderboard::achievementPoints()` | "Achievements", "Achievement Points", "AP", "Achievement Points Ladder", "badge" (e-mail, URL, membership copy) | Detail for members only; free owner sees a locked teaser; catalogue public (`xp`) |
| 3 | Karma (community upkeep) | **Not built.** The nearest things are 2 achievements: **Librarian** (accepted change/merge requests) and **Cataloger** (approved puzzles added), plus the moderator role (`player.moderator_since`) | `LibrarianCondition`, `CatalogerCondition` | "Librarian", "Cataloger" | Members (as achievements) |
| 4 | "Points" (competitive, rewards time, to become seasonal) | **MSP Rating.** `PlayerElo.eloRating` (float 0-1) shown ×1000 as an integer. A portfolio over 24 months with decay, first try 75 % + best time 25 %, difficulty-weighted (`MspRatingCalculator`). **Computed for 500 pieces only** (`PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS = [500]`). **There is no monthly or seasonal period:** `period_monthly`, `period_all_time`, `section_elo_periods` and `section_elo_formula` are dead keys. `player_rating_snapshot` stores daily snapshots that nothing shows. | `PlayerElo`, `MspRatingCalculator`, `GetPlayerRatingRanking`, `MspRatingLadder`, `PlayerRatingProfile`, translation namespace `puzzle_intelligence.elo.*`, legacy route `/en/msp-elo` | **"MSP Rating"**, "MSP Rating Ladder" (+ "BETA"), "Rating", "Your rating". **The word "points" never appears in the en UI for it.** The public API calls the value `rating[].points` (`src/Api/V1/PlayerRatingResponse.php`). The docs say "MSP Rating / MSP Points". | Ladder public; rating number public on profile cards and player cards; opt-out `rankingOptedOut`; private profiles excluded |
| 5 | "Rank" (competitive, all-time status) | **Skill tier.** `SkillTier` enum, 7 tiers by percentile per piece count (`PlayerSkill`, `PlayerSkillHistory`): Enthusiast, Apprentice, Proficient, Advanced, Expert, Master, **Legend** (the docs still say "Grandmaster"). The assets already call it "rank": `/rank-icons-sprite.svg#rank-expert`, `.rank-icon`, `--rank-*` CSS variables. | `SkillTier`, `GetPlayerSkill`, `PlayerSkillProfile`, `PlayerHeader::tierChip()`, `skill_icon()` / `skill_badge()` macros | "Skill tier", "Skill Tier", "Tier", "Skill Profile", "No tier yet", "Player Insights" | Members only (the tier name is never in public HTML); opt-out `rankingOptedOut` |

In the en UI, **"Rank" always means a position**, never the skill tier:
- "#12 of 340" on the MSP ladder and profile card (`puzzle_intelligence.elo.rank_of_total`)
- "Rank 3 of 40" on the puzzle leaderboard and result detail (`puzzle_times.my_attempts.rank`, `puzzle_result.rank`)
- "#3 out of 40 solvers" on the recap (`added_time_recap.ranking.position`, card title "Your Ranking")

Further ranking-like systems already live, which a "Points" or "Rank" design would compete with:

| System | What it ranks |
|---|---|
| Time ladders | Solo, Pair and Team at 500 and 1000 pieces, "Top 10" (`/en/ladder`, per-pieces pages, country variants) |
| Per-puzzle leaderboard | Rank, "faster than N %", next milestone rank |
| Country Cup | Monthly, pieces per active puzzler, already a *seasonal* ranking |
| Hub "Most active SOLO players" | Number of solves |
| Players page | "Most active this month", "Most followed", "On a roll" |
| Streaks | Statistics page, activity calendar, digest, the "On Fire" achievement |
| Difficulty tiers | A third use of the word "tier" |
| Comparison | "gained N points on the line-up" |

---

## 1. Touch-point inventory

**Inventory size: 74 touch points** across 22 routes/pages, 12 components, 10 partials/modals/overlays, 9 e-mail parts,
4 settings, 4 API fields and 13 nav/footer/menu/link entries.

### A. Global chrome (every page)

| # | Touch point | Route / file(s) | Who · flag | Concept · prominence on 375 px | User goal → helps? | Mobile-first issues |
|---|---|---|---|---|---|---|
| A1 | Header avatar **ambient XP ring** | all pages · `templates/base.html.twig:658` → `src/Component/XpRing.php` (`ambient`) | F/M signed in · `xp` | XP: a full 2 px ring around the user icon, colour per 10 levels, no numbers. Always visible. | Navigate → neutral. The ring is always full, so it carries no information; nobody will know what the colours mean (no tooltip, no label). | Costs `GetXpProfile` on every signed-in page (README open item). Adds 4 px around the 44 px icon box. Meaningless without a legend; the plan intended it to be "ambient", but it reads as decoration. |
| A2 | Main nav **"Leaderboard"** | `ladder` `/en/ladder` · `base.html.twig:794` (`menu.ladder`) | all · - | Time ladders | Find rankings → ok | The only nav entry for any of the 5 concepts. Achievements, XP and AP have no nav entry. |
| A3 | User menu (avatar dropdown) | `base.html.twig:666-720` | F/M · - | none | - | **No "Achievements", "My XP history" or "XP leaderboard" item.** The user's own progression is not in their menu. |
| A4 | Footer "Discover" list: Leaderboard, **XP Leaderboard**, **Achievement Points Ladder** | `templates/_footer.html.twig:9-12` | all · `xp` | 2 extra links | Discovery → ok | "Leaderboard" vs "Ladder" in adjacent lines. No link to the Achievements catalogue. |
| A5 | Footer "Learn": "Difficulty & Rating Methodology" | `_footer.html.twig:39` → `methodology` | all · - | Rating/tier explainer | ok | The XP explainer and the fair-play page are not in the footer. |
| A6 | Ladder category dropdown, plus **XP / AP entries** | `templates/_ladder_dropdown.html.twig:51-61` (on `/ladder` and the 6 per-pieces ladders) | all · `xp` | 6 time ladders, divider, XP + AP | Switch ladder | **MSP Rating is missing from the switcher** while XP and AP are in it. Mixed time and activity boards in one menu. |
| A7 | `#membersExclusiveModal` (lock CTA) | `base.html.twig` | G/F | Opened by tier chips, insight cards, blurred mocks | Upsell | Triggered from 6+ progression places with one generic text ("Exclusive Feature for Members") that never names what was tapped. |

### B. Solve loop

| # | Touch point | Route / file(s) | Who · flag | Concept · prominence | Goal → helps? | Mobile-first issues |
|---|---|---|---|---|---|---|
| B1 | **XP estimate line** in the puzzle header ("Solving this earns ~N XP + up to M bonus" + repeat note + unrated note) | `puzzle_detail` · `templates/puzzle_detail.html.twig:373` → `src/Component/XpPuzzleEstimate.php`, `templates/components/XpPuzzleEstimate.html.twig` | **G**/F/M (not opted out) · `xp` | XP · **above the fold**, 1-3 lines under the chips row (difficulty chip, collection badges, offers, pending proposals) | Decide whether to solve / see times → mostly a distraction; guests get "XP" with no explanation and no link | (1) **Leaks members-only difficulty to guests and free players:** `baseXp` includes the difficulty bonus, and the "unrated" note appears only for unrated puzzles, so a 1000 pc puzzle that "earns ~13 XP" is tier 4. (2) The copy bug "counts ×%multiplier%" renders "×50" / "×25" (no %); relax first solves are also ×50 % but are not covered. (3) "Up to M bonus" assumes top-10 % speed plus the weekly boost plus the warm-up, so it overpromises once the 5 boosted solves of the week are used. (4) Three lines of grey `fs-sm` text crowd the header. |
| B2 | Delete-solve **XP warning** | `edit_time` · `templates/edit-time.html.twig:75` (modal), `templates/edit-time_modal.html.twig:15` (`data-turbo-confirm`) | O · `xp` (0 while flagged) | XP loss | Delete → helps | The modal variant uses the native `confirm()` dialog: unstyled, appended to the sentence. ok. |
| B3 | Recap **XP receipt card** (lines, total, level chip, progress bar, free teaser) | `added_time_recap` `/en/time-added/{timeId}` · `templates/added_time_recap.html.twig:35` → `XpRecapCelebration` → `XpSolveReceipt` (`templates/components/XpSolveReceipt.html.twig`) | O participant · `xp` | XP · **below the fold on phones.** The column is `order-2`, so after the H1, duplicates review, the action row and the **320 px share image** | Confirm the time, see rank/prediction, add another → helps if short; competes with 4 other recap cards | (1) Each line is `min-height: 44px` "tap target", but the lines are **not tappable**, while the explainer FAQ says "Tap any receipt line to see its reason". A full receipt (up to 6 lines + 2 pending + total = 9 × 44 px) is **about 400 px plus header and bar**. (2) The skeleton "Counting your XP…" may stay forever: one poll after 2.5 s (`data-poll="delay(2500)"`), then `checked = true`. (3) The free teaser "This solve counted toward N waiting achievements 🔒" uses the count of **all** earned badges, not the ones this solve moved, and shows on every solve. (4) At Level 50 the receipt becomes nearest achievements (M) or a paywall (F). |
| B4 | **Level-up overlay** (full screen, confetti, "Tap anywhere to continue"; Level 50 = gold plus AP-ladder fork) | same route · `templates/components/XpRecapCelebration.html.twig:5-46`, `assets/styles/components/_xp.scss` (`.xp-levelup-overlay`, z-index 1090) | O · `xp` | XP · **covers the whole viewport** | Interrupts the main task (recap) | (1) `added_time_recap` is a **QUIET route** in `ResolveAnnouncementModal::QUIET_ROUTES` ("the interruption is in the way"), so it contradicts the site's own rule. (2) **It replays on every reload or back navigation:** level-up is re-derived from the ledger, and `celebrationDismissed` is only a LiveProp. (3) Dismissing is a Live action round trip (`click->live#action`), so it is slow on a bad connection and fails offline. (4) `role="dialog" aria-modal` on a clickable `div`: no focus move, no Esc, no close button. (5) Confetti `infinite` (only `prefers-reduced-motion` stops it). (6) In week 1 it fires on almost every solve (levels at 5/10/16/22/29 XP; one 500 pc solve with boost + warm-up = about 10 XP). (7) Level 50: "Enter the AP ladder" links to `xp_leaderboard` **without `tab=achievement-points`**, so it lands on the XP tab; free players are sent to a ladder they cannot join. |
| B5 | Recap **achievement toast** (48 px badge + "New achievement!") | same · `XpRecapCelebration.html.twig:48-61` | M · `xp` | AP / achievements | Celebrate → ok, but you only see it after scrolling down | A static `.toast.show` in the flow, not fixed: on phones it sits under the receipt, far below the fold. No close button, no auto-hide. Shows the newest badge of the last 3 minutes, so it reappears on reload. Links nowhere (not to the badge, not to the reveal). |
| B6 | Recap **"Your Ranking"** (#rank out of N solvers, "Top N %") | `templates/added_time_recap/_ranking.html.twig` | O solo timed, not opted out · - | Puzzle position | Helps | The 4th progression card on the page; "Ranking" here means the puzzle position, not the MSP Rating. |
| B7 | Recap **"Your Overall Progress"** (Skill Tier + MSP Rating, or a blurred mock for free players) | `templates/added_time_recap/_overall_progress.html.twig` | O · - | Rank (tier) + Points (rating) | Members: helps. Free: an upsell. | (1) For any piece count other than 500 (1000, 750 …) it shows **MSP Rating qualification progress for a ladder that does not exist** (rating is 500-only). (2) "Complete %count% more…" computes `20 - qualifyingPuzzlesCount`, but the minimum is **10** (`PlayerSkillCalculator::MINIMUM_QUALIFYING_PUZZLES`). (3) The blurred mock is **hard-coded English** ("Skill Tier", "Advanced", "Top 18% of players", "MSP Rating", "#124 / 582"). (4) "Skill Tier" (title case) here vs "Skill tier" elsewhere. |
| B8 | Recap prediction / performance | `added_time_recap/_performance_summary.html.twig` | M · - | (insights) | helps | Not progression; adds height. |
| B9 | **Tracking recap**: receipt + overlay | `added_tracking_recap` · `templates/added_tracking_recap.html.twig:17` | O · `xp` | XP · right under the title (above the actions) | Confirm → the receipt pushes the actions down | **`added_tracking_recap` is not a quiet route**, so the free-trial announcement modal can open on the same page view as the level-up overlay (a modal on top of an overlay). The receipt sits *above* the action buttons here but *below* them on `added_time_recap`. |
| B10 | Puzzle leaderboard **position line** ("Rank X of Y · faster than N % · gap to next milestone") + skill-tier icon on every row (members) | `puzzle_detail` · `src/Component/PuzzleTimes.php`, `templates/_leaderboard_player.html.twig:36-38` | F/M · - | Rank (position) + tier icon | helps | "Rank" = position; the tier icon next to every name is another reading of "rank". |
| B11 | Result detail modal ("Rank %rank% of %total%") | `puzzle_result_detail` · `templates/puzzle_result/_head.html.twig` | all · - | Position | helps | - |

### C. Profile and player pages

| # | Touch point | Route / file(s) | Who · flag | Concept · prominence | Goal → helps? | Mobile-first issues |
|---|---|---|---|---|---|---|
| C1 | Header **skill-tier chip** ("◆ Expert" / "No tier yet" / 🔒 "Skill tier") | all 24 player pages · `templates/components/PlayerHeader.html.twig:61-79`, `src/Component/PlayerHeader.php:122` (500 pc only) | M sees the tier; F/G see a lock button; "No tier yet" for members | Rank · **above the fold**, next to the "Member" and "Private profile" chips | Identify the player → ok | The tier is for 500 pieces only and the chip doesn't say so (only `title=`, which a phone never shows). The XP level is **not** in the header (README decision #4 pending), so the two "status" numbers live in two places. |
| C2 | **Profile XP block** (52 px avatar in a ring + "Lv N" + "N XP" + progress bar + "N XP to level N+1" + "My XP history" link for the owner) | `player_profile` · `templates/player_profile.html.twig:52-73` | all who may see the profile · `xp` | XP · **above the fold**, directly under the header | See the person → partly noise for visitors; for the owner it duplicates the header | (1) **Duplicate avatar:** the header already shows the 64 px avatar. (2) **Bug that ships today with the flag on:** `XpRing` renders its inner content when XP is hidden (`{% else %}{{ inner }}`), so **every visitor (non-admin while flagged, opted-out players, guests) sees a stray bare 52 px avatar** under the header. (3) About 70 px of vertical space. |
| C3 | Profile **skill-tier card(s)** ("Expert · Top 12 % · Faster than 88 % · Next: Master", one card **per piece count**) | `templates/components/PlayerSkillProfile.html.twig` | M viewer · - | Rank | Members: helps | Cards stack vertically on phones: 3 piece counts ≈ 330 px. The "?" methodology link is `btn-xs`, well under 44 px. |
| C4 | Profile **MSP Rating card** (#rank · rating, or 0/20 + 0/50 qualification bars for the owner, or "Not ranked yet") | `templates/components/PlayerRatingProfile.html.twig` | all · - | Points | helps competitors | The qualification card has inline **`min-width: 300px`**, so it overflows at 320 px (290 px available). Monospace "#12 · 847" with no unit. The total of 50 is hard-coded (`/50`) instead of the constant. `btn-xs` help button. |
| C5 | Profile **"Player Insights" lock button** (instead of the skill card) | `player_profile.html.twig:168-176` | F/G viewers · - | Rank (locked) | Upsell | Next to the rating card: a second lock on the same screen as the header lock chip (C1). |
| C6 | Profile ranking opt-out / private explanations (2 cards) | `player_profile.html.twig:99-153` | O · - | Rank, Points | ok | Two cards take about 150 px to say "hidden". |
| C7 | **Achievements strip** (members: 72 px badges, flip-to-reveal "?" medallions, "NEW" pill, "Browse all achievements") | `templates/components/BadgesProfileSection.html.twig` (`mode = detail`), `src/Component/BadgesProfileSection.php` | everyone who may see a member's profile · `xp` | AP / achievements · **very low on the page**: after the chart, the tabs, recent activity and the personal info | Show off / browse → **buried** | Up to 17 badges, about 3 per row on a phone, so **5-6 rows ≈ 750-850 px**. The count chip is the number of types, **not AP**: the AP total appears nowhere on the profile. Unrevealed "?" medallions are the only cue after joining, and they sit at the very bottom. |
| C8 | Free owner **achievements teaser** (6 greyed badges + "N achievements are waiting for you — become a member…" + "Unlock with membership") | same, `mode = teaser` | F owner · `xp` | AP (locked) | Upsell | ok; it is the 3rd paywall on the same page (C1, C5, C8). |
| C9 | **Achievement info modal** (160 px hero, description, 5 tier rows with "+N AP", progress, "See who earned it", "Browse all achievements") | `achievement_info` `/en/achievements/{type}/info?tier=` · `templates/_achievement_info_modal.html.twig`, `src/Controller/AchievementInfoController.php` | opened from the strip; members · `xp` | Achievements | Explains a badge → helps | Tall in `modal-frame`, scrolls on 667 px. "Close" `aria-label` hard-coded English. The progress shows the **viewer's** progress on someone else's profile, which is ambiguous. |
| C10 | Badge **flip reveal** on the profile ("?" medallion, CSS flip, POST `reveal_badge`) | `reveal_badge` `/en/badges/{badgeId}/reveal` · `assets/controllers/badge_reveal_controller.js`, `.badge-flip` in `_xp.scss` | O member · `xp` | Achievements | Delight | **A third reveal mechanic** next to the last-piece reveal (D4) and the launch deck (F1). The URL says "badges". The medallion wiggles forever. |
| C11 | "My XP history" link | `player_profile.html.twig:68` | O · `xp` | XP | Audit | The only entry point to `/my/xp-history`. |
| C12 | **Statistics**: "Longest streak" | `player_statistics` · `templates/components/PlayerStatistics.html.twig:142` | all · - | Streak | ok | - |
| C13 | **Activity calendar**: current/longest streak + 🔥 cells | `activity_calendar` · `templates/components/ActivityCalendar.html.twig:52-213` | all · - | Streak | ok | Overlaps "On Fire" (an achievement on days in a row) and the "Steady Hands" quarters. |
| C14 | **Puzzler card** (Players page Turbo Frame): "MSP Rating 847" chip + tier chip / "No tier yet" / 🔒 "Skill tier" | `player_card` `/en/puzzler-card/{id}` · `templates/players/_card.html.twig:111-140` | all · - | Points + Rank | People discovery | No XP level here (inconsistent with the profile). The chip row (rating, tier, competes, swaps, instagram) wraps to 2 lines. |
| C15 | "Someone at your speed": tier chip | `comparison` · `templates/components/ComparisonSimilarSpeed.html.twig:78` | M · - | Rank | ok | - |
| C16 | Comparison form "gained N points on the line-up" | `comparison` · translations `comparison.form.takeaway.*` | M · - | "points" (pace) | ok | A fourth meaning of "points". |
| C17 | Round results / official results: tier icon next to names | `templates/round_results/_player_times.html.twig:36`, `round_results/_official.html.twig` | M · - | Rank | ok | - |
| C18 | Every feed / leaderboard row: tier icon (`_leaderboard_player`) | `RecentActivity`, `LadderTable`, `MspRatingLadder`, `PuzzleTimes`, `_most_active_player_row`, `_player_list_item`, `_player_solvings` | M · - | Rank | ambient | 16 px icon, no label; free players see nothing (fine). |

### D. Achievements pages

| # | Touch point | Route / file(s) | Who · flag | Concept · prominence | Goal → helps? | Mobile-first issues |
|---|---|---|---|---|---|---|
| D1 | **Achievements catalogue** (17 cards: 96 px badge, description, "x / 5", tier row 5 × 48 px + "+N AP", progress bar, "See who earned it"; header AP chip for members, free hint) | `badges_overview` `/en/achievements` (old `/en/badges` → 301) · `templates/badges_overview.html.twig`, `src/Controller/BadgesOverviewController.php` | all (progress signed in) · `xp` | Achievements + AP | Browse goals → helps | (1) **Not linked from nav, user menu, footer or Hub**; reachable only from the profile strip, the modal, the receipt at Level 50 and e-mails. (2) One column on phones: 17 cards ≈ 280-320 px each, so a **≈5,000 px** page with no filter, sort or "in progress" view. (3) The tier row's requirements are only in `title=` (hover), so **invisible on touch**. 5 tiers × (48 px + "Platinum" label) ≈ 300 px fits a ≈303 px card body only just, and wraps in de/fr. (4) The AP chip reuses the `.xp-level-chip` style, so "120 AP" looks exactly like "Lv 12". (5) The subtitle runs straight into the link with no separator: "…puzzling journey Achievement Points Ladder". (6) `aria-label="Progress toward next achievement tier"` hard-coded English. (7) "+N AP" is shown to guests and free players though AP is a member display. |
| D2 | **Achievement holders page** (hero = always the Diamond badge at 160 px, country select, "Newest earners" chips, a section per tier with holder pills, "+N more puzzlers") | `achievement_detail` `/en/achievements/{type}` · `templates/achievement_detail.html.twig`, `src/Controller/AchievementDetailController.php` | all · `xp` | Achievements | Social proof | Holder pills ≈ 28-30 px tall (`py-1 fs-sm`, 22 px avatar), **below 44 px**. The country select shows **ISO codes** ("CZ") and submits with inline `onchange`. The hero is Diamond even for a viewer with nothing earned. |
| D3 | Membership-page **reveal invite** ("N achievements are waiting for their reveal moment!" + "Reveal them") | `membership` · `templates/membership.html.twig:19` → `src/Component/XpRevealInvite.php` | M · `xp` | Achievements | Get to the reveal | **The only in-app entry to D4.** Stripe success redirects to `my_profile` and the free trial to `free_trial_started`; neither shows it. |
| D4 | **Badge reveals page** (grid of last-piece puzzles, sound toggle, "Back to my profile") | `badge_reveals` `/en/my/achievement-reveals` · `templates/badge_reveals.html.twig`, `assets/controllers/last_piece_reveal_controller.js`, `assets/styles/_last-piece-reveal.scss` | M (F → redirect to membership) · `xp` | Achievements | Delight after joining | Good mobile sizing (`--lpr-size: min(160px, (100vw-3rem)/2)`). Not a quiet route, so the free-trial modal can't hit members, fine. |
| D5 | `badges_earned` **e-mail** (to be removed, Jan 2026-10-04 #2) | `templates/emails/badges_earned.html.twig`, `SendBadgeNotificationEmail` | M · `xp` | Achievements | - | Subject "You earned new **badges**", title "%count% new badge(s) earned!" (fake plural). To be deleted. |

### E. Leaderboards, explainers, audit

| # | Touch point | Route / file(s) | Who · flag | Concept · prominence | Goal → helps? | Mobile-first issues |
|---|---|---|---|---|---|---|
| E1 | **Ladder overview** (H1 + dropdown, **MSP banner** "We just launched MSP Rating Ladder BETA", **XP banner** "Two new ladders are live…", country select, 6 Top-10 tables) | `ladder` `/en/ladder` (+ `ladder_country`) · `templates/ladder.html.twig`, `templates/components/LadderTable.html.twig` | all · banner 2 = `xp` | Time ladders + Points + XP/AP promos | See the fastest → the banners distract | **Two stacked promo alerts (≈180-220 px) before any table.** The XP alert has no `flex-wrap` (unlike the MSP one, which was fixed for 320 px), so text and button squeeze at 320. The MSP banner still says "just launched" / BETA. |
| E2 | Per-pieces ladders (Top 100) | `ladder_solo_500_pieces` … `ladder_groups_1000_pieces_country` · `src/Controller/LadderPerPiecesController.php`, `templates/ladder_per_pieces.html.twig` | all (country = M) · - | Time ladders | ok | No XP banner; dropdown as A6. |
| E3 | **MSP Rating Ladder** (BETA chip, subtitle, "How is this calculated?", your-position card, "fresh" alert, recalculation countdown, qualification card, search/country/favourites filters, paged table) | `msp_rating_ladder` `/en/msp-rating` (legacy `/en/msp-elo`) · `templates/msp_rating_ladder/index.html.twig`, `src/Component/MspRatingLadder.php`, `templates/components/MspRatingLadder.html.twig` | all · - | Points | Compete → helps | (1) The subtitle "Competitive ranking based on **head-to-head** performance" is wrong (it is portfolio-based). (2) 3 info blocks (BETA, "brand new" alert, countdown) before the table. (3) No column headers; the rating number sits in the column styled as a time (`lb-time`). (4) 500 pc only and the page doesn't say so in the H1. (5) No period / season. |
| E4 | **XP / AP Leaderboard** (tabs "XP" / "Achievement Points", country select, favourites switch, self card, top-100 table with 🥇🥈🥉, avatar, flag, **Lv chip on every row**) | `xp_leaderboard` `/en/players/xp-leaderboard?tab=` · `templates/xp_leaderboard.html.twig`, `src/Controller/XpLeaderboardController.php`, `src/Query/GetXpLeaderboard.php` | all; AP tab signed in; AP lists members only · `xp` | XP, AP | Explore → ok | (1) **Its own table design** (`table-striped`, emoji medals, Lv chip inline), not the shared `lb-table` row the MSP ladder and puzzle leaderboards use. On 375 px the name + flag + Lv chip cell wraps to 2 lines. (2) At launch about 115 players sit at Level 50, so the top rows all carry an identical gold "Lv 50" chip. (3) The **self card ignores the country/favourites filter** (global rank shown above a CZ list) and is not a link to anything. (4) Hard limit of 100 rows, no paging (the MSP ladder pages). (5) Country select shows ISO codes. (6) The AP tab for guests says "Log in to browse…" with **no login link**. (7) The URL path "players/xp-leaderboard" suggests the Players section, but the page sits under nothing. |
| E5 | **My XP history** (Level chip, XP, table When / Reason / XP, paging ‹ › only) | `xp_history` `/en/my/xp-history` · `templates/xp_history.html.twig`, `src/Controller/XpHistoryController.php` | O · `xp` | XP | Audit | 3-6 lines per solve, each repeating the puzzle name ("Solve — X", "Difficulty bonus — X", "Weekly boost — X"…), so it is noisy; group by solve. No page jump. It doesn't use the `PlayerHeader` compact strip that the other owner-only pages use. |
| E6 | **How XP & Levels work** (3-currency table, formula list, worked example, unrated, achievements, a 50-level table in batches of 10, can I lose XP, FAQ) | `xp_explainer` `/en/how-xp-works` · `templates/xp_explainer.html.twig` | all · `xp` | XP, AP, Points | Learn → helps | `<!-- COPY:pending-jan-approval -->` marker. The table says **MSP Rating is "Members"**, but the MSP ladder is public. The FAQ promises "Tap any receipt line" (not built). The 3-column table at 375 px wraps heavily. The 5 level tables ≈ 5 × 360 px stacked. |
| E7 | **Fair play & trust** | `xp_fair_play` `/en/fair-play-xp` · `templates/xp_fair_play.html.twig` | all · `xp` | XP | Trust | Linked only from the explainer FAQ. |
| E8 | **Methodology** (difficulty, skill tiers, MSP Rating) | `methodology` `/en/methodology` · `templates/methodology/index.html.twig` | all · - | Rank + Points | Learn | Linked from 12 places (profile cards `btn-xs ?`, ladder, guides, footer). Separate from the XP explainer: two "how it works" pages. |
| E9 | Hub **"Most active SOLO players"** (this month / last month / all time) | `hub` · `templates/components/MostActiveSoloPlayers.html.twig` | all · - | Activity ranking (overlaps XP) | ok | A second activity ladder next to the XP ladder. The Hub has **no XP / level / achievement element** although it is the signed-in home. |
| E10 | Players page **Spotlight** ("Most active this month", "Most followed", "New faces", "Fastest puzzlers: the leaderboard →") | `players` `/en/puzzlers` · `src/Component/Players/Spotlight.php` | all · - | Activity ranking | People | - |
| E11 | Players page **This week** ("On a roll", moment chips "New best on N pieces", "Reached N puzzles", "N pieces placed") | `src/Component/Players/ThisWeek*` (translations `players.this_week.*`) | all · - | Milestones that **duplicate achievements** (Puzzle Explorer, Piece Cruncher) | People | Milestones that are neither XP nor achievements, a third "milestone" vocabulary. |
| E12 | Players page **Country Cup** (monthly, pieces per active puzzler) | `src/Component/Players/CountryCup.php` | all · - | Seasonal ranking | Community | **The only seasonal ranking that exists today.** Relevant for "Points, soon seasonal". |
| E13 | Getting-started card: "See how I compare" → ladder | `templates/onboarding/_getting_started_card.html.twig:26` (Hub + own profile) | F/M newcomer · - | Ladder | onboarding | No XP / level step in the newcomer checklist; the checklist has its own progress bar next to the XP bar on the profile. |
| E14 | Free-trial feature cards "More from the ladder" | `free_trial_started` · `templates/membership/_free_trial_feature_cards.html.twig` | new trial members · - | Ladder | onboarding | **No card for achievements / reveal**, though every teaser says "become a member to reveal them". |
| E15 | Membership page copy: "Membership badge", "Gamification - earning experience and levels" **(listed as a coming member benefit)**, "Badges for various achievements" | `membership.full_description` (`translations/messages.en.yml:2595-2615`) | all · - | XP, achievements | Decide to buy | **Contradicts the locked rule "XP free forever"** and still calls achievements "soon". Must be rewritten at launch. |
| E16 | Homepage SEO copy ("global leaderboard", "MSP Rating ladder measures your overall skill") | `templates/homepage.html.twig:136`, `homepage.seo_sections.leaderboards` | all · - | Ladder, Points | SEO | - |

### F. Launch reveal and share cards

| # | Touch point | Route / file(s) | Who · flag | Concept · prominence | Goal → helps? | Mobile-first issues |
|---|---|---|---|---|---|---|
| F1 | **XP launch reveal page**. Members: intro (1200×675 hero, title, "N achievements and your level are waiting", "Reveal them") → deck (XP counter bar, "3 / 18", sound, "Skip to summary"; a last-piece card per badge in crescendo order: name, tier, ✓ requirement, "+N XP"; big "Next" button; level-medallion finale) → summary (medallion, XP, badge grid, "Share my level", "Take me to my profile"). Free: autoplay level medallion, XP count-up, greyed badges + teaser, share, continue. | `xp_launch_reveal` `/en/my/xp-reveal` · `templates/xp_launch_reveal.html.twig`, `src/Controller/XpLaunchRevealController.php`, `assets/controllers/xp_launch_reveal_controller.js`, `xp_count_controller.js`, `xp_reveal_finish_controller.js`, `src/Query/GetXpLaunchRevealBadges.php` | O, once (`HintType::XpLaunchReveal`) · `xp` | XP, AP | One-time delight → helps, if people find it | (1) **Reachable only from the reveal e-mail**: no banner, no announcement modal, no profile hint, although the once-per-player `AnnouncementModal` system exists for exactly this. Bounced, opted-out-of-mail and non-opening players never see it. (2) **Not a quiet route**, so the free-trial offer modal can open over the deck. (3) **The free variant has no membership button**: only the sentence "…become a member to reveal them!". (4) A veteran member taps through up to 17 cards plus the level card; "Skip to summary" is a small `btn-link`. (5) The hint is set only by "Take me to my profile"; leaving via Share or Back keeps it pending (fine), but there is no reminder path. |
| F2 | **Share card PNG** `launch` | `xp_share_card` `/xp-card/{playerId}/launch` · `src/Controller/XpShareCardController.php` | O (owner shares) · `xp` | XP | Share | ok |
| F3 | Share card `level-up` | same, `variant = level-up` | - · `xp` | XP | - | **Orphaned:** nothing links or shares it (the level-up overlay has no share button). |
| F4 | Recap **result share image** | `result_image` | O · - | none | - | Carries no level / tier, by design. |

### G. E-mails, settings, notifications

| # | Touch point | Route / file(s) | Who · flag | Concept · prominence | Goal → helps? | Issues |
|---|---|---|---|---|---|---|
| G1 | **XP reveal e-mail** (inline 185 KB hero, greeting, intro, "Level N", "N XP earned…", achievements line, **text link** "See your reveal moment", outro, explainer link) | `templates/emails/xp_reveal.html.twig`, `SendXpRevealEmailHandler`, `myspeedpuzzling:send-xp-reveal-emails` | all with XP, not opted out · `xp` | XP, AP | Bring people to F1 → the only door | The CTA is an underlined text link, not a 44 px Inky button. Free: "quietly waiting" with no membership link. |
| G2 | **Weekly digest**: subject "Your week in puzzling 🧩" / quiet variant; blocks: greeting → **"Your XP this week"** (+N XP, levels climbed; achievements as badge rows for members, a teaser for free) → week in numbers → 🔥 streak → favourites → **"Within your reach"** (members, next tiers with %) → community most-solved → footer (settings link, unsubscribe) | `templates/emails/content_digest_weekly.html.twig`, `templates/emails/content_digest/*.twig`, `src/Services/Digest/WeeklyDigestDataProvider.php`, `SendPlayerContentDigestHandler` | default-on, not opted out · `xp` | XP, AP, streak | Recurring touch | **No call to action anywhere**: no button or link to the profile, the achievements, the history, the ladder, a puzzle or a favourite. The only links are the settings (`edit_profile`, login required) and unsubscribe. Badge rows are not links. The free teaser has no membership link. No total XP / progress to the next level / rank in the XP block. Rating / skill-tier movement (block 6) is not built. |
| G3 | Digest **unsubscribe page** | `content_digest_unsubscribe` `/{_locale}/weekly-digest/unsubscribe/{playerId}` · `templates/unsubscribe_content_digest.html.twig` | anyone with the link · - | - | ok | "re-enable it in your notification settings": wording fine. |
| G4 | **Settings: "Hide the experience system (XP & levels)"** | `edit_profile` · `templates/edit-profile.html.twig:34`, `src/FormType/FeaturesOptionsFormType.php:43` | O · `xp` | XP | Opt out | Sits next to "Hide my streak", "Hide my ranking & skill", "Hide time predictions": **4 separate gamification switches with 4 wording styles**. Achievements / AP have no opt-out, although the help text implies "leaderboard entries disappear everywhere". |
| G5 | **Settings: "Weekly digest email"** select (none / **"Daily + weekly"** / weekly) | `edit-profile.html.twig:59`, `MessagingSettingsFormType.php:63` | O · `xp` | digest | - | Offers **daily, which does not exist** (README decision #6: becomes a boolean). |
| G6 | In-app notifications / bell | - | - | - | - | **None** (by design). Teammates who earned XP on a group time get no signal at all. |
| G7 | Activity feed | `RecentActivity` | - | - | - | No XP / achievement entries (by design, leak inventory row unticked). |

### H. Public API (exempt from the flag)

| # | Field | File | Notes |
|---|---|---|---|
| H1 | `rating[] {pieces_count, points, rank, total_players}` on `/api/v1/players/{id}` and `/api/v1/me` | `src/Api/V1/PlayerRatingResponse.php` | **The only place MSP Rating is called "points".** |
| H2 | `skill[] {… tier …}` (`enthusiast`…`legend`) | `src/Api/V1/PlayerSkillResponse.php` | Members only (token owner). |
| H3 | `badges: list<string>` (types only, no tier) | `src/Api/V1/PlayerProfileResponse.php:76`, `CurrentUserResponse.php:62`, `src/Services/Api/ProfileInsightsResponseFactory.php:59` | Ignores membership and opt-out (README open item); no tier, no AP. |
| H4 | XP / level / AP | - | **Not in the API at all.** |

---

## 2. Critical journeys end to end (★ = a progression element on that step)

### (a) Logging a time → recap → celebration → profile (phone, solo timed 500 pc)
1. Puzzle page: ★ B1 XP estimate under the chips (above the fold, 1-3 lines); ★ B10 position line + tier icons in the leaderboard; ★ C1-style difficulty chip (members).
2. Add-time form (`puzzle_add`): no progression (good). The first-try / pace checks may answer 422.
3. Redirect to `added_time_recap`. Above the fold at 375 × 667: H1, duplicates review (if any), 3 buttons + collection CTA, the top of the 320 px share image.
4. About 0.5-2.5 s later: ★ **B4 level-up overlay covers everything** (week 1: almost every solve), confetti; tap to dismiss (server round trip).
5. Scroll: ★ B3 receipt (≈ 400 px of lines, total, Lv chip, bar, free teaser), ★ B5 badge toast (members, static), ★ B6 "Your Ranking", B8 prediction, history, ★ B7 "Your Overall Progress" (Skill Tier + MSP Rating, or a blurred mock + "Members exclusive").
   **5 progression blocks and 3 kinds of number on one recap.**
6. Back or reload: ★ the overlay plays **again**, and the toast too (within 3 minutes).
7. Profile (via the menu): ★ A1 ring (no information), ★ C1 tier chip, ★ C2 duplicate avatar + Lv + bar + "My XP history", ★ C3/C4 skill and rating cards, … ★ C7 a "?" medallion to flip (members) at the very bottom.
   Nothing on the profile says "you just earned X".

### (b) A new player's first week
1. Register → `/welcome` → finish profile → Hub with the getting-started card. No XP and no achievements mentioned anywhere in onboarding (A3, E9, E13).
2. First solve: receipt "Solve +5, Weekly boost +3, Daily warm-up +2" → ★ **level-up overlay to Lv 3** (5 / 10 XP thresholds).
   Free: ★ the teaser "This solve counted toward N waiting achievements 🔒" as soon as one bronze exists (Unboxed / Zen / Photographer / Team Spirit are tier 1 at 1 solve), with a count that is not this solve's.
3. Solves 2-5 in week 1: ★ an overlay on nearly every solve (Lv 4 at 16, 5 at 22, 6 at 29, 7 at 37), so the celebration loses value fast. The weekly boost ends after 5 solves without being shown.
4. Own profile: ★ getting-started progress bar **and** XP progress bar **and** (members) skill-tier "Skill Tier Qualification" bars + MSP "0/20, 0/50" bars. **4-6 progress bars**, all saying "not yet".
   Free: ★ the lock chip (C1), ★ "Player Insights" lock (C5), ★ the achievements teaser (C8).
5. First Monday: ★ the digest with "+N XP, you climbed N levels" and no link back.
6. The newcomer never sees the word "Achievements" unless they scroll to the bottom of their own profile (free teaser) or hit Level 50.

### (c) A member browsing achievements
1. There is no nav / menu / footer link. The entry is ★ C7 at the bottom of the profile, or "Browse all achievements".
2. ★ D1 catalogue: AP chip (styled like the level chip), 17 long cards, tier requirements hidden in hover titles, "+N AP" per tier, progress toward the next tier, "See who earned it".
3. ★ D2 holders page: Diamond hero, ISO-code country select, small holder pills.
4. Back to the profile: ★ C9 info modal on tap; ★ C10 flip for unrevealed ones; the AP total is not on the profile.
5. ★ The AP ladder via the catalogue subtitle link (E4 tab 2); rows show "Lv" chips, not achievement counts.
6. Newly joined member: Stripe → `my_profile` (no invite) or free trial → `free_trial_started` (no achievements card). ★ D3 invite only on `/membership`, so the promised reveal is easy to miss.

### (d) A competitive player checking rankings
1. Nav "Leaderboard" → ★ E1 two promo alerts → country select → 6 Top-10 time tables (tier icons for members).
2. To reach the MSP Rating: the banner button (not in the dropdown) → ★ E3 BETA, "head-to-head" subtitle, "brand new" alert, countdown, own position / qualification card, filters, table without headers.
3. Own status: ★ C1 header chip (500 pc tier), ★ C3 per-piece skill cards, ★ C4 "#12 · 847".
4. Per puzzle: ★ B10 "Rank X of Y · faster than N %". After a solve: ★ B6 "Your Ranking" + ★ B7 "Overall Progress".
5. Players page: ★ E12 Country Cup (monthly), ★ E10 "Fastest puzzlers: the leaderboard".
6. The XP ladder (E4) borrows the competitive look (🥇) for an activity metric.
   **"Rank" appears as a position in 4 places; the tier is the real "rank" status but is called "Skill tier".**

### (e) A player opening the weekly digest
1. Inbox: subject "Your week in puzzling 🧩" (or "A quiet puzzle week — we saved you a seat"), preheader "Your XP, solves and achievements…".
2. Body: greeting → ★ "Your XP this week: +N XP earned — you climbed N levels, now Level N!" → ★ achievements: members get badge rows, free get the teaser → ★ week in numbers → ★ 🔥 streak → favourites → ★ "Within your reach" (members) → community.
3. **Dead end:** nothing to tap except "notification settings" (→ `edit_profile`, login wall) and "unsubscribe".
   No "See your week", no "Reveal your achievement", no "Join to unlock", no favourite or puzzle links, no total XP or next-level distance.

### (f) The one-time XP launch reveal
1. E-mail G1 (subject "Your whole puzzling journey just turned into XP ✨"): hero, Level N, N XP, achievements line, text link "See your reveal moment".
2. `/en/my/xp-reveal` (remember-me OK). Members: ★ hero → "Reveal them" → ★ deck (counter climbs, `n / total`, sound, Skip) → ★ per-badge last-piece reveal + "+N XP" → ★ level medallion finale → ★ summary + "Share my level" (F2) + "Take me to my profile" (sets the hint) → profile (★ C2/C7).
   Free: ★ the level medallion assembles itself, ★ the XP count-up, ★ greyed badges + teaser with no button, share, continue.
3. Nobody without the e-mail ever reaches it; there is no in-app trigger. The free-trial modal may stack on top (not a quiet route).
4. After launch, the profile also has ★ flip medallions (C10) for the same badges, unless the deck revealed them (it POSTs `reveal_badge` for each), so mechanics differ by entry point.

---

## 3. Terminology in the en UI today

### 3.1 By concept

| Concept | Keys → strings (en) |
|---|---|
| XP / level | `xp.level_chip` "Lv %level%" · `xp.total` "%xp% XP" · `xp.progress_label` "%xp% XP to level %level%" · `xp.max_level_reached` "Top of the mountain — level 50!" · `xp.levelup.label` "Level up!" · `xp.receipt.title` "Your XP for this solve" · `xp.line.*` ("Solve", "Difficulty bonus", "Weekly boost", "Daily warm-up", "Speed bonus", "… (settled)", "Achievement earned", "Solve deleted") · `xp.history.title` "My XP history" · `xp.leaderboard.title` "XP Leaderboard" · `ladder.xp_ladder_cta` "XP ladder" · `xp.explainer.title` "How XP & Levels work" · `xp.reveal.level_label` "Level" · `edit_profile.hide_experience_system` "Hide the experience system (XP & levels)" · emails `content_digest.weekly.xp_title` "Your XP this week", `levels_gained` "…now Level %level%!" |
| Achievements / AP | `badges.title` / `overview_title` "Achievements" · `badges.my_title` "My achievements" (unused) · `badges.ap_total` "%points% AP" · `badges.ap_total_tooltip` "Your Achievement Points…" · `xp.leaderboard.tab.achievement-points` "Achievement Points" · `xp.leaderboard.ap_title` "Achievement Points Ladder" · `xp.leaderboard.value.achievement-points` "AP" · `xp.levelup.enter_ap_ladder` "Enter the AP ladder" · `badges.tier.*` Bronze/Silver/Gold/Platinum/Diamond · `badges.badge.supporter` "Early Adopter" · `badges.reveal` "Reveal your new achievement" · `xp.achievement_toast.title` "New achievement!" · `badges.new` "NEW" · emails `badges_earned.subject` "You earned new **badges**…", `badges_earned.title` "%count% new **badge(s)** earned!" · `membership.full_description` "Membership badge", "Badges for various achievements" · URLs `/en/badges` (301), `/en/badges/{id}/reveal` |
| Karma | none (Librarian: "Help keep the puzzle catalog accurate…", Cataloger: "Grow the puzzle catalog…") |
| Points = MSP Rating | `puzzle_intelligence.elo.title` "MSP Rating Ladder" · `elo.profile_title` / `qualification_title` "MSP Rating" / "MSP Rating Ranking" (heading of the profile card) · `elo.rating` "Rating" · `elo.subtitle` "Competitive ranking based on head-to-head performance" · `elo.beta_badge` "BETA" · `elo.ranked_out_of` "Ranked among %total% players" · `elo.not_ranked_yet` "Not ranked yet" · `elo.view_full_ladder` "View full ladder" · `ladder.msp_elo_cta` "MSP Rating Ladder" · `players.card.rating` "MSP Rating %rating%" · `added_time_recap.progress.elo_title` "MSP Rating" · `xp.explainer.currencies.rating_name` "MSP Rating" ("Members (unchanged, separate)") · `edit_profile.hide_ranking` "Hide my ranking & skill" (help: "…Rating ranking…") · `footer.methodology` "Difficulty & Rating Methodology" · API `points` |
| Rank = skill tier | `puzzle_intelligence.skill.tiers.*` Enthusiast … Legend · `player_header.skill_tier` "Skill tier" · `player_header.tier_title` "Skill tier for %pieces% pieces" · `player_header.no_tier_yet` "No tier yet" · `added_time_recap.progress.skill_tier` "**Skill Tier**" · `puzzle_intelligence.skill.title` "Skill Profile" · `skill.tier_label` "Tier" · `skill.progress_title` "Skill Tier Qualification" · `puzzle_intelligence.player_insights.title` "Player Insights" (the lock button) · `skill.ranking_opted_out` "You have opted out of skill tracking." · `players.card.tier_locked` "Skill tier" |
| Positions | `puzzle_times.my_attempts.rank` "Rank %rank% of %total%" · `puzzle_times.stats.rank` "#%rank% of %total%" · `added_time_recap.ranking.title` "Your Ranking" / `.position` "#%rank% out of %total% solvers" · `elo.rank_of_total` "#%rank% of %total%" · `elo.your_position` "Your position: #%rank%" · `xp.leaderboard.your_position` "Your position" |
| Ladders | `menu.ladder` "**Leaderboard**" · `ladder.title` "Speed Puzzling Leaderboard" · route/URL `ladder` `/en/ladder` · "MSP Rating **Ladder**" · "XP **Leaderboard**" (title) vs "XP **ladder**" (CTA) · "Achievement Points **Ladder**" · `xp.leaderboard.all_leaderboards` "All leaderboards" · `players.spotlight.fastest_world` "Fastest puzzlers: the leaderboard" · `free_trial.features.ladder.title` "More from the ladder" · `onboarding…all_set_ladder` "See how I compare" |
| Streak | `statistics.longest_streak` "Longest streak" · `activity_calendar.current_streak` / `longest_streak` · `edit_profile.hide_streak` "Hide my streak" · badge "On Fire" · email "…%days%-day streak" |
| Digest | `edit_profile.content_digest_frequency` "Weekly digest email" (options incl. "Daily + weekly") · `content_digest.unsubscribe.*` "weekly digest" · e-mail subject "Your week in puzzling" |

### 3.2 Inconsistencies to fix

1. **"Achievements" vs "badges".** The plan locks "Achievements" (badge = only the medallion), but the e-mail subject and title say "badges", the reveal URL is `/badges/…/reveal`, the membership page says "Badges for various achievements" and "Membership badge", and `new_badge` / `menu.new_badge` "NEW" pills are "badges" in the keys. The "Member" chip is also "Membership badge" in copy.
2. **"Leaderboard" vs "Ladder"** for the same kind of thing:
   - the nav says "Leaderboard", the page H1 "Speed Puzzling Leaderboard", the URL `/ladder`;
   - "MSP Rating Ladder", "Achievement Points Ladder", but "XP Leaderboard" (H1) and "XP ladder" (CTA) for one page;
   - the footer lists "XP Leaderboard" and "Achievement Points Ladder" side by side.
3. **"Rating" / "Ranking" / "Rank" / "Points".**
   - MSP Rating is called "MSP Rating Ranking" (profile card heading), "Rating" (column), "Rating ranking" (opt-out help) and `points` (API).
   - "Ranking" also titles the per-puzzle position ("Your Ranking"), and "Rank" is a position everywhere.
   - "points" in the UI means Achievement Points only (plus the comparison's "points on the line-up").
   - If the owner's "Points" becomes the MSP Rating, it collides with **Achievement Points / AP**.
4. **"tier" means three things:**
   - skill tier (Enthusiast…Legend; assets call it "rank");
   - difficulty tier (Very Easy…Very Hard; "strongest on %tier% puzzles");
   - achievement tier (Bronze…Diamond, "Next: %tier%").
   And "Tier" alone is the label of the skill card.
5. **Skill-tier naming:**
   - "Skill tier" vs "Skill Tier" (recap) vs "Tier" vs "Skill Profile" vs "Player Insights" (the lock) vs "skill tracking" (opt-out);
   - the top tier is "Legend" in code and UI but "Grandmaster" in `docs/features/puzzle-intelligence/README.md`;
   - the opt-out label is "Hide my ranking & skill".
6. **Level naming:** "Lv %level%" (chip) vs "Level %level%" (reveal, e-mails) vs "level 50" (lower case in copy). Fine as chip vs prose, but pick one for the chip and the ladder rows.
7. **Wrong or stale facts in copy:**
   - `xp.explainer.currencies.rating_who` "Members" (the ladder is public);
   - `elo.subtitle` "head-to-head";
   - `ladder.msp_elo_banner` "We just launched … BETA";
   - `xp.explainer.faq.friend_answer` "Tap any receipt line";
   - `membership.full_description` "Gamification - earning experience and levels" under "What can you look forward to soon?" (implies a member perk);
   - `added_time_recap.progress.skill_tier_unavailable` computed from 20 instead of 10;
   - dead keys `elo.period_monthly`, `period_all_time`, `section_elo_periods`, `section_elo_formula` (K-factor);
   - `xp.estimate.repeat_note` "×%multiplier%" (missing "%").
8. **Same visual for different currencies:** the AP total and the level both use `.xp-level-chip` (indigo pill), so "120 AP" and "Lv 12" look identical.
9. **Three reveal mechanics** for the same moment: CSS flip with a "?" medallion (profile), last-piece puzzle (reveal page), auto-assembling deck (launch).
10. **Two explainer pages** ("How XP & Levels work" vs "Difficulty & Rating Methodology"), neither linking the other; the XP one lists the MSP Rating as a third "currency".

---

## 4. Mobile-first problems: the 10 most important

1. **The recap is overloaded and interrupted** (B3-B9). At 375 px the receipt sits below the 320 px share image. A full-screen level-up overlay fires on a route the site itself marks as "quiet", in week 1 on nearly every solve, **replays on every reload or back**, and needs a server round trip to close. The badge toast is inline below the fold and never closes. One 2.5 s poll can leave "Counting your XP…" stuck. 5 progression blocks (receipt, toast, Your Ranking, prediction, Skill Tier + MSP Rating) compete with "Add new time".
   → Persist dismissal; make the overlay a small bottom sheet or a ring animation in the receipt; move the receipt above the image on phones; one combined "progress" card.
2. **Duplicate and stray avatar on every profile** (C2). The XP block wraps a second 52 px avatar under the header. Because `XpRing` falls back to its inner content, **visitors already see a bare stray avatar today while the flag is on** (and so do opted-out or private players later).
   → Ring the header avatar (README decision #4) and delete the block.
3. **The profile top has 3 systems and up to 6 progress bars** (C1-C8, E13): header tier chip, Lv / XP / bar, getting-started bar, skill-qualification bars per piece count, MSP 0/20 + 0/50 bars, plus up to 3 paywall locks for free viewers. Meanwhile the **achievements, the one visual reward, sit at the very bottom**, and the AP total is nowhere on the profile.
4. **Discoverability holes.**
   - The Achievements catalogue has no nav, menu, footer or Hub link.
   - XP history is reachable only from the own profile.
   - **The launch reveal is reachable only from one e-mail** (no in-app trigger, although the once-per-player announcement-modal system exists).
   - The reveal invite after joining is only on `/membership` (Stripe success and free trial land elsewhere).
   - The level-up share card is orphaned.
   - The Hub (signed-in home) shows no progression at all.
5. **The weekly digest is a dead end** (G2): zero calls to action or content links; only settings (login wall) and unsubscribe. Free teasers have no link to membership. "Daily + weekly" is offered but does not exist.
6. **Five ranking systems with no common frame** (E1-E4, E9-E12, B6/B10).
   - The ladder page opens with two promo alerts (the XP one does not wrap at 320 px).
   - The ladder switcher lists XP + AP but not the MSP Rating.
   - The XP/AP board uses its own striped table, emoji medals and a Lv chip on every row (wrapping at 375 px; about 115 identical gold "Lv 50" at launch); the self card ignores the filters; no paging; ISO country codes; the guest AP tab has no login link.
   - The MSP ladder has three info blocks before the table, no column headers and a wrong subtitle.
   - For "Points, soon seasonal": the only seasonal board today is the Country Cup, and `player_rating_snapshot` is unused.
7. **Paywall teasers are repeated, miscounted or missing their button.**
   - Receipt: "This solve counted toward N waiting achievements" counts all badges, not this solve's, on every solve.
   - Recap: blurred mock in hard-coded English.
   - Free launch reveal and digest teasers: no membership button.
   - The Level-50 overlay sends free players to a ladder they can't join (and links the XP tab, not AP).
   - The generic `#membersExclusiveModal` never says which feature was tapped.
8. **The XP estimate on the puzzle page** (B1) is above the fold in an already crowded chip row, shown to guests without explanation. It **leaks the members-only difficulty tier** through the XP number and the "unrated" note, overpromises the bonus, and has the "×50" copy bug.
9. **Tap targets and overflow:**
   - Receipt lines are 44 px tall but not interactive (wasted height), while the copy promises tappable lines.
   - Holder pills (≈28 px), "Newest earners" chips, `btn-xs` "?" methodology buttons, the "NEW" pills and the `form-switch` favourites toggles are under 44 px.
   - `PlayerRatingProfile` qualification card `min-width: 300px` overflows at 320 px.
   - The catalogue tier row (5 × 48 px + labels) is at its limit at 375 px and wraps in longer locales.
   - Tier requirements live only in `title=` tooltips, invisible on touch.
   - The XP history repeats the puzzle name on 3-6 lines per solve.
10. **Naming collisions block the 5-concept model** (section 3.2): Achievements/badges, Leaderboard/Ladder, Rating/Ranking/Rank/Points, three "tiers", Skill tier/Skill Tier/Tier/Player Insights, AP chip styled like the level chip, three reveal mechanics, two explainer pages, the membership copy promising XP as a member perk. Introducing "Points" (MSP Rating) and "Rank" (skill tier) needs a rename pass first:
    - "Points" vs "Achievement Points": drop "Points" from one of them, e.g. AP → "Achievement score" or MSP Rating → "MSP Points";
    - "Rank" vs "#position": call positions "Place" / "#12", or name the tier "Rank".

### Further issues (lower priority)
- The recap's MSP Rating progress shows qualification for piece counts with no ladder (only 500 exists). The skill-tier remaining count uses 20 instead of 10.
- The free-trial announcement modal is allowed on `added_tracking_recap`, `xp_launch_reveal` and `badge_reveals` (not quiet routes), so it can stack with the level-up overlay or the reveal deck.
- Teammates of a pair/team time earn XP with no notice of any kind; they see it only by opening the recap URL (which they don't get).
- Settings: 4 separate opt-outs with inconsistent wording. The XP opt-out ("Hide the experience system (XP & levels)") also removes the player from the **AP** ladder (`GetXpLeaderboard::PUBLIC_ELIGIBILITY` applies `experience_system_opted_out` to both boards), but their achievements stay on the profile. The label doesn't say it affects achievements at all.
- The API exposes `badges` regardless of membership or opt-out and has no XP / level / AP at all.
- Hard-coded English: `aria-label="Close"` in the achievement modal, "Progress toward next achievement tier", the recap's blurred mock.
- `xp_explainer` still carries `COPY:pending-jan-approval`.
- Level-up confetti loops infinitely (honours reduced motion only); the flip medallion wiggles forever.
- The header ring costs a query on every signed-in page and conveys nothing (always a full circle).

---

## 5. Where Karma would plug in (not built)

Existing surfaces where "community upkeep" already happens or is rewarded:

| Surface | Route / file | Today's reward |
|---|---|---|
| "Suggest a change" (puzzle change proposal) | `PuzzleReport/ProposeChangesController`, `puzzle-report/*` | Librarian achievement (on approval) |
| Report duplicate / merge request | `ReportDuplicatePuzzleController` | Librarian achievement (on approval) |
| Add puzzle (approved) | `puzzle_add`, puzzle approvals queue | Cataloger achievement |
| Moderator queues (`/admin/puzzle-approvals`, change/merge requests, `/admin/time-verification`) | `src/Controller/Admin/*` | Moderator role only (`moderator_since`) |
| Feature requests (votes, comments) | `FeatureRequest/*` | none |
| Marketplace ratings | `Rating/*` (`rating.*` keys = "Your rating", transaction ratings!) | none; **another "rating"** collision |

Karma would overlap Librarian and Cataloger (which already turn upkeep into AP + XP), and its name must not collide with
the marketplace's "rating" (`rating.your_rating` "Your rating") or the MSP Rating.

---

## 6. File index (absolute)

- Templates:
  - `templates/player_profile.html.twig`
  - `…/templates/components/PlayerHeader.html.twig`
  - `…/templates/components/XpRing.html.twig`
  - `…/templates/components/XpSolveReceipt.html.twig`
  - `…/templates/components/XpRecapCelebration.html.twig`
  - `…/templates/components/XpPuzzleEstimate.html.twig`
  - `…/templates/components/XpRevealInvite.html.twig`
  - `…/templates/components/BadgesProfileSection.html.twig`
  - `…/templates/components/PlayerSkillProfile.html.twig`
  - `…/templates/components/PlayerRatingProfile.html.twig`
  - `…/templates/components/MspRatingLadder.html.twig`
  - `…/templates/added_time_recap.html.twig` (+ `added_time_recap/_ranking.html.twig`, `_overall_progress.html.twig`)
  - `…/templates/added_tracking_recap.html.twig`
  - `…/templates/badges_overview.html.twig`
  - `…/templates/achievement_detail.html.twig`
  - `…/templates/_achievement_info_modal.html.twig`
  - `…/templates/badge_reveals.html.twig`
  - `…/templates/xp_launch_reveal.html.twig`
  - `…/templates/xp_leaderboard.html.twig`
  - `…/templates/xp_history.html.twig`
  - `…/templates/xp_explainer.html.twig`
  - `…/templates/xp_fair_play.html.twig`
  - `…/templates/ladder.html.twig`
  - `…/templates/_ladder_dropdown.html.twig`
  - `…/templates/msp_rating_ladder/index.html.twig`
  - `…/templates/_footer.html.twig`
  - `…/templates/base.html.twig`
  - `…/templates/membership.html.twig`
  - `…/templates/edit-profile.html.twig`
  - `…/templates/puzzle_detail.html.twig`
  - `…/templates/emails/xp_reveal.html.twig`
  - `…/templates/emails/badges_earned.html.twig`
  - `…/templates/emails/content_digest_weekly.html.twig`
  - `…/templates/emails/content_digest/*.twig`
- Components and controllers: `…/src/Component/Xp*.php`, `BadgesProfileSection.php`, `PlayerHeader.php`; `…/src/Controller/Xp*Controller.php`, `Achievement*Controller.php`, `Badge*Controller.php`, `MspRatingLadderController.php`, `AddedTimeRecapController.php`
- Styles: `…/assets/styles/components/_xp.scss`, `_badge-art.scss`, `…/assets/styles/_last-piece-reveal.scss`
- Copy: `…/translations/messages.en.yml` (sections `xp`, `badges`, `ladder`, `puzzle_intelligence.elo` / `.skill`, `added_time_recap`, `player_header`, `players`, `membership`, `edit_profile`), `…/translations/emails.en.yml` (`xp_reveal`, `badges_earned`, `content_digest`)
- Quiet-route list: `…/src/Services/AnnouncementModals/ResolveAnnouncementModal.php:40`
