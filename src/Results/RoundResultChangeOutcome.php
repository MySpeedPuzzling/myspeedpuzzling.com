<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use SpeedPuzzling\Web\Value\RoundResultField;

/**
 * What RecordRoundResults did with one change. `current` is the field's value after the change set (in the field's
 * wire format) - for a conflict the value somebody else saved, with who entered the result and when.
 */
readonly final class RoundResultChangeOutcome implements \JsonSerializable
{
    public function __construct(
        public string $clientChangeId,
        public RoundResultChangeStatus $status,
        // Translation key of the reason, official_results.reason.* (rejected and conflict only)
        public null|string $reason,
        public null|string $entryRef,
        public null|RoundResultField $field,
        public null|RoundEntryResult|int|bool $current,
        public null|DateTimeImmutable $enteredAt = null,
        public null|string $enteredById = null,
        public null|string $enteredByName = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'clientChangeId' => $this->clientChangeId,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'entry' => $this->entryRef,
            'field' => $this->field?->value,
            'current' => $this->current instanceof RoundEntryResult ? $this->current->toWire() : $this->current,
            'enteredAt' => $this->enteredAt?->format(DateTimeImmutable::ATOM),
            'enteredBy' => $this->enteredById === null ? null : [
                'playerId' => $this->enteredById,
                'name' => $this->enteredByName,
            ],
        ];
    }
}
