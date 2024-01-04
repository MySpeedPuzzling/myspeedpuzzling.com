<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlatformChange;

readonly final class GetPlatformChanges
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, array<PlatformChange>>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT *
FROM changelog
ORDER BY date DESC
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        $rows = array_map(static function(array $row): PlatformChange {
            /**
             * @var array{
             *     date: string,
             *     title: string,
             *     text: null|string,
             * } $row
             */
            return PlatformChange::fromDatabaseRow($row);
        }, $data);

        $results = [];

        foreach ($rows as $row) {
            $date = $row->date->format('d.m.');

            if (!isset($results[$date])) {
                $results[$date] = [];
            }

            $results[$date][] = $row;
        }

        return $results;
    }

    /**
     * @return array<string, array<PlatformChange>>
     */
    public function recentDays(int $days): array
    {
        return array_slice($this->all(), 0, $days, true);
    }
}
