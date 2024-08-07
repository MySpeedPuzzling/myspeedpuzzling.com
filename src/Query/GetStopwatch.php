<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;
use SpeedPuzzling\Web\Results\StopwatchDetail;
use SpeedPuzzling\Web\Value\StopwatchStatus;

readonly final class GetStopwatch
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws StopwatchNotFound
     */
    public function byId(string $stopwatchId): StopwatchDetail
    {
        if (Uuid::isValid($stopwatchId) === false) {
            throw new StopwatchNotFound;
        }

        $query = <<<SQL
SELECT
  stopwatch.id AS stopwatch_id, status,
  stopwatch.puzzle_id AS puzzle_id,
  puzzle.name AS puzzle_name,
  SUM(
    (EXTRACT(EPOCH FROM (lap->>'end')::timestamp - (lap->>'start')::timestamp))
  ) AS total_seconds,
  (laps->(jsonb_array_length(laps::jsonb) - 1)->>'start')::timestamp AS last_start_time,
  (laps->(jsonb_array_length(laps::jsonb) - 1)->>'end')::timestamp AS last_end_time
FROM
  stopwatch LEFT JOIN puzzle ON puzzle.id = stopwatch.puzzle_id,
  JSONB_ARRAY_ELEMENTS(laps::jsonb) AS lap
WHERE
    stopwatch.id = :stopwatchId
GROUP BY
  stopwatch.id, puzzle_id, puzzle_name
SQL;

        /**
         * @var false|array{
         *     stopwatch_id: string,
         *     total_seconds: null|int,
         *     last_start_time: null|string,
         *     last_end_time: null|string,
         *     status: string,
         *     puzzle_id: null|string,
         *     puzzle_name: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'stopwatchId' => $stopwatchId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new StopwatchNotFound();
        }

        return StopwatchDetail::fromDatabaseRow($row);
    }

    /**
     * @return array<StopwatchDetail>
     */
    public function allForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
  stopwatch.id AS stopwatch_id, status,
  stopwatch.puzzle_id AS puzzle_id,
  puzzle.name AS puzzle_name,
  SUM(
    (EXTRACT(EPOCH FROM (lap->>'end')::timestamp - (lap->>'start')::timestamp))
  ) AS total_seconds,
  (laps->(jsonb_array_length(laps::jsonb) - 1)->>'start')::timestamp AS last_start_time,
  (laps->(jsonb_array_length(laps::jsonb) - 1)->>'end')::timestamp AS last_end_time
FROM
  stopwatch LEFT JOIN puzzle ON puzzle.id = stopwatch.puzzle_id,
  JSONB_ARRAY_ELEMENTS(laps::jsonb) AS lap
WHERE
    stopwatch.player_id = :playerId
    AND stopwatch.status != :finishedStatus
GROUP BY
  stopwatch.id, puzzle_id, puzzle_name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'finishedStatus' => StopwatchStatus::Finished->value,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): StopwatchDetail {
            /**
             * @var array{
             *     stopwatch_id: string,
             *     total_seconds: null|int,
             *     last_start_time: null|string,
             *     last_end_time: null|string,
             *     status: string,
             *     puzzle_id: null|string,
             *     puzzle_name: null|string,
             * } $row
             */

            return StopwatchDetail::fromDatabaseRow($row);
        }, $data);
    }
}
