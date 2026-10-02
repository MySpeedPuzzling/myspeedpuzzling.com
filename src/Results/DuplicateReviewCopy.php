<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One copy of a duplicate case as the person sees it on the review page and the recap - people the viewer may
 * not see are masked (FirstTryPerson), exactly like on the first-try conflicts.
 */
readonly final class DuplicateReviewCopy
{
    /**
     * @param list<FirstTryPerson> $people whoever took part, tracker first
     */
    public function __construct(
        public string $timeId,
        public string $trackerId,
        public DateTimeImmutable $solvedAt,
        public DateTimeImmutable $trackedAt,
        public null|int $secondsToSolve,
        public null|string $comment,
        public null|string $finishedPuzzlePhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $competitionName,
        public array $people,
    ) {
    }

    public function isTrackedBy(string $playerId): bool
    {
        return strtolower($this->trackerId) === strtolower($playerId);
    }

    public function tracker(): null|FirstTryPerson
    {
        foreach ($this->people as $person) {
            if ($person->is($this->trackerId)) {
                return $person;
            }
        }

        return null;
    }

    public function isGroup(): bool
    {
        return count($this->people) > 1;
    }

    /**
     * @return list<FirstTryPerson>
     */
    public function peopleExcept(string $playerId): array
    {
        return array_values(array_filter(
            $this->people,
            static fn (FirstTryPerson $person): bool => $person->is($playerId) === false,
        ));
    }
}
