<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
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
        public null|RoundEntryResult|RoundEntryPuzzles|int|bool $current,
        public null|DateTimeImmutable $enteredAt = null,
        public null|string $enteredById = null,
        public null|string $enteredByName = null,
        // Competition toolkit P1: the time proposal the change confirmed, rejected or found - only when it named one, or
        // the resolver decided the entry's proposal through it (TimeProposalResolver)
        /** @var null|array{id: string, status: string} $proposal */
        public null|array $proposal = null,
    ) {
    }

    public function withProposal(string $proposalId, string $status): self
    {
        return new self(
            clientChangeId: $this->clientChangeId,
            status: $this->status,
            reason: $this->reason,
            entryRef: $this->entryRef,
            field: $this->field,
            current: $this->current,
            enteredAt: $this->enteredAt,
            enteredById: $this->enteredById,
            enteredByName: $this->enteredByName,
            proposal: ['id' => $proposalId, 'status' => $status],
        );
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
            'current' => $this->current instanceof RoundEntryResult || $this->current instanceof RoundEntryPuzzles ? $this->current->toWire() : $this->current,
            'enteredAt' => $this->enteredAt?->format(DateTimeImmutable::ATOM),
            'enteredBy' => $this->enteredById === null ? null : [
                'playerId' => $this->enteredById,
                'name' => $this->enteredByName,
            ],
            // Only on a change about a time proposal - every other outcome keeps its shape
            ...($this->proposal !== null ? ['proposal' => $this->proposal] : []),
        ];
    }
}
