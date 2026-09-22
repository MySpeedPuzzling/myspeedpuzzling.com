<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Results\MultiscanEligibilityReport;
use SpeedPuzzling\Web\Services\MultiscanEligibility;
use SpeedPuzzling\Web\Value\MultiscanAction;

/**
 * Shared front half of every multiscan batch handler: de-duplicate, refuse an
 * empty batch, read the player's statuses fresh and refuse the whole batch on
 * the first puzzle the action does not apply to - before anything is written
 * (a rolled-back handler would otherwise leak through a later flush).
 */
readonly final class MultiscanBatchGuard
{
    public function __construct(
        private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        private MultiscanEligibility $eligibility,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     * @throws MultiscanBatchRejected
     */
    public function check(
        MultiscanAction $action,
        string $playerId,
        array $puzzleIds,
        null|string $collectionId = null,
    ): MultiscanEligibilityReport {
        $puzzleIds = array_values(array_unique($puzzleIds));

        if ($puzzleIds === []) {
            throw new MultiscanBatchRejected(null, 'empty');
        }

        // The request-scoped cache may hold statuses read before this batch
        $this->getUserPuzzleStatuses->reset();
        $statuses = $this->getUserPuzzleStatuses->byPlayerId($playerId);

        $report = $this->eligibility->check($action, $puzzleIds, $statuses, $collectionId);

        foreach ($report->skipped as $puzzleId => $reason) {
            throw new MultiscanBatchRejected($puzzleId, $reason);
        }

        return $report;
    }
}
