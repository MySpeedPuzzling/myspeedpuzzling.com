<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class PuzzleSolversGroup
{
    public function __construct(
        public null|string $teamId,
        public int $time,
        /** @var array<Puzzler> */
        public array $players,
        public DateTimeImmutable $finishedAt,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     puzzle_id: string,
     *     time: int,
     *     comment: null|string,
     *     team_id: null|string,
     *     players: string,
     *     finished_at: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = Puzzler::createPuzzlersFromJson($row['players']);

        return new self(
            teamId: $row['team_id'],
            time: $row['time'],
            players: $players,
            finishedAt: new DateTimeImmutable($row['finished_at']),
        );
    }
}
