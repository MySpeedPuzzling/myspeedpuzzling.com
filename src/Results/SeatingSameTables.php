<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * "Same tables as round X" of a seating proposal (SeatingProposer, competition toolkit W3): the rounds the tables can
 * be taken from - earlier rounds of the same kind that have table numbers - and the one the proposal uses.
 */
readonly final class SeatingSameTables implements \JsonSerializable
{
    public function __construct(
        public string $fromRoundId,
        public string $fromRoundName,
        // Entrants of this round without a table in the from-round (placed after the highest table)
        public int $notMatched,
        /** @var list<array{roundId: string, name: string}> in the event's order */
        public array $rounds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'fromRound' => ['roundId' => $this->fromRoundId, 'name' => $this->fromRoundName],
            'notMatched' => $this->notMatched,
            'rounds' => $this->rounds,
        ];
    }
}
