<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Karma;

/**
 * Karma is derived, never collected (docs/features/karma.md): one statement over the records that already exist - the
 * puzzles, their change and merge requests, the merge audit and the moderation decision log. Every reader (the
 * player's history, the totals stored on player.karma, the public list's per-kind and last-12-months variants, the
 * review queues) builds on the `karma_items` CTE below, so there is one rule in one place.
 *
 * The rule (Jan, 2026-10-10) - 1 karma each, no caps, no weights:
 * - an approved change request → its reporter (an EAN linked by a scan approves itself - it counts too)
 * - an approved merge request → its reporter
 * - an approved puzzle with an EAN → whoever added it, unless it is a copy: of the puzzles a merge joined, only the
 *   earliest added one keeps the credit (merged-away puzzles are known from puzzle_merge_audit, followed through
 *   later merges; before the audit existed the survivor's own adder keeps it). The later copies are listed in
 *   the history as not counted (KarmaKind::DuplicatePuzzle)
 * - a moderator's decision on a change request, a merge request or a new puzzle, and a direct edit → the moderator,
 *   made by a person in the admin UI. A decision on one's own proposal or puzzle counts once in total: the proposal
 *   when it counts (approved), the decision otherwise
 * - outdated requests, brands, events: nothing. Admins count like everybody (Jan 2026-10-12 - including the proposals
 *   the internal API files in its reviewer's name, INTERNAL_API_REVIEWER_PLAYER_ID); the readers join the player
 *   through ELIGIBLE_PLAYER
 *
 * Columns of `karma_items`: player_id, kind (KarmaKind), at, puzzle_id (null = the puzzle is gone), puzzle_name,
 * action (PuzzleModerationAction of a decision), counted.
 */
final class KarmaDerivation
{
    /** "Has an EAN": at least 8 digits in a row - placeholders and catalogue numbers in the EAN field do not count */
    public const string HAS_EAN = "p.ean ~ '[0-9]{8,}'";

    /** Join condition for the player a karma item belongs to - everybody, admins too (Jan 2026-10-12) */
    public const string ELIGIBLE_PLAYER = 'pl.id = i.player_id';

    /**
     * @param bool $scoped Only the items of the players in the `:karmaPlayerIds` array parameter (fast: a player's
     *                     history, the recalculation after a decision). Unscoped = everybody (the full recalculation,
     *                     the public list's variants).
     */
    public static function itemsCte(bool $scoped): string
    {
        $hasEan = self::HAS_EAN;
        $playerFilter = static fn (string $column): string => $scoped ? "AND {$column} IN (:karmaPlayerIds)" : '';

        $scopePuzzles = $scoped ? <<<SQL
-- the puzzles whose credit concerns these players: their own, and the ones their merged-away copies live on in
scope_puzzles AS (
    SELECT p.id FROM puzzle p WHERE p.added_by_user_id IN (:karmaPlayerIds)
    UNION
    SELECT m.target FROM karma_merges m WHERE m.added_by IN (:karmaPlayerIds)
),
SQL : '';
        $ownScope = $scoped ? 'AND p.id IN (SELECT id FROM scope_puzzles)' : '';
        $mergedScope = $scoped ? 'AND m.target IN (SELECT id FROM scope_puzzles)' : '';
        $reporterFilter = $playerFilter('r.reporter_id');
        $mergeReporterFilter = $playerFilter('q.reporter_id');
        $deciderFilter = $playerFilter('d.decided_by_id');
        $creditFilter = $scoped ? 'AND c.added_by IN (:karmaPlayerIds)' : '';

        return <<<SQL
WITH
-- every puzzle a merge deleted, with who added it and when (the audit's snapshot, since 2026-09-16), and the puzzle it
-- lives on in now: puzzle_redirect is kept one hop deep (a later merge rewrites the older redirects), the audit's
-- survivor until the merge's deletion wrote the redirect
karma_merges AS (
    SELECT (m->>'id')::uuid AS merged_id,
           COALESCE(rd.survivor_puzzle_id, a.survivor_puzzle_id) AS target,
           NULLIF(m->>'addedByUserId', '')::uuid AS added_by,
           NULLIF(m->>'addedAt', '')::timestamp AS added_at,
           m->>'name' AS name
    FROM puzzle_merge_audit a
    CROSS JOIN LATERAL jsonb_array_elements(a.snapshot_before::jsonb -> 'mergedPuzzles') m
    LEFT JOIN puzzle_redirect rd ON rd.old_puzzle_id = (m->>'id')::uuid
),
{$scopePuzzles}
-- who could hold the credit of each puzzle: the puzzle itself and every copy merged into it
karma_candidates AS (
    SELECT p.id AS puzzle_id, p.added_by_user_id AS added_by, p.added_at, p.id AS source_id, p.name AS source_name
    FROM puzzle p
    -- a merged puzzle is deleted right after its merge commits - until then it is one of the survivor's copies only
    WHERE NOT EXISTS (SELECT 1 FROM karma_merges m WHERE m.merged_id = p.id)
      {$ownScope}
    UNION ALL
    SELECT m.target, m.added_by, m.added_at, m.merged_id, m.name
    FROM karma_merges m
    WHERE TRUE {$mergedScope}
),
-- the earliest add keeps the credit (an unknown date is older than the tracking of dates)
karma_puzzle_credits AS (
    SELECT c.*, ROW_NUMBER() OVER (PARTITION BY c.puzzle_id ORDER BY c.added_at ASC NULLS FIRST, c.source_id) AS position
    FROM karma_candidates c
),
karma_puzzle_items AS (
    SELECT c.added_by AS player_id,
           CASE WHEN c.position = 1 THEN 'puzzle_added' ELSE 'duplicate_puzzle' END AS kind,
           c.added_at AS at,
           p.id AS puzzle_id,
           CASE WHEN c.position = 1 THEN p.name ELSE c.source_name END AS puzzle_name,
           NULL::text AS action,
           c.position = 1 AS counted
    FROM karma_puzzle_credits c
    JOIN puzzle p ON p.id = c.puzzle_id
    WHERE c.added_by IS NOT NULL
      AND p.approved = true
      AND {$hasEan}
      {$creditFilter}
),
karma_items AS (
    SELECT * FROM karma_puzzle_items
    UNION ALL
    -- approved change requests (they move with a merge, so puzzle_id is the puzzle they are about now)
    SELECT r.reporter_id, 'change_request', COALESCE(r.reviewed_at, r.submitted_at), p.id, p.name, NULL, true
    FROM puzzle_change_request r
    LEFT JOIN puzzle p ON p.id = r.puzzle_id
    WHERE r.status = 'approved'
      {$reporterFilter}
    UNION ALL
    -- approved merge requests, linked to the puzzle the merge lives on in
    SELECT q.reporter_id, 'merge_request', COALESCE(q.reviewed_at, q.submitted_at), p.id,
           COALESCE(p.name, q.source_puzzle_name), NULL, true
    FROM puzzle_merge_request q
    LEFT JOIN puzzle_redirect rd ON rd.old_puzzle_id = q.survivor_puzzle_id
    LEFT JOIN puzzle p ON p.id = COALESCE(rd.survivor_puzzle_id, q.survivor_puzzle_id)
    WHERE q.status = 'approved'
      AND q.reporter_id IS NOT NULL
      {$mergeReporterFilter}
    UNION ALL
    -- decisions and direct edits a person made in the admin UI (the application's own closings and the internal
    -- API are nobody's work); on one's own proposal or puzzle only when that does not count already
    SELECT d.decided_by_id,
           CASE WHEN d.action = 'puzzle_edited' THEN 'direct_edit' ELSE 'decision' END,
           d.decided_at, p.id, COALESCE(p.name, d.puzzle_name), d.action, true
    FROM puzzle_moderation_decision d
    LEFT JOIN puzzle p ON p.id = d.puzzle_id
    -- also drops the backfill's decision rows for EANs linked by a scan (approved by their own reporter)
    LEFT JOIN puzzle_change_request own_change ON d.action = 'change_request_approved'
        AND own_change.id = d.change_request_id AND own_change.reporter_id = d.decided_by_id
    LEFT JOIN puzzle_merge_request own_merge ON d.action = 'merge_request_approved'
        AND own_merge.id = d.merge_request_id AND own_merge.reporter_id = d.decided_by_id
    LEFT JOIN karma_puzzle_items own_puzzle ON d.action = 'puzzle_approved'
        AND own_puzzle.counted AND own_puzzle.puzzle_id = d.puzzle_id AND own_puzzle.player_id = d.decided_by_id
    WHERE d.decided_by_id IS NOT NULL
      AND d.source = 'admin_ui'
      AND d.action IN ('change_request_approved', 'change_request_rejected', 'merge_request_approved',
                       'merge_request_rejected', 'puzzle_approved', 'puzzle_edited')
      AND own_change.id IS NULL
      AND own_merge.id IS NULL
      AND own_puzzle.puzzle_id IS NULL
      {$deciderFilter}
)
SQL;
    }
}
