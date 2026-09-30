<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\FirstTryTime;

/**
 * What the first-try rules say about a result about to be saved with the tag
 * (docs/features/first-try-integrity.md):
 *
 * - a hold: somebody of the result already has another result of the puzzle marked as a first try. It blocks
 *   the save, unless the viewer is part of every such result and moves the tag to the new one;
 * - a warning: somebody of the result solved the puzzle on an earlier day, without the tag. Saving stays possible.
 *
 * An edit that neither ticks the tag newly nor adds anybody is tolerated even with holds - the old duplicates
 * must not keep people from fixing a typo. They get a pointer to the conflicts page instead.
 */
readonly final class FirstTryAssessment
{
    /**
     * @param list<FirstTryTime> $holds
     * @param list<FirstTryNoticeLine> $holdLines
     * @param list<FirstTryNoticeLine> $earlierLines
     */
    public function __construct(
        public array $holds,
        public array $holdLines,
        public array $earlierLines,
        public bool $viewerCanMove,
        public bool $tolerated,
        public DateTimeImmutable $solvedAt,
    ) {
    }

    public static function nothing(DateTimeImmutable $solvedAt): self
    {
        return new self([], [], [], false, false, $solvedAt);
    }

    public function isEmpty(): bool
    {
        return $this->holds === [] && $this->earlierLines === [];
    }

    public function hasHolds(): bool
    {
        return $this->holds !== [];
    }

    public function blocks(FirstTryResolution $resolution): bool
    {
        if ($this->holds === [] || $this->tolerated) {
            return false;
        }

        return $this->movesHere($resolution) === false;
    }

    public function movesHere(FirstTryResolution $resolution): bool
    {
        return $this->holds !== [] && $this->viewerCanMove && $resolution === FirstTryResolution::MoveHere;
    }

    /**
     * @return list<string> the results the tag comes off when the viewer moves it here
     */
    public function timeIdsToUnmark(FirstTryResolution $resolution): array
    {
        if ($this->movesHere($resolution) === false) {
            return [];
        }

        return array_map(static fn(FirstTryTime $time): string => $time->timeId, $this->holds);
    }

    /**
     * Every held first try is from a day after the new result - the new one is most likely the real first try.
     */
    public function isOlderThanHolds(): bool
    {
        if ($this->holds === []) {
            return false;
        }

        foreach ($this->holds as $hold) {
            if ($hold->solvedDay() <= $this->solvedAt->format('Y-m-d')) {
                return false;
            }
        }

        return true;
    }
}
