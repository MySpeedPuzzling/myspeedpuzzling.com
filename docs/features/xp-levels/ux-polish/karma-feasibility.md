# Karma — what community contributions MySpeedPuzzling already records

Read-only analysis, 2026-10-10. Code read on `badges-rebase-2026-10-09` (entities are the same as `main` for everything below).

## 0. Status of the numbers

Numbers in **section 7** come from the local dev copy (`speedpuzzling` DB), queried read-only
(`default_transaction_read_only=on`, SELECT only). **The copy ends on 2026-09-25** (last solving time 16:29, last
decision 16:03) - it predates the community moderators (0 players with `moderator_since`), the time-verification
queue (0 decisions), organizations (0), the `outdated` request status and the 2026-10-05 code-proposal wave. Re-run
on a fresh copy before deciding; the shape will not change much, the moderation rows will.

Admin players (`player.is_admin`, 2 players) are **excluded** from every distribution and simulation (they still
show in q1's `by_admins` column).

---

## 1. Inventory: what is recorded, who gets credit, is the outcome known

Legend — **Outcome**: is it known whether the contribution was accepted? **Survives**: is the contributor still
attributable after merges / deletions?

### A. Puzzle catalogue (the core of the karma idea)

| # | Contribution | Table / columns | Contributor | Outcome | Survives merges / deletions |
|---|---|---|---|---|---|
| A1 | **Change proposal** ("Suggest a change") | `puzzle_change_request`: `reporter_id`, `submitted_at`, `status` (pending/approved/rejected/outdated), `reviewed_by_id`, `reviewed_at`, `rejection_reason`, `outdated_reason` (already_applied / already_merged / puzzles_gone), `proposed_*` vs `original_*` (name, other names + languages, brand, pieces, EAN, brand code, image) | `reporter_id` | Yes (status). Field-level diff computable (`proposed_x IS DISTINCT FROM original_x`) → "trivial edit" detectable | Puzzle merge: the request **moves** to the survivor (`merged_from_puzzle_id` set) — kept. **Reporter deletion: `ON DELETE CASCADE` — the row is gone** (its decision-log row survives, without reporter) |
| A1b | **EAN linked by scanning** (multiscan / scanner) | same table, written by `LinkEanToPuzzleHandler`: puzzle without code → code written at once + an **already-approved** request with `reviewed_by_id = reporter_id`; puzzle with another code → normal pending proposal | `reporter_id` | "Approved" but **self-approved, never reviewed by a person**. No decision-log row | as A1 |
| A2 | **Merge proposal** ("these are duplicates") | `puzzle_merge_request`: `reporter_id`, `submitted_at`, `source_puzzle_id`, `reported_duplicate_puzzle_ids`, `status`, `reviewed_by_id`, `reviewed_at`, `survivor_puzzle_id`, `merged_puzzle_ids`, `outdated_reason`, `outdated_by_merge_request_id` | `reporter_id` | Yes | Puzzles: `source_puzzle_id` SET NULL but `source_puzzle_name` + id lists kept. **Reporter deletion: SET NULL** (row kept, credit lost — fine for karma) |
| A3 | **Merge execution audit** | `puzzle_merge_audit`: `merge_request_id`, `performed_by_id`, `performed_at`, `decision_source` (admin_ui / internal_api / automatic), `decision_confidence`, `snapshot_before` (json: survivor + every merged puzzle **incl. `addedByUserId`, `addedAt`, `image`**) | `performed_by_id` = decider | n/a (always an approval) | Exists since **2026-09-16** only; earlier merges have no snapshot |
| A4 | **Moderator / admin decisions** (append-only log) | `puzzle_moderation_decision`: `action` (change_request_approved/rejected, merge_request_approved/rejected, *_outdated, puzzle_approved, brand_approved, brand_merged, brand_deleted, puzzle_edited), `decided_at`, `decided_by_id` (+ copied name/code), `source` (admin_ui / internal_api / automatic), ids of request/puzzle/brand, `note`, `details` | `decided_by_id` | n/a — it *is* the decision | **Best-surviving source**: no FKs, outlives requests, puzzles, brands and the decider's player row. Backfilled (`details.backfilled = true`) on 2026-09-25 from requests still existing then; puzzle approvals before the queue (SQL bulk approval of 6,147 puzzles on 2026-09-17) are **not** in it |
| A5 | **New puzzle added** | `puzzle`: `added_by_user_id`, `added_at`, `approved`, `approved_at`, `approved_by_id`, `image` (box photo mandatory since the approvals queue; only competition-round puzzles and internal-API `AddApprovedPuzzle` may lack one) | `added_by_user_id` | Yes: `approved` true/false. **No reject** exists — a bad/duplicate add ends **merged away** | Merged-away puzzle row is deleted → credit survives only in `puzzle_merge_audit.snapshot_before->mergedPuzzles[].addedByUserId` (since 2026-09-16). Player deletion → `added_by_user_id` set NULL. `approved_by_id` NULL for everything approved by SQL before the queue |
| A6 | **New brand added** | `manufacturer`: `added_by_user_id`, `added_at`, `approved`, `logo` | `added_by_user_id` | approved true/false; a duplicate brand is merged (deleted) → `brand_merged` decision row, no adder kept | Merged brand row deleted, adder lost. Most brands are a **side effect** of typing a brand in the add form (root cause of the 2,277→2,057 duplicate cleanup) — weak signal of merit |
| A7 | **Duplicate puzzle signals** | `duplicate_puzzle_signal`: `detected_at`, `status` (open/merge_proposed/dismissed), `merge_request_id`, `example_player_id` | **No human contributor** — computed by a cron; `example_player_id` is just an example solver | — | Not a contribution. The admin who presses "Propose merge" becomes the merge request's reporter (A2) |

### B. Moderation work outside the catalogue

| # | Contribution | Table / columns | Contributor | Notes |
|---|---|---|---|---|
| B1 | **Time verification decisions** | `suspicious_time_decision`: `decision` (marked, trusted, unmarked, kept_after_reply, pieces_confirmed, slow_threshold_set/removed; also automatic/SQL kinds: corrected_automatically, unmarked_after_edit, marked/unmarked_outside_app), `decided_at`, `decided_by_id` (+ name/code), `tracker_id` | `decided_by_id` | Live since 2026-10-07 only. Only the human kinds count; exclude `decided_by_id = tracker_id` |
| B2 | Puzzle slow threshold | `suspicious_time_puzzle_confirmation.confirmed_by_id` | moderator | Also logged as B1 `slow_threshold_set` — don't double count |
| B3 | Marketplace moderation | `moderation_action` (`admin_id`, warning/mute/ban…), report `resolved_by_id` | admins only | Admin work, not community — exclude |
| B4 | Moderator role itself | `player.moderator_since` | — | Use to label/segment, not to score |

### C. Event organising (community upkeep of the events database)

| # | Contribution | Table / columns | Outcome | Notes |
|---|---|---|---|---|
| C1 | One-time event / series edition created | `competition`: `added_by_player_id`, `created_at`, `approved_at`/`approved_by_player_id`, `rejected_at`/`rejected_by_player_id`, `is_draft`, `series_id` (edition) | approved / rejected / pending / draft | Player deletion → SET NULL. Items created inside an approved organization by its team are approved at once (`OrganizationApprovalPolicy`) → `approved_by = creator` possible |
| C2 | Series created | `competition_series`: same columns | same | |
| C3 | Organization created | `organization`: `added_by_player_id`, `created_at`, `approved_*`, `rejected_*`, `is_draft` | same | |
| C4 | Results entered, participant sheet edits, referees | `participant_sheet_change_receipt.acting_player_id`, `round_result_change_receipt`, `competition_referee.added_by_id`, maintainers | — | Organiser labour for their own event; better as an "Organiser" achievement than karma |

### D. Feedback & social upkeep

| # | Contribution | Table / columns | Outcome | Gaming risk |
|---|---|---|---|---|
| D1 | Feature request | `feature_request`: `author_id`, `created_at`, `status` (open/in_progress/completed/declined), `vote_count` | yes (status) | low (admin decides); declined = 0 |
| D2 | Feature vote | `feature_request_vote`: `voter_id`, `voted_at` (duplicate vote rows are intentional per memory) | — | trivially farmable → tiny value, cap, or none |
| D3 | Feature comment | `feature_request_comment`: `author_id`, `created_at` | — | farmable spam → cap or none |
| D4 | Reports (conversation, feature comment) | `conversation_report` / `feature_request_comment_report`: `reporter_id`, `status` (pending/reviewed/resolved/dismissed), `resolved_by_id` | yes | resolved = useful report; dismissed = 0 |
| D5 | Marketplace rating given | `transaction_rating`: `reviewer_id`, `rated_at`, `stars` (tied to a `sold_swapped_item`) | — | needs a real transaction; collusion possible; it is marketplace hygiene, not DB upkeep |
| D6 | Guest-link confirmation | `guest_link_request`: `requester_id`, `target_id`, `accepted`, `resolved_at` | accepted/declined | target accepting cleans up guest names into real players — tiny, mostly self-interest |

### E. Things that look like contributions but are self-housekeeping (recommend: not karma)
`result_duplicate_case` (resolving your own duplicates), `first_try_review_dismissal`, `suspicious_time_confirmation`
(confirming your own pace), `puzzling_team` renames, `player_code_change`. They fix the player's own data and are
partly *caused* by the player's own mistakes — rewarding them rewards creating them.

---

## 2. Attribution traps and abuse risks (must shape the formula)

1. **Admins and the internal API.** Every proposal filed through `/internal-api/puzzle-change-requests` and
   `/internal-api/puzzle-merge-requests` is attributed to `INTERNAL_API_REVIEWER_PLAYER_ID` (reporter = an admin player),
   e.g. the 94 code proposals of 2026-10-05. Duplicate-signal merges have the admin as reporter; approval-queue merges
   have the **moderator** as reporter. → **Exclude `player.is_admin` from karma**, and treat a moderator-filed merge on
   an unapproved puzzle as moderation, not a proposal.
2. **Self-approved EAN links (A1b).** `reviewed_by_id = reporter_id`, no human review. Scanning random codes onto
   code-less puzzles is the cheapest karma farm in the app. → low value, daily cap, and/or only for puzzles the player
   has in a collection/solved, and zero if the code is later removed by a change request.
3. **Moderators deciding their own proposals** (explicitly allowed, docs/features/community-moderators.md:79).
   → a self-decided proposal earns the proposal value at a reduced rate and **no** decision karma (can't double-dip).
4. **Rubber-stamping.** Decision karma per click rewards approve-everything. Measurable from the log: approve ratio per
   moderator, seconds between consecutive decisions, decisions later superseded (an approved change reverted by another
   change request on the same field). → flat small value per decision **with a monthly cap**, or a monthly "active
   moderator" stipend instead of per-click.
5. **Spam / trivial proposals.** Rejected = 0 (never negative — negatives discourage good-faith newcomers), but a
   reporter with a low acceptance rate (e.g. < 50 % after ≥ 5 decided) earns nothing until it recovers. Trivial edits are
   detectable field by field (name-language-only, casing-only name change, adding an "other name").
6. **Pending backlog = karma lag.** On 2026-10-07 there were 279 pending merge requests; 165 code proposals were left
   for people on 2026-10-05. Karma for proposals arrives only on decision → fine, but say so in the UI ("counts once
   reviewed"). `outdated` (someone else did it first, or a merge made it moot) was usually *right*: partial credit.
7. **Puzzle adds are a by-product of logging a time** (you must add the puzzle to log it). Still real catalogue work
   (photo + codes), but it will dwarf everything else in volume — weight it low, credit only `approved` and not merged
   away; a duplicate add (later merged into an existing puzzle) = 0.
8. **History gaps.** Merged-away puzzles before 2026-09-16 lost their adder; change requests of deleted players are
   gone; 6,147 puzzles approved by SQL have no approver; decision log starts 2026-09-25 (backfilled from surviving
   rows). For a *recomputable* karma these gaps are harmless (they were never visible), but do not promise "lifetime"
   accuracy before ~Sept 2026 for moderation.
9. **Backfill artifact in the decision log (found while running the queries).** The 2026-09-25 backfill turned every
   approved change request into a `change_request_approved` decision - including the 49 scanned-EAN self-approvals
   (4 players), which therefore look like moderator decisions by non-admins. `contrib.sql` and `q5.sql` filter them out
   (`reviewed_by_id = reporter_id AND reviewed_at = submitted_at`). Without that filter 4 ordinary players would get
   "moderator" karma.
10. **"Approved puzzle" is mostly not a review.** On the dev copy **0 of 41,282** puzzles have `approved_by_id`: everything
   approved before the queue was approved in bulk (by SQL), so `approved = true` for an add means "not merged away",
   not "checked by a person". Only 16 puzzles lack an image, so "with photo" does not discriminate either.
11. **Derivability.** Everything in sections A, B, C, D is derivable from existing tables with **no new write path**.
   A nightly/15-min recompute (like `community_player_stats`) into a `player_karma` read model would be enough. The one
   thing missing for robust anti-rubber-stamp scoring is "was this decision later reversed" — derivable but expensive;
   not needed for v1.

---

## 3. Production numbers already on record (context, not a distribution)

- 2026-09-16: backlog of **249 pending merge requests** (each a pair). 2026-10-07: **279 pending**, 3 already done by
  other merges, 24 sharing a puzzle. Change-request queue was empty on 2026-10-07 after the code cleanup.
- 2026-10-05: **263 pending code proposals** (from the codes canonicalisation); 94 approved via internal API by the admin
  reviewer, 4 by a moderator first, **165 left for people**.
- 2026-09-17: **6,147 unapproved puzzles approved by SQL** (no `approved_by`), 204 of 362 unapproved brands approved.
- Brands 2026-10-03: 2,277 (2,064 approved, 213 unapproved) → 2,057 after merging duplicates.
- Time verification go-live 2026-10-07: 434 pending cases.
- 2026-10-04: 12 change requests proposed the Ravensburger `45555…` misread.

These suggest: catalogue *proposals* are a few hundred to low thousands of rows, a large part of them from admins
(excluded) — so proposal karma alone will most likely reach **dozens, not thousands** of players, while *puzzle adds*
(thousands of players) are the only contribution with a broad base. Section 4 verifies this.

---

## 4. Ready-to-run SQL (read-only)

All statements are plain `SELECT`s. Run with a read-only transaction:

```bash
cd ~/www/speedpuzzling.cz
# once Postgres is up again (not started by this analysis)
cat contrib.sql q1.sql | docker compose exec -T postgres psql -U postgres -d speedpuzzling \
  -v ON_ERROR_STOP=1 -c 'SET default_transaction_read_only = on' -f -
```
(or prepend `BEGIN READ ONLY;` / append `ROLLBACK;`). `contrib.sql` is the shared CTE; each `qN.sql` continues it.

### contrib.sql — one row per contribution

```sql
WITH
admins AS (SELECT id FROM player WHERE is_admin),
moderators AS (SELECT id FROM player WHERE moderator_since IS NOT NULL AND NOT is_admin),
contrib AS (
    -- A1 + A1b change proposals
    SELECT r.reporter_id AS player_id,
           -- LinkEanToPuzzleHandler: approved by the reporter in the same instant, only proposed_ean set
           CASE WHEN r.reviewed_by_id = r.reporter_id AND r.status = 'approved' AND r.reviewed_at = r.submitted_at
                     AND r.proposed_ean IS NOT NULL AND r.proposed_name IS NULL AND r.proposed_image IS NULL
                THEN 'ean_link_self' ELSE 'change_request' END AS kind,
           r.status::text AS outcome, r.submitted_at AS at,
           (r.reviewed_by_id IS NOT NULL AND r.reviewed_by_id = r.reporter_id) AS self_decided,
           (r.proposed_image IS NOT NULL) AS with_photo
    FROM puzzle_change_request r
    UNION ALL
    -- A2 merge proposals
    SELECT r.reporter_id, 'merge_request', r.status::text, r.submitted_at,
           (r.reviewed_by_id IS NOT NULL AND r.reviewed_by_id = r.reporter_id), false
    FROM puzzle_merge_request r WHERE r.reporter_id IS NOT NULL
    UNION ALL
    -- A5 puzzles added (still existing)
    SELECT p.added_by_user_id, 'puzzle_added', CASE WHEN p.approved THEN 'approved' ELSE 'pending' END, p.added_at,
           (p.approved_by_id IS NOT NULL AND p.approved_by_id = p.added_by_user_id), (p.image IS NOT NULL)
    FROM puzzle p WHERE p.added_by_user_id IS NOT NULL
    UNION ALL
    -- A5 puzzles added and later merged away as duplicates (audit exists since 2026-09-16)
    SELECT (m->>'addedByUserId')::uuid, 'puzzle_added', 'merged_away', (m->>'addedAt')::timestamp, false,
           (m->>'image') IS NOT NULL
    FROM puzzle_merge_audit a
    CROSS JOIN LATERAL jsonb_array_elements(a.snapshot_before::jsonb -> 'mergedPuzzles') m
    WHERE m->>'addedByUserId' IS NOT NULL
    UNION ALL
    -- A6 brands added
    SELECT b.added_by_user_id, 'brand_added', CASE WHEN b.approved THEN 'approved' ELSE 'pending' END, b.added_at,
           false, (b.logo IS NOT NULL)
    FROM manufacturer b WHERE b.added_by_user_id IS NOT NULL
    UNION ALL
    -- A4 catalogue decisions by a person in the admin UI (internal API + automatic excluded)
    SELECT d.decided_by_id, 'mod_decision', d.action::text, d.decided_at, false, false
    FROM puzzle_moderation_decision d
    WHERE d.decided_by_id IS NOT NULL AND d.source = 'admin_ui' AND d.action NOT LIKE '%outdated'
      -- the 2026-09-25 backfill also logged scanned-EAN links (approved by their own reporter) as decisions
      AND NOT EXISTS (SELECT 1 FROM puzzle_change_request x
                      WHERE x.id = d.change_request_id AND x.reviewed_by_id = x.reporter_id
                        AND x.reviewed_at = x.submitted_at)
    UNION ALL
    -- B1 time verification decisions by a person
    SELECT d.decided_by_id, 'tv_decision', d.decision::text, d.decided_at, false, false
    FROM suspicious_time_decision d
    WHERE d.decided_by_id IS NOT NULL
      AND d.decided_by_id IS DISTINCT FROM d.tracker_id
      AND d.decision IN ('marked', 'trusted', 'unmarked', 'kept_after_reply', 'pieces_confirmed',
                         'slow_threshold_set', 'slow_threshold_removed')
    UNION ALL
    -- C1-C3 events, editions, series, organizations
    SELECT c.added_by_player_id, CASE WHEN c.series_id IS NULL THEN 'event_added' ELSE 'edition_added' END,
           CASE WHEN c.approved_at IS NOT NULL THEN 'approved' WHEN c.rejected_at IS NOT NULL THEN 'rejected'
                WHEN c.is_draft THEN 'draft' ELSE 'pending' END,
           c.created_at, (c.approved_by_player_id = c.added_by_player_id), false
    FROM competition c WHERE c.added_by_player_id IS NOT NULL
    UNION ALL
    SELECT s.added_by_player_id, 'series_added',
           CASE WHEN s.approved_at IS NOT NULL THEN 'approved' WHEN s.rejected_at IS NOT NULL THEN 'rejected'
                WHEN s.is_draft THEN 'draft' ELSE 'pending' END,
           s.created_at, (s.approved_by_player_id = s.added_by_player_id), false
    FROM competition_series s WHERE s.added_by_player_id IS NOT NULL
    UNION ALL
    SELECT o.added_by_player_id, 'organization_added',
           CASE WHEN o.approved_at IS NOT NULL THEN 'approved' WHEN o.rejected_at IS NOT NULL THEN 'rejected'
                WHEN o.is_draft THEN 'draft' ELSE 'pending' END,
           o.created_at, (o.approved_by_player_id = o.added_by_player_id), false
    FROM organization o WHERE o.added_by_player_id IS NOT NULL
    UNION ALL
    -- D1-D6 feedback & social
    SELECT f.author_id, 'feature_request', f.status::text, f.created_at, false, false
    FROM feature_request f WHERE f.author_id IS NOT NULL
    UNION ALL
    SELECT v.voter_id, 'feature_vote', 'cast', v.voted_at, false, false
    FROM feature_request_vote v WHERE v.voter_id IS NOT NULL
    UNION ALL
    SELECT c.author_id, 'feature_comment', 'posted', c.created_at, false, false
    FROM feature_request_comment c WHERE c.author_id IS NOT NULL
    UNION ALL
    SELECT r.reporter_id, 'report', r.status::text, r.reported_at, false, false
    FROM conversation_report r WHERE r.reporter_id IS NOT NULL
    UNION ALL
    SELECT r.reporter_id, 'report', r.status::text, r.reported_at, false, false
    FROM feature_request_comment_report r WHERE r.reporter_id IS NOT NULL
    UNION ALL
    SELECT t.reviewer_id, 'rating_given', t.stars::text, t.rated_at, false, false
    FROM transaction_rating t WHERE t.reviewer_id IS NOT NULL
    UNION ALL
    SELECT g.target_id, 'guest_link_answered',
           CASE WHEN g.accepted THEN 'accepted' WHEN g.accepted = false THEN 'declined' ELSE 'pending' END,
           g.requested_at, false, false
    FROM guest_link_request g
)
```

### q1.sql — totals and outcomes per type (admins counted separately)

```sql
SELECT kind, outcome,
       count(*)                                                        AS n,
       count(*) FILTER (WHERE player_id IN (SELECT id FROM admins))     AS by_admins,
       count(*) FILTER (WHERE player_id IN (SELECT id FROM moderators)) AS by_moderators,
       count(*) FILTER (WHERE self_decided)                            AS self_decided,
       count(*) FILTER (WHERE with_photo)                              AS with_photo,
       count(DISTINCT player_id)                                       AS contributors
FROM contrib
GROUP BY ROLLUP (kind, outcome)
ORDER BY kind NULLS LAST, outcome NULLS FIRST;
```

### q2.sql — distribution per type (non-admins; anonymised: counts only)

```sql
, per AS (
    SELECT kind, player_id, count(*) AS n
    FROM contrib
    WHERE player_id NOT IN (SELECT id FROM admins)
    GROUP BY kind, player_id
), ranked AS (
    SELECT kind, n,
           row_number() OVER (PARTITION BY kind ORDER BY n DESC) AS rk,
           sum(n)       OVER (PARTITION BY kind)                 AS tot
    FROM per
)
SELECT kind,
       max(tot)                                                           AS total,
       count(*)                                                           AS contributors,
       round(100.0 * sum(n) FILTER (WHERE rk <= 10) / max(tot), 1)        AS top10_share_pct,
       round(100.0 * max(n) / max(tot), 1)                                AS top1_share_pct,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY n)                     AS median_per_contributor,
       percentile_cont(0.9) WITHIN GROUP (ORDER BY n)                     AS p90_per_contributor,
       (array_agg(n ORDER BY rk))[1:10]                                   AS top10_counts   -- #1..#10
FROM ranked
GROUP BY kind
ORDER BY total DESC;
```

### q3.sql — last 12 months, per type and month

```sql
SELECT kind, to_char(date_trunc('month', at), 'YYYY-MM') AS month,
       count(*) AS n,
       count(*) FILTER (WHERE outcome IN ('approved', 'completed', 'resolved', 'accepted')) AS accepted,
       count(DISTINCT player_id) AS people
FROM contrib
WHERE at >= date_trunc('month', now()) - interval '11 months'
  AND player_id NOT IN (SELECT id FROM admins)
GROUP BY 1, 2
ORDER BY 1, 2;
```

### q4.sql — trivial-edit check on change proposals (which fields a proposal changes)

```sql
SELECT status,
       (proposed_name IS NOT NULL AND proposed_name IS DISTINCT FROM original_name)::int
     + (proposed_manufacturer_id IS NOT NULL AND proposed_manufacturer_id IS DISTINCT FROM original_manufacturer_id)::int
     + (proposed_pieces_count IS NOT NULL AND proposed_pieces_count IS DISTINCT FROM original_pieces_count)::int
     + (proposed_ean IS NOT NULL AND proposed_ean IS DISTINCT FROM original_ean)::int
     + (proposed_identification_number IS NOT NULL AND proposed_identification_number IS DISTINCT FROM original_identification_number)::int
     + (proposed_image IS NOT NULL)::int
     + (proposed_alternative_names IS NOT NULL AND proposed_alternative_names IS DISTINCT FROM original_alternative_names)::int
       AS fields_changed,
       (proposed_name IS NOT NULL AND lower(proposed_name) = lower(original_name) AND proposed_name <> original_name) AS casing_only_name,
       count(*) AS n
FROM puzzle_change_request
WHERE reporter_id NOT IN (SELECT id FROM player WHERE is_admin)
GROUP BY 1, 2, 3
ORDER BY 1, 2;
```
(`q4` does not need `contrib.sql`.)

### q5.sql — moderator behaviour (rubber-stamp signals, anonymised)

```sql
, d AS (
    SELECT decided_by_id, action, decided_at,
           extract(epoch FROM decided_at - lag(decided_at) OVER (PARTITION BY decided_by_id ORDER BY decided_at)) AS gap_s
    FROM puzzle_moderation_decision
    WHERE decided_by_id IS NOT NULL AND source = 'admin_ui' AND action NOT LIKE '%outdated'
      AND decided_by_id NOT IN (SELECT id FROM admins)
      AND NOT EXISTS (SELECT 1 FROM puzzle_change_request x
                      WHERE x.id = change_request_id AND x.reviewed_by_id = x.reporter_id
                        AND x.reviewed_at = x.submitted_at)
)
SELECT row_number() OVER (ORDER BY count(*) DESC) AS moderator_rank,
       count(*) AS decisions,
       round(100.0 * count(*) FILTER (WHERE action LIKE '%approved' OR action = 'puzzle_edited') / count(*), 1) AS approve_or_edit_pct,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY gap_s) FILTER (WHERE gap_s < 3600) AS median_gap_s_in_session,
       count(DISTINCT date_trunc('day', decided_at)) AS active_days
FROM d GROUP BY decided_by_id ORDER BY decisions DESC;

-- own-proposal decisions by moderators
SELECT count(*) FILTER (WHERE r.reviewed_by_id = r.reporter_id) AS self_decided,
       count(*) AS decided_by_moderators
FROM puzzle_change_request r
WHERE r.reviewed_by_id IN (SELECT id FROM player WHERE moderator_since IS NOT NULL AND NOT is_admin);
```

### q6.sql — karma simulation (three formulas) + baseline of active players

```sql
, scopes(scope, since) AS (VALUES ('all-time', '-infinity'::timestamp), ('last 12 m', (now() - interval '12 months')::timestamp))
, scored AS (
    SELECT s.scope, c.*, date_trunc('month', c.at) AS m
    FROM contrib c
    JOIN scopes s ON c.at >= s.since OR (s.scope = 'all-time')
    WHERE c.player_id NOT IN (SELECT id FROM admins)
), pts AS (
    SELECT scope, player_id, kind, m,
        -- Formula A: catalogue, flat values, rejected = 0
        CASE
            WHEN kind = 'ean_link_self' THEN 1
            WHEN kind = 'change_request' AND outcome = 'approved' AND self_decided THEN 2
            WHEN kind = 'change_request' AND outcome = 'approved' THEN 5
            WHEN kind = 'change_request' AND outcome = 'outdated' THEN 2
            WHEN kind = 'merge_request'  AND outcome = 'approved' AND self_decided THEN 4
            WHEN kind = 'merge_request'  AND outcome = 'approved' THEN 10
            WHEN kind = 'merge_request'  AND outcome = 'outdated' THEN 3
            WHEN kind = 'puzzle_added'   AND outcome = 'approved' AND with_photo THEN 3
            WHEN kind = 'puzzle_added'   AND outcome = 'approved' THEN 1
            WHEN kind = 'brand_added'    AND outcome = 'approved' THEN 1
            WHEN kind = 'mod_decision' THEN 1
            WHEN kind = 'tv_decision'  THEN 1
            ELSE 0 END::numeric AS a,
        -- extra items of Formula C (broad community)
        CASE
            WHEN kind IN ('event_added', 'edition_added', 'series_added', 'organization_added')
                 AND outcome = 'approved' THEN 5
            WHEN kind = 'feature_request' AND outcome = 'completed'   THEN 10
            WHEN kind = 'feature_request' AND outcome = 'in_progress' THEN 5
            WHEN kind = 'feature_vote'    THEN 0.5
            WHEN kind = 'feature_comment' THEN 1
            WHEN kind = 'report' AND outcome = 'resolved' THEN 3
            WHEN kind = 'report' AND outcome = 'reviewed' THEN 1
            WHEN kind = 'rating_given' THEN 1
            WHEN kind = 'guest_link_answered' AND outcome = 'accepted' THEN 1
            ELSE 0 END::numeric AS c_extra
    FROM scored
), a_kind AS (
    -- moderation capped at 60 per month (catalogue + time verification together); scanned EANs at 30 per month
    SELECT scope, player_id, grp AS kind, sum(capped) AS a
    FROM (
        SELECT scope, player_id, grp, m,
               CASE WHEN grp = 'moderation'    THEN LEAST(sum(a), 60)
                    WHEN grp = 'ean_link_self' THEN LEAST(sum(a), 30)
                    ELSE sum(a) END AS capped
        FROM (SELECT scope, player_id, m, a,
                     CASE WHEN kind IN ('mod_decision', 'tv_decision') THEN 'moderation' ELSE kind END AS grp
              FROM pts) p0
        GROUP BY scope, player_id, grp, m
    ) x GROUP BY scope, player_id, grp
), c_kind AS (
    SELECT scope, player_id, kind,
           CASE WHEN kind = 'feature_vote'    THEN LEAST(sum(c_extra), 10)
                WHEN kind = 'feature_comment' THEN LEAST(sum(c_extra), 10)
                WHEN kind = 'rating_given'    THEN LEAST(sum(c_extra), 20)
                ELSE sum(c_extra) END AS c
    FROM pts GROUP BY scope, player_id, kind
), totals AS (
    SELECT p.scope, p.player_id,
           coalesce(a.a, 0)  AS karma_a,
           coalesce(a.a0, 0) AS karma_a0,
           coalesce(a.b, 0)  AS karma_b,
           coalesce(a.a, 0) + coalesce(c.c, 0) AS karma_c
    FROM (SELECT DISTINCT scope, player_id FROM pts) p
    LEFT JOIN (SELECT scope, player_id, sum(a) AS a,
                      sum(a) FILTER (WHERE kind NOT IN ('puzzle_added', 'ean_link_self')) AS a0,
                      sum(round(10 * sqrt(a))) AS b
               FROM a_kind GROUP BY scope, player_id) a USING (scope, player_id)
    LEFT JOIN (SELECT scope, player_id, sum(c) AS c FROM c_kind GROUP BY scope, player_id) c USING (scope, player_id)
), f AS (
    SELECT t.scope, v.formula, v.k,
           row_number() OVER (PARTITION BY t.scope, v.formula ORDER BY v.k DESC) AS rk,
           row_number() OVER (PARTITION BY t.scope, v.formula ORDER BY v.k ASC)  AS i,
           count(*)     OVER (PARTITION BY t.scope, v.formula)                   AS n,
           sum(v.k)     OVER (PARTITION BY t.scope, v.formula)                   AS tot
    FROM totals t
    CROSS JOIN LATERAL (VALUES ('A', t.karma_a), ('A0 (no puzzle adds, no scanned EANs)', t.karma_a0),
                               ('B', t.karma_b), ('C', t.karma_c)) v(formula, k)
    WHERE v.k > 0
)
SELECT scope, formula,
       count(*)                                                      AS ge_1,
       count(*) FILTER (WHERE k >= 10)                               AS ge_10,
       count(*) FILTER (WHERE k >= 50)                               AS ge_50,
       count(*) FILTER (WHERE k >= 100)                              AS ge_100,
       count(*) FILTER (WHERE k >= 500)                              AS ge_500,
       (array_agg(round(k) ORDER BY rk))[1:10]                       AS top10_totals,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY k)                AS median_holder,
       percentile_cont(0.9) WITHIN GROUP (ORDER BY k)                AS p90_holder,
       round(100.0 * sum(k) FILTER (WHERE rk <= 10) / max(tot), 1)   AS top10_share_pct,
       round(sum((2 * i - n - 1) * k) / max(n * tot), 3)              AS gini
FROM f
GROUP BY scope, formula
ORDER BY scope, formula;
```

### q7.sql — proposals only (accepted change 5, accepted merge 10)

```sql
, prop AS (
    SELECT s.scope, c.player_id,
           sum(CASE WHEN c.kind = 'change_request' AND c.outcome = 'approved' THEN 5
                    WHEN c.kind = 'merge_request'  AND c.outcome = 'approved' THEN 10 ELSE 0 END) AS k,
           count(*) FILTER (WHERE c.kind IN ('change_request','merge_request')) AS proposals
    FROM contrib c
    JOIN (VALUES ('all-time', '-infinity'::timestamp), ('last 12 m', (now() - interval '12 months')::timestamp)) s(scope, since)
      ON c.at >= s.since
    WHERE c.player_id NOT IN (SELECT id FROM admins) AND c.kind IN ('change_request', 'merge_request')
    GROUP BY 1, 2
)
SELECT scope, count(*) AS proposers, count(*) FILTER (WHERE k >= 1) AS ge_1, count(*) FILTER (WHERE k >= 10) AS ge_10,
       count(*) FILTER (WHERE k >= 50) AS ge_50, count(*) FILTER (WHERE k >= 100) AS ge_100,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY k) FILTER (WHERE k > 0) AS median_holder,
       (array_agg(k ORDER BY k DESC))[1:10] AS top10
FROM prop GROUP BY scope ORDER BY scope;
```

### base.sql — baseline of active players (standalone)

```sql
SELECT count(DISTINCT player_id) FILTER (WHERE tracked_at >= now() - interval '12 months') AS active_12m,
       count(DISTINCT player_id) AS ever_logged_a_time,
       count(DISTINCT player_id) FILTER (WHERE tracked_at >= now() - interval '30 days') AS active_30d
FROM puzzle_solving_time;
SELECT count(*) FILTER (WHERE approved_by_id IS NOT NULL) AS with_approver, count(*) FILTER (WHERE image IS NULL) AS no_image,
       count(*) FILTER (WHERE added_by_user_id IS NULL) AS no_adder, count(*) AS puzzles FROM puzzle;
```

Helper used for the run: `scratchpad/sql/run.sh <q> [nocontrib]` (pipes into `psql` with
`PGOPTIONS='-c default_transaction_read_only=on'`).

---

## 5. Candidate formulas (encoded in q6)

All three: derived from existing rows, recomputable at any time, rejected = 0 (never negative), admins excluded.

**A — "Catalogue keeper" (flat, what the owner proposed).**
Accepted change proposal 5 (self-decided 2), outdated 2, accepted merge proposal 10 (self-decided 4), outdated 3,
approved new puzzle with box photo 3 (without 1, merged away 0), approved brand 1, EAN linked by scan 1 (≤ 30/month),
moderator decision (catalogue or time verification) 1 each, **≤ 60/month**.
Expected shape: very concentrated — a handful of moderators and a few heavy proposers on top; the long tail is people
who added a few puzzles (3 points each). If puzzle adds are excluded, expect only dozens of holders.

**B — "Diminishing returns".** Same items, but each type contributes `10 × √(points of that type)` — 100 accepted
change proposals (500 pts) count 224, one counts 22. Rewards breadth (a bit of everything) over grinding one action,
damps moderator dominance; same holder count as A, lower top-10 share.

**C — "Community"** = A + approved events/series/organizations 5, feature request completed 10 / in progress 5,
feature votes 0.5 (≤ 10 lifetime), feature comments 1 (≤ 10), useful reports 3 (resolved) / 1 (reviewed), marketplace
ratings given 1 (≤ 20), accepted guest links 1. Widest base; the cheap social items are capped so they cannot
outweigh catalogue work.

Recommended extras (not in the SQL, decide later): acceptance-rate gate for proposals (≥ 50 % after ≥ 5 decided);
proposal karma only once *decided* (pending shows as "awaiting review"); optional time decay or a rolling 12-month
"karma this year" next to lifetime, if karma should behave like seasonal Points rather than permanent XP.

## 6. Verdict

See section 7 for the numbers. In short:

- **The owner's initial idea (proposals + moderator decisions) gives karma to ~235 players all-time** (accepted change or
  merge proposal; 158 with ≥ 10, 37 with ≥ 50, 16 with ≥ 100) - about **4 % of the 5,535 players active in the last
  12 months**. One person dominates (≈ 1,540 points vs ≈ 280 for #2). On this copy there is **no non-admin moderator
  decision at all** (moderators were appointed after 2026-09-25), so the moderator half of the idea cannot be judged
  here - in production it will be a handful of people (the moderator team), which argues for a capped or monthly
  stipend rather than per-click karma.
- **Formula A0** (everything in A except puzzle adds and scanned EANs) reaches ~1,000 players, but ~75 % of them only
  because they once typed a new brand into the add form (1 point) - median holder 1 point. Remove brands and A0 ≈ the
  proposals-only number (~235).
- **Only puzzle adds make karma broad**: Formula A gives 3,652 holders all-time (2,389 in the last 12 months), half of
  them with ≥ 10. But puzzle adds dominate the totals (top holders = people who added ~1,000 puzzles) and the
  "approved" outcome is a bulk approval, not a review.
- **Formula B** (√ per type) keeps the same holders and flattens the top (top-10 share 3 % vs 12 %, Gini 0.43 vs
  0.72) - the only one where a proposer and a bulk puzzle adder end up in the same range.
- **Formula C** adds only ~40 holders over A: feature votes, comments, events, ratings and guest links are too few to
  matter; they are not worth a currency of their own.
- Recommendation: karma as a currency is meaningful only if it includes puzzle adds (≈ 3,700 holders, ≈ 2,400 per
  year), ideally with diminishing returns (B). If it should reward *curation* only (proposals + moderation), it is a
  ~200-person hall of fame - better expressed as a "Catalogue keeper" achievement line in Achievement Points than as a
  fifth currency.

---

## 7. Results on the dev copy (data up to 2026-09-25, admins excluded unless stated)

Baseline: 11,326 players; **7,598** ever logged a time, **5,535** in the last 12 months, 2,213 in the last 30 days.

### 7.1 Inventory counts (q1, all players incl. admins)

| Type | Total | Outcome | Contributors | Admin rows |
|---|---|---|---|---|
| Change proposals | 1,624 | 931 approved · 672 pending · 21 rejected · 0 outdated (status newer than the copy) | 334 | 43 (all self-decided) |
| Scanned-EAN links (self-approved) | 49 | approved | 4 | 0 |
| Merge proposals | 615 | 360 approved · 244 pending · 11 rejected | 175 | 47 (all self-decided) |
| Catalogue decisions (admin UI, real) | 1,304 | 931/21 change, 341/11 merge | **2 (both admins)** | 1,304 |
| Puzzles added | 41,226 | 40,541 approved · 659 pending · 26 merged away (audit since 2026-09-16) | 3,714 | 247 |
| Brands added | 2,227 | 2,031 approved · 196 pending | 958 | 1 |
| Events / editions / series | 35 / 1 / 15 | all approved | 27 / 1 / 15 | 1 |
| Feature requests | 126 | 9 completed · 1 in progress · 116 open | 80 | 4 |
| Feature votes / comments | 1,119 / 23 | — | 241 / 18 | 11 / 0 |
| Reports | 2 | resolved | 1 | 0 |
| Marketplace ratings given | 231 | (all 5 stars) | 60 | 1 |
| Guest-link answers | 23 | 4 accepted · 19 pending | 20 | 0 |
| Time-verification decisions, organizations | 0 | not in this copy | — | — |

Only 21 of 1,624 change proposals and 11 of 615 merge proposals were ever rejected (1.3 % / 1.8 %): spam is not a
problem today, a pending backlog is (672 + 244 pending).

### 7.2 Distribution per type (q2, non-admins; #1..#10 = anonymised ranks)

| Type | Total | People | Top-10 share | #1 share | Median / person | p90 | #1..#10 counts |
|---|---|---|---|---|---|---|---|
| Puzzles added | 40,979 | 3,712 | 10.8 % | 2.5 % | 3 | 25 | 1013, 916, 495, 327, 325, 284, 278, 266, 264, 240 |
| Brands added | 2,226 | 957 | 12.7 % | 3.2 % | 1 | 4 | 71, 29, 28, 28, 25, 23, 22, 20, 19, 18 |
| Change proposals | 1,581 | 332 | 38.5 % | 16.8 % | 1.5 | 8 | 266, 63, 44, 38, 38, 38, 33, 31, 29, 28 |
| Feature votes | 1,108 | 239 | 16.2 % | 1.9 % | 3 | 10 | 21, 21, 20, 19, 18, 18, 18, 15, 15, 15 |
| Merge proposals | 568 | 174 | 36.6 % | 7.7 % | 1 | 7 | 44, 36, 23, 19, 18, 17, 15, 14, 12, 10 |
| Ratings given | 230 | 59 | 55.2 % | 12.2 % | 2 | 9 | 28, 24, 10, 10, 10, 10, 9, 9, 9, 8 |
| Feature requests | 122 | 78 | 31.1 % | 7.4 % | 1 | 2 | 9, 5, 5, 4, 3, 3, 3, 2, 2, 2 |
| Scanned EANs | 49 | 4 | 100 % | 87.8 % | 2.5 | — | 43, 3, 2, 1 |
| Events added | 34 | 26 | 52.9 % | 17.6 % | 1 | 2 | 6, 2, 2, 2, 1, … |
| Guest links, comments, series, reports | 23 / 23 / 15 / 2 | 20 / 18 / 15 / 1 | — | — | 1 | 1–2 | — |

### 7.3 Last 12 months per month (q3, non-admins)

| Month | Puzzles added (people) | Brands (people) | Change proposals: filed / approved so far (people) | Merge proposals: filed / approved (people) | Feature votes (people) |
|---|---|---|---|---|---|
| 2025-11 | 1,016 (409) | 48 (41) | — | — | — |
| 2025-12 | 2,413 (455) | 104 (57) | — (feature from 2025-12-18) | — | — |
| 2026-01 | 2,180 (557) | 97 (72) | 284 / 277 (56) | 92 / 88 (39) | — |
| 2026-02 | 1,998 (501) | 104 (52) | 113 / 107 (50) | 38 / 38 (23) | — |
| 2026-03 | 1,876 (579) | 96 (77) | 182 / 179 (69) | 91 / 88 (41) | 192 (83) |
| 2026-04 | 1,745 (617) | 87 (75) | 218 / 215 (83) | 54 / 52 (33) | 246 (104) |
| 2026-05 | 1,865 (616) | 86 (71) | 166 / 13 (78) | 57 / 42 (39) | 153 (73) |
| 2026-06 | 1,902 (541) | 87 (71) | 186 / 0 (74) | 68 / 2 (39) | 129 (60) |
| 2026-07 | 1,865 (578) | 118 (93) | 123 / 1 (66) | 52 / 1 (37) | 162 (71) |
| 2026-08 | 2,091 (663) | 130 (88) | 180 / 92 (68) | 73 / 0 (36) | 131 (51) |
| 2026-09 (to 25th) | 1,850 (586) | 123 (80) | 129 / 6 (63) | 43 / 2 (31) | 95 (37) |

Steady supply: ~60-80 people a month file change proposals and ~30-40 file merge proposals; ~550-650 add puzzles.
Reviews stalled from May 2026 (the backlog the moderators were appointed for) - karma tied to *accepted* proposals
would have frozen for months for these people. Events: 2-12 a month by 2-7 people.

### 7.4 Trivial edits (q4, non-admin change proposals)

Approved: 641 change 1 field, 197 two, 78 three, 23 four; only 20 approved proposals are a casing-only name change.
Single-field proposals dominate but are not trivial by themselves (an EAN, a photo, a brand fix) - weight by field
type rather than count, if at all.

### 7.5 Moderators (q5)

No non-admin catalogue decision exists in this copy (the 49 apparent ones are the backfill artifact, trap 9). All
1,304 real decisions were made by the 2 admins. Re-run on a current copy to see the moderator team.

### 7.6 Karma simulation (q6 + q7, admins excluded) — holders and concentration

| Scope | Formula | ≥ 1 | ≥ 10 | ≥ 50 | ≥ 100 | ≥ 500 | #1..#5 totals | Median holder | p90 | Top-10 share | Gini |
|---|---|---|---|---|---|---|---|---|---|---|---|
| all-time | **A** catalogue flat | 3,652 | 1,839 | 556 | 257 | 29 | 3216, 2972, 1803, 1557, 1105 | 10 | 77 | 11.6 % | 0.72 |
| all-time | **A0** = A without puzzle adds & scanned EANs | 998 | 177 | 40 | 16 | 1 | 1542, 282, 274, 233, 198 | 1 | 15 | 35.0 % | 0.79 |
| all-time | proposals only (change 5, merge 10) | 235 | 158 | 37 | 16 | 1 | 1540, 280, 270, 205, 190 | 10 | — | — | — |
| all-time | **B** √ per type | 3,652 | 3,652 | 1,119 | 354 | 5 | 839, 777, 706, 540, 535 | 35 | 98 | 3.0 % | 0.43 |
| all-time | **C** community | 3,692 | 1,852 | 561 | 259 | 29 | 3226, 2977, 1837, 1557, 1115 | 10 | 76 | 11.6 % | 0.72 |
| last 12 m | **A** | 2,389 | 1,122 | 302 | 123 | 12 | 2758, 1730, 1543, 1090, 908 | 9 | 62 | 16.2 % | 0.71 |
| last 12 m | **A0** | 602 | 160 | 39 | 16 | 1 | 1541, 280, 273, 229, 192 | 2 | 29 | 38.7 % | 0.79 |
| last 12 m | proposals only | 235 | 158 | 37 | 16 | 1 | 1540, 280, 270, 205, 190 | 10 | — | — | — |
| last 12 m | **B** | 2,389 | 2,389 | 657 | 198 | 4 | 753, 677, 557, 531, 494 | 30 | 92 | 4.4 % | 0.42 |
| last 12 m | **C** | 2,438 | 1,135 | 307 | 124 | 12 | 2763, 1764, 1543, 1100, 913 | 9 | 62 | 16.0 % | 0.71 |

Proposals all fall inside the last 12 months (the feature started 2025-12-18), so both scopes are equal there.
In B every holder has ≥ 10 by construction (10 × √1). A0's ~1,000 holders are mostly one-point brand adders.

---

## 8. Jan's rule (2026-10-10)

**Rule:** karma = 1 per accepted contribution, no caps, no weights, no square root:
- 1 per approved change request (scanned-EAN self-approvals included; reporter = decider counts once)
- 1 per approved merge request
- 1 per puzzle added that **has an EAN** and still exists as its own puzzle. A merge credits whoever added the
  **earliest** of the merged puzzles, taken from `puzzle_merge_audit`, followed through chains of merges.
- 1 per moderator decision and 1 per direct edit (`puzzle_edited`), except a decision on one's own proposal or puzzle
  (counted once in total). Brand decisions are not counted.
- No events, no brands.

**How it was run.** Dev copy (data up to 2026-09-25), read-only, admins excluded. Queries:
`scratchpad/sql/jbase.sql` + `j1` (holders), `j2` (split by type), `j3`/`j3b` (merge credit), `j4` (EAN share).
- "Has an EAN" = the stored `ean` contains ≥ 8 consecutive digits. 648 puzzles store something else (a catalogue
  number, a placeholder) and do not count.
- The date of a request is its review date. A puzzle add is dated by the credited add.
- **There are no moderator decisions or direct edits by non-admins in this copy**, so that part contributes 0 here.
  In production it adds the moderator team (a handful of people).

### 8.1 Holders

| Scope | Puzzles counted | Total karma | ≥ 1 | ≥ 10 | ≥ 50 | ≥ 100 | Top-10 share | Median | #1..#5 |
|---|---|---|---|---|---|---|---|---|---|
| all-time | **EAN, approved** (the rule) | 23,841 | **2,952** | 543 | 75 | 24 | 12.4 % | 3 | 687, 359, 299, 292, 274 |
| all-time | EAN, approved + pending | 24,253 | 3,007 | 550 | 75 | 24 | 12.3 % | 3 | 690, 360, 300, 292, 288 |
| all-time | every puzzle, approved | 41,546 | 3,650 | 906 | 143 | 56 | 11.2 % | 3 | 1054, 945, 501, 361, 331 |
| all-time | every puzzle, approved + pending | 42,205 | 3,714 | 922 | 147 | 56 | 11.1 % | 3 | 1070, 948, 503, 362, 332 |
| last 12 m | **EAN, approved** (the rule) | 13,419 | **1,966** | 299 | 35 | 10 | 17.2 % | 2 | 620, 335, 261, 228, 161 |
| last 12 m | EAN, approved + pending | 13,831 | 2,027 | 308 | 35 | 10 | 16.7 % | 2 | 623, 336, 262, 228, 162 |
| last 12 m | every puzzle, approved | 22,107 | 2,388 | 497 | 67 | 27 | 15.5 % | 3 | 875, 497, 361, 337, 293 |
| last 12 m | every puzzle, approved + pending | 22,766 | 2,459 | 519 | 69 | 28 | 15.1 % | 3 | 878, 499, 377, 338, 294 |

For scale: 5,535 players logged a time in the last 12 months, so the rule reaches about 36 % of them (1,966). It
reaches 43 % (2,388) when every added puzzle counts. Pending puzzles change little (+55 to +60 holders): there were
only 659 pending puzzles.

### 8.2 Where the karma comes from (EAN rule, approved)

| Type | All-time karma (people) | Share | Last 12 m karma | Share |
|---|---|---|---|---|
| Puzzles added with EAN | 22,589 (2,935) | 94.7 % | 12,167 (1,933) | 90.7 % |
| Approved change requests (49 of them scanned EANs) | 939 (187) | 3.9 % | 939 | 7.0 % |
| Approved merge requests | 313 (108) | 1.3 % | 313 | 2.3 % |
| Moderator decisions / direct edits | 0 (none by non-admins in this copy) | 0 % | 0 | 0 % |

Even with the EAN rule, adding puzzles is about 91–95 % of all karma. Proposals are about 5 % all-time and 9 % in the
last 12 months (all proposals fall inside it). Without the EAN rule, puzzles make up 97 %.

### 8.3 Does the EAN rule make karma for adding puzzles rare? No, it roughly halves it

| Puzzles added by players | Added | With EAN | Share |
|---|---|---|---|
| Last 12 months | 21,517 | 12,582 | **58.5 %** |
| Older or undated | 19,436 | 10,419 | 53.6 % |
| All | 40,953 | 23,001 | 56.2 % |

- Per month in the last 12 months: 44–45 % (Oct–Nov 2025), then 53–65 % (59–63 % in recent months).
- 1,996 of the 2,443 people who added a puzzle in the last 12 months added at least one with an EAN (82 %).
- The EAN rule therefore cuts puzzle karma by about 44 % (22,589 vs 40,294) and holders by about 19 % (2,952 vs 3,650). It does not make karma for
  adding puzzles rare.

### 8.4 Merged puzzles: credit to the earliest add

- **How far back the audit reaches:** `puzzle_merge_audit` starts on **2026-09-17**, with 26 audited merges (26 merged
  puzzles, all with a known adder). The 338 earlier approved merges (since 2026-01-05) have no audit and no
  `puzzle_redirect` row (redirects also start 2026-09-17). For those, nobody knows who added the deleted puzzle, so the
  survivor's own adder keeps the credit.
- **Within the 26 audited merges:**
  - In 8 the merged-away puzzle was older than the survivor, so the credit moves to it.
  - In 7 of those 8 the credit goes to a different person, so 7 survivor adders lose a point.
  - In 1 it is the same person.
  - The other 18 merged-away adds earn nothing.
  - No credit is lost to "nobody".
- **Extrapolation:** about 30 % of merges (7 of 26) move credit to a different person. Applied to the 338 unaudited
  merges, roughly 90–100 historic credits sit with the "wrong" person and cannot be corrected. That is small next to
  22,589 puzzle points.

### 8.5 Verdict on Jan's rule

- **Broad and simple:** 2,952 holders all-time and 1,966 in the last 12 months (about 36 % of active players). The
  median holder has 2–3 karma, and only 24 people (10 in the last 12 months) reach 100.
- **Not concentrated:** the top 10 hold 12 % all-time and 17 % in the last 12 months.
- **In practice it is a "puzzles added" counter:** puzzle adds are about 91–95 % of all karma. Curation (change and merge
  proposals) is about 1,250 points across about 250 people. Moderator work, which this copy cannot show, will be a
  handful of people with potentially large counts, because there are no caps.
- **Gaming surface:**
  - Scanned-EAN links are self-approved and uncapped (today 49 links by 4 people).
  - A puzzle add is 1 point whether it is a real catalogue gap or a by-product of logging a time.
  - A moderator's own proposals count once, as designed.
