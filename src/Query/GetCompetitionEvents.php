<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Results\CompetitionEvent;

/**
 * @phpstan-import-type CompetitionEventDatabaseRow from CompetitionEvent
 */
readonly final class GetCompetitionEvents
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function byId(string $competitionId): CompetitionEvent
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $query = 'SELECT * FROM competition WHERE id = :id';

        /** @var false|CompetitionEventDatabaseRow $data */
        $data = $this->database
            ->executeQuery($query, [
                'id' => $competitionId,
            ])
            ->fetchAssociative();

        if ($data === false) {
            throw new CompetitionNotFound();
        }

        return CompetitionEvent::fromDatabaseRow($data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allPast(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE COALESCE(date_to, date_from)::date < :date::date
ORDER BY date_from DESC;
SQL;
        $date = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $date->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allUpcoming(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE COALESCE(date_from, date_to)::date > :date::date
ORDER BY date_from;
SQL;
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allLive(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE :date::date 
      BETWEEN COALESCE(date_from, date_to)::date 
          AND COALESCE(date_to, date_from)::date;
SQL;
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query, [
                'date' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
ORDER BY date_from DESC;
SQL;
        $now = $this->clock->now();

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }
}
