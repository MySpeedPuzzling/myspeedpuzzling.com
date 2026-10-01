<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Routes that take a time id require an RFC 4122 uuid (`Requirement::UUID`), which the fixture time ids are not.
 * A test opens a copy of a fixture time under a fresh uuid7 instead - same player/pair/team, same puzzle, so the
 * copy joins the same subject's results.
 */
trait ClonesSolvingTimes
{
    /**
     * @param array<string, int|bool|string> $changes column => value, plus 'days_ago' for the finish/tracked date
     */
    private function cloneSolvingTime(string $sourceTimeId, array $changes = []): string
    {
        $timeId = Uuid::uuid7()->toString();
        $columns = ['id' => $timeId];

        foreach ($changes as $column => $value) {
            if ($column === 'days_ago') {
                $date = (new DateTimeImmutable("-{$value} days"))->format('Y-m-d H:i:s');
                $columns['finished_at'] = $date;
                $columns['tracked_at'] = $date;
                continue;
            }

            $columns[$column] = $value;
        }

        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO puzzle_solving_time SELECT (jsonb_populate_record(pst, CAST(:columns AS jsonb))).* FROM puzzle_solving_time pst WHERE pst.id = :source',
            ['columns' => json_encode($columns, JSON_THROW_ON_ERROR), 'source' => $sourceTimeId],
        );

        return $timeId;
    }
}
