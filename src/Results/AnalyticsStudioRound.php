<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * One round of a publicly visible event as the analytics studio reads it (GetAnalyticsStudioRound,
 * docs/features/competitions-management/analytics-studio.md §2.2) - the round, its stopwatch, the event's settings the
 * studio follows and the event's other slugged rounds (the round switcher, earlier published rounds of an entrant).
 */
readonly final class AnalyticsStudioRound
{
    /**
     * @param list<AnalyticsStudioEventRound> $eventRounds every slugged round of the event in schedule order, this one included
     */
    public function __construct(
        public string $id,
        public string $competitionId,
        public string $name,
        public string $slug,
        public RoundCategory $category,
        public DateTimeImmutable $startsAt,
        public null|int $minutesLimit,
        public null|string $stopwatchStatus,
        public null|DateTimeImmutable $stopwatchStartedAt,
        public null|DateTimeImmutable $stopwatchFirstStartedAt,
        public null|DateTimeImmutable $stopwatchStoppedAt,
        public null|DateTimeImmutable $resultsPublishedAt,
        public bool $tableNumbersOff,
        public string $puzzleFormat,
        public null|int $puzzlesToSolve,
        public string $eventName,
        public null|string $eventSlug,
        public null|string $seriesName,
        public null|string $seriesSlug,
        // The event shows confirmed results before publishing: competitor pages AND their live results (the setting
        // does nothing while the pages are off - the competitor page rule, analytics-studio.md D8)
        public bool $liveResults,
        public array $eventRounds,
    ) {
    }

    public function published(): bool
    {
        return $this->resultsPublishedAt !== null;
    }

    public function isPick(): bool
    {
        return $this->puzzleFormat === 'pick';
    }

    /**
     * "Before this round" (analytics-studio.md D11): the moment the round's stopwatch first started
     * (CompetitionRound::stopwatchOriginalStartAt()), else its scheduled start.
     */
    public function historyCutOff(): DateTimeImmutable
    {
        if ($this->stopwatchStartedAt === null) {
            return $this->startsAt;
        }

        return $this->stopwatchFirstStartedAt ?? $this->stopwatchStartedAt;
    }

    public function stopwatch(DateTimeImmutable $asOf): RoundStopwatchState
    {
        return new RoundStopwatchState(
            roundId: $this->id,
            storedStatus: $this->stopwatchStatus,
            startedAt: $this->stopwatchStartedAt,
            stoppedAt: $this->stopwatchStoppedAt,
            minutesLimit: $this->minutesLimit ?? 0,
            asOf: $asOf,
        );
    }
}
