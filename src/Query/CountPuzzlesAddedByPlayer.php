<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * How many new puzzles a player put into the catalogue since a moment - whichever way (the add form, the scanner,
 * "Add to the catalogue" of an unlisted puzzle). Every one of them is a puzzle the moderators approve by hand, which
 * is what "Add to the catalogue" counts its daily limit by (docs/features/data-import/unlisted-puzzles.md).
 */
readonly final class CountPuzzlesAddedByPlayer
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function since(string $playerId, DateTimeImmutable $since): int
    {
        if (Uuid::isValid($playerId) === false) {
            return 0;
        }

        /** @var int|string|false $count */
        $count = $this->database->executeQuery(<<<SQL
SELECT COUNT(*)
FROM puzzle
WHERE puzzle.added_by_user_id = :playerId
    AND puzzle.added_at >= :since
SQL, ['playerId' => $playerId, 'since' => $since->format('Y-m-d H:i:s')])->fetchOne();

        return (int) $count;
    }
}
