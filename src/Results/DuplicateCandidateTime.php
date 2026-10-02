<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One copy of a duplicate candidate, with every field "identical in every field" compares.
 */
readonly final class DuplicateCandidateTime
{
    /**
     * @param list<array{id: string, name: null|string, code: string}> $people the registered people of the result,
     *     the tracker alone for a solo result; guests are left out
     */
    public function __construct(
        public string $timeId,
        public string $trackerId,
        public null|string $trackerName,
        public string $trackerCode,
        public null|string $teamId,
        public array $people,
        // Y-m-d of COALESCE(finished_at, tracked_at)
        public string $solvedDay,
        public null|DateTimeImmutable $finishedAt,
        public DateTimeImmutable $trackedAt,
        public null|string $comment,
        public bool $hasPhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $competitionId,
        public null|string $competitionRoundId,
    ) {
    }

    public function isGroup(): bool
    {
        return $this->teamId !== null;
    }
}
