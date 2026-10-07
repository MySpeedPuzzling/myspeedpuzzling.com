<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One round of an event as the official results tools need it (GetRoundResultsOverview): when it runs, its stopwatch,
 * whether its results are published, and how far result entry and seating are - "Tables: 180 / 200 assigned"
 * (entriesWithTableNumber / entriesTotal), unless the organiser switched table numbers off for the round.
 */
readonly final class RoundResultsOverview implements \JsonSerializable
{
    public function __construct(
        public string $roundId,
        public string $name,
        /** RoundCategory value: solo | duo | team */
        public string $category,
        public DateTimeImmutable $startsAt,
        public int $minutesLimit,
        public null|string $slug,
        public null|string $stopwatchStatus,
        public null|DateTimeImmutable $stopwatchStartedAt,
        public null|DateTimeImmutable $stopwatchStoppedAt,
        public null|DateTimeImmutable $resultsPublishedAt,
        public null|DateTimeImmutable $resultsFirstPublishedAt,
        public bool $tableNumbersOff,
        public int $puzzlesCount,
        // The piece count of the round's puzzle when it has exactly one - unfinished results place fewer pieces
        public null|int $piecesCount,
        public int $entriesTotal,
        public int $entriesWithTableNumber,
        public int $entriesWithResult,
        public int $entriesQualified,
    ) {
    }

    /**
     * Every entry has a table number, or the round does not use them.
     */
    public function isSeated(): bool
    {
        return $this->tableNumbersOff || $this->entriesWithTableNumber >= $this->entriesTotal;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->roundId,
            'name' => $this->name,
            'category' => $this->category,
            'startsAt' => $this->startsAt->format(DateTimeImmutable::ATOM),
            'minutesLimit' => $this->minutesLimit,
            'slug' => $this->slug,
            'stopwatch' => [
                'status' => $this->stopwatchStatus,
                'startedAt' => $this->stopwatchStartedAt?->format(DateTimeImmutable::ATOM),
                'stoppedAt' => $this->stopwatchStoppedAt?->format(DateTimeImmutable::ATOM),
            ],
            'resultsPublished' => $this->resultsPublishedAt !== null,
            'resultsPublishedAt' => $this->resultsPublishedAt?->format(DateTimeImmutable::ATOM),
            'resultsFirstPublishedAt' => $this->resultsFirstPublishedAt?->format(DateTimeImmutable::ATOM),
            'tableNumbersOff' => $this->tableNumbersOff,
            'puzzlesCount' => $this->puzzlesCount,
            'piecesCount' => $this->piecesCount,
            'entries' => [
                'total' => $this->entriesTotal,
                'withTableNumber' => $this->entriesWithTableNumber,
                'withResult' => $this->entriesWithResult,
                'qualified' => $this->entriesQualified,
            ],
            'seated' => $this->isSeated(),
        ];
    }
}
