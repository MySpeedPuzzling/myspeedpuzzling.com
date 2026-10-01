<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One attempt of the subject (a player, or one exact pair/team) on a puzzle - a row of the result detail.
 */
readonly final class PuzzleResultAttempt
{
    public function __construct(
        public string $timeId,
        // Whoever tracked the time
        public string $trackedByPlayerId,
        // NULL = relax solve
        public null|int $time,
        public null|DateTimeImmutable $finishedAt,
        public DateTimeImmutable $trackedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public bool $suspicious,
        public null|string $comment,
        public null|string $finishedPuzzlePhoto,
        public null|string $competitionId,
        public null|string $competitionShortcut,
        public null|string $competitionName,
        public null|string $competitionSlug,
        public null|string $competitionSeriesName,
        public null|string $competitionSeriesShortcut,
        public null|string $competitionSeriesSlug,
        /**
         * Registered members of the pair/team (empty for a solo attempt) - every one of them may edit it
         *
         * @var list<string>
         */
        public array $memberPlayerIds = [],
        public bool $isBest = false,
        // Change against the chronologically previous timed attempt: negative = faster
        public null|int $deltaToPrevious = null,
        // How far behind the subject's best this attempt is (only for timed attempts that are not the best)
        public null|int $gapToBest = null,
    ) {
    }

    public function isEditableBy(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        return $playerId === $this->trackedByPlayerId || in_array($playerId, $this->memberPlayerIds, true);
    }

    public function withComparison(bool $isBest, null|int $deltaToPrevious, null|int $gapToBest): self
    {
        return new self(
            timeId: $this->timeId,
            trackedByPlayerId: $this->trackedByPlayerId,
            time: $this->time,
            finishedAt: $this->finishedAt,
            trackedAt: $this->trackedAt,
            firstAttempt: $this->firstAttempt,
            unboxed: $this->unboxed,
            suspicious: $this->suspicious,
            comment: $this->comment,
            finishedPuzzlePhoto: $this->finishedPuzzlePhoto,
            competitionId: $this->competitionId,
            competitionShortcut: $this->competitionShortcut,
            competitionName: $this->competitionName,
            competitionSlug: $this->competitionSlug,
            competitionSeriesName: $this->competitionSeriesName,
            competitionSeriesShortcut: $this->competitionSeriesShortcut,
            competitionSeriesSlug: $this->competitionSeriesSlug,
            memberPlayerIds: $this->memberPlayerIds,
            isBest: $isBest,
            deltaToPrevious: $deltaToPrevious,
            gapToBest: $gapToBest,
        );
    }
}
