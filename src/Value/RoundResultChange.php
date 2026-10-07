<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One change sent by an organiser's device (RecordRoundResults): set `field` of one round entry from `from` (the
 * value the device last saw) to `to`. The entry is an existing one (`target`) or one created by this change
 * (`newEntry`). A change the parser could not read carries only its id and `rejectedReason`.
 *
 * Values are RoundEntryResult for the result, null|int for the table number, bool for the qualified mark.
 */
final readonly class RoundResultChange
{
    private function __construct(
        public string $clientChangeId,
        public null|RoundEntryRef $target,
        public null|NewRoundEntry $newEntry,
        public null|RoundResultField $field,
        public null|RoundEntryResult|int|bool $from,
        public null|RoundEntryResult|int|bool $to,
        public null|string $rejectedReason,
    ) {
    }

    public static function forEntry(
        string $clientChangeId,
        RoundEntryRef|NewRoundEntry $entry,
        RoundResultField $field,
        null|RoundEntryResult|int|bool $from,
        null|RoundEntryResult|int|bool $to,
    ): self {
        return new self(
            clientChangeId: strtolower($clientChangeId),
            target: $entry instanceof RoundEntryRef ? $entry : null,
            newEntry: $entry instanceof NewRoundEntry ? $entry : null,
            field: $field,
            from: $from,
            to: $to,
            rejectedReason: null,
        );
    }

    public static function unreadable(string $clientChangeId, string $reason): self
    {
        return new self(strtolower($clientChangeId), null, null, null, null, null, $reason);
    }

    public function entryRef(): null|RoundEntryRef
    {
        return $this->target ?? $this->newEntry?->ref();
    }
}
