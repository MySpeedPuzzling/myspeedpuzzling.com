<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PaceHistorySyncResult;

/**
 * Brings player_pace_history in line with the qualifying first attempts - for every player (the
 * 15-min batch) or for some (the backfill's keyset batches). A genuine bulk operation over up to
 * ~230k rows, so set-based SQL, like the rest of the insights batch.
 *
 * The watermark is the data itself: every row carries the checksum of its pair's qualifying solves up
 * to it (PlayerPaceHistoryCalculator::checksums()). One aggregate statement sums the hashes of every
 * pair's solves as they are now; a pair whose count and sum equal its last stored row is unchanged -
 * not loaded, not written. Only the changed pairs are loaded, and there the first position whose
 * checksum differs from the stored one is the first changed solve (a new or back-dated add, an edit
 * of seconds or dates, a deletion, a suspicious/unboxed flag, a changed first attempt or piece count,
 * a merge, a moved owner): the rows from there on are deleted and written anew. So an incremental run
 * leaves exactly the rows a rebuild from scratch does, and a run over unchanged data writes nothing.
 */
final readonly class PlayerPaceHistoryWriter
{
    // Batch runs and the backfill take turns (session-level; re-entrant inside the backfill's own transaction lock)
    public const int LOCK_KEY = 7_270_301;

    private const int INSERT_CHUNK = 400;
    private const int PLAYER_CHUNK = 500;

    public function __construct(
        private Connection $connection,
        private PlayerBaselineCalculator $baselineCalculator,
        private PlayerPaceHistoryCalculator $calculator,
        private PaceHistoryReadiness $readiness,
    ) {
    }

    /**
     * @param null|list<string> $playerIds null = every player
     */
    public function sync(null|array $playerIds = null): PaceHistorySyncResult
    {
        if ($playerIds === []) {
            return new PaceHistorySyncResult();
        }

        $this->connection->executeStatement('SELECT pg_advisory_lock(:key)', ['key' => self::LOCK_KEY]);

        try {
            return $this->syncUnderLock($playerIds);
        } finally {
            $this->connection->executeStatement('SELECT pg_advisory_unlock(:key)', ['key' => self::LOCK_KEY]);
        }
    }

    /**
     * Holds the writer's lock until the surrounding transaction ends (the backfill handler's).
     */
    public function lockUntilCommit(): void
    {
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(:key)', ['key' => self::LOCK_KEY]);
    }

    /**
     * Players with a qualifying solve or stored rows, after $afterPlayerId in uuid order - the backfill's keyset.
     *
     * @return list<string>
     */
    public function playerIdsAfter(string $afterPlayerId, int $limit): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn("
            SELECT player_id FROM (
                SELECT DISTINCT player_id FROM puzzle_solving_time
                WHERE puzzling_type = 'solo' AND suspicious = false AND unboxed = false AND seconds_to_solve IS NOT NULL
                UNION
                SELECT DISTINCT player_id FROM player_pace_history
            ) players
            WHERE player_id > CAST(:after AS uuid)
            ORDER BY player_id
            LIMIT :limit
        ", ['after' => $afterPlayerId, 'limit' => $limit]);
    }

    /**
     * @param null|non-empty-list<string> $playerIds
     */
    private function syncUnderLock(null|array $playerIds): PaceHistorySyncResult
    {
        $result = new PaceHistorySyncResult();
        $writes = new PaceHistoryWrites();
        $stored = $this->storedLastRows($playerIds);

        /** @var array<string, array<int, null|array{player_id: string, pieces_count: int, qualifying_count: int, inputs_checksum: int}>> $changed player => pieces => its stored last row */
        $changed = [];

        foreach ($this->freshPairs($playerIds) as $key => $fresh) {
            $result->pairs++;
            $storedPair = $stored[$key] ?? null;
            unset($stored[$key]);

            if ($fresh['count'] < PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
                if ($storedPair !== null) {
                    $this->deletePair($fresh['player_id'], $fresh['pieces_count'], $storedPair['qualifying_count'], $result, $writes);
                }

                continue;
            }

            if ($storedPair !== null && $storedPair['qualifying_count'] === $fresh['count'] && $storedPair['inputs_checksum'] === $fresh['checksum']) {
                continue;
            }

            $changed[$fresh['player_id']][$fresh['pieces_count']] = $storedPair;
        }

        // Pairs with rows but no qualifying solve left (all deleted, flagged, moved to another piece count or owner)
        foreach ($stored as $storedPair) {
            $this->deletePair($storedPair['player_id'], $storedPair['pieces_count'], $storedPair['qualifying_count'], $result, $writes);
        }

        foreach (array_chunk(array_keys($changed), self::PLAYER_CHUNK) as $chunk) {
            $this->rewriteChangedPairs($chunk, $changed, $result, $writes);
        }

        $this->flush($writes);

        // Every player is in: from now on the as-of difficulty may be computed from the history
        if ($playerIds === null) {
            $this->readiness->markBuilt();
        }

        return $result;
    }

    /**
     * Marks the history built after a walk over every player in batches (the backfill).
     */
    public function markBuilt(): void
    {
        $this->readiness->markBuilt();
    }

    /**
     * @param list<string> $playerIds
     * @param array<string, array<int, null|array{player_id: string, pieces_count: int, qualifying_count: int, inputs_checksum: int}>> $changed
     */
    private function rewriteChangedPairs(array $playerIds, array $changed, PaceHistorySyncResult $result, PaceHistoryWrites $writes): void
    {
        $pair = null;
        $solves = [];

        foreach ($this->baselineCalculator->iterateQualifyingFirstAttempts($playerIds, paceOrder: true) as $row) {
            $piecesCount = (int) $row['pieces_count'];

            if (!array_key_exists($piecesCount, $changed[$row['player_id']] ?? [])) {
                continue;
            }

            if ($pair !== [$row['player_id'], $piecesCount]) {
                if ($pair !== null) {
                    $this->rewritePair($pair[0], $pair[1], $solves, $changed[$pair[0]][$pair[1]], $result, $writes);
                }

                $pair = [$row['player_id'], $piecesCount];
                $solves = [];
            }

            $solves[] = [
                'id' => $row['id'],
                'seconds' => (int) $row['seconds_to_solve'],
                'solve_date' => $row['solve_date'],
                'tracked_at' => $row['tracked_at'],
            ];
        }

        if ($pair !== null) {
            $this->rewritePair($pair[0], $pair[1], $solves, $changed[$pair[0]][$pair[1]], $result, $writes);
        }
    }

    /**
     * @param list<array{id: string, seconds: int, solve_date: string, tracked_at: string}> $solves
     * @param null|array{player_id: string, pieces_count: int, qualifying_count: int, inputs_checksum: int} $stored the pair's last row
     */
    private function rewritePair(string $playerId, int $piecesCount, array $solves, null|array $stored, PaceHistorySyncResult $result, PaceHistoryWrites $writes): void
    {
        $checksums = PlayerPaceHistoryCalculator::checksums($solves);
        $count = count($solves);
        $from = PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT;

        if ($stored !== null) {
            $storedChecksums = $this->storedChecksums($playerId, $piecesCount);

            while ($from <= $count && ($storedChecksums[$from] ?? null) === $checksums[$from - 1]) {
                $from++;
            }

            if ($from <= $stored['qualifying_count']) {
                $writes->delete($playerId, $piecesCount, $from);
                $result->deleted += $stored['qualifying_count'] - $from + 1;
            }
        }

        foreach ($this->calculator->rows($solves, $checksums, $from) as $row) {
            $writes->insert($playerId, $piecesCount, $row);
            $result->inserted++;
        }

        $result->changedPairs++;

        if ($writes->insertCount() >= self::INSERT_CHUNK) {
            $this->flush($writes);
        }
    }

    private function deletePair(string $playerId, int $piecesCount, int $lastQualifyingCount, PaceHistorySyncResult $result, PaceHistoryWrites $writes): void
    {
        $writes->delete($playerId, $piecesCount, 1);
        $result->deleted += $lastQualifyingCount - PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT + 1;
        $result->changedPairs++;
    }

    /**
     * Every pair's qualifying solves as they are now: how many, and the sum of their hashes.
     *
     * @param null|non-empty-list<string> $playerIds
     * @return iterable<string, array{player_id: string, pieces_count: int, count: int, checksum: int}> keyed "player|pieces"
     */
    private function freshPairs(null|array $playerIds): iterable
    {
        [$qualifying, $params, $types] = $this->baselineCalculator->qualifyingFirstAttemptsQuery($playerIds);
        $solveHash = PlayerPaceHistoryCalculator::sqlSolveHash();

        $rows = $this->connection->executeQuery("
            SELECT player_id, pieces_count, COUNT(*) AS solves, SUM({$solveHash}) AS checksum
            FROM ({$qualifying}) qualifying
            GROUP BY player_id, pieces_count
        ", $params, $types)->iterateAssociative();

        foreach ($rows as $row) {
            /** @var array{player_id: string, pieces_count: int|string, solves: int|string, checksum: int|string} $row */
            yield $row['player_id'] . '|' . $row['pieces_count'] => [
                'player_id' => $row['player_id'],
                'pieces_count' => (int) $row['pieces_count'],
                'count' => (int) $row['solves'],
                'checksum' => (int) $row['checksum'],
            ];
        }
    }

    /**
     * The last row of every pair (of the given players).
     *
     * @param null|non-empty-list<string> $playerIds
     * @return array<string, array{player_id: string, pieces_count: int, qualifying_count: int, inputs_checksum: int}> keyed "player|pieces"
     */
    private function storedLastRows(null|array $playerIds): array
    {
        $condition = $playerIds !== null ? 'WHERE player_id IN (:playerIds)' : '';

        /** @var list<array{player_id: string, pieces_count: int|string, qualifying_count: int|string, inputs_checksum: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT DISTINCT ON (player_id, pieces_count) player_id, pieces_count, qualifying_count, inputs_checksum
            FROM player_pace_history
            {$condition}
            ORDER BY player_id, pieces_count, qualifying_count DESC
        ", ['playerIds' => $playerIds ?? []], ['playerIds' => ArrayParameterType::STRING]);

        $stored = [];

        foreach ($rows as $row) {
            $stored[$row['player_id'] . '|' . $row['pieces_count']] = [
                'player_id' => $row['player_id'],
                'pieces_count' => (int) $row['pieces_count'],
                'qualifying_count' => (int) $row['qualifying_count'],
                'inputs_checksum' => (int) $row['inputs_checksum'],
            ];
        }

        return $stored;
    }

    /**
     * @return array<int, int> qualifying_count => checksum
     */
    private function storedChecksums(string $playerId, int $piecesCount): array
    {
        /** @var array<int|string, int|string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT qualifying_count, inputs_checksum FROM player_pace_history WHERE player_id = :playerId AND pieces_count = :piecesCount',
            ['playerId' => $playerId, 'piecesCount' => $piecesCount],
        );

        $checksums = [];

        foreach ($rows as $qualifyingCount => $checksum) {
            $checksums[(int) $qualifyingCount] = (int) $checksum;
        }

        return $checksums;
    }

    private function flush(PaceHistoryWrites $writes): void
    {
        // Deletes first: a rewritten pair's new rows take the positions of the deleted ones
        $deletes = $writes->takeDeletes();

        if ($deletes !== []) {
            $this->connection->executeStatement('
                DELETE FROM player_pace_history h
                USING unnest(CAST(:playerIds AS uuid[]), CAST(:piecesCounts AS integer[]), CAST(:froms AS integer[])) AS d (player_id, pieces_count, from_count)
                WHERE h.player_id = d.player_id
                    AND h.pieces_count = d.pieces_count
                    AND h.qualifying_count >= d.from_count
            ', [
                'playerIds' => self::arrayLiteral(array_column($deletes, 0)),
                'piecesCounts' => self::arrayLiteral(array_column($deletes, 1)),
                'froms' => self::arrayLiteral(array_column($deletes, 2)),
            ]);
        }

        foreach (array_chunk($writes->takeInserts(), self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $i => [$playerId, $piecesCount, $row]) {
                $values[] = "(:id{$i}, :player{$i}, :pieces{$i}, :from{$i}, :tracked{$i}, :time{$i}, :baseline{$i}, :count{$i}, CAST(:step{$i} AS double precision), NULL, :checksum{$i})";
                $params["id{$i}"] = Uuid::uuid7()->toString();
                $params["player{$i}"] = $playerId;
                $params["pieces{$i}"] = $piecesCount;
                $params["from{$i}"] = $row['valid_from'];
                $params["tracked{$i}"] = $row['valid_from_tracked_at'];
                $params["time{$i}"] = $row['time_id'];
                $params["baseline{$i}"] = $row['baseline_seconds'];
                $params["count{$i}"] = $row['qualifying_count'];
                $params["step{$i}"] = $row['step'];
                $params["checksum{$i}"] = $row['inputs_checksum'];
            }

            $this->connection->executeStatement(
                'INSERT INTO player_pace_history (id, player_id, pieces_count, valid_from, valid_from_tracked_at, time_id, baseline_seconds, qualifying_count, step, drift, inputs_checksum) VALUES ' . implode(', ', $values),
                $params,
            );
        }
    }

    /**
     * @param list<int|string> $values
     */
    private static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(static fn (int|string $value): string => '"' . $value . '"', $values)) . '}';
    }
}
