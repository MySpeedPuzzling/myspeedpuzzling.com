<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;

/**
 * A listing the player marked as "bringing" to an event - also the rows the site no longer shows (the event is
 * over or the player stopped going), flagged by currentlyShown (docs/features/marketplace/11-events.md).
 */
readonly final class ExportableSellSwapEvent
{
    public const array COLUMNS = ['sell_swap_item_id', 'puzzle_id', 'puzzle_name', 'event_id', 'event_name', 'event_series_name', 'event_date_from', 'event_date_to', 'added_at', 'currently_shown'];

    public function __construct(
        public string $sellSwapItemId,
        public string $puzzleId,
        public string $puzzleName,
        public string $eventId,
        public string $eventName,
        public null|string $eventSeriesName,
        public null|DateTimeImmutable $eventDateFrom,
        public null|DateTimeImmutable $eventDateTo,
        public DateTimeImmutable $addedAt,
        public bool $currentlyShown,
    ) {
    }

    /**
     * @param array{
     *     sell_swap_item_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     event_id: string,
     *     event_name: string,
     *     event_series_name: null|string,
     *     event_date_from: null|string,
     *     event_date_to: null|string,
     *     added_at: string,
     *     currently_shown: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            sellSwapItemId: $row['sell_swap_item_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            eventId: $row['event_id'],
            eventName: $row['event_name'],
            eventSeriesName: $row['event_series_name'],
            eventDateFrom: $row['event_date_from'] !== null ? new DateTimeImmutable($row['event_date_from']) : null,
            eventDateTo: $row['event_date_to'] !== null ? new DateTimeImmutable($row['event_date_to']) : null,
            addedAt: new DateTimeImmutable($row['added_at']),
            currentlyShown: $row['currently_shown'],
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toColumns(): array
    {
        return [
            'sell_swap_item_id' => $this->sellSwapItemId,
            'puzzle_id' => $this->puzzleId,
            'puzzle_name' => $this->puzzleName,
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'event_series_name' => $this->eventSeriesName,
            'event_date_from' => $this->eventDateFrom?->format('Y-m-d H:i:s'),
            'event_date_to' => $this->eventDateTo?->format('Y-m-d H:i:s'),
            'added_at' => $this->addedAt->format('Y-m-d H:i:s'),
            'currently_shown' => $this->currentlyShown,
        ];
    }
}
