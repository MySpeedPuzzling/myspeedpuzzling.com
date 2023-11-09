<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;
use SpeedPuzzling\Web\Results\StopwatchDetail;

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
  SUM(
    (EXTRACT(EPOCH FROM (lap->>'end')::timestamp - (lap->>'start')::timestamp))
  ) AS total_seconds,
  (laps->(jsonb_array_length(laps::jsonb) - 1)->>'start')::timestamp AS last_start_time,
  (laps->(jsonb_array_length(laps::jsonb) - 1)->>'end')::timestamp AS last_end_time
FROM
  stopwatch,
  JSONB_ARRAY_ELEMENTS(laps::jsonb) AS lap
WHERE stopwatch.id = :stopwatchId
GROUP BY
  stopwatch.id
SQL;

        /**
         * @var null|array{
         *     stopwatch_id: string,
         *     total_seconds: null|int,
         *     last_start_time: null|string,
         *     last_end_time: null|string,
         *     status: string,
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
}
