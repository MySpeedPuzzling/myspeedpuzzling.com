<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ProposerTrackRecord;

/**
 * Next to every request in the review queues (docs/features/karma.md, "Review queues"): the proposer's karma and their
 * accepted / declined proposals (change and merge requests). One statement per queue page, however many requests.
 * Proposals the proposer decided themselves (a moderator's own, an EAN linked by a scan) say nothing about whether
 * they are usually right - left out of the rate; outdated ones were never judged - left out too.
 */
readonly final class GetProposerTrackRecords
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param array<null|string> $playerIds
     * @return array<string, ProposerTrackRecord> Lower-case player id => track record
     */
    public function forPlayers(array $playerIds): array
    {
        $playerIds = array_values(array_unique(array_filter(
            array_map(static fn (null|string $id): string => strtolower((string) $id), $playerIds),
            static fn (string $id): bool => Uuid::isValid($id),
        )));

        if ($playerIds === []) {
            return [];
        }

        $sql = <<<SQL
WITH proposals AS (
    SELECT r.reporter_id, r.status
    FROM puzzle_change_request r
    WHERE r.reporter_id IN (:playerIds)
      AND r.status IN ('approved', 'rejected')
      AND r.reviewed_by_id IS DISTINCT FROM r.reporter_id
    UNION ALL
    SELECT q.reporter_id, q.status
    FROM puzzle_merge_request q
    WHERE q.reporter_id IN (:playerIds)
      AND q.status IN ('approved', 'rejected')
      AND q.reviewed_by_id IS DISTINCT FROM q.reporter_id
)
SELECT
    p.id AS player_id,
    p.karma,
    COUNT(pr.reporter_id) FILTER (WHERE pr.status = 'approved') AS accepted,
    COUNT(pr.reporter_id) FILTER (WHERE pr.status = 'rejected') AS declined
FROM player p
LEFT JOIN proposals pr ON pr.reporter_id = p.id
WHERE p.id IN (:playerIds)
GROUP BY p.id, p.karma
SQL;

        /** @var list<array{player_id: string, karma: int|string, accepted: int|string, declined: int|string}> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            ['playerIds' => $playerIds],
            ['playerIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $records = [];

        foreach ($rows as $row) {
            $records[strtolower($row['player_id'])] = new ProposerTrackRecord(
                karma: (int) $row['karma'],
                accepted: (int) $row['accepted'],
                declined: (int) $row['declined'],
            );
        }

        return $records;
    }
}
