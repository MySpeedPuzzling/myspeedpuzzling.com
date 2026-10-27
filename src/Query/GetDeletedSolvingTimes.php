<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\DeletedSolvingTime;

/**
 * The player's tombstones, oldest first, a page at a time - GET /api/v1/me/solving-times/deleted
 * (docs/features/api/solving-time-sync.md). Keyset on (deleted_at, solving_time_id): deletions share a second,
 * so the id breaks ties and no page skips or repeats one. Served by the (player_id, deleted_at, solving_time_id)
 * index alone.
 */
readonly final class GetDeletedSolvingTimes
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<DeletedSolvingTime> up to $limit + 1 rows - the extra one tells there is another page
     */
    public function forPlayer(
        string $playerId,
        null|DateTimeImmutable $since,
        null|DeletedSolvingTime $after,
        int $limit,
    ): array {
        $conditions = '';
        $parameters = [
            'playerId' => $playerId,
            'limit' => $limit + 1,
        ];

        if ($since !== null) {
            $conditions .= ' AND deleted_at >= :since';
            $parameters['since'] = $since->format('Y-m-d H:i:s');
        }

        if ($after !== null) {
            $conditions .= ' AND (deleted_at, solving_time_id) > (CAST(:afterAt AS timestamp), CAST(:afterId AS uuid))';
            $parameters['afterAt'] = $after->deletedAt->format('Y-m-d H:i:s');
            $parameters['afterId'] = $after->timeId;
        }

        /** @var list<array{solving_time_id: string, deleted_at: string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT solving_time_id, deleted_at
FROM solving_time_tombstone
WHERE player_id = :playerId
    {$conditions}
ORDER BY deleted_at, solving_time_id
LIMIT :limit
SQL, $parameters)->fetchAllAssociative();

        return array_map(
            static fn (array $row): DeletedSolvingTime => new DeletedSolvingTime(
                timeId: $row['solving_time_id'],
                deletedAt: new DateTimeImmutable($row['deleted_at'], new DateTimeZone('UTC')),
            ),
            $rows,
        );
    }
}
