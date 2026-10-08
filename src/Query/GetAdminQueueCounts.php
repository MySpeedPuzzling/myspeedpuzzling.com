<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\AdminQueueCounts;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalStatus;
use SpeedPuzzling\Web\Value\OAuth2ClientRequestStatus;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

/**
 * The backlog of every review queue, as badges next to their links in the key menu (base.html.twig): one statement
 * per page, run only for admins and moderators (the menu is inside PUZZLE_MODERATION_ACCESS); the admin-only queues
 * are not even counted for a moderator. Each count is the queue's own definition of "waiting" - pinned against the
 * queues' own counts by GetAdminQueueCountsTest:
 *
 * - change requests: pending, a puzzle a competition keeps secret only for admins (GetPuzzleChangeRequests::countByStatus())
 * - merge requests: pending, none touching a secret puzzle (GetPuzzleMergeReviewQueue::countPending())
 * - puzzle approvals: unapproved, not secret (GetPuzzleApprovals::countPending())
 * - time verification: pending cases (GetSuspiciousTimeQueue::countPending())
 * - competition approvals: events (not series editions), series and organizations neither approved nor rejected, and
 *   not drafts - a draft is submitted by publishing it (GetCompetitionEvents::allUnapproved(),
 *   GetCompetitionSeries::allUnapproved(), GetOrganizations::allUnapproved())
 * - OAuth2 requests: pending
 * - duplicate results: open strong "possible duplicate puzzles" signals - what an admin acts on there; duplicate cases
 *   are for the players to decide
 *
 * Measured on production 2026-10-08: ~15 ms for all of them, all but ~1 ms from two scans of every puzzle (the merge
 * requests' secrecy check and the unapproved puzzles); with custom_puzzle_hidden + custom_puzzle_unapproved
 * (docs/database-indexes.md) ~0.15 ms each on a copy of production - about 1 ms in total.
 */
readonly final class GetAdminQueueCounts
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function forViewer(bool $isAdmin): AdminQueueCounts
    {
        // Admins may correct a secret puzzle, so their change request count includes it (as on the queue's page)
        $changeRequestsNotSecret = $isAdmin ? 'true' : PuzzleSecrecy::sqlNotSecret('p');
        $approvalsNotSecret = PuzzleSecrecy::sqlNotSecret('p');
        $mergeRequestsNoSecret = GetPuzzleMergeRequests::sqlNoSecretPuzzle();

        $competitionApprovals = $isAdmin
            ? '(SELECT COUNT(*) FROM competition c WHERE c.approved_at IS NULL AND c.rejected_at IS NULL AND c.series_id IS NULL AND c.is_draft = false)'
                . ' + (SELECT COUNT(*) FROM competition_series cs WHERE cs.approved_at IS NULL AND cs.rejected_at IS NULL AND cs.is_draft = false)'
                . ' + (SELECT COUNT(*) FROM organization o WHERE o.approved_at IS NULL AND o.rejected_at IS NULL AND o.is_draft = false)'
            : 'NULL';
        $oauth2Requests = $isAdmin
            ? '(SELECT COUNT(*) FROM oauth2_client_request r WHERE r.status = :oauth2Pending)'
            : 'NULL';
        $duplicatePuzzleSignals = $isAdmin
            ? '(SELECT COUNT(*) FROM duplicate_puzzle_signal s WHERE s.status = :signalOpen AND s.weak = false)'
            : 'NULL';

        $query = <<<SQL
SELECT
    (
        SELECT COUNT(*)
        FROM puzzle_change_request pcr
        INNER JOIN puzzle p ON p.id = pcr.puzzle_id
        WHERE pcr.status = :reportPending AND {$changeRequestsNotSecret}
    ) AS change_requests,
    (SELECT COUNT(*) FROM puzzle_merge_request pmr WHERE pmr.status = :reportPending AND {$mergeRequestsNoSecret}) AS merge_requests,
    (SELECT COUNT(*) FROM puzzle p WHERE p.approved = false AND {$approvalsNotSecret}) AS puzzle_approvals,
    (SELECT COUNT(*) FROM suspicious_time_case t WHERE t.status = :casePending) AS time_verification,
    {$competitionApprovals} AS competition_approvals,
    {$oauth2Requests} AS oauth2_requests,
    {$duplicatePuzzleSignals} AS duplicate_puzzle_signals
SQL;

        $parameters = [
            'reportPending' => PuzzleReportStatus::Pending->value,
            'casePending' => SuspiciousTimeCaseStatus::Pending->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];

        if ($isAdmin) {
            $parameters['oauth2Pending'] = OAuth2ClientRequestStatus::Pending->value;
            $parameters['signalOpen'] = DuplicatePuzzleSignalStatus::Open->value;
        }

        /** @var array{change_requests: int|string, merge_requests: int|string, puzzle_approvals: int|string, time_verification: int|string, competition_approvals: null|int|string, oauth2_requests: null|int|string, duplicate_puzzle_signals: null|int|string} $row */
        $row = $this->database->fetchAssociative($query, $parameters);

        return new AdminQueueCounts(
            changeRequests: (int) $row['change_requests'],
            mergeRequests: (int) $row['merge_requests'],
            puzzleApprovals: (int) $row['puzzle_approvals'],
            timeVerification: (int) $row['time_verification'],
            competitionApprovals: $row['competition_approvals'] !== null ? (int) $row['competition_approvals'] : null,
            oauth2Requests: $row['oauth2_requests'] !== null ? (int) $row['oauth2_requests'] : null,
            duplicatePuzzleSignals: $row['duplicate_puzzle_signals'] !== null ? (int) $row['duplicate_puzzle_signals'] : null,
        );
    }
}
