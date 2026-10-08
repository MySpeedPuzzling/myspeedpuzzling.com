<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * A person's place in a round (CompetitionParticipantRound) - in it alone, or in a pair/team of it (`teamId`). The
 * official fields (table number, result, qualified, entered by/at) belong to the place in a solo round only; in a
 * pair/team round they live on the team (ParticipantsSheetTeam) and are empty here.
 */
readonly final class ParticipantsSheetPlace implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $participantId,
        public string $roundId,
        public null|string $teamId,
        public null|int $table,
        public RoundEntryResult $result,
        public bool $qualified,
        public null|DateTimeImmutable $enteredAt,
        // Who entered the result - a name, else "#CODE"
        public null|string $enteredBy,
    ) {
    }

    /**
     * @return array{id: string, participantId: string, roundId: string, teamId: null|string, table: null|int, result: null|array{seconds: int}|array{piecesPlaced: int}|array{didNotStart: true}, qualified: bool, enteredAt: null|string, enteredBy: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'participantId' => $this->participantId,
            'roundId' => $this->roundId,
            'teamId' => $this->teamId,
            'table' => $this->table,
            'result' => $this->result->toWire(),
            'qualified' => $this->qualified,
            'enteredAt' => $this->enteredAt?->format(DateTimeImmutable::ATOM),
            'enteredBy' => $this->enteredBy,
        ];
    }
}
