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
        // A series pick (docs/features/events-page/high-frequency-series.md): the series the player picked - the
        // competition is then the edition MySpeedPuzzling found for it, or none (series-level)
        public null|string $competitionSeriesId = null,
    ) {
    }

    public function isGroup(): bool
    {
        return $this->teamId !== null;
    }

    /**
     * The event the copy was saved with, as "identical in every field" compares it (high-frequency-series.md P23): a
     * series pick is its series - the edition is derived, so two copies of one series pick are the same event even when
     * only one of them was matched to an edition so far; an explicit link is its competition and round.
     */
    public function eventIdentity(): null|string
    {
        if ($this->competitionSeriesId !== null) {
            return 'series:' . $this->competitionSeriesId;
        }

        if ($this->competitionId === null && $this->competitionRoundId === null) {
            return null;
        }

        return 'competition:' . ($this->competitionId ?? '') . ' round:' . ($this->competitionRoundId ?? '');
    }
}
