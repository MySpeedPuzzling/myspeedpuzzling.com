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
        public bool $firstAttempt,
        public null|string $competitionId,
        public null|string $competitionShortcut,
        public null|string $competitionName,
        public null|string $competitionSlug,
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
     *     first_attempt: bool,
     *     competition_id: null|string,
     *     competition_shortcut: null|string,
     *     competition_name: null|string,
     *     competition_slug: null|string,
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
            firstAttempt: $row['first_attempt'],
            competitionId: $row['competition_id'],
            competitionShortcut: $row['competition_shortcut'],
            competitionName: $row['competition_name'],
            competitionSlug: $row['competition_slug'],
        );
    }

    public function containsPlayer(null|string $playerId): bool
    {
        if ($playerId !== null) {
            foreach ($this->players as $puzzler) {
                if ($puzzler->playerId === $playerId) {
                    return true;
                }
            }
        }

        return false;
    }
}
