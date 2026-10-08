<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * A pair/team of a round (CompetitionTeam) with its official record - its members are the places pointing at it
 * (ParticipantsSheetPlace::$teamId).
 */
readonly final class ParticipantsSheetTeam implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $roundId,
        // null = unnamed
        public null|string $name,
        public null|int $table,
        public RoundEntryResult $result,
        public bool $qualified,
        public null|DateTimeImmutable $enteredAt,
        // Who entered the result - a name, else "#CODE"
        public null|string $enteredBy,
    ) {
    }

    /**
     * @return array{id: string, roundId: string, name: null|string, table: null|int, result: null|array{seconds: int}|array{piecesPlaced: int}|array{didNotStart: true}, qualified: bool, enteredAt: null|string, enteredBy: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'roundId' => $this->roundId,
            'name' => $this->name,
            'table' => $this->table,
            'result' => $this->result->toWire(),
            'qualified' => $this->qualified,
            'enteredAt' => $this->enteredAt?->format(DateTimeImmutable::ATOM),
            'enteredBy' => $this->enteredBy,
        ];
    }
}
