<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use Nette\Utils\Json;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class PuzzleSolversGroup
{
    public function __construct(
        public null|string $teamId,
        public int $time,
        /** @var array<Puzzler> */
        public array $players,
    ) {
    }

    /**
     * @param array{
     *     added_by_player_id: string,
     *     puzzle_id: string,
     *     time: int,
     *     comment: null|string,
     *     team_id: null|string,
     *     players: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = Puzzler::createPuzzlersFromJson($row['players']);

        return new self(
            teamId: $row['team_id'],
            time: $row['time'],
            players: $players,
        );
    }
}
